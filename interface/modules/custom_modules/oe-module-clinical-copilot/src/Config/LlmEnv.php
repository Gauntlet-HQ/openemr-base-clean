<?php

/**
 * Resolves Clinical Co-Pilot LLM environment variables for PHP (Apache/CLI).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Config;

/**
 * Docker compose, shell exports, and (for local dev) `ops/local/gemini.local.env`
 * may each supply credentials. Apache/mod_php does not always mirror container
 * env into `getenv()` the same way the shell does, so this resolver checks
 * getenv, $_SERVER, $_ENV, then lazily loads the optional local env file.
 */
final class LlmEnv
{
    private const LOCAL_ENV_RELATIVE = '/ops/local/gemini.local.env';

    private static bool $localFileLoaded = false;

    private function __construct()
    {
    }

    public static function getString(string $name): string
    {
        self::ensureLocalFileLoaded();

        $fromGetenv = getenv($name);
        if (is_string($fromGetenv) && trim($fromGetenv) !== '') {
            return trim($fromGetenv);
        }

        $fromServer = $_SERVER[$name] ?? null;
        if (is_string($fromServer) && trim($fromServer) !== '') {
            return trim($fromServer);
        }

        $fromEnv = $_ENV[$name] ?? null;
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }

        return '';
    }

    public static function geminiApiKey(): string
    {
        return self::getString('CLINICAL_COPILOT_GEMINI_API_KEY');
    }

    /**
     * An OPTIONAL second Gemini API key, tried only when the primary key's call
     * fails (bad key, quota exhaustion, transient provider/transport error).
     * Empty/unset means "no backup" — the primary is used alone. See the
     * failover clients ({@see \OpenEMR\Modules\ClinicalCopilot\Reduce\FailoverLlmClient},
     * {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Llm\FailoverChatLlmClient}).
     */
    public static function geminiApiKeyBackup(): string
    {
        return self::getString('CLINICAL_COPILOT_GEMINI_API_KEY_BACKUP');
    }

    public static function gcpProjectId(): string
    {
        return self::getString('CLINICAL_COPILOT_GCP_PROJECT_ID');
    }

    public static function gcpLocation(): string
    {
        $location = self::getString('CLINICAL_COPILOT_GCP_LOCATION');

        return $location !== '' ? $location : 'us-central1';
    }

    private static function ensureLocalFileLoaded(): void
    {
        if (self::$localFileLoaded) {
            return;
        }
        self::$localFileLoaded = true;

        $path = dirname(__DIR__, 2) . self::LOCAL_ENV_RELATIVE;
        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$rawKey, $rawValue] = explode('=', $line, 2);
            $key = trim($rawKey);
            $value = trim($rawValue, " \t\n\r\0\x0B\"'");
            if ($key === '' || $value === '') {
                continue;
            }

            if (self::getString($key) !== '') {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
