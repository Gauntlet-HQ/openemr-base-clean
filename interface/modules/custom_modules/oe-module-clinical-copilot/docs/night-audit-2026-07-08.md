# Clinical Co-Pilot — Pre-Thursday Deployment Audit (2026-07-08, overnight run)

Autonomous overnight audit of the `oe-module-clinical-copilot` module ahead of
Thursday's deployment. Four sequential code-review passes covered the entire
module (Verify/Reduce → Fact/Lab/Capability → Chat/Worker/Observability/
DocStore/ReadPath/Config → install lifecycle/ACL/CSRF/endpoints), a 50-patient
synthetic endocrinology seed dataset was built, and every high-confidence,
low-risk bug found was fixed directly. Per instructions, `ARCHITECTURE.md`,
`ARCHITECTURE_COMPLETE.md`, `AUDIT.md`, and `USER(S).md` were **not** treated
as ground truth — every finding below was verified against actual code,
tests, and (where relevant) core OpenEMR behavior, not against those docs.

**Branch:** `claude/practical-edison-5je7tg` (based on `main`, 8 commits ahead
from this run). Nothing was pushed to `main`.

---

## 1. The reported bug — "none of the data or responses can be validated"

**Root cause found and fixed.** `src/Reduce/GeminiGenerateContentContract.php`
(`extractText()`) read only `candidates[0].content.parts[0].text` and threw
`LlmUnavailableException` whenever that specific part was empty — even though
a later part carried the real answer. This is a legitimate response shape on
Gemini 2.5 (a leading reasoning/metadata part before the visible text — the
exact class of behavior the most recent pre-audit commit, `2591b50`, was
already trying to defend against). Because `VerifiedGeneration::attempt()`
checks reduce-availability *before* ever calling the deterministic verifier,
this silently classified a normal response as "LLM unavailable" and **skipped
the V1–V6 verification gate entirely** rather than failing a check — matching
the reported symptom exactly. The sibling chat-path trait
(`src/Chat/Llm/GeminiChatContentContract.php`) already had the correct
scan-all-parts logic; the reduce path had drifted from it.

