<?php

/**
 * Seed the external knowledge Postgres from the in-repo, PHI-free guideline corpus.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

/*
 * One command to stand up (or refresh) the knowledge store:
 *
 *   CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL=postgresql://user:pass@host:5432/db \
 *     php ops/knowledge/seed_from_corpus.php
 *
 * It applies schema.sql (idempotent) and upserts every chunk from
 * src/Rag/corpus/*.json. This is the ONLY writer to the knowledge database and
 * it runs offline from a committed, PHI-free source — the request path never
 * writes here. Re-running is safe: rows are upserted by id.
 */

use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeBaseConfig;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeTableName;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\PgLiteral;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineCorpus;

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

function fail(string $message): never
{
    fwrite(STDERR, "seed_from_corpus: {$message}\n");
    exit(1);
}

if (!in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
    fail('the pdo_pgsql PHP extension is required but not installed.');
}

$config = KnowledgeBaseConfig::fromEnv();
if (!$config->isConfigured()) {
    fail(
        "no knowledge DB configured. Set CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL "
        . "(or CLINICAL_COPILOT_KNOWLEDGE_DB_HOST/_NAME/_USER/_PASSWORD)."
    );
}

KnowledgeTableName::assertValid($config->table);

if ($config->table !== 'guideline_chunks') {
    // schema.sql hard-codes the table name; a custom table must be created by the
    // operator with the same columns before seeding.
    fwrite(STDERR, "seed_from_corpus: note -- seeding custom table '{$config->table}'.\n");
}

try {
    $pdo = new \PDO($config->dsn(), $config->user, $config->password, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ]);
} catch (\PDOException $e) {
    fail('could not connect: ' . $e->getMessage());
}

// Apply the schema (CREATE TABLE IF NOT EXISTS ... — idempotent).
$schema = @file_get_contents(__DIR__ . '/schema.sql');
if (is_string($schema) && trim($schema) !== '') {
    try {
        $pdo->exec($schema);
    } catch (\PDOException $e) {
        fail('schema apply failed: ' . $e->getMessage());
    }
}

$chunks = GuidelineCorpus::createDefault()->all();
if ($chunks === []) {
    fail('the in-repo corpus is empty — nothing to seed.');
}

$sql = sprintf(
    'INSERT INTO %s (id, title, source, section, body, tags, url) '
    . 'VALUES (:id, :title, :source, :section, :body, :tags::text[], :url) '
    . 'ON CONFLICT (id) DO UPDATE SET '
    . 'title = EXCLUDED.title, source = EXCLUDED.source, section = EXCLUDED.section, '
    . 'body = EXCLUDED.body, tags = EXCLUDED.tags, url = EXCLUDED.url, updated_at = now()',
    $config->table,
);
$statement = $pdo->prepare($sql);

$seeded = 0;
foreach ($chunks as $chunk) {
    $statement->execute([
        'id' => $chunk->id,
        'title' => $chunk->title,
        'source' => $chunk->source,
        'section' => $chunk->section,
        'body' => $chunk->text,
        'tags' => PgLiteral::textArray($chunk->tags),
        'url' => $chunk->url,
    ]);
    $seeded++;
}

echo "seed_from_corpus: upserted {$seeded} guideline chunks into '{$config->table}' on {$config->host}.\n";
