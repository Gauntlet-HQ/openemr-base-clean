<?php

/**
 * Suggests differential diagnoses for a patient using OpenAI.
 *
 * Gathers the minimum-necessary clinical context that a clinician would weigh
 * when forming a differential — the current (most recent) visit, the active
 * medication list, and the pre-existing problem list — and asks the language
 * model for a short, ranked set of candidate diagnoses with brief reasoning
 * and recommended next steps. If the patient has too little on record to
 * reason about, no external call is made and an "insufficient data" result is
 * returned instead.
 *
 * The output is clinical decision support only: it is advisory, never a
 * diagnosis, and is intended to be reviewed by a qualified clinician.
 *
 * Only clinical fields are sent to the model; direct identifiers such as name
 * and date of birth are deliberately excluded to limit PHI exposure.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI;

use OpenEMR\Services\AI\Observability\AiObservability;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\PatientIssuesService;

final class DiagnosisSuggestionService
{
    private const SYSTEM_PROMPT = 'You are a clinical decision-support assistant for a licensed physician. '
        . 'Given structured fragments of a patient\'s current visit, active medications, and pre-existing '
        . 'conditions, propose a short ranked differential diagnosis (at most five candidates). For each '
        . 'candidate give a one-line rationale grounded only in the data provided and, where relevant, a '
        . 'suggested next step (test, exam, or referral). Do not invent findings that are not present. Do '
        . 'not state a definitive diagnosis. Do not include any patient identifiers. End with a brief '
        . 'reminder that these suggestions are advisory and require clinician review.';

    private const TRANSACTION_TYPE = 'diagnosis_suggestion';

    private const MEDICATION_TYPE = 'medication';

    private readonly OpenAiChatClientInterface $chatClient;
    private readonly RecentEncounterProviderInterface $encounterService;
    private readonly ActivePatientIssuesProviderInterface $issuesService;
    private readonly AiObservability $observability;

    public function __construct(
        ?OpenAiChatClientInterface $chatClient = null,
        ?RecentEncounterProviderInterface $encounterService = null,
        ?ActivePatientIssuesProviderInterface $issuesService = null,
        ?AiObservability $observability = null,
    ) {
        $this->chatClient = $chatClient ?? new OpenAiChatClient();
        $this->encounterService = $encounterService ?? new EncounterService();
        $this->issuesService = $issuesService ?? new PatientIssuesService();
        $this->observability = $observability ?? new AiObservability();
    }

    /**
     * Suggest differential diagnoses for the given patient pid.
     *
     * Every call is instrumented: step timings, token usage, cost, and any
     * failure are recorded to the observability ledger.
     *
     * @throws OpenAiException when the AI service is unavailable or errors
     */
    public function suggestByPid(int $pid): DiagnosisSuggestionResult
    {
        if ($pid <= 0) {
            throw new \InvalidArgumentException('Patient pid must be a positive integer.');
        }

        $transaction = $this->observability->begin(self::TRANSACTION_TYPE);

        try {
            $encounter = $transaction->measure(
                'fetch_current_visit',
                fn() => $this->encounterService->getMostRecentEncounterForPatient($pid),
            );

            $activeIssues = $transaction->measure(
                'fetch_active_issues',
                fn() => $this->fetchActiveIssues($pid),
            );

            [$medications, $conditions] = $this->partitionIssues($activeIssues);

            $hasSufficientData = $encounter !== null
                || $medications !== []
                || $conditions !== [];

            if (!$hasSufficientData) {
                // Nothing to reason about; never spend a call on the AI service.
                $transaction->markSuccess();

                return DiagnosisSuggestionResult::insufficientData();
            }

            $clinicalText = $this->buildClinicalText($encounter, $medications, $conditions);

            $chatResult = $transaction->measure('openai_chat', fn(): OpenAiChatResult => $this->chatClient->chatWithUsage([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $clinicalText],
            ]));

            $transaction->recordUsage($chatResult->model, $chatResult->usage);
            $transaction->markSuccess();

            return DiagnosisSuggestionResult::fromSuggestions($chatResult->content);
        } catch (\Throwable $e) {
            $transaction->markFailure($e);

            throw $e;
        } finally {
            $transaction->commit();
        }
    }

    /**
     * Fetch the patient's active `lists` rows as a well-typed list.
     *
     * @return list<array<array-key, mixed>>
     */
    private function fetchActiveIssues(int $pid): array
    {
        $result = $this->issuesService->getActiveIssues($pid);
        if (!$result->hasData()) {
            return [];
        }

        $data = $result->getData();
        if (!is_array($data)) {
            return [];
        }

        $rows = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Split the active problem list into medications and everything else
     * (pre-existing conditions/problems), keyed by the list `type` column.
     *
     * @param list<array<array-key, mixed>> $issues
     *
     * @return array{0: list<array<array-key, mixed>>, 1: list<array<array-key, mixed>>}
     */
    private function partitionIssues(array $issues): array
    {
        $medications = [];
        $conditions = [];

        foreach ($issues as $issue) {
            if ($this->stringValue($issue, 'type') === self::MEDICATION_TYPE) {
                $medications[] = $issue;
                continue;
            }
            $conditions[] = $issue;
        }

        return [$medications, $conditions];
    }

    /**
     * Assemble a minimum-necessary, identifier-free text block for the model.
     *
     * @param array<array-key, mixed>|null  $encounter
     * @param list<array<array-key, mixed>> $medications
     * @param list<array<array-key, mixed>> $conditions
     */
    private function buildClinicalText(?array $encounter, array $medications, array $conditions): string
    {
        $sections = [];

        if ($encounter !== null) {
            $lines = [];
            $date = $this->stringValue($encounter, 'date');
            if ($date !== '') {
                $lines[] = 'Date: ' . $date;
            }
            $reason = $this->stringValue($encounter, 'reason');
            if ($reason !== '') {
                $lines[] = 'Reason for visit: ' . $reason;
            }
            if ($lines !== []) {
                $sections[] = "Current visit:\n" . implode("\n", $lines);
            }
        }

        $medicationLines = $this->issueLines($medications);
        if ($medicationLines !== []) {
            $sections[] = "Active medications:\n" . implode("\n", $medicationLines);
        }

        $conditionLines = $this->issueLines($conditions);
        if ($conditionLines !== []) {
            $sections[] = "Pre-existing conditions:\n" . implode("\n", $conditionLines);
        }

        return implode("\n\n", $sections);
    }

    /**
     * Render a list of `lists` rows as "- title (diagnosis) since begdate" bullets.
     *
     * @param list<array<array-key, mixed>> $issues
     *
     * @return list<string>
     */
    private function issueLines(array $issues): array
    {
        $lines = [];
        foreach ($issues as $issue) {
            $title = $this->stringValue($issue, 'title');
            $diagnosis = $this->stringValue($issue, 'diagnosis');
            $begin = $this->stringValue($issue, 'begdate');
            $parts = array_filter([
                $title,
                $diagnosis !== '' ? '(' . $diagnosis . ')' : '',
                $begin !== '' ? 'since ' . $begin : '',
            ], static fn(string $part): bool => $part !== '');
            if ($parts !== []) {
                $lines[] = '- ' . implode(' ', $parts);
            }
        }

        return $lines;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
