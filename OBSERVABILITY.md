# AI Observability Ledger

This file is an append-only ledger of every AI transaction the Clinical
Co-Pilot performs. Each row records one transaction so the team can track
**failures**, **time spent in each step**, and the **number and cost of
tokens** consumed.

Rows are written automatically by
`OpenEMR\Services\AI\Observability\MarkdownObservabilityRecorder`. Do not
edit rows by hand. For an aggregated view (failure rate, average latency,
total spend) run:

```bash
php bin/ai-observability-report.php
```

**Privacy note:** this ledger deliberately contains no patient identifiers
or clinical content — only operational metrics — so it is safe to commit.

## Column reference

| Column | Meaning |
| --- | --- |
| Timestamp (UTC) | When the transaction completed. |
| ID | Random per-transaction correlation id. |
| Transaction | The instrumented operation (e.g. `patient_history_summary`). |
| Status | `SUCCESS` or `FAILURE`. |
| Model | The AI model used, or `n/a` when no external call was made. |
| Prompt / Completion / Total Tokens | Token counts reported by the provider. |
| Est. Cost (USD) | Estimated spend from token counts and list pricing. |
| Duration (ms) | Total measured time across all steps. |
| Step Timings (ms) | Per-step wall-clock breakdown. |
| Detail | Failure reason, or `-` on success. |

## Transactions

| Timestamp (UTC) | ID | Transaction | Status | Model | Prompt | Completion | Total Tokens | Est. Cost (USD) | Duration (ms) | Step Timings (ms) | Detail |
| --- | --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | --- | --- |
