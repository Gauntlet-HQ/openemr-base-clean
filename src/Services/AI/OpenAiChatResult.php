<?php

/**
 * Immutable result of an OpenAI chat completion call.
 *
 * Carries the assistant's text alongside the model used and the token usage
 * the provider reported, so callers (and the observability layer) can track
 * how many tokens each transaction consumed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI;

use OpenEMR\Services\AI\Observability\TokenUsage;

final readonly class OpenAiChatResult
{
    public function __construct(
        public string $content,
        public string $model,
        public TokenUsage $usage,
    ) {
    }
}
