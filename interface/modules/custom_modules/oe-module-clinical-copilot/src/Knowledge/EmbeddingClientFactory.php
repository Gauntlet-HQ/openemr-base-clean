<?php

/**
 * Selects the embedding client: Gemini when an API key is set, else unavailable.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv;

/**
 * One decision point for "where do embeddings come from," so the writer and the
 * retriever embed with identical settings. With a Gemini API key set, returns
 * {@see GeminiEmbeddingClient}; otherwise {@see UnavailableEmbeddingClient}, in
 * which case the knowledge store runs on full-text search alone. Model and
 * dimensionality are env-tunable but MUST match the pgvector column width in
 * schema.sql (default: gemini-embedding-001 / 1536).
 */
final class EmbeddingClientFactory
{
    public const DEFAULT_MODEL = 'gemini-embedding-001';
    public const DEFAULT_DIMENSION = 1536;

    public static function create(): EmbeddingClientInterface
    {
        $dimension = self::dimension();
        $apiKey = LlmEnv::geminiApiKey();
        if ($apiKey === '') {
            return new UnavailableEmbeddingClient($dimension);
        }

        return new GeminiEmbeddingClient($apiKey, self::model(), $dimension, null, new SystemLogger());
    }

    public static function model(): string
    {
        $model = LlmEnv::getString('CLINICAL_COPILOT_KNOWLEDGE_EMBED_MODEL');

        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    public static function dimension(): int
    {
        $dim = LlmEnv::getString('CLINICAL_COPILOT_KNOWLEDGE_EMBED_DIM');

        return $dim !== '' && ctype_digit($dim) && (int)$dim > 0 ? (int)$dim : self::DEFAULT_DIMENSION;
    }
}
