<?php

/**
 * Outcome of an instrumented AI transaction.
 *
 * A backed enum because the value is persisted to the OBSERVABILITY.md
 * ledger and parsed back by the reporting tool.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI\Observability;

enum TransactionStatus: string
{
    case Success = 'success';
    case Failure = 'failure';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'SUCCESS',
            self::Failure => 'FAILURE',
        };
    }
}
