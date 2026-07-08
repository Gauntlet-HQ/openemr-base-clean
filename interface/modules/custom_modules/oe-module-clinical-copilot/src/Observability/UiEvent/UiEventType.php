<?php

/**
 * mod_copilot_ui_event.event_type -- the two over-reliance indicators (ARCHITECTURE.md §2.5).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\UiEvent;

enum UiEventType: string
{
    case CitationClick = 'citation_click';
    case FactsPanelOpen = 'facts_panel_open';
}
