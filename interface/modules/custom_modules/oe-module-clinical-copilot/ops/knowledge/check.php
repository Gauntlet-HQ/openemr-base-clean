<?php

/**
 * One-shot health check for the external medical-knowledge Postgres (RAG).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

/*
 * Runs every link in the chain that has to hold for vector search to work, and
 * prints PASS / FAIL / WARN per line with a single verdict at the end:
 *
 *   1. pdo_pgsql PHP driver present            (the usual wall — MySQL-only image)
 *   2. CLINICAL_COPILOT_KNOWLEDGE_* configured  (the module knows where the DB is)
 *   3. connection opens                         (host/creds/sslmode reachable)
 *   4. pgvector extension enabled               (CREATE EXTENSION vector succeeded)
 *   5. table + embedding column present, width == CLINICAL_COPILOT_KNOWLEDGE_EMBED_DIM
 *   6. corpus seeded                            (row count > 0)
 *   7. embeddings present + API key             (WARN — full-text still works without)
 *
 * Standalone (its own autoloader, no OpenEMR bootstrap), so it runs anywhere php
 * + the module are present — including as root in the container:
 *
 *   php <module>/ops/knowledge/check.php
 *
 * Exit code: 0 when every REQUIRED link (1-6) passes, 1 otherwise. A WARN on the
 * embedding link does not fail the run (retrieval degrades to full-text).
 */

use OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\EmbeddingClientFactory;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeBaseConfig;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeTableName;

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

$failed = false;

/**
 * @param 'PASS'|'FAIL'|'WARN' $state
 */
function line(string $state, string $label, string $detail = ''): void
{
    global $failed;
    if ($state === 'FAIL') {
        $failed = true;
    }
    $colors = ['PASS' => '32', 'WARN' => '33', 'FAIL' => '31'];
    $tag = sprintf("\033[1;%sm[%s]\033[0m", $colors[$state] ?? '0', $state);
    echo $tag . ' ' . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "Clinical Co-Pilot — knowledge store health check\n";
echo str_repeat('-', 52) . "\n";

// 1. pdo_pgsql driver -------------------------------------------------------
if (in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
    line('PASS', 'pdo_pgsql PHP driver present');
} else {
    line('FAIL', 'pdo_pgsql PHP driver MISSING', 'add it to the app image (OpenEMR core is MySQL-only)');
    // Nothing else can pass without the driver — report and stop.
    echo str_repeat('-', 52) . "\n";
    echo "VERDICT: FAIL — install pdo_pgsql, then re-run.\n";
    exit(1);
}

// 2. configured -------------------------------------------------------------
$config = KnowledgeBaseConfig::fromEnv();
if ($config->isConfigured()) {
    line('PASS', 'knowledge DB configured', "host={$config->host} db={$config->dbName} sslmode={$config->sslMode}");
} else {
    line('FAIL', 'knowledge DB NOT configured', 'set CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL (or the discrete DB_* vars)');
    echo str_repeat('-', 52) . "\n";
    echo "VERDICT: FAIL — the module has no DB to reach; it runs on the offline corpus.\n";
    exit(1);
}

// 3. connection -------------------------------------------------------------
$pdo = null;
try {
    $pdo = new \PDO($config->dsn(), $config->user, $config->password, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_TIMEOUT => 5,
    ]);
    line('PASS', 'connection opens');
} catch (\PDOException $e) {
    line('FAIL', 'could not connect', $e->getMessage());
    echo str_repeat('-', 52) . "\n";
    echo "VERDICT: FAIL — check host/port/credentials/sslmode.\n";
    exit(1);
}

// 4. pgvector extension -----------------------------------------------------
try {
    $row = $pdo->query("SELECT extversion FROM pg_extension WHERE extname = 'vector'")->fetch(\PDO::FETCH_ASSOC);
    if (is_array($row) && isset($row['extversion'])) {
        line('PASS', 'pgvector extension enabled', 'v' . (string)$row['extversion']);
    } else {
        line('FAIL', 'pgvector extension NOT enabled', 'run schema.sql / seed_from_corpus.php; the Postgres image must ship pgvector');
    }
} catch (\PDOException $e) {
    line('FAIL', 'could not query pg_extension', $e->getMessage());
}

