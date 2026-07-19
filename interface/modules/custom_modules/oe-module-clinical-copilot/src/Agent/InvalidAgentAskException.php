<?php

/**
 * A rejected public/agent.php request -- the message is deliberately user-safe.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

/**
 * Thrown only by {@see AgentAskRequest::fromPost()} (parse, don't validate:
 * one boundary, one failure type). Every message is written for the 400
 * response body -- no internals, no PHI, no exception chaining exposure --
 * so `public/agent.php` may echo {@see \Throwable::getMessage()} verbatim,
 * the one sanctioned exception to the "never expose getMessage()" rule.
 */
final class InvalidAgentAskException extends \DomainException
{
}
