<?php

/**
 * In-memory recorder that keeps transaction records in a list.
 *
 * Intended for tests and for callers that want to inspect transactions
 * without touching the filesystem. Also usable as a null sink when the
 * recorded list is simply ignored.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI\Observability;

final class InMemoryObservabilityRecorder implements ObservabilityRecorderInterface
{
    /** @var list<AiTransactionRecord> */
    private array $records = [];

    public function record(AiTransactionRecord $record): void
    {
        $this->records[] = $record;
    }

    /**
     * @return list<AiTransactionRecord>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function latest(): ?AiTransactionRecord
    {
        if ($this->records === []) {
            return null;
        }

        return $this->records[array_key_last($this->records)];
    }
}
