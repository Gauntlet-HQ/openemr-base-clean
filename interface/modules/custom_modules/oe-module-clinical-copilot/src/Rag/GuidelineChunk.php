<?php

/**
 * One retrievable unit of guideline evidence from the committed corpus.
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
 * A guideline chunk is general clinical evidence ("ADA recommends an A1c target
 * <7% for most non-pregnant adults"), never patient data — so the corpus lives
 * in the repo, contains no PHI, and is reproducible from source control. `tags`
 * are the analyte/topic keywords (`a1c`, `lipids`, `acr`, ...) the retriever
 * boosts on, letting the summarizer pull "the guideline for THIS out-of-range
 * fact" without an LLM in the loop.
 */
final readonly class GuidelineChunk
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $source,
        public string $section,
        public string $text,
        public array $tags = [],
        public ?string $url = null,
    ) {
        if ($id === '') {
            throw new \DomainException('GuidelineChunk.id must not be empty');
        }
        if ($text === '') {
            throw new \DomainException('GuidelineChunk.text must not be empty');
        }
        if ($source === '') {
            throw new \DomainException('GuidelineChunk.source must not be empty');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $tags = [];
        if (isset($data['tags']) && is_array($data['tags'])) {
            foreach ($data['tags'] as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        return new self(
            self::stringField($data, 'id'),
            self::stringField($data, 'title'),
            self::stringField($data, 'source'),
            self::stringField($data, 'section'),
            self::stringField($data, 'text'),
            $tags,
            isset($data['url']) && is_string($data['url']) && $data['url'] !== '' ? $data['url'] : null,
        );
    }

    /**
     * A short excerpt for citation display (`quote_or_value`), never the whole
     * chunk — the retriever cites what it grounded on, not a wall of text.
     */
    public function excerpt(int $maxChars = 240): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $this->text) ?? $this->text);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxChars)) . '…';
    }

    /**
     * The inverse of {@see fromArray()} — a round-trippable representation used to
     * carry a proposed chunk through the ingestion review step (hidden form field)
     * without re-transcribing the source document on commit.
     *
     * @return array{id: string, title: string, source: string, section: string, text: string, tags: list<string>, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'source' => $this->source,
            'section' => $this->section,
            'text' => $this->text,
            'tags' => $this->tags,
            'url' => $this->url,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
