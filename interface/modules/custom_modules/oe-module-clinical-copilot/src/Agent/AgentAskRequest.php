<?php

/**
 * The parsed public/agent.php request: raw POST input turned into one typed boundary object.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;
use OpenEMR\Modules\ClinicalCopilot\Ingest\UploadedDocument;
use OpenEMR\Modules\ClinicalCopilot\Rag\PatientEvidenceService;

/**
 * Parse, don't validate, at the HTTP boundary (repo standard): the endpoint
 * reads its superglobals exactly once and hands the raw values to
 * {@see self::fromPost()}, which either throws {@see InvalidAgentAskException}
 * (with a user-safe message the endpoint returns as the 400 reason) or
 * produces this object -- after which every field guarantees its own
 * validity and nothing downstream re-checks. Mirrors the chat path's input
 * bounds (question length) and the evidence page's closed topic vocabulary
 * (unknown tags are dropped, not fatal, exactly like `evidence.php`).
 *
 * The correlation id is deliberately NOT parsed from the request -- it is
 * minted server-side per invocation (module convention: UUIDv7, same as the
 * synthesis and chat paths), which is why {@see self::toAgentRequest()}
 * takes it as an argument instead of this object carrying it.
 */
final readonly class AgentAskRequest
{
    /** Mirror of the chat turn's message bound (ChatController). */
    private const MAX_QUESTION_LENGTH = 4000;

    /**
     * @param list<string> $tags validated evidence topics (closed vocabulary)
     */
    private function __construct(
        public int $pid,
        public string $question,
        public array $tags,
        public ?DocType $docType,
        public ?UploadedDocument $document,
    ) {
    }

    /**
     * @param mixed $rawPid      `$_POST['pid']`
     * @param mixed $rawQuestion `$_POST['question']`
     * @param mixed $rawTags     `$_POST['tags']` (comma-separated, optional)
     * @param mixed $rawDocType  `$_POST['doc_type']` (required iff a document was uploaded)
     * @param ?UploadedDocument $document the already-parsed upload, or null
     *
     * @throws InvalidAgentAskException with a user-safe reason
     */
    public static function fromPost(
        mixed $rawPid,
        mixed $rawQuestion,
        mixed $rawTags,
        mixed $rawDocType,
        ?UploadedDocument $document,
    ): self {
        $pid = is_numeric($rawPid) ? (int)$rawPid : 0;
        if ($pid <= 0) {
            throw new InvalidAgentAskException('a valid pid is required');
        }

        $question = is_string($rawQuestion) ? trim($rawQuestion) : '';
        if ($question === '') {
            throw new InvalidAgentAskException('question must not be empty');
        }
        if (strlen($question) > self::MAX_QUESTION_LENGTH) {
            throw new InvalidAgentAskException('question is too long');
        }

        $tags = [];
        if (is_string($rawTags) && $rawTags !== '') {
            foreach (explode(',', $rawTags) as $key) {
                $key = trim($key);
                if (PatientEvidenceService::isTopic($key)) {
                    $tags[] = $key;
                }
            }
        }

        $docType = null;
        if ($document !== null) {
            $docType = is_string($rawDocType) ? DocType::tryFrom($rawDocType) : null;
            if ($docType === null) {
                throw new InvalidAgentAskException('doc_type must be intake_form, lab_pdf, or medication_list when a document is uploaded');
            }
        }

        return new self($pid, $question, $tags, $docType, $document);
    }

    public function toAgentRequest(string $correlationId): AgentRequest
    {
        return new AgentRequest(
            $this->pid,
            $correlationId,
            $this->docType,
            $this->document?->bytes,
            $this->document?->mimeType,
            $this->question,
            $this->tags,
        );
    }
}
