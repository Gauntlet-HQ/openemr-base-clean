<?php

/**
 * Unauthenticated liveness check -- checks NO dependencies (ARCHITECTURE.md §3.4).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

/**
 * "GET /copilot/health -- unauthenticated liveness: returns only
 * module-enabled + module version. Deliberately checks no dependencies -- a
 * DB outage must not fail liveness and get the app pointlessly restarted by
 * an orchestrator" (ARCHITECTURE.md §3.4). This class reads only PHP
 * constants/files already loaded by the page bootstrap -- no `QueryUtils`
 * call, no network call, nothing that can itself fail for a reason unrelated
 * to "is this PHP process alive."
 */
final class HealthCheck
{
    /**
     * @return array{status: string, module: string, version: string}
     */
    public function check(): array
    {
        return [
            'status' => 'ok',
            'module' => 'oe-module-clinical-copilot',
            'version' => self::moduleVersion(),
        ];
    }

    private static function moduleVersion(): string
    {
        $versionFile = dirname(__DIR__, 2) . '/version.php';
        if (!is_file($versionFile)) {
            return 'unknown';
        }

        // version.php only defines four plain string variables ($v_major,
        // $v_minor, $v_patch, $v_tag) -- no side effects, safe to include
        // directly (the same file the Module Manager itself reads). Isolated
        // in its own static method (not a closure) so the included file's
        // `include`-scope variables land directly in THIS method's scope.
        include $versionFile;

        $parts = array_filter([$v_major ?? null, $v_minor ?? null, $v_patch ?? null]);
        $version = implode('.', $parts);

        return $version !== '' ? $version . (($v_tag ?? '') !== '' ? '-' . $v_tag : '') : 'unknown';
    }
}