**Fixed** in commit `e5ab5f5` (mirrors the chat path's logic), with new
regression tests (`GeminiGenerateContentContractTest.php`) — this exact
trait had **zero** prior test coverage despite gating whether verification
runs at all.

**Separately, and worth keeping distinct:** no LLM credentials
(`CLINICAL_COPILOT_GCP_PROJECT_ID` / `CLINICAL_COPILOT_GEMINI_API_KEY`) are
configured anywhere in the audit sandbox, so every generation attempt
currently hits the `Unavailable*` client and verification never runs for
*anyone* right now — this is documented, intentional fail-closed behavior,
not a bug, but it's very plausibly part of what's currently being observed.
**If you want to see live-verified synthesis/chat before Thursday, someone
needs to configure a credential** (the Gemini API-key dev/test fast-path is
fine for this — synthetic patients only, see `docs/configuration.md`).

---

## 2. What got fixed tonight (8 commits, all pushed to the branch)

| Commit | What |
|---|---|
| `e5ab5f5` | **The root-cause fix above** — scan all Gemini response parts, not just `parts[0]`. Regression test added. |
| `ce325a4` | Added `SeedEndoCohort.php` — 50-patient synthetic endocrinology cohort, 0–20yr randomized history. Extracted shared DB-insert helpers into `SeedCoreTableHelpers` trait (used by both seed scripts now). |
| `9a27dbc` | Fixed `DateInterval` month/day-boundary overflow in `OverdueTests`' due-date math (e.g. `2024-01-31 + P3M` was landing on `2024-05-01` instead of `2024-04-30` — delays overdue flagging by 1–3 days for ~10% of draw dates). Fixed `number_format()` thousands-separator bug corrupting displayed deltas ≥1000 (realistic for glucose in a DKA/HHS crisis). Regression tests added for both. |
| `b2d88aa` | Scoped the `CLINICAL_COPILOT_GEMINI_API_MODEL` override to only apply on the Gemini-API-key path (was silently affecting cache-digest computation even with no LLM configured at all). Documented an undocumented local-env-file config fallback. Corrected an overstated docblock claim on `DocStore.php`'s append-only audit completeness. |
| `d7f98c5` + `279ac61` | Removed `moduleConfig.php` (structurally broken — verified it produces zero output and is never actually read by OpenEMR core; clicking "Configure" in Module Manager showed a blank iframe). Closed a Reports-menu ACL gap (menu item was missing the module's own `copilot_access` ACL check, unlike the sibling patient-chart menu registration in the same file). Bounded `dashboard.php`'s `window_hours` GET parameter (previously unbounded, admin-only resource-exhaustion vector). |

All fixes are small, mechanically verified (`php -l` clean, and where
possible traced by hand or with a standalone PHP script proving the before/
after behavior — see commit messages for specifics), and have regression
tests where the fix touches testable logic.

---

## 3. Bugs found that need YOUR input before/around Thursday

These are documented as backlog items **BL-5 through BL-12** in
[`docs/known-issues.md`](known-issues.md) (same format as the existing BL-1
through BL-4). Summary, ranked by what matters most for Thursday:

### 🔴 BL-8 — QA-driven rerun bypasses the per-tick LLM budget entirely, including an explicit `$0`
Not currently exploitable — `CLINICAL_COPILOT_WORKER_LLM_ENABLED` defaults to
`false`. **But if anyone turns on background LLM before/around Thursday**,
know that the budget knob (`per_tick_worker_llm_budget_usd`) only throttles
the `warm()` path, not the QA-driven-rerun path — a QA sweep can still trigger
up to ~20 unconditional paid LLM calls per tick regardless of budget. This
directly contradicts a recent commit that specifically claimed to fix "honour
zero worker budget." **Decide:** should QA-rerun share `warm()`'s generation
counter, or get its own cap? Not a quick fix — it's a design call.

### 🟡 BL-9 — QA sweep can silently loop forever on one bad target
A malformed doc/unresolvable chat session in the QA queue never gets marked
terminal, so it's re-selected on every tick forever, and the sweep's own
error count doesn't go up when this happens — the QA dashboard can read
"clean" while a target is stuck. Needs a decision on the right terminal state.

### 🟡 BL-5 — A garbage lab value can tell a physician "not overdue" when it shouldn't
`OverdueTests` derives "does this row prove the patient isn't overdue" from
the result-status column only, never checking whether the value itself
actually parsed. The module already defines the right exclusion path for this
(`ExclusionReason::UnparseableValue`) but never wires it up anywhere. Needs a
decision on whether to require a real parsed value before a row can prove
"not overdue."

### 🟡 BL-6 — Lab correction-matching can silently miss a correction
If a corrected lab re-transmission's timestamp lands on a different calendar
day than the original (e.g. a midnight-adjacent draw), the correction becomes
an unrelated second result instead of replacing the stale one. Verified this
usually doesn't happen with real HL7 traffic, but nothing enforces it. Needs a
design decision on more robust correction-matching.

### 🟡 BL-7 — HDL cholesterol is flagged "out of range" backwards
A healthy *high* HDL can show as a flagged abnormal value; a dangerous *low*
HDL might not get flagged at all. Already half-acknowledged in a SQL comment,
now tracked. Needs a clinical decision on the correct HDL threshold/direction.

### 🟢 BL-10, BL-11, BL-12 — lower priority, details in known-issues.md
A fail-open default on a progress-poll endpoint (effectively inert given this
fork's chart-wide ACL model), a dead `sql/uninstall.sql` file that's never
actually executed by core (maintenance trap, not a live bug), and a non-atomic
rate-limiter counter on an unauthenticated health-check-adjacent endpoint.

---

## 4. Performance risks flagged for volume (not correctness bugs)

Worth hardening before/soon after Thursday if the patient panel grows toward
what the new 50-patient/20-year seed cohort simulates:

- **N+1 medication lookups**: `MedResponse::resolveMedRow()` issues one query
  per prescription/med-list row (100–300+ for a 20-year insulin-titration
  patient) with no batching, and the underlying `PrescriptionService::getAll()`
  has no `LIMIT`.
- **Redundant config reloads**: lab-slice reading reloads the cadence config
  table from scratch per LOINC code per capability call — 15–20+ redundant
  identical reads per patient per synthesis pass.
- **Unbounded queries**: several lab/vitals/med queries have no `LIMIT` or
  date-range bound. Safe today at the row density this module's seeded
  cadence produces, but no structural ceiling exists if that density grows.

None of these will break Thursday's demo at current scale — flagging as
"do before this scales past a handful of active patients."

---

## 5. What was fully reviewed and came back clean

To be explicit about the breadth of this pass, not just what's broken — all
of the following were independently verified (code read, traced, and where
possible exercised with a standalone script), not just assumed correct from
docs or comments:

- **The V1–V6 deterministic verifier** and its claim-schema parsing/JSON
  unwrap logic — correct.
- **Patient-pinning (I10)**: a chat session can never answer about a
  different patient than the one it's pinned to. Verified with real
  defense-in-depth (schema validation rejects a forged `pid` tool argument;
  a second server-side check drops and alerts on any fact whose `pid`
  doesn't match, persisting zero facts on failure).
- **SQL injection**: every query in every file reviewed uses parameterized
  `QueryUtils` calls; no string-interpolated SQL found anywhere in the
  module.
- **XSS**: `doc.html.twig` and `chat_panel.html.twig` both correctly escape
  every dynamic value (`|text` filters, or safe-by-type fields); the
  stored-XSS bug fixed in an earlier commit (`52f4211`) has not regressed.
- **CSRF**: all 5 real POST-accepting endpoints check a CSRF token before
  ACL, before any patient data is touched.
- **ACL enforcement**: every real endpoint checks the module's own
  `clinical_copilot`/`copilot_access` ACL server-side (in addition to core
  chart ACL) before returning anything — confirmed no GET-checked/
  POST-unchecked asymmetry and no code path that returns data before the
  check.
- **Enum DB-hydration safety**: a recent hardening commit's `tryFrom()` +
  safe-fallback pattern is complete across every DB-hydrated enum in the
  module — no remaining throwing `::from()` call found anywhere.
- **Exception-message redaction**: no raw exception text reaches a
  user-facing surface, an unredacted log, or an LLM prompt anywhere in the
  module (a couple of internal-only storage sites were noted as "latent
  footguns" if a future observability UI surfaces them raw — not a live
  leak today).
- **Health/ready checks are real**, not stubs: they perform actual DB
  round-trips, a real write-then-rollback permissions probe, and (for the
  LLM reachability probe) a real credential-fetch + token call — not just
  "the PHP process is up."
- **The "never assert causation" (I8) boundary** — reviewed every capability
  that pairs evidence (med change → labs, overdue → pending order); all
  pairing happens through citations only, never a causal-sounding string.

---

## 6. New synthetic seed data — 50-patient endocrinology cohort

Added `tests/Seed/SeedEndoCohort.php` (idempotent, CLI-only, same safety
guards as the existing `SeedClinicalCopilot.php`: refuses to run outside a
dev-stack checkout, requires `--force`). Complements — does not replace — the
existing 4 hand-authored "landmine" edge-case patients (`CCP-001..004`).

- **50 patients** (`pubpid` `ENDO-001`..`ENDO-050`), each with
  **`years_of_history = mt_rand(0, 20)`** under a **fixed RNG seed**, so
  re-running the script reproduces the exact same cohort every time (no drift
  between runs).
- Genuinely varied depth: some patients are brand-new (0 years, a single
  intake visit), some have 5 years, some 10, some 18–20 — not one shape
  repeated 50 times.
- Each patient also gets a randomly weighted **condition profile** (type 2
  diabetes / hypothyroidism / both / new-patient screening) and a randomly
  assigned **trajectory** (improving / worsening / stable / volatile) that
  biases an A1c/TSH random walk across a semi-annual visit cadence — so the
  cohort varies clinically, not just in length.
- Per-patient data: A1c + fasting glucose per diabetic visit, TSH (+ Free T4
  when abnormal) per thyroid visit, an annual lipid panel + ACR + creatinine,
  vitals every visit, and metformin/levothyroxine with a realistic
  mid-history dose titration.
- A per-patient JSON summary is written to
  `tests/Seed/fixtures/expected/endo_cohort_summary.json`, including a
  years-of-history distribution breakdown, for quick review without querying
  the DB.

**Not executed in this environment** — see limitations below. Run it inside
the dev docker stack before relying on it:
```
php tests/Seed/SeedEndoCohort.php --force
```

---

## 7. Testing — what could and couldn't be done, and why

**This sandbox has no Docker daemon and no MySQL/MariaDB binary** (checked at
the start of this run). `composer install` was attempted twice — once
foreground (timed out at 3 minutes) and once backgrounded for over an hour —
and ultimately **failed outright** ("Could not authenticate against
github.com") after falling back to slow `git clone` mirrors for nearly every
package, because this environment's outbound proxy times out on GitHub's
zipball/API path. `vendor/autoload.php` was never generated.

**Consequence: no PHPUnit (isolated or DB-backed), no PHPStan, no phpcs, and
neither seed script could actually be *executed* in this environment.**
Every finding in this report — and every fix applied — was verified by:
- Reading the actual code and tracing call paths by hand (including into
  core OpenEMR files, e.g. the real Module Manager install/uninstall code
  and the HL7 result receiver, not just this module's own claims about them).
- `php -l` syntax-checking every touched file.
- Standalone PHP scripts proving specific before/after behavior where
  possible (e.g. directly executing the buggy `DateInterval` math and the
  fixed version side-by-side, executing `moduleConfig.php` directly to
  confirm it produces zero output).
- Adding regression tests for every fix that touches testable logic, so they
  run automatically the next time someone *can* run the suite.

**This is real, but it is not the same as green CI.** Before Thursday,
someone needs to run the actual test suite against the real dev stack:
```
openemr-cmd cst          # full suite, or at minimum:
openemr-cmd pit           # isolated tests only (fastest signal)
```
and run `SeedEndoCohort.php` for real to confirm the 50-patient cohort
actually inserts cleanly and renders in the synthesis/dashboard UI.

---

## 8. What it would take to get this to "110%" for Thursday

In priority order:

1. **Run the real test suite** (`openemr-cmd cst`) against everything in this
   report — this audit's fixes are well-reasoned but statically verified
   only; nothing here has actually executed against a database yet.
2. **Decide BL-8** (QA-rerun budget bypass) before anyone flips on background
   LLM in a cost-sensitive environment.
3. **Configure an LLM credential** (even just the Gemini API-key dev
   fast-path) somewhere before Thursday if the plan is to demo live
   synthesis/chat — right now verification never runs anywhere because
   nothing is configured, which is safe but also means nobody has seen the
   fixed verification path actually fire yet.
4. Run `SeedEndoCohort.php --force` for real, then spot-check a few patients
   across the years-of-history spectrum (a 0-year, a ~10-year, an 18-20 year)
   in the synthesis doc and dashboard UI.
5. Work through BL-5/BL-6/BL-7 (clinical-accuracy items) on whatever cadence
   makes sense — none are Thursday-blocking, but they're all real gaps in the
   module's core "don't silently miss/mislead" promise.
6. Consider the performance items in §4 before the patient panel grows past a
   handful of active users.

---

*Generated by an autonomous overnight Claude Code review session. All fixes
are on `claude/practical-edison-5je7tg`, based on and only referencing `main`
(never pushed to `main`). This file and `docs/known-issues.md` BL-5–BL-12 are
the durable record — reference them directly rather than re-deriving this
context from scratch.*
