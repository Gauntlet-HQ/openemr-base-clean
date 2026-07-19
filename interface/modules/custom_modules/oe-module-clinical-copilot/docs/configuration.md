# Clinical Co-Pilot — LLM credential / config surface

This module talks to exactly one external service: Google's Gemini models,
via either of two providers. Which provider (if any) gets used is decided
**entirely by environment variables** — there is no code path that needs to
change when real credentials show up. This file is the complete list of
those variables.

See `docs/build-notes.md` ("LLM platform", T18) and
`../../../../docs/clinical-copilot-tradeoffs.md` (T18, T23) for the decision
record behind this design. This file is the operational reference: every
variable, what it does, and a copy-pasteable example.

## The two providers

| | Vertex AI (production) | Gemini API key (dev/test) |
|---|---|---|
| **Status** | The only production, HIPAA-eligible path (T18) | Convenience fast-path for exercising the narrated experience with synthetic data (T23) |
| **Auth** | GCP service account via Application Default Credentials (ADC) — no key in config | A single API key (Google AI Studio), read from an env var |
| **BAA** | Yes, under a GCP BAA | **No BAA. Not HIPAA-eligible.** |
| **PHI** | Synthetic patients only this phase (OPEN-1) | **Synthetic data ONLY — never real PHI, ever** |
| **Client classes** | `Reduce\VertexLlmClient`, `Chat\Llm\VertexChatLlmClient` | `Reduce\GeminiApiLlmClient`, `Chat\Llm\GeminiApiChatLlmClient` |

> ⚠️ **The API-key path is synthetic-data-only.** There is no Business
> Associate Agreement covering traffic sent to the Google AI Studio consumer
> API. `CLINICAL_COPILOT_GEMINI_API_KEY` **must never be set on any
> deployment, environment, or database that contains real patient data.**
> If you are not certain a given OpenEMR instance is 100% synthetic data,
> do not set this variable — leave the module in its default "unavailable /
> facts-only" state instead. Vertex AI (with a signed BAA) is the only path
> approved for anything resembling production or real PHI.

## Environment variables

All variables are read once per request via `getenv()` by
`src/ReadPath/LlmClientFactory.php` and `src/Chat/Llm/ChatLlmClientFactory.php`
(and, transitively, by the QA sweep's Flash reviewer, which reuses the same
factory-selected client — see "Selection precedence" below). None are read
anywhere else, and none are ever logged.

**Local dev-only fallback:** `LlmEnv::getString()` (`src/Config/LlmEnv.php`)
checks `getenv()`, then `$_SERVER`, then `$_ENV`, and — only for a key not
already set by one of those three — lazy-loads `ops/local/gemini.local.env`
(see `ops/local/gemini.local.env.example`) once per request and merges any
keys found there in via `putenv()`. This fallback can only *fill a gap*, never
override a variable already set through the normal three sources, but it is
easy to forget a stale `ops/local/gemini.local.env` is present in a dev image
and be confused about where a value is coming from — if a variable's value is
a mystery, check that file before anything else.

