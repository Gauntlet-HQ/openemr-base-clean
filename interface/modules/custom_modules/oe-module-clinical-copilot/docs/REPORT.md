# Clinical Co-Pilot — Code Quality & Correctness Report

**Scope:** `interface/modules/custom_modules/oe-module-clinical-copilot/src/` (all subdirectories), `public/*.php`, `templates/*.twig`. Companion to `docs/SECURITY.md`, which covers the security-taxonomy pass (auth, injection, secrets, PHI egress) over the same module plus `ops/`/deploy scripts. This report covers everything from the code-quality/SOLID/DRY review that is **still open** — i.e. everything not already applied in the prior 14-item safe-refactor batch (listed at the bottom for reference).

---

## Summary

Seven security subtree audits and a multi-agent code-quality pass covered every file in `src/`, all 13 `public/*.php` entry points, and all 9 Twig templates. The headline finding, consistent across both passes: **this module is unusually disciplined for its size.** Parameterized queries throughout, consistent PSR-3 logging, `strict_types=1` everywhere, real DTOs with `final readonly` + named factories where it matters, and a static-analysis rule (`ForbiddenWriteOutsideRepositoriesRule`) that actually enforces the "only `ChartWriter` touches core tables" invariant rather than just documenting it. The open items below are mostly real design debt — accumulated duplication, array shapes that outgrew their welcome, constructors that quietly default their own dependencies — not sloppiness. That said, there is a real cluster of **correctness bugs**, several of which touch the exact places (PHI redaction, cross-patient identity matching, trace correlation, transactional writes) where this module can least afford to be subtly wrong, and one of which compounds the CRITICAL finding in `SECURITY.md`. Fix those first; the design debt can be scheduled.

---

## 1. Correctness bugs (fix first)

Ordered worst-first. Every item below was re-read against the current source (post safe-refactor-batch) to confirm it's still present.

### 1.1 — HIGH — `VerifiedGeneration::attempt()` treats an empty claims array as a verified PASS
**`src/Verify/VerifiedGeneration.php:128-146`**

