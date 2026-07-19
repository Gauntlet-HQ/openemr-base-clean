<?php

/**
 * Class to be called from Laminas Module Manager for reporting management actions.
 * Example is if the module is enabled, disabled or unregistered etc.
 *
 * The class is in the Laminas "Installer\Controller" namespace.
 * Currently, register isn't supported of which support should be a part of install.
 * If an error needs to be reported to user, return description of error.
 * However, whatever action trapped here has already occurred in Manager.
 * Catch any exceptions because chances are they will be overlooked in Laminas module.
 * Report them in the return value.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

/* Allows maintenance of background tasks depending on Module Manager action. */

class ModuleManagerListener extends AbstractModuleActionListener
{
    private const WORKER_SERVICE_NAME = 'clinical_copilot_worker';

    /**
     * mod_copilot_* tables owned by this module. Uninstall (reset_module)
     * drops exactly these plus the background_services row — nothing else
     * (additivity invariant I9 / ARCHITECTURE_COMPLETE.md "Placement" test 3).
     * Export-before-drop confirmation for these ledgers is OPEN-2
     * (ARCHITECTURE_COMPLETE.md OPEN section) — not implemented here.
     */
    private const OWNED_TABLES = [
        'mod_copilot_doc',
        'mod_copilot_cadence',
        'mod_copilot_chat_session',
        'mod_copilot_chat_turn',
        'mod_copilot_trace',
        'mod_copilot_qa',
        'mod_copilot_trace_payload',
        'mod_copilot_ui_event',
        'mod_copilot_extraction',
        'mod_copilot_extracted_fact',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param        $methodName
     * @param        $modId
     * @param string $currentActionStatus
     * @return string On method success a $currentAction status should be returned or error string.
     */
    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if (method_exists(self::class, $methodName)) {
            return self::$methodName($modId, $currentActionStatus);
        } else {
            // no reason to report action method is missing.
            return $currentActionStatus;
        }
    }

    /**
     * Required method to return namespace
     * If namespace isn't provided return empty
     * and register namespace at top of this script..
     *
     * @return string
     */
    public static function getModuleNamespace(): string
    {
        // Module Manager will register this namespace.
        return 'OpenEMR\\Modules\\ClinicalCopilot\\';
    }

    /**
     * Required method to return this class object
     * so it will be instantiated in Laminas Manager.
     *
     * @return ModuleManagerListener
     */
    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    /**
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function install($modId, $currentActionStatus): mixed
    {
        /* setting the active ui flag here will allow the config button to show
         * before enable. This is a good thing because it allows the user to
         * configure the module before enabling it. However, if the module is disabled
         * this flag is reset by MM.
        */
        self::setModuleState($modId, '0', '1');

        // Best-effort: register this module's own ACL section so a site can
        // grant/deny it independently of the 'patients'/'med' section the
        // module's pages are gated on at runtime. There is no modern Symfony
        // ACL-extension event for custom modules (checked src/Events/); the
        // only supported registration surface is the legacy gacl API
        // (AclExtended), which echoes status text rather than returning it,
        // so output is buffered and logged instead of leaking to the Module
        // Manager UI. Failure here must never block install (I7-style
        // degradation: the module still runs fine gated on 'patients'/'med'
        // alone if this best-effort registration fails).
        try {
            ob_start();
            AclExtended::addObjectSectionAcl('clinical_copilot', 'Clinical Co-Pilot');
            AclExtended::addObjectAcl('clinical_copilot', 'Clinical Co-Pilot', 'copilot_access', 'Access Clinical Co-Pilot');
            $aclLog = ob_get_clean();
            (new SystemLogger())->info('ClinicalCopilot: ACL section registration', ['detail' => (string)$aclLog]);
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            (new SystemLogger())->error('ClinicalCopilot: ACL section registration failed', ['error' => $e->getMessage()]);
        }

