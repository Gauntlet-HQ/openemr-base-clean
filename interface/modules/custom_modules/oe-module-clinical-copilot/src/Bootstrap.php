<?php

/**
 * Clinical Co-Pilot Module Bootstrap Class
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Menu\MenuEvent;
use OpenEMR\Menu\PatientMenuEvent;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_INSTALLATION_PATH = '/interface/modules/custom_modules/oe-module-clinical-copilot';
    public const MODULE_NAME = 'oe-module-clinical-copilot';

    /**
     * Site administrators may grant/deny this section independently of the
     * 'patients'/'med' section this module's pages are gated on at runtime
     * (see build-notes.md). Best-effort registration of the section itself
     * happens in ModuleManagerListener::install() via AclExtended, since no
     * modern Symfony ACL-extension event exists for custom modules to hook
     * (verified against src/Events/ — only the legacy gacl API supports this).
     */
    public const ACL_SECTION_NAME = 'clinical_copilot';

    private readonly SystemLogger $logger;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
        $this->logger = new SystemLogger();
    }

    public function subscribeToEvents(): void
    {
        $this->registerMenuItems();
        $this->registerPatientMenuItems();
    }

    public function registerMenuItems(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addCustomMenuItem(...));
    }

    public function registerPatientMenuItems(): void
    {
        $this->eventDispatcher->addListener(PatientMenuEvent::MENU_UPDATE, $this->addPatientMenuItem(...));
    }

    /**
     * Adds a "Clinical Co-Pilot" item under the Reports menu, linking to the
     * pre-visit synthesis document page (built in U8). Defensive: any
     * failure to locate the Reports menu or build the item is logged and
     * swallowed rather than breaking the host menu (I6/I7 style degradation
     * — this module must never take down core navigation).
     *
     * @param MenuEvent $event
     * @return MenuEvent
     */
    public function addCustomMenuItem(MenuEvent $event): MenuEvent
    {
        try {
            if (
                !AclMain::aclCheckCore('patients', 'med')
                || !AclMain::aclCheckCore(self::ACL_SECTION_NAME, 'copilot_access')
            ) {
                return $event;
            }

            $menu = $event->getMenu();

            foreach ($menu as $menuItem) {
                $menuId = $menuItem->menu_id ?? '';
                $label = $menuItem->label ?? '';
                if ($menuId === 'repimg' || $label === 'Reports') {
                    $copilotMenuItem = new stdClass();
                    $copilotMenuItem->requirement = 0;
                    $copilotMenuItem->target = 'copilot0';
                    $copilotMenuItem->menu_id = 'copilot0';
                    $copilotMenuItem->label = xlt('Clinical Co-Pilot');
                    $copilotMenuItem->url = self::MODULE_INSTALLATION_PATH . '/public/doc.php';
                    $copilotMenuItem->children = [];
                    $copilotMenuItem->acl_req = ['patients', 'med'];
                    $copilotMenuItem->global_req = [];

                    $menuItem->children[] = $copilotMenuItem;
                    break;
                }
            }

            $event->setMenu($menu);
        } catch (\Throwable $e) {
            $this->logger->error('ClinicalCopilot: failed to register menu item', ['error' => $e->getMessage()]);
        }

        return $event;
    }

    /**
     * Adds an "Appointment Copilot" tab on the patient chart nav bar,
     * immediately after External Data (standard patient menu order). Skipped
     * when the caller lacks chart or copilot ACL — same gates as doc.php.
     */
    public function addPatientMenuItem(PatientMenuEvent $event): PatientMenuEvent
    {
        try {
            if (
                !AclMain::aclCheckCore('patients', 'med')
                || !AclMain::aclCheckCore(self::ACL_SECTION_NAME, 'copilot_access')
            ) {
                return $event;
            }

            $menu = $event->getMenu();
            $updated = [];
            $inserted = false;

            foreach ($menu as $menuItem) {
                $updated[] = $menuItem;
                if (($menuItem->menu_id ?? '') === 'external_data') {
                    $updated[] = $this->buildPatientCopilotMenuItem();
                    $inserted = true;
                }
            }

            if (!$inserted) {
                $updated[] = $this->buildPatientCopilotMenuItem();
            }

            $event->setMenu($updated);
        } catch (\Throwable $e) {
            $this->logger->error('ClinicalCopilot: failed to register patient menu item', ['error' => $e->getMessage()]);
        }

        return $event;
    }

    private function buildPatientCopilotMenuItem(): stdClass
    {
        $menuItem = new stdClass();
        $menuItem->requirement = 0;
        $menuItem->target = 'main';
        $menuItem->menu_id = 'clinical_copilot';
        $menuItem->label = xlt('Appointment Copilot');
        $menuItem->url = self::MODULE_INSTALLATION_PATH . '/public/doc.php?pid=';
        $menuItem->pid = 'true';
        $menuItem->on_click = 'top.restoreSession()';
        $menuItem->children = [];

        return $menuItem;
    }
}
