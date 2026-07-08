<?php

/**
 * Exception thrown when a call to the OpenAI API fails or returns an
 * unusable response. Carries only a generic, user-safe message; any
 * provider detail is logged separately via the original throwable.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI;

final class OpenAiException extends \RuntimeException
{
}
