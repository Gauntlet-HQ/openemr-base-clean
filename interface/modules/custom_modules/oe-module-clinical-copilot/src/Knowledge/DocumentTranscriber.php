<?php

/**
 * Transcribes a binary document (PDF/image) to plain text via the vision model.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Reduce\InlineDataPart;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;

/**
 * The knowledge store holds only PHI-free, published material (guideline PDFs,
 * journal articles), so — unlike a patient chart — it is safe to hand a whole
 * document to the model. This reuses the SAME {@see LlmClientInterface} vision
 * seam the intake extractor uses to turn a PDF/image into plain text, so no new
 * PDF-parsing dependency enters the stack. The heavy structured-extraction step
 * is deliberately NOT used here: knowledge ingestion only needs the readable
 * text; {@see DocumentChunker} does the structuring deterministically in PHP.
 *
 * With no model configured the injected client throws
 * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException}, which
 * propagates so the endpoint can tell the operator to paste text / upload a
 * .txt/.md instead.
 */
final class DocumentTranscriber
{
    private const PROMPT_VERSION = 'knowledge-transcribe-v1';

    private const SYSTEM_INSTRUCTIONS =
        'You are a precise document transcriber. Return the full readable text of the '
        . 'attached document verbatim, preserving heading and paragraph structure (one blank '
        . 'line between paragraphs). Do not summarize, translate, comment, or add anything not '
        . 'present in the document. Return only the transcription in the required field.';

    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly string $model,
    ) {
    }

    public function transcribe(string $bytes, string $mimeType): string
    {
        $request = new PromptRequest(
            systemInstructions: self::SYSTEM_INSTRUCTIONS,
            userContent: 'Transcribe the complete readable text of this document into the "text" field.',
            responseSchema: [
                'type' => 'object',
                'properties' => ['text' => ['type' => 'string']],
                'required' => ['text'],
            ],
            model: $this->model,
            promptVersion: self::PROMPT_VERSION,
            temperature: 0.0,
            // A guideline/journal transcription runs long; lift the output budget
            // above the default so a multi-page document is not truncated. Very
            // large documents may still exceed it — the operator splits those.
            maxOutputTokens: 32768,
            parts: [InlineDataPart::fromBytes($mimeType, $bytes)],
        );

        $response = $this->llm->generateStructured($request);

        $decoded = json_decode($response->rawJson, true);
        if (is_array($decoded) && isset($decoded['text']) && is_string($decoded['text'])) {
            return $decoded['text'];
        }

        return '';
    }
}
