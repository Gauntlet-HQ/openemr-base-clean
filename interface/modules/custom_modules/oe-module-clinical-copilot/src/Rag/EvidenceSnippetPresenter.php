<?php

/**
 * Flattens EvidenceSnippets to the wire/persistence array shape the chat surface uses.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Rag;

/**
 * One place that turns a retrieved {@see EvidenceSnippet} into the plain array
 * a JSON response or a persisted chat-turn row carries — the same fields the
 * agent endpoint exposes ({@see \OpenEMR\Modules\ClinicalCopilot\Controller\AgentController}:
 * title/source/section/score + the full {@see \OpenEMR\Modules\ClinicalCopilot\Ingest\SourceCitation}
 * contract), plus the display excerpt and url so a JS client can render the
 * cited section without unpacking the citation. Deterministic and lossless on
 * provenance: the citation array keeps source_type=guideline, chunk id, section,
 * quote, and url, so evidence replayed from a stored turn is as attributable as
 * evidence fresh from the retriever.
 */
final class EvidenceSnippetPresenter
{
    private function __construct()
    {
        // static-only
    }

    /**
     * @param list<EvidenceSnippet> $snippets
     *
     * @return list<array{title: string, source: string, section: string, quote: string, url: ?string, score: float, citation: array<string, mixed>}>
     */
    public static function toWire(array $snippets): array
    {
        return array_map(static fn (EvidenceSnippet $s): array => [
            'title' => $s->chunk->title,
            'source' => $s->chunk->source,
            'section' => $s->chunk->section,
            'quote' => $s->chunk->excerpt(),
            'url' => $s->chunk->url,
            'score' => $s->score,
            'citation' => $s->citation->toArray(),
        ], $snippets);
    }
}