// 5. table + embedding column width ----------------------------------------
$table = $config->table;
$expectedDim = EmbeddingClientFactory::dimension();
if (!KnowledgeTableName::isValid($table)) {
    line('FAIL', 'configured table name is invalid', $table);
} else {
    try {
        $exists = $pdo->query("SELECT to_regclass(" . $pdo->quote($table) . ") AS t")->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($exists) || ($exists['t'] ?? null) === null) {
            line('FAIL', "table '{$table}' does not exist", 'run seed_from_corpus.php (applies schema.sql)');
        } else {
            $col = $pdo->query(
                "SELECT format_type(a.atttypid, a.atttypmod) AS coltype
                 FROM pg_attribute a
                 WHERE a.attrelid = " . $pdo->quote($table) . "::regclass
                   AND a.attname = 'embedding' AND NOT a.attisdropped"
            )->fetch(\PDO::FETCH_ASSOC);
            $coltype = is_array($col) ? (string)($col['coltype'] ?? '') : '';
            if ($coltype === '') {
                line('FAIL', "'{$table}.embedding' column missing", 'run seed_from_corpus.php');
            } elseif (preg_match('/\((\d+)\)/', $coltype, $m) === 1 && (int)$m[1] === $expectedDim) {
                line('PASS', "table + embedding column present", "{$coltype} matches EMBED_DIM={$expectedDim}");
            } else {
                line('FAIL', 'embedding column width mismatch', "column is {$coltype} but EMBED_DIM={$expectedDim} — recreate + re-embed");
            }
        }
    } catch (\PDOException $e) {
        line('FAIL', 'could not inspect the table', $e->getMessage());
    }
}

// 6. corpus seeded ----------------------------------------------------------
$total = null;
if (KnowledgeTableName::isValid($table)) {
    try {
        $n = $pdo->query("SELECT count(*) AS n FROM {$table}")->fetch(\PDO::FETCH_ASSOC);
        $total = is_array($n) ? (int)($n['n'] ?? 0) : 0;
        if ($total > 0) {
            line('PASS', 'corpus seeded', "{$total} chunks");
        } else {
            line('FAIL', 'table is empty', 'run seed_from_corpus.php (or ingest via Maintenance → Knowledge Base)');
        }
    } catch (\PDOException $e) {
        line('FAIL', 'could not count rows', $e->getMessage());
    }
}

// 7. embeddings present (WARN, not required) --------------------------------
$hasKey = LlmEnv::geminiApiKey() !== '';
if ($total !== null && $total > 0 && KnowledgeTableName::isValid($table)) {
    try {
        $e = $pdo->query("SELECT count(*) AS n FROM {$table} WHERE embedding IS NOT NULL")->fetch(\PDO::FETCH_ASSOC);
        $embedded = is_array($e) ? (int)($e['n'] ?? 0) : 0;
        if ($embedded > 0) {
            line('PASS', 'vector search live', "{$embedded}/{$total} rows embedded");
        } elseif (!$hasKey) {
            line('WARN', 'no embeddings — full-text only', 'set CLINICAL_COPILOT_GEMINI_API_KEY, then ingest docs via the UI to vector-index');
        } else {
            line('WARN', 'no embeddings yet — full-text only', 'the CLI seed does not embed; ingest via Maintenance → Knowledge Base to add vectors');
        }
    } catch (\PDOException $ex) {
        line('WARN', 'could not check embeddings', $ex->getMessage());
    }
}

echo str_repeat('-', 52) . "\n";
if ($failed) {
    echo "VERDICT: FAIL — fix the [FAIL] line(s) above.\n";
    exit(1);
}
echo "VERDICT: PASS — the knowledge store is wired and populated.\n";
exit(0);
