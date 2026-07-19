<?php

/**
 * CLI: ingest a document (or a directory of them) into the knowledge Postgres.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

/*
 * The bulk/batch twin of the Maintenance upload page. Runs INSIDE the OpenEMR
 * container (it bootstraps globals so the reused Gemini transcription path is
 * available for PDFs/images):
 *
 *   # one document
 *   openemr-cmd e 'php .../ops/knowledge/ingest_document.php \
 *       --file=/path/ada-2026.pdf --source="ADA Standards of Care 2026" --tags=a1c,lipids'
 *
 *   # a whole folder (source defaults to each file name)
 *   openemr-cmd e 'php .../ops/knowledge/ingest_document.php --dir=/path/guidelines'
 *
 * Text/Markdown/HTML files need no model; PDFs/images are transcribed via the
 * configured LLM. Requires the knowledge DB env (see docs/knowledge-base.md).
 */

use OpenEMR\Modules\ClinicalCopilot\Knowledge\ChunkOptions;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\DocumentMetadata;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeDocumentIngestor;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\TagInput;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ingest_document.php is CLI-only\n");
    exit(1);
}

$ignoreAuth = true;
$_GET['site'] = $_GET['site'] ?? 'default';
require_once __DIR__ . '/../../../../../globals.php';

$moduleRoot = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($moduleRoot): void {
    $prefix = 'OpenEMR\\Modules\\ClinicalCopilot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $moduleRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$opts = getopt('', ['file:', 'dir:', 'source:', 'title:', 'section:', 'tags:', 'chunk-size:', 'overlap:', 'no-replace', 'help']);

if (isset($opts['help']) || (!isset($opts['file']) && !isset($opts['dir']))) {
    fwrite(STDERR, "Usage: php ingest_document.php --file=PATH [--source=..] [--title=..] [--section=..] [--tags=a,b] [--chunk-size=1200] [--overlap=180] [--no-replace]\n");
    fwrite(STDERR, "       php ingest_document.php --dir=PATH  [--tags=a,b] [--chunk-size=..] [--overlap=..] [--no-replace]   (source defaults to each file name)\n");
    exit(isset($opts['help']) ? 0 : 2);
}

$ingestor = KnowledgeDocumentIngestor::createDefault();
if (!$ingestor->isStoreAvailable()) {
    fwrite(STDERR, "ingest_document: knowledge store is not configured/reachable (set CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL).\n");
    exit(1);
}

$replaceExisting = !isset($opts['no-replace']);
$baseTags = TagInput::parse(is_string($opts['tags'] ?? null) ? $opts['tags'] : '');
$chunkOptions = isset($opts['chunk-size'])
    ? new ChunkOptions((int)$opts['chunk-size'], isset($opts['overlap']) ? (int)$opts['overlap'] : ChunkOptions::DEFAULT_OVERLAP)
    : ChunkOptions::default();

$files = [];
if (isset($opts['file']) && is_string($opts['file'])) {
    $files[] = $opts['file'];
}
if (isset($opts['dir']) && is_string($opts['dir'])) {
    foreach (glob(rtrim($opts['dir'], '/') . '/*') ?: [] as $path) {
        if (is_file($path)) {
            $files[] = $path;
        }
    }
}

$totalDocs = 0;
$totalChunks = 0;
foreach ($files as $path) {
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, "skip (not readable): {$path}\n");
        continue;
    }

    $bytes = (string)file_get_contents($path);
    $mime = function_exists('mime_content_type') ? (string)@mime_content_type($path) : 'text/plain';
    $source = isset($opts['dir']) || !isset($opts['source']) || !is_string($opts['source'])
        ? basename($path)
        : $opts['source'];

    $meta = new DocumentMetadata(
        title: is_string($opts['title'] ?? null) ? $opts['title'] : basename($path),
        source: $source,
        section: is_string($opts['section'] ?? null) ? $opts['section'] : '',
    );

    try {
        $chunks = $ingestor->preview($bytes, $mime, $meta, $baseTags, $chunkOptions);
        if ($chunks === []) {
            fwrite(STDERR, "skip (no text extracted): {$path}\n");
            continue;
        }
        $written = $ingestor->commit($chunks, $replaceExisting);
        echo "ok: {$source} -> {$written} chunks\n";
        $totalDocs++;
        $totalChunks += $written;
    } catch (\Throwable $e) {
        fwrite(STDERR, "error ({$path}): " . $e->getMessage() . "\n");
    }
}

echo "done: {$totalDocs} document(s), {$totalChunks} chunk(s) written.\n";
