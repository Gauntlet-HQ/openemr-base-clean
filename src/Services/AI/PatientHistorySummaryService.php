<?php

/**
 * Summarizes a patient's most recent history using OpenAI.
 *
 * Gathers the minimum-necessary clinical context (most recent encounter,
 * latest social/family history, and active problem list) for a single
 * patient and asks the language model for a concise clinician-facing
 * summary. If the patient has no history on record, no external call is
 * made and a "new patient" result is returned instead.
 *
 * Only clinical fields are sent to the model; direct identifiers such as
 * name and date of birth are deliberately excluded to limit PHI exposure.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI;

use OpenEMR\Services\EncounterService;
use OpenEMR\Services\PatientIssuesService;
use OpenEMR\Services\SocialHistoryService;

final class PatientHistorySummaryService
{
    private const SYSTEM_PROMPT = 'You are a clinical assistant for a physician. '
        . 'Given structured fragments of a patient\'s most recent history, write a concise, '
        . 'factual summary (2-4 sentences) highlighting the most clinically relevant points. '
        . 'Do not invent information that is not present. Do not include any patient identifiers.';

    private readonly OpenAiChatClient $chatClient;
    private readonly EncounterService $encounterService;
    private readonly SocialHistoryService $socialHistoryService;
    private readonly PatientIssuesService $issuesService;

    public function __construct(
        ?OpenAiChatClient $chatClient = null,
        ?EncounterService $encounterService = null,
        ?SocialHistoryService $socialHistoryService = null,
        ?PatientIssuesService $issuesService = null,
    ) {
        $this->chatClient = $chatClient ?? new OpenAiChatClient();
        $this->encounterService = $encounterService ?? new EncounterService();
        $this->socialHistoryService = $socialHistoryService ?? new SocialHistoryService();
        $this->issuesService = $issuesService ?? new PatientIssuesService();
    }

    /**
     * Summarize the most recent history for the given patient pid.
     *
     * @throws OpenAiException when the AI service is unavailable or errors
     */
    public function summarizeByPid(int $pid): PatientHistorySummaryResult
    {
        if ($pid <= 0) {
            throw new \InvalidArgumentException('Patient pid must be a positive integer.');
        }

        $encounter = $this->encounterService->getMostRecentEncounterForPatient($pid);

        $socialHistory = $this->socialHistoryService->getHistoryData($pid);
        $socialHistory = is_array($socialHistory) ? $socialHistory : [];

        $issuesResult = $this->issuesService->getActiveIssues($pid);
        $issues = $issuesResult->hasData() ? $issuesResult->getData() : [];

        $hasHistory = $encounter !== null
            || !empty($socialHistory['id'])
            || $issues !== [];

        if (!$hasHistory) {
            return PatientHistorySummaryResult::newPatient();
        }

        $historyText = $this->buildHistoryText($encounter, $socialHistory, $issues);

        $summary = $this->chatClient->chat([
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $historyText],
        ]);

        return PatientHistorySummaryResult::fromSummary($summary);
    }

    /**
     * Assemble a minimum-necessary, identifier-free text block for the model.
     *
     * @param array<string, mixed>|null $encounter
     * @param array<string, mixed>      $socialHistory
     * @param list<array<string, mixed>> $issues
     */
    private function buildHistoryText(?array $encounter, array $socialHistory, array $issues): string
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
                $sections[] = "Most recent encounter:\n" . implode("\n", $lines);
            }
        }

        $socialFields = [
            'tobacco' => 'Tobacco',
            'alcohol' => 'Alcohol',
            'exercise_patterns' => 'Exercise',
            'recreational_drugs' => 'Recreational drugs',
        ];
        $socialLines = [];
        foreach ($socialFields as $key => $label) {
            $value = $this->stringValue($socialHistory, $key);
            if ($value !== '') {
                $socialLines[] = $label . ': ' . $value;
            }
        }
        if ($socialLines !== []) {
            $sections[] = "Social history:\n" . implode("\n", $socialLines);
        }

        $issueLines = [];
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $title = $this->stringValue($issue, 'title');
            $diagnosis = $this->stringValue($issue, 'diagnosis');
            $begin = $this->stringValue($issue, 'begdate');
            $parts = array_filter([
                $title,
                $diagnosis !== '' ? '(' . $diagnosis . ')' : '',
                $begin !== '' ? 'since ' . $begin : '',
            ], static fn(string $part): bool => $part !== '');
            if ($parts !== []) {
                $issueLines[] = '- ' . implode(' ', $parts);
            }
        }
        if ($issueLines !== []) {
            $sections[] = "Active problems:\n" . implode("\n", $issueLines);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param array<string, mixed> $row
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