        return $currentActionStatus;
    }

    /**
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function help_requested($modId, $currentActionStatus): mixed
    {
        // must call a script that implements a dialog to show help.
        // I can't find a way to override the Lamina's UI except using a dialog.
        if (file_exists(__DIR__ . '/show_help.php')) {
            include __DIR__ . '/show_help.php';
        }
        return $currentActionStatus;
    }

    /**
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function preenable($modId, $currentActionStatus): mixed
    {
        return $currentActionStatus;
    }

    /**
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function enable($modId, $currentActionStatus): mixed
    {
        self::setModuleState($modId, '1', '0');

        try {
            self::activateWorkerService();
        } catch (\Throwable $e) {
            (new SystemLogger())->error('ClinicalCopilot: failed to activate background worker service', ['error' => $e->getMessage()]);
        }

        return $currentActionStatus;
    }

    /**
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function disable($modId, $currentActionStatus): mixed
    {
        // allow config button to show before enable.
        self::setModuleState($modId, '0', '1');

        try {
            $sql = "UPDATE `background_services` SET `active` = 0 WHERE `name` = ?";
            QueryUtils::sqlStatementThrowException($sql, [self::WORKER_SERVICE_NAME]);
        } catch (\Throwable $e) {
            (new SystemLogger())->error('ClinicalCopilot: failed to deactivate background worker service', ['error' => $e->getMessage()]);
        }

        return $currentActionStatus;
    }

    /**
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function unregister($modId, $currentActionStatus): mixed
    {
        return $currentActionStatus;
    }

    /**
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function install_sql($modId, $currentActionStatus): mixed
    {
        return $currentActionStatus;
    }

    /**
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function upgrade_sql($modId, $currentActionStatus): mixed
    {
        return $currentActionStatus;
    }

    /**
     * Grab all Module setup or columns values.
     *
     * @param        $modId
     * @param string $col
     * @return array
     */
    public function getModuleRegistry($modId, $col = '*'): array
    {
        $registry = [];
        $sql = "SELECT $col FROM modules WHERE mod_id = ?";
        $results = QueryUtils::querySingleRow($sql, [$modId]);
        foreach ($results as $k => $v) {
            $registry[$k] = trim(((string)preg_replace('/\R/', '', (string)$v)));
        }

        return $registry;
    }

    /**
     * Insert-or-activate the clinical_copilot_worker background_services row.
     * table.sql inserts it inactive at install time (#IfNotRow guard, so this
     * is a no-op on repeat installs); enable() is what turns it on so the
     * warmer only ever runs while the module is actively enabled (I7: worker
     * failure or absence degrades latency only, never correctness).
     *
     * @return void
     */
    private static function activateWorkerService(): void
    {
        $sql = "INSERT INTO `background_services`
                    (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`)
                VALUES
                    (?, ?, 1, 0, NOW(), 5, ?, ?, 100)
                ON DUPLICATE KEY UPDATE `active` = 1, `next_run` = NOW()";
        QueryUtils::sqlStatementThrowException($sql, [
            self::WORKER_SERVICE_NAME,
            'Clinical Co-Pilot Warm Worker',
            'clinicalCopilotWorkerRun',
            '/interface/modules/custom_modules/oe-module-clinical-copilot/src/worker_entry.php',
        ]);
    }

    /**
     * @param      $flag
     * @param      $serviceArray
     * @param bool $reset
     * @param bool $removeTask
     * @return void
     */
    private static function setTaskState($flag, $serviceArray, bool $reset = false, bool $removeTask = false): void
    {
    }

    /**
     * @param $modId   int|string module id or directory name
     * @param $flag    int|string 1 or 0 to activate or deactivate module.
     * @param $flag_ui int|string custom flag to activate or deactivate Manager UI button states.
     * @return array|bool|null
     */
    private static function setModuleState(int|string $modId, int|string $flag, int|string $flag_ui): array|bool|null
    {
        // set module state.
        $sql = "UPDATE `modules` SET `mod_active` = ?, `mod_ui_active` = ? WHERE `mod_id` = ? OR `mod_directory` = ?";
        return QueryUtils::querySingleRow($sql, [$flag, $flag_ui, $modId, $modId]);
    }

    /**
     * Uninstall path: drops only this module's owned tables and its
     * background_services row (ARCHITECTURE_COMPLETE.md "Placement" test 3).
     * Export-before-drop tooling for the append-only ledgers (mod_copilot_doc,
     * mod_copilot_chat_turn, mod_copilot_trace) is OPEN-2 — not built in this
     * unit; an operator running this today loses that provenance record, by
     * design tension acknowledged in the spec pending that tooling.
     *
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function reset_module($modId, $currentActionStatus): mixed
    {
        $rtn = true;
        $logMessage = ''; // Initialize an empty string to store log messages

        if (!self::getModuleActiveFlag($modId)) {
            $sql = "DELETE FROM `background_services` WHERE `name` = ?";
            $rtn = QueryUtils::querySingleRow($sql, [self::WORKER_SERVICE_NAME]);
            $logMessage .= "DELETE FROM `background_services`: " . (empty($rtn) ? "Success" : "Failed") . "\n";

            foreach (self::OWNED_TABLES as $table) {
                $sql = "DROP TABLE IF EXISTS `$table`";
                $rtn = QueryUtils::querySingleRow($sql);
                $logMessage .= "DROP TABLE `$table`: " . (empty($rtn) ? "Success" : "Failed") . "\n";
            }

            error_log(text($logMessage));
        }

        // return log messages to the MM to show user.
        return text($logMessage);
    }

    /**
     * @param $modId
     * @return bool true if the module is currently marked active in `modules`.
     */
    private static function getModuleActiveFlag($modId): bool
    {
        $sql = "SELECT `mod_active` FROM `modules` WHERE `mod_id` = ? OR `mod_directory` = ?";
        $row = QueryUtils::querySingleRow($sql, [$modId, $modId]);
        return !empty($row['mod_active']);
    }
}