When the verification gate is disabled (see `SECURITY.md` finding #1 — `VerificationPolicy::GATE_ENFORCED_DEFAULT = false`), `attempt()` returns `AttemptOutcome::passed($verification->verdicts, $verification->claims ?? [], ...)` unconditionally — including when `$verification->claims` is `[]`. Even with the gate re-enabled, line 137's `$verification->allPassed()` check has the same hole: an empty claims array is schema-valid, so every V1–V6 check trivially passes with nothing to check, and `allPassed()` is `true`.

Contrast with `src/Chat/ChatAgent.php:132`, which explicitly guards this exact edge case: `($verification->claims ?? []) !== []` is required before treating a result as passed, and otherwise forces the one fail-closed regeneration with an injected `[empty_answer]` finding. `VerifiedGeneration`'s own docblock claims it implements "the identical fail-closed policy" as `ChatAgent` — it doesn't, on this path.

**Why it matters now, not hypothetically:** this bug directly compounds the CRITICAL finding in `SECURITY.md` (verification gate off by default). Even after that gate is correctly re-enabled, this second bug means a degenerate/empty model output on the synthesis path still renders as `verify_status=Passed` with zero claims — an empty, silently "verified" narrative reaching the physician, which is exactly the failure mode `ChatAgent` was hardened against on the chat path.

**Fix:** mirror `ChatAgent`'s guard — require `$verification->allPassed() && $verification->claims !== []` before returning `passed()`, falling through to the ordinary-failure path (eligible for the one retry) with an explicit no-claims finding otherwise. Applies to *both* the gate-disabled branch (line 128-135) and the gate-enabled branch (line 137-144).

### 1.2 — HIGH — `Redactor::redactPrompt()`'s cascading `str_replace()` can leave a PHI fragment un-redacted
**`src/Reduce/Redactor.php:58-63`**

```php
$search = array_values($valueByToken);
$replace = array_keys($valueByToken);
str_replace($search, $replace, $request->systemInstructions)
```

PHP's array-form `str_replace()` applies replacements **sequentially**, left to right, over the progressively-modified string. Fields are processed in a fixed order (`name`, `mrn`, `dob`, `address`, per `PatientIdentifiers::nonEmptyFields()`). If one identifier's value is a substring of another's — a common real-world collision, e.g. surname "Johnson" embedded in address "14 Johnson Ave" — the earlier replacement (name) corrupts the string before the later replacement (address) gets to run its own full-value match, so the address's `str_replace` no longer matches and the surrounding fragment ("14 " / " Ave") reaches the model un-tokenized, right next to the substituted name token.

**Why it matters:** this is the module's *entire* HIPAA/BAA egress-redaction boundary (ARCHITECTURE.md §4) — its one job is guaranteeing zero direct identifiers reach the LLM provider. This bug breaks that guarantee silently: nothing errors, nothing logs, the prompt just carries a PHI fragment. Cross-reference `SECURITY.md` finding #3 (the knowledge-query scrubber has an analogous, independently-discovered PHI-leak gap) — this module has two separate redaction boundaries and both currently have holes.

**Fix:** replace the cascading array `str_replace` with an order-independent single pass — sort identifier values by descending length and use one regex alternation (`preg_replace_callback` over `preg_quote`-escaped alternatives), or otherwise ensure no partial match from one field can consume text needed by another. Applies to `redactPrompt()`; `rehydrate()` (line 80-83) has the same array-`str_replace` shape but reversed direction (token→value), which is lower risk since tokens are namespaced and collision-free by construction — worth a second look but not the same bug.

### 1.3 — HIGH — `LabIdentityMatcher::nameMatches()` false-mismatches hyphenated/apostrophe'd surnames
**`src/Ingest/LabIdentityMatcher.php:115-146`**

`tokens($docName)` splits the document-printed name on every non-letter character (`/[^\p{L}]+/u`, line 132) — so "Jane Smith-Jones" tokenizes to `["jane", "smith", "jones"]`. But `nameMatches()` compares this token list against `self::fold($chartFirst)` / `self::fold($chartLast)` (lines 122-123), and `fold()` only lowercases and trims — it does **not** split on the same punctuation. If the chart's `patient_data.lname` is stored as "Smith-Jones", `fold($chartLast)` produces the literal string `"smith-jones"`, which never equals any single token in `["jane", "smith", "jones"]`. The whole-token check fails even though the document unambiguously names this patient.

Because `compare()` (lines 91-95) treats any failed name check as a concrete conflict, this doesn't degrade to `Unknown` — it produces a hard `LabIdentityStatus::Mismatch`, which the review template renders as a "may belong to a different patient — do NOT lock" banner.

**Why it matters:** hyphenated and apostrophe'd surnames are common (married names, Spanish/Portuguese double surnames, Irish/Scottish "O'"/"Mac" prefixes). Every such patient's correctly-uploaded lab report trips the PHI-mix banner with no actual mismatch — which is exactly the kind of false positive that trains reviewers to click through the banner without reading it, undermining the one guard `SECURITY.md` finding #2 already flags as advisory-only (`ExtractionReview::lock()` never hard-blocks on `Mismatch` regardless of this bug). A frequently-wrong guard is worse than no guard.

**Fix:** normalize both sides identically before comparing — either tokenize `$chartFirst`/`$chartLast` the same way `tokens()` splits the document name, or strip the same punctuation set from both sides before the token/substring check.

### 1.4 — HIGH — `ChartWriter::commitLabResults()` + `ExtractionReview::lock()` have no transaction, breaking the documented idempotency guarantee
**`src/Ingest/ChartWriter.php:287-362`, `src/Ingest/ExtractionReview.php:79-168`**

`commitLabResults()` runs four separate `QueryUtils::sqlInsert()` calls — `procedure_order`, `procedure_order_code`, `procedure_report`, then one `procedure_result` per pending field — with no `QueryUtils::startTransaction()`/commit/rollback around them (confirmed: no transaction call anywhere in `ChartWriter.php`, despite the pattern being used elsewhere in this module, e.g. `Observability/ReadyCheck.php`, `Knowledge/KnowledgeWriteConnection.php`). `ExtractionReview::lock()` then loops over the returned committed ids and calls `setFieldLineage()` per field, and only calls `markLocked()` after that loop finishes.

If the process dies or throws between the `procedure_result` inserts and the corresponding `setFieldLineage()` updates — a DB blip, a PHP timeout, a worker restart, all plausible operational events — the extraction is left in `draft` status with some fields showing `committed_core_pk = NULL` even though their `procedure_result` rows already exist in core. A retry of `lock()` re-fetches those fields, sees them as `!isCommitted()`, and `commitLabResults()` inserts them **again** — a genuine duplicate lab result under the chart, plus an orphaned extra `procedure_order`/`procedure_order_code`/`procedure_report` per retry.

**Why it matters:** `ChartWriter`'s own docblock claims "re-committing an already-committed field is a no-op — no duplicate, untraceable records," and `ExtractionReview::lock()` claims to be "idempotent." Both claims hold only for a clean first attempt followed by a clean second call — not for the partial-failure-then-retry case, which is the case idempotency guarantees exist for.

**Fix:** wrap the full commit-and-lineage sequence in one transaction — begin before the first insert in `commitLabResults()` (or have `lock()` open it before calling `commitLabs()`/`commitIntake()`, committing only after `markLocked()` succeeds), rolling back on any `\Throwable` so a partial attempt leaves no core rows behind at all.

### 1.5 — HIGH — `SynthesisReadPath::currentDigest()` mints a throwaway correlation id, breaking trace linkage
**`src/ReadPath/SynthesisReadPath.php:236-255`**

`currentDigest()` has no `correlationId` parameter — it calls `self::mintCorrelationId()` internally (line 238) purely to satisfy `extractAll()`'s signature, and never exposes the id to the caller. Its only caller, the QA-driven-rerun freshness guard in `Worker.php` (~line 309, per the narrative report), then calls `regenerate()` with no correlation id either, so `regenerate()` mints yet a *third*, independent id (`?? self::mintCorrelationId()` at line 215).

**Why it matters:** the freshness-check spans and the actual regenerate-attempt spans for one logical QA-rerun operation land under two-to-three unrelated correlation ids in `mod_copilot_trace`, breaking the "every span traces back to the triggering operation" invariant the module's own docblocks assert (`TraceRecorderInterface.php:18-25`, `TraceSpan.php:18`). This isn't cosmetic — it's the mechanism an operator uses to reconstruct what a QA-driven rerun actually did.

**Fix:** add an optional `?string $correlationId = null` parameter to `currentDigest()`; have the `Worker.php` QA-rerun loop mint one id per candidate and thread it through both the freshness check and the subsequent `regenerate()` call. This needs coordinated changes in `Worker.php`, which is a gap in this pass anyway (see §5 below) — treat as one unit of work.

### 1.6 — HIGH — `IngestController::commitReviewedIntake()` passes no correlation id at all
**`src/Controller/IngestController.php:92-101`**

Every sibling method mints a correlation id and threads it through: `previewIntake()` (line 78: `$this->newCorrelationId()`), `ingestLab()` (line 105), `startManualLab()` (line 110). `commitReviewedIntake()` — the step that **creates the patient record** and writes reviewed allergies/medications to the chart, i.e. the highest-stakes write in the entire ingest flow — calls `$this->ingest->commitReviewedIntake(...)` with no correlation id argument at all, and there's no linkage back to the earlier `previewIntake()` call's id either.

**Why it matters:** the human-review round-trip (upload → preview → human corrects fields → commit) is untraceable end-to-end in `mod_copilot_trace`. If a commit produces a bad chart write, there's no way to reconstruct which preview/extraction attempt it came from via the trace tables — the one mechanism this module built specifically for that purpose.

**Fix:** thread `$this->newCorrelationId()` into `commitReviewedIntake()` at minimum; ideally carry the id from the originating `previewIntake()` call if the client can round-trip it, so the whole flow shares one id. Requires a signature change on `AttachAndExtract`, out of this controller's file scope alone — needs coordination.

### 1.7 — HIGH — Duplicated ~100-200 line Gemini transport traits have already drifted apart once
**`src/Reduce/GeminiGenerateContentContract.php` (215 lines) vs `src/Chat/Llm/GeminiChatContentContract.php` (282 lines)**

`classifyTransportError()`, `extractTokenCount()`, and `extractOutputTokenCount()` are byte-for-byte identical between the two traits. `assertCleanFinish()` and `noCandidatesError()` differ only in message text. Both traits are `use`d in pairs of near-identical provider classes (`VertexLlmClient`/`GeminiApiLlmClient` in `Reduce`; `VertexChatLlmClient`/`GeminiApiChatLlmClient` in `Chat/Llm`).

**Why it matters:** the `Reduce` trait's own docblock records a real historical bug caused by exactly this duplication — "previously only the AI-Studio client did this and every Vertex HTTP error collapsed to unreachable." That's proof, not speculation, that the two copies drift. A future fix to error classification, finish-reason handling, or token accounting has two places to apply it, and a maintainer fixing one will plausibly miss the other, silently reintroducing a bug already paid for once.

**Fix:** extract the provider-agnostic pieces into one shared trait or small base class both `GeminiGenerateContentContract` and `GeminiChatContentContract` compose/delegate to; leave only the genuinely different request-body-building and response-part-extraction logic in each. Not mechanical — needs care around the two message-text differences and test coverage for both call paths.

### 1.8 — HIGH — `HealthCheck::moduleVersion()` silently drops zero-valued version segments
**`src/Observability/HealthCheck.php:54`**

```php
$parts = array_filter([$v_major ?? null, $v_minor ?? null, $v_patch ?? null]);
```

`array_filter()` with no callback drops every falsy element — including the string `"0"`. Confirmed against the module's actual `version.php` values (`$v_major = '0'`, `$v_minor = '1'`, `$v_patch = '0'`): the unauthenticated `/copilot/health` payload currently reports `version: "1"` instead of `"0.1.0"`.

**Fix:** filter explicitly on `!== null`, not truthiness: `array_filter([...], static fn($v) => $v !== null)`.

### 1.9 — HIGH — `IpRateLimiter`'s counter window never resets under steady traffic
**`src/Observability/IpRateLimiter.php:38-59`**

`apcu_store($key, $value, self::WINDOW_SECONDS)` is called both on the initial hit (line 47) and on every subsequent accepted increment (line 56) — each call resets the 60-second TTL from that moment. As long as requests keep arriving with gaps under 60 seconds, the counter's TTL keeps getting pushed out and it never actually expires — it just climbs toward `maxRequestsPerWindow` and then blocks permanently, only recovering after a full 60s of total silence from that IP.

**Why it matters:** a legitimate low-rate poller — a k8s/orchestrator readiness probe hitting `/copilot/ready` every 20-30 seconds, exactly the traffic pattern this endpoint exists to serve — will accumulate toward the cap over a few minutes and then get **permanently** blocked, precisely the failure mode a rate limiter on a readiness endpoint must not produce.

**Fix:** only set the TTL on creation (`apcu_add` instead of the first `apcu_store`), and use `apcu_inc` for increments so subsequent calls don't touch the TTL.

### Other bugs from the review worth flagging (MEDIUM unless noted)

- **MEDIUM — `src/Ingest/ChartWriter.php:287-330`** — every `commitLabResults()` call with ≥1 newly-pending field creates a *fresh* `procedure_order`/`procedure_order_code`/`procedure_report`, never reusing an already-committed extraction's order. An unlock→edit→re-lock correction cycle fragments one lab report across multiple disconnected core lab orders, degrading the chart's orders/results views even though the module's own staging data correctly tracks it as one extraction.
- **MEDIUM — `src/Observability/AlertEvaluator.php:279-316` vs `src/Observability/ReadyCheck.php:152-177`** — worker-heartbeat staleness duplicated with *different* multipliers: `AlertEvaluator` reads the admin-tunable `heartbeat_stale_multiplier`; `ReadyCheck` hardcodes `2`. If an operator tunes the config, the alert and the `/copilot/ready` field can disagree about whether the same heartbeat is stale.
- **MEDIUM — `public/lab_upload.php`** — missing the 413-oversized-upload guard that `intake_upload.php` and `knowledge_upload.php` both have for the identical failure mode. A user uploading an oversized lab PDF gets a confusing generic CSRF-failure message instead of a clear "file too large" one. Small, but a real behavioral gap, not just duplication — adding the guard is a behavior change, not a mechanical dedup.
- **LOW/informational — `src/Observability/LlmReachabilityProbe.php` / `LlmCostEstimate.php`** — `AlertName::P95Latency` silently reports a different metric (synthesis warm-miss rate) during the 8-9am window (documented intent, misleading name); `FALLBACK_RATE` duplicates `gemini-2.5-pro`'s pricing verbatim with no cross-reference, can silently drift if pricing changes.

---

## 2. Design debt (SOLID/DRY)

Grouped by theme, worst-impact first within each group.

### 2.1 Dependency injection — constructor-default `new X()` is the module's most repeated violation of its own CLAUDE.md rule

CLAUDE.md is explicit: "Never use `new` for service-layer objects inside business logic." This is violated as a *pattern*, not a one-off, across Observability:

- `AlertEvaluator.php:53-54` (`CadenceConfigStore`, `SystemLogger` defaults)
- `LogAlertNotifier.php:30`, `QaReviewer.php:59`, `QaStore.php:33`, `TelemetryRetention.php:72`, `WorkerTick.php:50`, `FlashReviewer.php:86` (`Redactor` default)
- `ReadyCheck.php:37-38` (`CadenceCircuitBreaker`, `LlmReachabilityProbe` defaults)
- `LlmReachabilityProbe.php:105,144` — actual `new Client(['verify' => true])` calls *inside method bodies*, not even constructor defaults
- `src/Chat/Tool/ToolExecutor.php:92` — `(new SystemLogger())->error(...)` inline in a catch block, the one inconsistent instantiation among ~20 `SystemLogger` construction sites in this module, and notable because `ToolExecutor`'s constructor already takes seven other collaborators by DI — the pattern was right there.
- `src/Controller/ChatController.php:119-152, 476-520` — `buildChatAgent()` rebuilds the *entire 5-capability set* from scratch on every chat turn, called from `runTurnLocked()` (business logic, not the composition root). Worse than the others: the freshness-checker's config providers and `buildChatAgent()`'s config providers are **separate instances** — if `DbLabContractConfigProvider`/`DbLabTurnaroundConfigProvider` cache anything per-instance, the freshness check and the actual tool-call capabilities could observe different config snapshots *within the same turn*. That's a latent correctness risk riding on top of the DI violation, not just a style complaint.

**This is a pattern-level issue, not a quick fix** — changing it means moving default wiring to each class's own `createDefault()`/factory method and making constructors require all dependencies, which ripples into every call site relying on the current defaults. Worth a dedicated follow-up unit, not a drive-by.

Related: **`src/Controller/ChatController.php`, `DocController.php`, `Observability/LoggingAlertSink.php`** repeatedly call static service locators (`SessionWrapperFactory::getInstance()`, `EventAuditLogger::getInstance()`) instead of injecting them — same root cause, same "needs a dedicated pass" caveat since it's a constructor-signature change with ripple into `createDefault()`.

### 2.2 Untyped array shapes past the 3-4 key DTO threshold

Per CLAUDE.md's own array-typing progression, these should be DTOs:

- **`src/Observability/AlertEvaluator.php:368-393`** — `loadThresholds()` returns a 7-key array-shape, documented once, consumed as bare `array` by 7 different check methods.
- **`src/Observability/MetricsService.php` — `overview()`** — untyped 19-key associative array.
- **`src/ReadPath/DocViewModel.php:675-702`** — `factRow()` returns an untyped 18-key array consumed across 4 methods; several call sites already defensively re-check types because the shape isn't statically guaranteed, and it gets mutated post-hoc (`$row['group_label'] = ...`) — exactly the fragility CLAUDE.md's DTO guidance is meant to prevent.
- **`src/Controller/ChatController.php`** — three *overlapping* "turn result" shapes assembled independently (`persistAssistantTurn` 7 keys, `turnResponse` 13 keys, `pollStatus`'s `'turn'` value 7 keys) with no single source of truth. A field added to one is easy to forget in the other two.
- **`src/Lab/LabRowProcessor.php:69,116-122,153,182-191`** — a 5-key ad-hoc shape (`row`/`clinicalDate`/`statusClassification`/`parsedValue`/`unitConversion`) threaded through `process()`/`resolveGroup()`, requiring six inline `@var` re-narrowing casts to get typed locals back — exactly the "each cast should prompt the question why the type doesn't match" pattern CLAUDE.md asks to avoid.
- **`src/Observability/QaReviewer.php`** — `recordReviewed()` (11 positional params) and `recordDegraded()` (6 params) both repeat the same `targetType`/`targetId`/`correlationId`/`pid`/`userId`/`factDigest` data clump — a `QaTarget` DTO built once in the calling `reviewDoc`/`reviewChatTurn` methods would remove the transposition risk of 11 same-typed positional arguments.

None of these are mechanical — each touches multiple call sites and, in `ChatController`'s and `DocViewModel`'s case, contracts likely consumed outside the reviewed scope (SSE/JSON responses). Prioritize `AlertEvaluator`'s 7-key threshold shape and `QaReviewer`'s parameter clump first — smallest blast radius, clearest transposition risk.

Also worth a follow-up, lower urgency: `src/Observability/FlashReviewResult.php:32`, `NewQaVerdict.php:43,49-51`, `QaSweepOutcome.php:37`, and `QaReviewer::tally()` (139-143) all use bare string literals (`'ok'`/`'unavailable'`/`'error'`) for a QA verdict status where the module already has a `QaStatus` enum used right next to this code — a natural `FlashReviewStatus` backed enum candidate.

### 2.3 Remaining DRY duplication

**Verified-safe-to-fix-later, mechanical in isolation:**
- `src/ReadPath/FactAnalyteResolver.php::resolveCodes()` and `src/ReadPath/MedNameResolver.php::resolveNames()` are structurally identical modulo variable names — extraction needs the two files' SQL column aliases normalized to a common name as part of the change.
- `ChatController::errorResponse()` and `DocController::errorResponse()` are the same method with different key insertion order — flagged with a caveat that JSON key-order sensitivity on the consuming side wasn't verified, so confirm before merging.
- Correlation-id minting: three call sites use `Uuid::uuid7()->toString()` identically; a fourth, `IngestController::newCorrelationId()`, uses a completely different `'ingest-' . bin2hex(random_bytes(8))` scheme. Unifying the three UUIDv7 sites is safe; touching `IngestController`'s distinct prefix format is not — it may be an intentional, product-meaningful distinction and needs confirmation before changing.
- `ChatFreshnessChecker.php:55-69` duplicates three digest-input constants (`CODE_SET_VERSION`, `DOC_TYPE`, `PROMPT_VERSION`) that must exactly match private constants on `SynthesisReadPath` by convention, not by construction — the class's own docblock already flags this and recommends hoisting into a shared `DigestInputs` class. Directly relevant to bug 1.5 above, since both classes are involved in the QA-rerun path's correlation-id problem — worth doing in the same unit of work.
- `src/Chat/Tool/ToolExecutor.php` / `src/Chat/ChatAgent.php` / `src/Verify/VerifiedGeneration.php` — `formatFindings()` (verdict→regeneration-prompt string) is duplicated verbatim between `VerifiedGeneration` and `ChatAgent`; `patientBlock()` (prompt header rendering) is duplicated verbatim between `Reduce/PromptAssembler.php` and `Chat/ChatPromptAssembler.php`; `parseDateTime()` is duplicated between `ChatTurnStore.php` and `ChatSessionStore.php`. All small, low-risk, straightforward extractions.
- `src/Knowledge/KnowledgeBaseConnection.php` / `KnowledgeWriteConnection.php` — byte-for-byte identical `isAvailable()`, near-identical PDO construction options (only the timeout differs).
- `src/Rag/SparseRetriever.php` / `HeuristicReranker.php` / `LocalDenseRetriever.php` — the same tokenization regex and tag-score-boost loop duplicated across three retrieval-stage classes; a missed update to one silently makes sparse/dense/rerank disagree on what counts as a token.
- `src/Ingest/ExtractionClient.php` / `src/Knowledge/DocumentTranscriber.php` — both independently reimplement "build a `PromptRequest` with inline document bytes, call `generateStructured`, `json_decode` the raw JSON" — two untested-together copies of the same vision-call sequence.

**Explicitly NOT mechanical — needs a deliberate follow-up, not a drive-by fix:**

The **CSRF/ACL/session boilerplate across `public/*.php`** is the largest duplication surface in the module by file count (session bootstrap repeated in 10 files, `$isPost` derivation in 6, conditional CSRF checks in 6, three distinct ACL-predicate groupings across 11 files, `$authUserId`/`$authUser` extraction in 9, `$webRoot`/`$moduleBase` derivation in 6, Twig-environment construction in 7). The review's own instruction, which this report endorses, was that **any duplication touching CSRF/ACL/session semantics is never safe to mechanically dedup, no matter how textually identical it looks** — a naive merge risks silently changing an auth check. One concrete trap already found in this category: a "keep chart pid in sync" block that *looks* identical between `doc.php` and `lab_upload.php` but actually relies on different upstream preconditions in each file — a mechanical merge here would be a real, live bug, not a refactor. Treat this whole surface as one deliberate, reviewed unit of work with its own test plan, not a batch of independent one-liners.

Two items from this same DRY inventory are pure/no-ACL-risk and genuinely safe whenever someone picks this up: the `$webRoot`/`$moduleBase` string derivation, and the Twig-environment factory construction (`twig()`/`twigEnv()` — see 2.4 below for why that one is more than just duplication).

### 2.4 `twig()` vs `twigEnv()` — a latent landmine, not just a naming quirk

`public/intake_upload.php` and `public/knowledge_upload.php` each declare an **un-namespaced global function** named `renderReview` with **different signatures**, plus near-identical Twig-environment factory functions that ended up named `twig()` and `twigEnv()` purely by accident of who wrote which file first. Nothing stops both files from being `require`d into the same PHP process (a test harness, a future CLI script, an includes refactor) — at which point PHP fatals with "Cannot redeclare `renderReview()`." This is a direct consequence of §2.5's SRP finding below: these functions exist as bare globals instead of methods on a controller class specifically because `intake_upload.php`/`knowledge_upload.php` mix routing, business logic, and rendering inline. Flagged as worth prioritizing precisely because it's currently silent — nothing exercises the failure mode today, but the first thing that requires both files together will hard-fail in a confusing way.

### 2.5 SRP offenders — routing + business logic + rendering mixed inline in `public/`

- **`public/knowledge_upload.php:146-298`** — worst offender by line count (298 lines): routing, orchestration, and three rendering functions all inline in the entry-point file.
- **`public/doc.php:170-309`** — five free functions build/derive the Twig view model inline, including a legacy `dashboard_header.php` require + `ob_start` call.
- **`public/intake_upload.php:157-241`** — six free functions including two Twig-rendering functions inline, a smaller-scale version of the same pattern.
- **`public/dashboard.php:67-112`** — POST action dispatch (circuit breaker toggle / evals / load-test) inline in the entry point, including a `shell_exec()` call for the load-test bench and a `require_once` of an ops-only class in the request path.

All four should follow the pattern `ChatController`/`DocController`/`IngestController` already establish elsewhere in this module — extract a dedicated controller + view-model builder per entry point. None of this is mechanical; it's a structural redesign of each file, and `dashboard.php`'s specifically touches audit-logging call ordering, so it needs care around what gets logged when.

---

## 3. `health.php`'s "zero dependencies" claim is false

**`public/health.php`**, **`src/Observability/HealthCheck.php`**

`health.php`'s own comment states it "checks NO dependencies, not even DB" — specifically to satisfy ARCHITECTURE.md §3.4's stated requirement that "a DB outage must not fail liveness and get the app pointlessly restarted by an orchestrator." `HealthCheck::check()` itself lives up to that: it reads only `version.php` constants, no `QueryUtils` call, no network call.

But `health.php` unconditionally does `require_once __DIR__ . '/../../../../globals.php'` before calling `HealthCheck::check()` — and that bootstrap chain (`globals.php` → `library/sql.inc.php`) makes an eager DB connection attempt via `DatabaseConnectionFactory` **regardless of `$ignoreAuth`**. If the database is unreachable, the bootstrap itself is the likely failure point — the exact scenario the design explicitly says must be avoided. An orchestrator liveness-probing `/copilot/health` during a DB blip can see it hang or 500, and get the app restarted for the one reason this endpoint was built to be immune to.

This is not a quick fix. It's a real architectural question — what is `health.php` actually allowed to depend on, and is bypassing the standard OpenEMR bootstrap even feasible for a module page — that needs a deliberate decision, not a one-line patch. Recommend resolving this **before** any deploy configuration relies on this endpoint's stated liveness guarantee (Railway or otherwise). A comment-only fix narrowing the overstated claim in the docblock is a fine stopgap in the meantime but should land together with the real decision, not as a substitute for it.

---

## 4. Coverage note

**`src/Worker.php` and `src/Config/` were not independently covered by a dedicated code-quality pass.** They were touched only tangentially — by the security review (which is why `Worker.php:279-329`'s QA-rerun cost-budget bypass and `LlmEnv.php`'s dev-guard gap appear in `SECURITY.md`, not here) and by cross-references from other subtrees (e.g. bug 1.5 above requires `Worker.php` changes but the file itself wasn't read start-to-finish by the quality pass). This is a real gap, not a "nothing to see here" — `Worker.php` in particular is where several of the correctness bugs above (1.5, the QA-rerun cost bypass, the heartbeat-multiplier mismatch) actually converge, and it deserves its own dedicated quality pass rather than being inferred from its callers and callees.

---

## 5. Appendix: full findings table

Every still-open finding from the three source reports. Severity/category/location as reported; "already applied" items are excluded (see list below the table).

| Severity | Category | Location | Summary |
|---|---|---|---|
| HIGH | bug | `Verify/VerifiedGeneration.php:95-146` | Empty claims array trivially passes verification, unlike `ChatAgent`'s guard against this edge case |
| HIGH | bug | `Reduce/Redactor.php:58-63` | Cascading `str_replace()` can leave a PHI fragment un-redacted when one identifier is a substring of another |
| HIGH | bug | `Ingest/LabIdentityMatcher.php:115-146` | Hyphenated/apostrophe'd surnames produce false hard `Mismatch` verdicts |
| HIGH | bug | `Ingest/ChartWriter.php:287-362`, `Ingest/ExtractionReview.php:79-168` | No transaction wrapping the commit+lineage write sequence; partial failure + retry double-inserts `procedure_result` rows |
| HIGH | bug | `ReadPath/SynthesisReadPath.php:236-255` | `currentDigest()` mints a throwaway correlation id, breaking QA-rerun trace linkage |
| HIGH | bug | `Controller/IngestController.php:92-101` | `commitReviewedIntake()` passes no correlation id for the highest-stakes chart-write step |
| HIGH | dry | `Reduce/GeminiGenerateContentContract.php` vs `Chat/Llm/GeminiChatContentContract.php` | ~100-200 line duplicated transport traits, already drifted apart once (documented historical bug) |
| HIGH | bug | `Observability/HealthCheck.php:54` | `array_filter()` drops "0" version segments — `/copilot/health` reports wrong version |
| HIGH | bug | `Observability/IpRateLimiter.php:38-59` | Counter TTL resets on every accepted request; steady low-rate traffic never resets and gets permanently blocked |
| MEDIUM | bug | `Ingest/ChartWriter.php:287-330` | New `procedure_order`/report created on every correction cycle instead of reusing the extraction's original order — fragments one lab report across multiple core lab events |
| MEDIUM | dry+bug-adjacent | `Observability/AlertEvaluator.php:279-316` vs `Observability/ReadyCheck.php:152-177` | Worker-heartbeat staleness duplicated with different (config vs. hardcoded `2`) multipliers — can disagree |
| MEDIUM | dry | `Observability/AlertEvaluator.php` (4 call sites) / `ReadyCheck.php` / `QaReviewer.php` / `WorkerTick.php` | Cadence `config_json` fetch-and-decode boilerplate repeated 5x; `AlertEvaluator.php` alone was routed through `CadenceConfigStore::get()` in the safe batch — other 3 sites still open |
| MEDIUM | dry+architecture | `Observability/ReadyCheck.php:104-126` | `checkTablesWritable()` bypasses `TraceRecorder`'s own validation invariant, duplicates its INSERT directly |
| MEDIUM | type-safety | `Doc/FlashReviewResult.php:32`, `NewQaVerdict.php:43,49-51`, `Doc/QaSweepOutcome.php:37`, `QaReviewer::tally()` | QA verdict status is a bare string in 4 places despite an existing `QaStatus` enum precedent |
| MEDIUM | di | Observability-wide + `Chat/Tool/ToolExecutor.php:92`, `Controller/ChatController.php:119-152,476-520` | Widespread constructor-default `new X()` / inline `new` for service-layer collaborators; `ChatController::buildChatAgent()` rebuilds the 5-capability set every turn from business logic, with two separately-instantiated config-provider sets risking a within-turn config-snapshot mismatch |
| MEDIUM | complexity | `Observability/QaReviewer.php:245-297` | `recordReviewed()`/`recordDegraded()` — 11/6-param data-clump signatures, transposition risk |
| MEDIUM | type-safety | `Observability/AlertEvaluator.php:368-393` | 7-key threshold array-shape passed as bare `array` through 7 methods |
| MEDIUM | srp | `Controller/DocController.php:271-284` | `isAuthorizedForCorrelation()` runs raw SQL directly, bypassing the `ReadPath` abstraction |
| MEDIUM | di | `Controller/ChatController.php`, `DocController.php`, `Observability/LoggingAlertSink.php` | Repeated static service-locator calls (`SessionWrapperFactory::getInstance()`, `EventAuditLogger::getInstance()`) |
| MEDIUM | dry | `ReadPath/FactAnalyteResolver.php::resolveCodes()` vs `ReadPath/MedNameResolver.php::resolveNames()` | Structurally identical modulo variable names; needs SQL alias normalization to extract |
| MEDIUM | dry | `Controller/ChatController.php::errorResponse()` vs `DocController.php::errorResponse()` | Same method duplicated, differing key insertion order (JSON key-order sensitivity unverified) |
| MEDIUM | type-safety | `Controller/ChatController.php` | Three overlapping "turn result" array shapes (7/13/7 keys) with no single source of truth |
| MEDIUM | dry+type-safety | `Controller/ChatController.php::startSession()`/`reseed()` | Identical 6-key return shape with copy-pasted PHPDoc |
| MEDIUM | type-safety | `ReadPath/DocViewModel.php:675-702` | `factRow()` returns an 18-key untyped array, mutated post-hoc, consumed by 4 methods |
| MEDIUM | dry | `Knowledge/KnowledgeBaseConnection.php:51-54,94-102` vs `KnowledgeWriteConnection.php:37-40,79-89` | Identical `isAvailable()`, near-identical PDO construction |
| MEDIUM | dry | `Rag/SparseRetriever.php:135-149`, `HeuristicReranker.php:110-116`, `LocalDenseRetriever.php:100-113` | Same tokenization regex + tag-boost loop duplicated across 3 retrieval classes |
| MEDIUM | dry | `Ingest/ExtractionClient.php:59-91` vs `Knowledge/DocumentTranscriber.php:51-79` | Independent reimplementations of the "inline-data part → `generateStructured` → JSON decode" sequence |
| MEDIUM | srp | `public/knowledge_upload.php:146-298` | Routing + orchestration + 3 rendering functions inline (298 lines) |
| MEDIUM | srp | `public/doc.php:170-309` | 5 free functions build the Twig view model inline, incl. legacy `dashboard_header.php` require |
| MEDIUM | dry+bug | `public/*.php` CSRF/ACL/session boilerplate, 13 files | Large duplication surface; explicitly NOT safe to mechanically dedup — see §2.3. Includes the `lab_upload.php` missing-413-guard bug and the `doc.php`/`lab_upload.php` false-twin pid-sync block |
| MEDIUM | dry (naming collision) | `public/intake_upload.php` vs `knowledge_upload.php` | Duplicate un-namespaced global `renderReview()` with different signatures; accidental `twig()`/`twigEnv()` naming split — latent redeclaration-fatal landmine |
| LOW-MEDIUM | srp | `public/intake_upload.php:157-241` | 6 free functions incl. 2 Twig-rendering functions inline |
| LOW | srp | `Controller/IngestController.php:134-162` | `addManualLabField()` contains 3 validation rules despite the class's own "owns no business logic" docblock |
| LOW | dry | Correlation-id minting, 4 sites | 3 sites use `Uuid::uuid7()`; `IngestController::newCorrelationId()` uses a distinct `'ingest-' . bin2hex()` scheme — unify the 3, confirm intent before touching the 4th |
| LOW | dry | `ChatFreshnessChecker.php:55-69` | 3 digest-input constants duplicated from `SynthesisReadPath` by convention, not construction — self-acknowledged, related to bug 1.5 |
| LOW | complexity | `ReadPath/DocViewModel.php` | 776-line static-only class, 7+ view-shaping responsibilities; not urgent, pure/isolated-tested |
| LOW | readability | `Verify/VerifiedGeneration.php:180-193` vs `Chat/ChatAgent.php:209-222` | `formatFindings()` duplicated verbatim |
| LOW | dry | `Reduce/PromptAssembler.php:185-199` vs `Chat/ChatPromptAssembler.php:172-186` | `patientBlock()` duplicated verbatim |
| LOW | dry | `Chat/ChatTurnStore.php:188-193` vs `Chat/ChatSessionStore.php:212-217` | `parseDateTime()` duplicated verbatim |
| LOW | readability | `Chat/ChatAgent.php:109,166` | `[cause: ...]` debug suffix on user-visible degrade message, self-marked "TEMP (QA), remove once understood" |
| LOW | readability | `Observability/AlertName` (`meaning()` docstring) | `P95Latency` silently reports a different metric during the 8-9am window; documentation-only fix is safe, renaming the alert is not |
| LOW | dry | `Observability/LlmCostEstimate.php` | `FALLBACK_RATE` duplicates `gemini-2.5-pro` pricing verbatim, can silently drift — informational |
| LOW | efficiency+dry | `Observability/MetricsService.php::errorRate()` | Redundant DB round-trip re-running `errorCount()`'s own query |
| LOW | readability | `Observability/QaReviewer::tally()` | 4-int accumulator threaded through match-returning-tuple, transposition risk |
| LOW | complexity | `Lab/LabRowProcessor.php:69,116-122,153,182-191` | 5-key ad-hoc array shape requiring 6 inline `@var` re-narrowing casts |
| LOW | complexity | `Ingest/ExtractionSchema.php:38-52,220-233` | Schema JSON re-read/re-parsed from disk on every call, no per-request caching |
| LOW | readability | `Controller/ChatController.php:797-798`, `DocController.php:233-234` | Inline `(string)(...)` casts instead of `is_string()` narrowing per CLAUDE.md's "narrow, don't cast" |
| LOW | complexity | `ReadPath/TraceSpan.php` | `$parentSpanId` always passed `null` at every construction site — unused capability, not wrong |
| LOW | dead code | `public/evidence.php:26` | Unused `$session` assignment — not fixed per instruction (session-touching changes never mechanical) |
| LOW | audit-integrity | `public/dashboard.php:133` | `$correlationId` from `$_GET` interpolated into an audit-log message unvalidated, unlike `$currentSite` a few lines later which is charset-validated first |
| — | informational (not a defect) | `ReadPath/SynthesisDocPayload.php:112,129` | Catches `\InvalidArgumentException\|\DomainException` instead of `\Throwable` — reviewer judged this arguably *more* correct here; recommend blessing explicitly as an intentional CLAUDE.md exception rather than "fixing" it |

---

## Already addressed in this pass (for reference — not open)

The following 14 safe, behavior-preserving fixes were already applied directly to the codebase and verified via `php -l` + manual diff review; they are excluded from the sections above:

1. XSS fix on `dashboard.html.twig:406` (`span.error_detail` now `|text`-escaped) — see `SECURITY.md` finding #5
2. `Verdict::toArray()` added; 3 duplicated inline closures replaced
3. `ScheduledPatientListReader` row-hydration deduped into `hydrateRow()`
4. `collapseSpaces()` deduped into a new `TextNormalizer` class
5. `MetricsService` now injects `UiEventStore` instead of hand-rolled queries
6. `MetricsService::qaBooleanRate()` added; `reviewerConcurrenceRate`/`salienceScore` are thin wrappers
7. `CadenceConfigStore::get()` added; `AlertEvaluator`'s 2 call sites use it (ReadyCheck/QaReviewer/WorkerTick's identical pattern deliberately left untouched — still open, see appendix)
8. `AlertEvaluator::intColumn`/`stringColumn` generalized via a shared `column()` helper
9. `LlmReachabilityProbe.php` redundant `catch (GuzzleException|\Throwable)` simplified to `catch (\Throwable)`
10. `RateMath::average()` got its missing `@param` docblock
11. `extraction_review.php:91` logging context fixed to `['exception' => $e]`
12. Missing `use` imports added in `IngestController.php` and `DocController.php`
13. `MedNameResolver.php`'s `@package` docblock typo fixed
14. Dead unreachable fallback branch removed in `FailoverLlmClient.php` and `FailoverChatLlmClient.php`
