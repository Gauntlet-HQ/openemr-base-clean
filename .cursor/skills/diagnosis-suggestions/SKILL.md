---
name: diagnosis-suggestions
description: >-
  Generate advisory differential-diagnosis suggestions for an OpenEMR patient
  from their current visit, active medications, and pre-existing conditions.
  Use when asked to suggest diagnoses, build a differential, or extend the
  AI diagnosis feature (OpenEMR\Services\AI\DiagnosisSuggestionService).
---

# Diagnosis Suggestions

Advisory clinical decision support: gather a patient's clinical context and ask
the language model for a ranked differential diagnosis. Output is **never a
diagnosis** — it is advisory and requires clinician review.

## Where the code lives

- `src/Services/AI/DiagnosisSuggestionService.php` — orchestrates data gathering
  and the model call. Entry point: `suggestByPid(int $pid): DiagnosisSuggestionResult`.
- `src/Services/AI/DiagnosisSuggestionResult.php` — immutable result
  (`hasSufficientData`, `suggestions`).
- Tests: `tests/Tests/Isolated/Services/AI/DiagnosisSuggestion*Test.php`.

It follows the same pattern as `PatientHistorySummaryService` — read that file
first when changing behavior.

## Data sources (the three inputs)

| Input | Collaborator | Method |
|-------|--------------|--------|
| Current visit | `EncounterService` | `getMostRecentEncounterForPatient($pid)` |
| Medications | `PatientIssuesService` | `getActiveIssues($pid)`, rows where `type === 'medication'` |
| Pre-existing conditions | `PatientIssuesService` | `getActiveIssues($pid)`, all other active `lists` rows |

## Invariants — keep these when editing

- **No external call without data.** If there is no encounter, no medications,
  and no conditions, return `DiagnosisSuggestionResult::insufficientData()` and
  never call the model.
- **Minimum-necessary / PHI.** Send only clinical fields. Never send name, DOB,
  or other direct identifiers. The system prompt also forbids echoing identifiers.
- **Advisory framing.** The system prompt must ask for a ranked differential
  (≤5), a one-line rationale per candidate, suggested next steps, and a
  clinician-review reminder — and must forbid stating a definitive diagnosis.
- **Observability.** Every call goes through `AiObservability::begin('diagnosis_suggestion')`,
  wrapping each fetch and the chat call in `$transaction->measure(...)`, recording
  usage on success and `markFailure($e)` on error. Let exceptions propagate.
- **Strict typing.** `declare(strict_types=1)`, constructor injection with
  nullable defaults, `catch (\Throwable)`, PSR-3 context arrays for any logging.

## Using it from code

```php
use OpenEMR\Services\AI\DiagnosisSuggestionService;

$result = (new DiagnosisSuggestionService())->suggestByPid($pid);
if ($result->hasSufficientData) {
    echo $result->suggestions; // present to clinician as decision support only
}
```

## Verify changes

Run the isolated tests (no database needed):

```bash
openemr-cmd phpunit-isolated   # in container (alias: pit)
composer phpunit-isolated      # on host
```

Then filter full-codebase PHPStan output for the changed files (`openemr-cmd phpstan`).
