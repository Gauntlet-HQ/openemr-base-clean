# Week 2 eval gate

The spec's **HARD GATE**: a 54-case (50 original + 4 medication_list) golden
set with **boolean** rubrics that
blocks regressions before they reach the demo. During grading a small regression
is introduced and the gate must fail — it does (verified: breaking retrieval
ordering or schema validation drops the relevant rubric below baseline and the
runner exits non-zero).

## What it checks

`cases.json` holds 54 cases across five categories, each run through the module's
**real, deterministic** code (no live model, no DB — every case supplies the
model output verbatim, so it runs in CI without API access):

| Category | Count | Exercises |
|---|---|---|
| `extraction` | 16 | valid intake/lab/medication-list payloads accepted, parsed, cited |
| `extraction` (invalid) | 10 | malformed payloads correctly rejected |
| `missing_data` | 8 | blank/illegible fields kept null, never invented |
| `refusal` | 8 | non-JSON / schema-violating model output refused |
| `retrieval` | 12 | the right guideline chunk retrieved, cited |

Boolean rubric categories (spec §6): `schema_valid`, `citation_present`,
`factually_consistent`, `safe_refusal`, `no_phi_in_logs`.

## Run it

```bash
# Gate (CI): compare to baseline, exit 0/1
php ops/eval/run-evals.php
# or the CI wrapper
ops/ci/run-eval-gate.sh

# Re-baseline after an intentional behaviour change (review the diff first!)
php ops/eval/run-evals.php --update-baseline

# Gate, plus write the per-case results report (ids/categories/rubric booleans
# only — never case content, so it is PHI-free by construction)
php ops/eval/run-evals.php --report ops/eval/results-latest.json
```

`baseline.json` records the per-rubric pass-rate the gate compares against. The
gate fails if any rubric drops more than 5% below its baseline **or** below the
0.90 absolute floor.

`results-latest.json` is the committed per-case report from the latest run
(`--report`): overall pass/fail, per-rubric rates/tallies, and each case's
id, category, and rubric outcomes. Regenerate it (same command as above)
whenever cases or behaviour change, and review the diff alongside
`baseline.json`'s.

## Adding a case

Append to `cases.json`. Each case needs an `id`, a `category`, the inputs that
category requires (`input`/`doc_type` for extraction & missing-data, `raw` for
refusal, `query`/`tags` for retrieval), and an `expect` block. Re-baseline after
adding cases. The golden set lives entirely in the repo — reproducible from
source control, no external database (spec backup/recovery requirement).