| Variable | Required for | Description | Example |
|---|---|---|---|
| `CLINICAL_COPILOT_GCP_PROJECT_ID` | Vertex (production) | GCP project id hosting the Vertex AI endpoint. Setting this is what turns Vertex on. | `CLINICAL_COPILOT_GCP_PROJECT_ID=my-clinic-prod-123456` |
| `CLINICAL_COPILOT_GCP_LOCATION` | Vertex (production, optional) | Vertex AI region. Defaults to `us-central1` if unset. Only consulted when a project id is set. | `CLINICAL_COPILOT_GCP_LOCATION=us-east4` |
| `GOOGLE_APPLICATION_CREDENTIALS` | Vertex (production) | Standard ADC variable (`google/auth`, not module-specific) — path to a service-account JSON key file. Required by Vertex's ADC resolution; the module never reads this itself. | `GOOGLE_APPLICATION_CREDENTIALS=/etc/openemr/gcp-copilot-sa.json` |
| `CLINICAL_COPILOT_GEMINI_API_KEY` | Gemini API key (dev/test) | Google AI Studio API key. Setting this (with no Vertex project id set) turns the dev/test fast-path on. **Synthetic-data-only — see warning above.** | `CLINICAL_COPILOT_GEMINI_API_KEY=AIza...` |
| `CLINICAL_COPILOT_GEMINI_API_MODEL` | Gemini API key (dev/test, optional) | Model id for synthesis + chat when using the API-key path. Defaults to `gemini-2.5-pro` (the tier that reliably passes the V1-V6 verifier; Flash degraded nearly every turn). Vertex keeps `gemini-2.5-pro` regardless. Set this to `gemini-2.5-flash` only to trade verification pass-rate for free-tier cost. Folded into `prompt_version` via {@see \OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig}. | `CLINICAL_COPILOT_GEMINI_API_MODEL=gemini-2.5-flash` |
| `CLINICAL_COPILOT_GEMINI_API_KEY_BACKUP` | Gemini API key (optional backup) | A **second** Google AI Studio key, used only when a call on the primary key fails (bad/expired key, quota exhaustion, transient provider/transport error). On failure the request fails over to this key before degrading to the facts-only path; if it also fails, degradation proceeds as normal. Only the API-key path uses it (Vertex is unaffected); a value equal to the primary is ignored. Applies to both synthesis and chat. See {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\FailoverLlmClient}. | `CLINICAL_COPILOT_GEMINI_API_KEY_BACKUP=AIza...second-key...` |
| `CLINICAL_COPILOT_WORKER_LLM_ENABLED` | Background worker (optional) | When `true`, the `clinical_copilot_worker` cron may call Gemini for pre-visit warm, QA sweep, and QA-driven reruns. **Defaults to off** — narration runs on user-facing doc/chat/regenerate only. Set `true` only when you want headless pre-warm in production. | `CLINICAL_COPILOT_WORKER_LLM_ENABLED=true` |
| `CLINICAL_COPILOT_TELEMETRY_RETENTION_DAYS` | Telemetry retention (optional) | Age (whole days) past which the `clinical_copilot_worker` cron prunes observability telemetry — `mod_copilot_trace`, its payload sidecar, UI-event pings, and QA verdicts (never chart, config, chat, or ingestion tables). **Defaults to `3`.** Clamped to a minimum of 1 day; blank/garbage/negative values fall back to the default, so it can never delete just-written rows. See {@see \OpenEMR\Modules\ClinicalCopilot\Observability\TelemetryRetention}. | `CLINICAL_COPILOT_TELEMETRY_RETENTION_DAYS=7` |
| `CLINICAL_COPILOT_MAX_ACTIVE_SESSIONS_PER_USER` | Rate limit (optional) | Max concurrent chat sessions one clinician may hold. **Default `3`.** | `CLINICAL_COPILOT_MAX_ACTIVE_SESSIONS_PER_USER=10` |
| `CLINICAL_COPILOT_MAX_TURNS_PER_USER_PER_HOUR` | Rate limit (optional) | Max chat turns per clinician per rolling hour. **Default `60`.** | `CLINICAL_COPILOT_MAX_TURNS_PER_USER_PER_HOUR=120` |
| `CLINICAL_COPILOT_DAILY_LLM_SPEND_CAP_USD` | Cost cap (optional) | Per-site daily LLM spend cap (USD); tripping it opens the circuit breaker. **Default `50`.** | `CLINICAL_COPILOT_DAILY_LLM_SPEND_CAP_USD=100` |
| `CLINICAL_COPILOT_HOURLY_LLM_BURN_CAP_USD` | Cost cap (optional) | Per-site hourly LLM burn cap (USD). **Default `10`.** | `CLINICAL_COPILOT_HOURLY_LLM_BURN_CAP_USD=25` |
| `CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL` | Knowledge store (optional) | Connection URL for the **separate, PHI-free** medical-knowledge Postgres the summarizer's RAG retrieves from. Unset ⇒ the module runs on the in-repo offline corpus. **Never point this at a database holding PHI** — see `docs/knowledge-base.md`. | `CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL=postgresql://user:pass@host:5432/knowledge?sslmode=require` |
| `CLINICAL_COPILOT_KNOWLEDGE_DB_HOST` / `_PORT` / `_NAME` / `_USER` / `_PASSWORD` / `_SSLMODE` | Knowledge store (optional) | Discrete equivalent of the URL above, for platforms that expose parts rather than a URL. `_PORT` defaults `5432`, `_SSLMODE` defaults `prefer`. The URL wins if both are set. | `CLINICAL_COPILOT_KNOWLEDGE_DB_HOST=host ...` |
| `CLINICAL_COPILOT_KNOWLEDGE_TABLE` | Knowledge store (optional) | Table name in the knowledge DB. **Default `guideline_chunks`.** Must be a bare (optionally schema-qualified) identifier. | `CLINICAL_COPILOT_KNOWLEDGE_TABLE=guideline_chunks` |
| `CLINICAL_COPILOT_KNOWLEDGE_DB_WRITE_USER` / `_WRITE_PASSWORD` | Knowledge ingestion (optional) | A dedicated INSERT-capable role for the ingestion (Maintenance → Knowledge Base) path. Retrieval always uses the plain read user and only SELECTs; set these to grant that read user SELECT-only and keep write privilege on a separate credential. Blank ⇒ ingestion reuses the read user. | `CLINICAL_COPILOT_KNOWLEDGE_DB_WRITE_USER=kb_writer` |
| `CLINICAL_COPILOT_KNOWLEDGE_EMBED_MODEL` | Vector search (optional) | Gemini embedding model for the pgvector "vector db." Uses the same `CLINICAL_COPILOT_GEMINI_API_KEY`. **Default `gemini-embedding-001`** (Matryoshka; the module requests a 1536-dim slice). With no key, retrieval runs on full-text search alone. | `CLINICAL_COPILOT_KNOWLEDGE_EMBED_MODEL=gemini-embedding-001` |
| `CLINICAL_COPILOT_KNOWLEDGE_EMBED_DIM` | Vector search (optional) | Embedding dimensionality. **Default `1536`** (under pgvector's 2000-dim HNSW ceiling for the standard `vector` type). MUST match the `vector(N)` column width in `schema.sql` — changing it requires recreating the column **and** re-embedding every row. | `CLINICAL_COPILOT_KNOWLEDGE_EMBED_DIM=1536` |

> The four caps above follow the precedence **env var → seeded DB config row
> (`mod_copilot_cadence`) → built-in default**. A blank, zero, negative, or
> non-numeric value is ignored (falls back), so a mis-set variable can never
> silently disable a cap. The finer breaker knobs (error threshold, window,
> cooldown, requests/min, per-tick worker budget) remain in the DB config row.

None of these are set in this environment by default — the module ships
configured to degrade cleanly (see below).

### Model selection (API-key path)

`LlmRuntimeConfig::reduceAndChatModel()` centralizes the model string synthesis
and chat pass to the LLM:

- **Vertex** (`CLINICAL_COPILOT_GCP_PROJECT_ID` set) → `gemini-2.5-pro`
- **API key** → `gemini-2.5-pro` by default (only the Pro tier reliably
  produces verifier-passing claims; Flash degraded nearly every turn),
  overridable to Flash for cost via `CLINICAL_COPILOT_GEMINI_API_MODEL`

The chosen model is folded into `prompt_version` (a digest input), so changing
`CLINICAL_COPILOT_GEMINI_API_MODEL` invalidates cached docs the same way a
prompt change would.

The advisory QA reviewer (`Observability\Qa\FlashReviewer`) always requests
`gemini-2.5-flash` independently — it reuses whichever client
`LlmClientFactory::create()` returned but passes its own model on each
`PromptRequest`.

## Selection precedence

Both factories (`LlmClientFactory` for synthesis, `ChatLlmClientFactory` for
chat) apply the **same three-way precedence, checked in this order,
production first:**

1. **`CLINICAL_COPILOT_GCP_PROJECT_ID` set** (with `..._GCP_LOCATION`
   optional, defaulting to `us-central1`) → construct the Vertex client
   (`VertexLlmClient` / `VertexChatLlmClient`). Production, ADC,
   HIPAA-eligible under a BAA.
2. **else `CLINICAL_COPILOT_GEMINI_API_KEY` set** → construct the Gemini
   API-key client (`GeminiApiLlmClient` / `GeminiApiChatLlmClient`). Dev/test
   only, synthetic data only, no BAA.
3. **else** → construct the Unavailable client (`UnavailableLlmClient` /
   `UnavailableChatLlmClient`). This is the default in an unconfigured
   environment (including this one): synthesis renders facts-only and chat
   becomes a facts browser (I6/I11) — no unverified prose is ever rendered.

**Vertex always wins when both are configured.** There is never a scenario
where an operator has to choose between the two live credentials; the only
real choice is "has production been set up yet."

The QA reviewer (`Observability\Qa\FlashReviewer`, `gemini-2.5-flash`) does
not have its own factory or its own env vars — it is constructed with
whatever client `LlmClientFactory::create()` already returned
(`QaReviewer::createDefault()`), so it follows the exact same precedence
automatically. The model string it requests (`gemini-2.5-flash`) is a
`PromptRequest` field, not a client-construction concern, so one factory
serves every model.

## Copy-pasteable examples

**Production (Vertex AI, real deployment, PHI-eligible under a signed BAA):**

```bash
export CLINICAL_COPILOT_GCP_PROJECT_ID="my-clinic-prod-123456"
export CLINICAL_COPILOT_GCP_LOCATION="us-central1"
export GOOGLE_APPLICATION_CREDENTIALS="/etc/openemr/gcp-copilot-sa.json"
```

**Dev/test (Gemini API key, synthetic patients only — see warning above):**

```bash
export CLINICAL_COPILOT_GEMINI_API_KEY="AIza...your-ai-studio-key..."
# Model defaults to gemini-2.5-pro (the tier that passes the V1-V6 verifier).
# Uncomment only to trade verification pass-rate for free-tier cost:
# export CLINICAL_COPILOT_GEMINI_API_MODEL="gemini-2.5-flash"
```

**Default (nothing set — the default in this environment):**

```bash
# No CLINICAL_COPILOT_* variables set at all.
# Synthesis renders facts-only; chat is a facts browser. No LLM call is
# ever attempted; UnavailableLlmClient / UnavailableChatLlmClient are used.
```

## Verifying the degraded default

With nothing set, both factories return their `Unavailable*` implementation,
which throws `LlmUnavailableException::noCredentials()` on the very first
call rather than returning a partial/empty result — this is I6's "degrade
cleanly" contract, and it is exercised by
`tests/Isolated/Chat/LlmUnavailableFactsBrowserTest.php` and
`tests/Isolated/Reduce/ReducerDegradationTest.php`.
`tests/Isolated/ReadPath/LlmClientFactorySelectionTest.php` and
`tests/Isolated/Chat/Llm/ChatLlmClientFactorySelectionTest.php` cover the
selection logic itself (all three branches, both factories, via `putenv()`).
