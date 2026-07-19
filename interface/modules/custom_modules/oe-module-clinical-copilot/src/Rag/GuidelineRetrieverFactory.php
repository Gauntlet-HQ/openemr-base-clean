<?php

/**
 * Chooses the guideline retriever: external knowledge Postgres, else offline corpus.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Rag;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\EmbeddingClientFactory;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeBaseConfig;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeBaseConnection;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeQueryScrubber;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\PostgresGuidelineRetriever;

/**
 * One decision point for "where does guideline evidence come from," so every
 * consumer (the evidence worker, the summarizer, chat) gets the same wiring:
 *
 *   - Knowledge Postgres CONFIGURED  ⇒ {@see PostgresGuidelineRetriever} — the
 *     tool that pulls from the separate, PHI-free knowledge store.
 *   - NOT configured                 ⇒ {@see HybridRetriever::createDefault()},
 *     the fully-offline keyword+dense+rerank pipeline over the in-repo corpus.
 *
 * The choice is made once, at construction, from env — not per query — so the
 * behaviour is predictable and an operator who configured the store but left it
 * unreachable sees an honest "degraded" (empty evidence, visible in traces),
 * rather than a silent fall-back that hides the misconfiguration. With nothing
 * configured the module runs exactly as before, entirely offline.
 */
final class GuidelineRetrieverFactory
{
    public static function createDefault(): RetrieverInterface
    {
        $config = KnowledgeBaseConfig::fromEnv();
        if (!$config->isConfigured()) {
            return HybridRetriever::createDefault();
        }

        $connection = new KnowledgeBaseConnection($config, new SystemLogger());

        return new PostgresGuidelineRetriever(
            $connection,
            new KnowledgeQueryScrubber(),
            $config->table,
            EmbeddingClientFactory::create(),
        );
    }
}
