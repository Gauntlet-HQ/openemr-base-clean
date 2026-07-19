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

                    // Week 2: intake upload — create a patient from a scanned
                    // intake form. Sits beside the synthesis page under Reports.
                    $menuItem->children[] = $this->buildIntakeMenuItem('copilot_intake', xlt('Co-Pilot Intake Upload'));
                }

                // The intake flow CREATES a patient from a scanned form, so it
                // also belongs in the New-patient area (the "Patient" top menu,
                // beside New/Search) where a user goes to add a patient. Added
                // here additively via the same MenuEvent, never by editing the
                // core menu JSON.
                if ($menuId === 'patimg' || $label === 'Patient') {
                    $menuItem->children[] = $this->buildIntakeMenuItem(
                        'copilot_intake_new',
                        xlt('New Patient from Intake PDF'),
                    );
                }
            }

            // Week 2 Maintenance: knowledge-base curation (chunk a guideline into
            // the RAG store) and observability are administrative tasks, so they
            // get their own top-level menu, gated on the host admin section rather
            // than the clinician ACL used above. Appended additively — the core
            // menu JSON is never edited.
            if (AclMain::aclCheckCore('admin', 'super') || AclMain::aclCheckCore('admin', 'users')) {
                $menu[] = $this->buildMaintenanceMenu();
            }

            $event->setMenu($menu);
        } catch (\Throwable $e) {
            $this->logger->error('ClinicalCopilot: failed to register menu item', ['error' => $e->getMessage()]);
        }

        return $event;
    }

    /**
     * One intake-upload menu item (create a patient from a scanned intake
     * form → {@see \OpenEMR\Modules\ClinicalCopilot\public\intake_upload.php}).
     * Built here so the same item can be surfaced from more than one menu
     * (Reports and the New-patient area) without duplicating the field wiring.
     */
    private function buildIntakeMenuItem(string $menuId, string $label): stdClass
    {
        $item = new stdClass();
        $item->requirement = 0;
        $item->target = $menuId;
        $item->menu_id = $menuId;
        $item->label = $label;
        $item->url = self::MODULE_INSTALLATION_PATH . '/public/intake_upload.php';
        $item->children = [];
        $item->acl_req = ['patients', 'med'];
        $item->global_req = [];

        return $item;
    }

    /**
     * The top-level "Co-Pilot Maintenance" menu: knowledge-base ingestion (chunk
     * a guideline/reference into the RAG store) and the observability dashboard.
     * Admin-gated, since these are curation/operations tasks rather than clinical
     * ones. Built as its own top-level entry so it reads clearly in the nav.
     */
    private function buildMaintenanceMenu(): stdClass
    {
        // A top-level dropdown CONTAINER: it must match the shape of core's
        // top-level menus (File/View/…) — label + menu_id + children + requirement
        // and NO `url`/`target`. Setting `url` (even '') or `target` on a parent
        // makes the nav treat it as a leaf link (navigate to the empty URL) instead
        // of opening the submenu, so clicking it does nothing and the children
        // never load. The children carry the url/target.
        $menu = new stdClass();
        $menu->requirement = 0;
        $menu->menu_id = 'cpmaint';
        $menu->label = xlt('Co-Pilot Maintenance');
        $menu->children = [
            $this->buildMaintenanceItem('cpmaint_kb', xlt('Knowledge Base (RAG)'), '/public/knowledge_upload.php'),
            $this->buildMaintenanceItem('cpmaint_obs', xlt('Observability Dashboard'), '/public/dashboard.php'),
        ];
        $menu->acl_req = ['admin', 'users'];
        $menu->global_req = [];

        return $menu;
    }

    private function buildMaintenanceItem(string $menuId, string $label, string $relativeUrl): stdClass
    {
        $item = new stdClass();
        $item->requirement = 0;
        $item->target = $menuId;
        $item->menu_id = $menuId;
        $item->label = $label;
        $item->url = self::MODULE_INSTALLATION_PATH . $relativeUrl;
        $item->children = [];
        $item->acl_req = ['admin', 'users'];
        $item->global_req = [];

        return $item;
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
                    $updated[] = $this->buildPatientLabsMenuItem();
                    $updated[] = $this->buildPatientEvidenceMenuItem();
                    $inserted = true;
                }
            }

            if (!$inserted) {
                $updated[] = $this->buildPatientCopilotMenuItem();
                $updated[] = $this->buildPatientLabsMenuItem();
                $updated[] = $this->buildPatientEvidenceMenuItem();
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

    /**
     * Week 2: the "Labs" tab, immediately after the Appointment Copilot tab.
     * Opens the Labs upload / manual-entry page for the current patient — the
     * entry point for the lab-PDF -> extract -> verify -> lock round-trip.
     */
    private function buildPatientLabsMenuItem(): stdClass
    {
        $menuItem = new stdClass();
        $menuItem->requirement = 0;
        $menuItem->target = 'main';
        $menuItem->menu_id = 'clinical_copilot_labs';
        $menuItem->label = xlt('Labs (Co-Pilot)');
        $menuItem->url = self::MODULE_INSTALLATION_PATH . '/public/lab_upload.php?pid=';
        $menuItem->pid = 'true';
        $menuItem->on_click = 'top.restoreSession()';
        $menuItem->children = [];

        return $menuItem;
    }

    /**
     * Week 2: the "Guideline Evidence" tab — cited clinical-guideline evidence
     * (RAG over the committed corpus) that augments the co-pilot's answers,
     * shown separately from the patient's own chart facts.
     */
    private function buildPatientEvidenceMenuItem(): stdClass
    {
        $menuItem = new stdClass();
        $menuItem->requirement = 0;
        $menuItem->target = 'main';
        $menuItem->menu_id = 'clinical_copilot_evidence';
        $menuItem->label = xlt('Guideline Evidence');
        $menuItem->url = self::MODULE_INSTALLATION_PATH . '/public/evidence.php?pid=';
        $menuItem->pid = 'true';
        $menuItem->on_click = 'top.restoreSession()';
        $menuItem->children = [];

        return $menuItem;
    }
}
