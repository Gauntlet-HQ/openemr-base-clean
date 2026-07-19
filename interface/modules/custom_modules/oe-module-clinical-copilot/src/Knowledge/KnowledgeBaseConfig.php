<?php

/**
 * Resolves the external medical-knowledge Postgres connection settings from env.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv;

/**
 * The knowledge base is a SEPARATE database from OpenEMR's own MySQL. That
 * separation is the whole point of this subsystem: OpenEMR's DB holds PHI (the
 * patient chart, extractions, telemetry) under the BAA; this Postgres holds only
 * general medical knowledge (guideline chunks) that carries no PHI. They are
 * distinct connections to distinct servers so a query against one can never
 * reach the other's rows — the segregation is physical, not a runtime `WHERE`.
 *
 * Configuration is env-only (Railway injects it), resolved through
 * {@see LlmEnv::getString()} so it uses the same getenv/$_SERVER/$_ENV cascade as
 * the LLM credentials. Two equivalent forms are accepted, URL first:
 *
 *   CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL =
 *       postgresql://user:pass@host:5432/dbname?sslmode=require
 *
 *   ...or discrete parts:
 *   CLINICAL_COPILOT_KNOWLEDGE_DB_HOST / _PORT / _NAME / _USER / _PASSWORD / _SSLMODE
 *
 * Unset/blank ⇒ {@see isConfigured()} is false and the whole subsystem degrades
 * to the in-repo offline corpus — the module never hard-depends on the external
 * store (the same degrade-cleanly discipline as the LLM path).
 */
final readonly class KnowledgeBaseConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $dbName,
        public string $user,
        public string $password,
        public string $sslMode,
        public string $table,
        // Optional dedicated write role. The retrieval path (the summarizer) uses
        // the plain user above and only ever SELECTs; the ingestion path can use
        // this insert-capable role instead, so a deployment can grant the read
        // user SELECT-only and keep write privilege on a separate credential.
        // Blank ⇒ ingestion reuses the read user/password.
        public string $writeUser = '',
        public string $writePassword = '',
    ) {
    }

    public static function fromEnv(): self
    {
        $url = LlmEnv::getString('CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL');
        $table = LlmEnv::getString('CLINICAL_COPILOT_KNOWLEDGE_TABLE');
        $table = $table !== '' ? $table : 'guideline_chunks';

        $writeUser = LlmEnv::getString('CLINICAL_COPILOT_KNOWLEDGE_DB_WRITE_USER');
        $writePassword = LlmEnv::getString('CLINICAL_COPILOT_KNOWLEDGE_DB_WRITE_PASSWORD');

        if ($url !== '') {
            return self::fromUrl($url, $table, $writeUser, $writePassword);
        }

        $port = LlmEnv::getString('CLINICAL_COPILOT_KNOWLEDGE_DB_PORT');
        $sslMode = LlmEnv::getString('CLINICAL_COPILOT_KNOWLEDGE_DB_SSLMODE');

        return new self(
            host: LlmEnv::getString('CLINICAL_COPILOT_KNOWLEDGE_DB_HOST'),
            port: $port !== '' && ctype_digit($port) ? (int)$port : 5432,
            dbName: LlmEnv::getString('CLINICAL_COPILOT_KNOWLEDGE_DB_NAME'),
            user: LlmEnv::getString('CLINICAL_COPILOT_KNOWLEDGE_DB_USER'),
            password: LlmEnv::getString('CLINICAL_COPILOT_KNOWLEDGE_DB_PASSWORD'),
            sslMode: $sslMode !== '' ? $sslMode : 'prefer',
            table: $table,
            writeUser: $writeUser,
            writePassword: $writePassword,
        );
    }

    /** The role ingestion writes with — the dedicated write user if set, else the read user. */
    public function effectiveWriteUser(): string
    {
        return $this->writeUser !== '' ? $this->writeUser : $this->user;
    }

    public function effectiveWritePassword(): string
    {
        return $this->writeUser !== '' ? $this->writePassword : $this->password;
    }

    /**
     * Parse a `postgres(ql)://user:pass@host:port/dbname?sslmode=...` URL — the
     * shape Railway and most managed Postgres providers hand out — into discrete
     * settings. A malformed URL yields an unconfigured (blank-host) config, which
     * simply degrades to the offline corpus rather than throwing on a page load.
     */
    private static function fromUrl(string $url, string $table, string $writeUser = '', string $writePassword = ''): self
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return new self('', 5432, '', '', '', 'prefer', $table);
        }

        parse_str($parts['query'] ?? '', $query);
        $sslMode = is_string($query['sslmode'] ?? null) && $query['sslmode'] !== '' ? $query['sslmode'] : 'prefer';

        return new self(
            host: $parts['host'],
            port: isset($parts['port']) ? (int)$parts['port'] : 5432,
            dbName: isset($parts['path']) ? ltrim($parts['path'], '/') : '',
            user: isset($parts['user']) ? rawurldecode($parts['user']) : '',
            password: isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
            sslMode: $sslMode,
            table: $table,
            writeUser: $writeUser,
            writePassword: $writePassword,
        );
    }

    /**
     * Configured enough to attempt a connection. Host + database name are the
     * minimum; a missing password is legal for trust/peer auth so it is not
     * required here.
     */
    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->dbName !== '';
    }

    /**
     * The PDO DSN (connection target only). Credentials travel as separate PDO
     * constructor arguments, never interpolated here, so the password cannot
     * surface in a DSN that gets logged.
     */
    public function dsn(): string
    {
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $this->host,
            $this->port,
            $this->dbName,
            $this->sslMode,
        );
    }
}
