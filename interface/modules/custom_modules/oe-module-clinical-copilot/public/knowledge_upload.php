<?php

/**
 * Clinical Co-Pilot -- Maintenance: push a document into the knowledge base (RAG).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
// Read-only OpenEMR session -- the only write is to the SEPARATE knowledge
// Postgres (via KnowledgeChunkWriter), never an OpenEMR/PHI table.
$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\ChunkOptions;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\DocumentMetadata;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeBaseStatus;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeDocumentIngestor;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\TagInput;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\UnsupportedDocumentException;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineChunk;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// A body over post_max_size makes PHP discard $_POST/$_FILES (incl. the CSRF
// token); detect it and return a clear size error rather than dying on CSRF.
if ($isPost && $_POST === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    echo xlt('That upload was too large to process. Please use a smaller file.');
    exit;
}

if ($isPost) {
    CsrfUtils::checkCsrfInput(INPUT_POST, $session, dieOnFail: true);
}

// Knowledge curation is an administrative task: gate on the host admin section
// AND the module's own ACL, mirroring the observability dashboard.
$isAdmin = AclMain::aclCheckCore('admin', 'super') || AclMain::aclCheckCore('admin', 'users');
if (!$isAdmin || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

$authUser = (string)($session->get('authUser') ?? '');
$authProvider = (string)($session->get('authProvider') ?? '');
$webRoot = OEGlobalsBag::getInstance()->getWebRoot();
$moduleBase = $webRoot . '/interface/modules/custom_modules/oe-module-clinical-copilot/public';
$postUrl = $moduleBase . '/knowledge_upload.php';

/** Upper bound on an uploaded knowledge document (matches the Gemini inline cap). */
const KNOWLEDGE_MAX_BYTES = 12 * 1024 * 1024;

$action = $isPost ? (string)($_POST['action'] ?? '') : '';

// Everything from here can reach the model or the external store. Any failure —
// an unconfigured/unreachable store, a transient outage, a bad table env, an
// audit or render hiccup — resolves to ONE generic notice, never a raw error or
// internal detail. Input guidance (missing source, wrong file type) stays specific.
try {
    $ingestor = KnowledgeDocumentIngestor::createDefault();

    if ($action === 'preview') {
        $meta = readMetadata();
        if ($meta === null) {
            renderForm($postUrl, error: xl('A source label is required (it is the citation and the re-upload key).'));
            exit;
        }
        $baseTags = TagInput::parse((string)($_POST['tags'] ?? ''));
        $chunkOptions = readChunkOptions();

        try {
            [$bytes, $mimeType, $pasted] = readDocumentInput();
        } catch (UnsupportedDocumentException) {
            renderForm($postUrl, error: xl('That file type is not supported. Upload text, markdown, HTML, PDF, or an image.'));
            exit;
        }
        if ($bytes === '') {
            renderForm($postUrl, error: xl('Upload a document or paste some text to ingest.'));
            exit;
        }

        $chunks = $pasted
            ? $ingestor->previewFromText($bytes, $meta, $baseTags, $chunkOptions)
            : $ingestor->preview($bytes, $mimeType, $meta, $baseTags, $chunkOptions);

        if ($chunks === []) {
            renderForm($postUrl, error: xl('No text could be read from that document. Please try again later, or contact your administrator.'));
            exit;
        }

        renderReview($postUrl, $meta, $chunks);
        exit;
    }

    if ($action === 'commit') {
        $chunks = decodeChunks((string)($_POST['chunks_json'] ?? ''));
        if ($chunks === []) {
            renderForm($postUrl, error: xl('Nothing to commit — please upload and preview a document first.'));
            exit;
        }
        $replaceExisting = ($_POST['replace_existing'] ?? '1') === '1';

        $written = $ingestor->commit($chunks, $replaceExisting);

        EventAuditLogger::getInstance()->newEvent(
            'security',
            $authUser,
            $authProvider,
            1,
            "Clinical Co-Pilot: ingested knowledge document '{$chunks[0]->source}' ({$written} chunks)",
        );

        renderResult($postUrl, $chunks[0]->source, $written);
        exit;
    }

    renderForm($postUrl);
} catch (\Throwable $e) {
    (new SystemLogger())->error('ClinicalCopilot: knowledge upload failed', ['exception' => $e]);
    try {
        renderForm($postUrl, error: xl('We could not complete that just now. Please try again later, or contact your administrator.'));
    } catch (\Throwable) {
        http_response_code(500);
        echo xlt('We could not complete that just now. Please try again later, or contact your administrator.');
    }
}

// --- helpers -------------------------------------------------------------

