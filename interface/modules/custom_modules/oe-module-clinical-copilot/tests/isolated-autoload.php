<?php

/**
 * PHPUnit --bootstrap for this module's isolated tests (tests/Isolated/).
 *
 * The module is not registered in the host project's Composer autoloader
 * (it is not a root composer.json dependency), so running tests/Isolated
 * through the host's phpunit-isolated.xml needs this shim to make
 * OpenEMR\Modules\ClinicalCopilot\* (src/ and tests/) loadable. It reuses
 * the same SPL loader as the eval runner and benchmark harness. Invocation
 * (what .github/workflows/w2-eval-gate.yml runs in CI):
 *
 *   vendor/bin/phpunit -c phpunit-isolated.xml \
 *     --bootstrap interface/modules/custom_modules/oe-module-clinical-copilot/tests/isolated-autoload.php \
 *     interface/modules/custom_modules/oe-module-clinical-copilot/tests/Isolated
 *
 * Third-party deps the tests touch (Guzzle, justinrainbow/json-schema,
 * google/auth) come from the host project's vendor/, which PHPUnit's own
 * binary autoloads before this bootstrap runs.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require __DIR__ . '/../ops/load/bench/_autoload.php';
