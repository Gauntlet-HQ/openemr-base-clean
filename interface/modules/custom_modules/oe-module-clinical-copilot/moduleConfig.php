<?php

/**
 * Clinical Co-Pilot Module Information
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

return [
    'name' => 'Clinical Co-Pilot',
    'description' => 'A pre-visit clinical synthesis and multi-turn chat co-pilot for outpatient endocrinology: deterministic, cited facts synthesized into a prioritized document per scheduled patient, with a patient-pinned chat agent for follow-up questions.',
    'version' => '0.1.0',
    'author' => 'Clinical Co-Pilot Team',
    'email' => 'adamnash19@gmail.com',
    'license' => 'GPL-3.0-or-later',
    'acl_category' => 'patients',
    'acl_section' => 'med',

    // Module dependencies
    'require' => [
        'openemr' => '>=7.0.0',
    ],

    // Database tables created by this module
    'tables' => [
        'mod_copilot_doc',
        'mod_copilot_cadence',
        'mod_copilot_chat_session',
        'mod_copilot_chat_turn',
        'mod_copilot_trace',
        'mod_copilot_qa',
        'mod_copilot_trace_payload',
        'mod_copilot_ui_event',
    ],

    // Menu items added by this module
    'menu' => [
        [
            'label' => 'Clinical Co-Pilot',
            'menu_id' => 'repimg',
            'acl' => ['patients', 'med'],
            'url' => '/interface/modules/custom_modules/oe-module-clinical-copilot/public/doc.php',
        ],
    ],

    // Installation hooks
    'install' => [
        'sql' => 'sql/install.sql',
    ],

    // Uninstallation hooks
    'uninstall' => [
        'sql' => 'sql/uninstall.sql',
    ],
];