function readMetadata(): ?DocumentMetadata
{
    $source = trim((string)($_POST['source'] ?? ''));
    if ($source === '') {
        return null;
    }
    $url = trim((string)($_POST['url'] ?? ''));

    return new DocumentMetadata(
        title: trim((string)($_POST['title'] ?? '')),
        source: $source,
        section: trim((string)($_POST['section'] ?? '')),
        url: $url !== '' ? $url : null,
    );
}

/**
 * Chunk size/overlap are chosen per document at upload time (a dense reference
 * wants small chunks; a review article wants large ones). ChunkOptions clamps
 * the raw values to a safe band, so blank/garbage cannot break chunking.
 */
function readChunkOptions(): ChunkOptions
{
    $target = (int)($_POST['chunk_size'] ?? 0);
    $overlap = (int)($_POST['overlap'] ?? 0);
    if ($target <= 0) {
        return ChunkOptions::default();
    }

    return new ChunkOptions($target, $overlap);
}

/**
 * Read the document to ingest from either the file upload or the pasted-text
 * box. Returns [bytes, mimeType, isPasted].
 *
 * @return array{0: string, 1: string, 2: bool}
 */
function readDocumentInput(): array
{
    $entry = $_FILES['document'] ?? null;
    if (
        is_array($entry)
        && ($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && is_string($entry['tmp_name'] ?? null)
        && $entry['tmp_name'] !== ''
        && is_uploaded_file($entry['tmp_name'])
    ) {
        $bytes = (string)@file_get_contents($entry['tmp_name']);
        if (strlen($bytes) > KNOWLEDGE_MAX_BYTES) {
            throw new UnsupportedDocumentException('document exceeds the size limit');
        }
        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($entry['tmp_name']) : '';
        if ($mime === '' && is_string($entry['type'] ?? null)) {
            $mime = $entry['type'];
        }

        return [$bytes, $mime !== '' ? $mime : 'application/octet-stream', false];
    }

    $pasted = trim((string)($_POST['pasted_text'] ?? ''));

    return [$pasted, 'text/plain', true];
}

/**
 * @return list<GuidelineChunk>
 */
function decodeChunks(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $chunks = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        try {
            $chunks[] = GuidelineChunk::fromArray($row);
        } catch (\Throwable) {
            // Skip a malformed row rather than failing the whole commit.
            continue;
        }
    }

    return $chunks;
}

function renderForm(string $postUrl, string $error = ''): void
{
    echo twig()->render('oe-module-clinical-copilot/knowledge_upload.html.twig', [
        'view' => 'form',
        'post_url' => $postUrl,
        'error' => $error,
        'llm_configured' => LlmRuntimeConfig::llmConfigured(),
        'knowledge' => KnowledgeBaseStatus::createDefault()->snapshot(),
        'max_mb' => (int)floor(KNOWLEDGE_MAX_BYTES / (1024 * 1024)),
        'default_chunk_size' => ChunkOptions::DEFAULT_TARGET,
        'default_overlap' => ChunkOptions::DEFAULT_OVERLAP,
        'min_chunk_size' => ChunkOptions::MIN_TARGET,
        'max_chunk_size' => ChunkOptions::MAX_TARGET,
    ]);
}

/**
 * @param list<GuidelineChunk> $chunks
 */
function renderReview(string $postUrl, DocumentMetadata $meta, array $chunks): void
{
    $view = array_map(static fn (GuidelineChunk $c): array => [
        'id' => $c->id,
        'section' => $c->section,
        'tags' => $c->tags,
        'excerpt' => $c->excerpt(280),
        'chars' => mb_strlen($c->text),
    ], $chunks);

    // Chunk bodies are already normalized to valid UTF-8 by the extractor, so
    // json_encode should not fail; guard anyway so a malformed round-trip becomes
    // an empty payload (→ "nothing to commit") rather than a broken hidden field.
    $chunksJson = json_encode(array_map(static fn (GuidelineChunk $c): array => $c->toArray(), $chunks));

    echo twig()->render('oe-module-clinical-copilot/knowledge_upload.html.twig', [
        'view' => 'review',
        'post_url' => $postUrl,
        'source' => $meta->source,
        'title' => $meta->title,
        'chunk_count' => count($chunks),
        'chunks' => $view,
        'chunks_json' => $chunksJson !== false ? $chunksJson : '[]',
    ]);
}

function renderResult(string $postUrl, string $source, int $written): void
{
    echo twig()->render('oe-module-clinical-copilot/knowledge_upload.html.twig', [
        'view' => 'result',
        'post_url' => $postUrl,
        'source' => $source,
        'written' => $written,
        'knowledge' => KnowledgeBaseStatus::createDefault()->snapshot(),
    ]);
}

function twig(): \Twig\Environment
{
    return (new TwigContainer(dirname(__DIR__) . '/templates', OEGlobalsBag::getInstance()->getKernel()))->getTwig();
}
