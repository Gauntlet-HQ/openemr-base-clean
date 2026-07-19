--
-- Clinical Co-Pilot Module
-- Uninstall SQL Script
--
-- @package   OpenEMR\Modules\ClinicalCopilot
-- @link      https://www.open-emr.org
-- @author    Clinical Co-Pilot Team
-- @copyright Copyright (c) 2026 OpenEMR Foundation
-- @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--
-- WARNING: This permanently deletes all Clinical Co-Pilot data, including the
-- append-only doc/chat-turn/trace ledgers (provenance record of what the
-- physician saw, T7). Export-before-drop tooling for those ledgers is OPEN-2
-- (ARCHITECTURE_COMPLETE.md OPEN section) and is not yet built; run this only
-- after an explicit operator confirmation and, until that tooling exists,
-- only after manually exporting mod_copilot_doc / mod_copilot_chat_turn /
-- mod_copilot_trace if retention is required.
--
-- Drops only this module's owned tables and its background_services row
-- (additivity invariant I9 / ARCHITECTURE_COMPLETE.md "Placement" test 3) —
-- nothing else in the host schema is touched.
--

DELETE FROM `background_services` WHERE `name` = 'clinical_copilot_worker';

DROP TABLE IF EXISTS `mod_copilot_extracted_fact`;
DROP TABLE IF EXISTS `mod_copilot_extraction`;
DROP TABLE IF EXISTS `mod_copilot_ui_event`;
DROP TABLE IF EXISTS `mod_copilot_trace_payload`;
DROP TABLE IF EXISTS `mod_copilot_qa`;
DROP TABLE IF EXISTS `mod_copilot_trace`;
DROP TABLE IF EXISTS `mod_copilot_chat_turn`;
DROP TABLE IF EXISTS `mod_copilot_chat_session`;
DROP TABLE IF EXISTS `mod_copilot_cadence`;
DROP TABLE IF EXISTS `mod_copilot_doc`;
