<?php

/**
 * A RetrieverInterface spy: records every call and returns a canned snippet list.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Rag;

use OpenEMR\Modules\ClinicalCopilot\Rag\EvidenceSnippet;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineChunk;
use OpenEMR\Modules\ClinicalCopilot\Rag\RetrieverInterface;

/**
 * The seam double for the retrieval-hookup tests: what a consumer actually
 * hands the retriever (the OUTBOUND query and tags — the thing the scrubber
 * boundary is about) is captured verbatim, so a test can assert the scrub
 * happened before the retriever, not merely that results came back.
 */
final class SpyRetriever implements RetrieverInterface
{
    /** @var list<array{query: string, tags: list<string>, topK: int}> */
    public array $calls = [];

    /**
     * @param list<EvidenceSnippet> $snippets returned from every retrieve() call
     */
    public function __construct(private readonly array $snippets = [])
    {
    }

    public function retrieve(string $query, array $tags = [], int $topK = 4): array
    {
        $this->calls[] = ['query' => $query, 'tags' => $tags, 'topK' => $topK];

        return $this->snippets;
    }

    public static function snippet(string $id = 'ada-a1c-target', ?string $url = 'https://example.org/ada'): EvidenceSnippet
    {
        return EvidenceSnippet::forChunk(
            new GuidelineChunk(
                id: $id,
                title: 'Glycemic targets',
                source: 'ADA Standards of Care',
                section: 'Glycemic Targets',
                text: 'An A1c target of <7% is reasonable for most non-pregnant adults.',
                tags: ['a1c', 'glycemic'],
                url: $url,
            ),
            0.9,
        );
    }
}
