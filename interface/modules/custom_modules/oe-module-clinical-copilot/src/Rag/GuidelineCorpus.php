<?php

/**
 * Loads the committed clinical-guideline corpus from JSON.
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
 * The corpus is a small set of endocrinology guideline chunks shipped in the
 * repo under `src/Rag/corpus/` — reproducible from source control (the same
 * principle the eval golden set must satisfy) and PHI-free. Kept deliberately
 * small: the Week 2 pitfall list warns against breadth before reliability, so
 * this is the office's agreed practices for the one endocrinologist, not a
 * medical library.
 */
final class GuidelineCorpus
{
    /** @var list<GuidelineChunk>|null */
    private ?array $chunks = null;

    public function __construct(private readonly string $corpusDir)
    {
    }

    public static function createDefault(): self
    {
        return new self(__DIR__ . '/corpus');
    }

    /**
     * @return list<GuidelineChunk>
     */
    public function all(): array
    {
        if ($this->chunks !== null) {
            return $this->chunks;
        }

        $chunks = [];
        foreach (glob(rtrim($this->corpusDir, '/') . '/*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $decoded = json_decode($raw, true);
            $entries = is_array($decoded) ? ($decoded['chunks'] ?? $decoded) : [];
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (is_array($entry)) {
                    $chunks[] = GuidelineChunk::fromArray($entry);
                }
            }
        }

        return $this->chunks = $chunks;
    }
}
