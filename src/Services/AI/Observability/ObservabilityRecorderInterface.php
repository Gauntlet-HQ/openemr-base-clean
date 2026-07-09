<?php

/**
 * Sink for completed AI transaction records.
 *
 * Implementations persist the record somewhere durable (the Markdown ledger
 * in production, an in-memory list in tests). Recording is best-effort:
 * implementations must never let an observability failure break the AI
 * request it is measuring.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI\Observability;

interface ObservabilityRecorderInterface
{
    public function record(AiTransactionRecord $record): void;
}
