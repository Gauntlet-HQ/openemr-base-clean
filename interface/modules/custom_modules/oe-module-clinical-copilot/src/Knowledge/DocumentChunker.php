<?php

/**
 * Splits a knowledge document's plain text into retrievable, cited chunks.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineChunk;

/**
 * Deterministic, dependency-free chunking — the "chunk it in PHP" half of the
 * knowledge-ingestion flow. Given a document's already-extracted plain text plus
 * its provenance (title/source/base tags), it produces {@see GuidelineChunk}s
 * shaped exactly like the in-repo corpus, so they retrieve identically once
 * written to Postgres.
 *
 * The split is boundary-aware rather than a blind fixed-width cut: paragraphs
 * (blank-line-separated blocks) are packed into chunks up to a target size, a
 * paragraph longer than the target is split on sentence boundaries, and each
 * chunk after the first carries a short trailing-sentence overlap from the
 * previous one so a passage that straddles a boundary is still retrievable from
 * either side. Markdown-style headings update the running "section" label, and a
 * small analyte keyword scan augments the operator-supplied tags so the
 * "ground THIS out-of-range fact" tag boost works without hand-tagging.
 */
final class DocumentChunker
{
    /** Analyte/topic keywords → tag, aligned with the in-repo corpus vocabulary. */
    private const TAG_KEYWORDS = [
        'a1c' => 'a1c', 'hba1c' => 'a1c', 'glycemic' => 'glycemic', 'glucose' => 'glucose',
        'ldl' => 'ldl', 'cholesterol' => 'lipids', 'lipid' => 'lipids', 'statin' => 'statin',
        'triglyceride' => 'triglycerides', 'hdl' => 'hdl',
        'blood pressure' => 'blood_pressure', 'hypertension' => 'hypertension',
        'metformin' => 'metformin', 'sglt2' => 'sglt2', 'glp-1' => 'glp1', 'glp1' => 'glp1',
        'insulin' => 'insulin', 'sulfonylurea' => 'sulfonylurea', 'hypoglycemia' => 'hypoglycemia',
        'creatinine' => 'renal', 'egfr' => 'renal', 'microalbumin' => 'acr', 'albumin' => 'acr',
        'tsh' => 'thyroid', 'thyroid' => 'thyroid', 'diabetes' => 'diabetes',
    ];

    /**
     * @param list<string> $baseTags operator-supplied tags applied to every chunk
     *
     * @return list<GuidelineChunk>
     */
    public function chunk(string $text, DocumentMetadata $meta, array $baseTags = [], ?ChunkOptions $options = null): array
    {
        $options ??= ChunkOptions::default();

        $normalized = $this->normalizeWhitespace($text);
        if ($normalized === '') {
            return [];
        }

        $docSlug = $this->slug($meta->source !== '' ? $meta->source : $meta->title);
        $section = $meta->section;

        $chunks = [];
        $buffer = '';
        $index = 0;

        $flush = function () use (&$buffer, &$index, &$chunks, $docSlug, $meta, &$section, $baseTags): void {
            $body = trim($buffer);
            $buffer = '';
            if ($body === '') {
                return;
            }
            $chunks[] = $this->buildChunk($docSlug, $index, $meta, $section, $body, $baseTags);
            $index++;
        };

        foreach ($this->blocks($normalized) as $block) {
            $heading = $this->asHeading($block);
            if ($heading !== null) {
                $flush();
                $section = $heading;
                continue;
            }

            foreach ($this->fitBlock($block, $options->targetChars) as $piece) {
                if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($piece) + 2 > $options->targetChars) {
                    $tail = $this->overlapTail($buffer, $options->overlapChars);
                    $flush();
                    $buffer = $tail;
                }
                $buffer = $buffer === '' ? $piece : $buffer . "\n\n" . $piece;
            }
        }
        $flush();

        return $chunks;
    }

    /**
     * @param list<string> $baseTags
     */
    private function buildChunk(
        string $docSlug,
        int $index,
        DocumentMetadata $meta,
        string $section,
        string $body,
        array $baseTags,
    ): GuidelineChunk {
        return new GuidelineChunk(
            id: sprintf('%s-%03d', $docSlug, $index),
            title: $meta->title,
            source: $meta->source,
            section: $section,
            text: $body,
            tags: $this->tagsFor($body, $baseTags),
            url: $meta->url,
        );
    }

    /**
     * @param list<string> $baseTags
     *
     * @return list<string>
     */
    private function tagsFor(string $body, array $baseTags): array
    {
        $tags = [];
        foreach ($baseTags as $tag) {
            $normalized = $this->normalizeTag($tag);
            if ($normalized !== '') {
                $tags[$normalized] = true;
            }
        }

        $haystack = mb_strtolower($body);
        foreach (self::TAG_KEYWORDS as $keyword => $tag) {
            if (str_contains($haystack, $keyword)) {
                $tags[$tag] = true;
            }
        }

        return array_keys($tags);
    }

    private function normalizeTag(string $tag): string
    {
        return TagNormalizer::normalize($tag);
    }

    /**
     * Split a document into blank-line-separated blocks (paragraphs/headings).
     *
     * @return list<string>
     */
    private function blocks(string $text): array
    {
        $blocks = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            $block = trim($block);
            if ($block !== '') {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * A short block that is a markdown heading or an all-caps/no-terminal-period
     * title reads as a section header; return its text, else null.
     */
    private function asHeading(string $block): ?string
    {
        if (str_contains($block, "\n")) {
            return null;
        }
        if (preg_match('/^#{1,6}\s+(.{1,80})$/', $block, $m) === 1) {
            return trim($m[1]);
        }
        // A short line with no sentence-ending punctuation, e.g. "Glycemic Targets".
        if (mb_strlen($block) <= 80 && preg_match('/[.!?]\s*$/', $block) !== 1 && str_word_count($block) <= 10) {
            return $block;
        }

        return null;
    }

    /**
     * A single block that fits stays whole; one larger than the target is split
     * on sentence boundaries so no chunk blows far past the target size.
     *
     * @return list<string>
     */
    private function fitBlock(string $block, int $targetChars): array
    {
        if (mb_strlen($block) <= $targetChars) {
            return [$block];
        }

        $pieces = [];
        $current = '';
        foreach ($this->sentences($block) as $sentence) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) + 1 > $targetChars) {
                $pieces[] = trim($current);
                $current = '';
            }
            $current = $current === '' ? $sentence : $current . ' ' . $sentence;
        }
        if (trim($current) !== '') {
            $pieces[] = trim($current);
        }

        return $pieces;
    }

    /**
     * @return list<string>
     */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/', $text) ?: [$text];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    /**
     * The trailing sentence(s) of a chunk, up to the overlap budget, to seed the
     * next chunk so a boundary-straddling passage stays retrievable either side.
     */
    private function overlapTail(string $body, int $overlapChars): string
    {
        if ($overlapChars <= 0) {
            return '';
        }

        $sentences = $this->sentences($body);
        $tail = '';
        for ($i = count($sentences) - 1; $i >= 0; $i--) {
            $candidate = $tail === '' ? $sentences[$i] : $sentences[$i] . ' ' . $tail;
            if (mb_strlen($candidate) > $overlapChars) {
                break;
            }
            $tail = $candidate;
        }

        return $tail;
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Collapse runs of spaces/tabs but preserve paragraph breaks.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? mb_substr($slug, 0, 60) : 'doc';
    }
}
