<?php

/**
 * Shared autoloader for the in-process benchmark harness.
 *
 * Mirrors ops/eval/run-evals.php's loader, and additionally maps the
 * Tests\ namespace so the committed isolated-test factories (which build
 * valid Fact/claim fixtures with computed content-address ids) are reusable
 * as realistic benchmark inputs — no DB, no Composer, no OpenEMR core.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// ops/load/bench/ -> ops/load -> ops -> module root
$moduleRoot = dirname(__DIR__, 3);

spl_autoload_register(static function (string $class) use ($moduleRoot): void {
    $prefix = 'OpenEMR\\Modules\\ClinicalCopilot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rest = substr($class, strlen($prefix));
    // Tests\... lives under tests/; everything else under src/.
    if (str_starts_with($rest, 'Tests\\')) {
        $file = $moduleRoot . '/tests/' . str_replace('\\', '/', substr($rest, strlen('Tests\\'))) . '.php';
    } else {
        $file = $moduleRoot . '/src/' . str_replace('\\', '/', $rest) . '.php';
    }
    if (is_file($file)) {
        require $file;
    }
});

return $moduleRoot;
