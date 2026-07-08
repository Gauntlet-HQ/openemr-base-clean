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

| Variable | Required for | Description | Example |
|---|---|---|---|
| `CLINICAL_COPILOT_GCP_PROJECT_ID` | Vertex (production) | GCP project id hosting the Vertex AI endpoint. Setting this is what turns Vertex on. | `CLINICAL_COPILOT_GCP_PROJECT_ID=my-clinic-prod-123456` |
| `CLINICAL_COPILOT_GCP_LOCATION` | Vertex (production, optional) | Vertex AI region. Defaults to `us-central1` if unset. Only consulted when a project id is set. | `CLINICAL_COPILOT_GCP_LOCATION=us-east4` |
| `GOOGLE_APPLICATION_CREDENTIALS` | Vertex (production) | Standard ADC variable (`google/auth`, not module-specific) — path to a service-account JSON key file. Required by Vertex's ADC resolution; the module never reads this itself. | `GOOGLE_APPLICATION_CREDENTIALS=/etc/openemr/gcp-copilot-sa.json` |
| `CLINICAL_COPILOT_GEMINI_API_KEY` | Gemini API key (dev/test) | Google AI Studio API key. Setting this (with no Vertex project id set) turns the dev/test fast-path on. **Synthetic-data-only — see warning above.** | `CLINICAL_COPILOT_GEMINI_API_KEY=AIza...` |
| `CLINICAL_COPILOT_GEMINI_API_MODEL` | Gemini API key (dev/test, optional) | Model id for synthesis + chat when using the API-key path. Defaults to `gemini-2.5-flash` (free-tier friendly). Vertex keeps `gemini-2.5-pro` regardless. Folded into `prompt_version` via {@see \OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig}. | `CLINICAL_COPILOT_GEMINI_API_MODEL=gemini-2.5-flash` |
| `CLINICAL_COPILOT_WORKER_LLM_ENABLED` | Background worker (optional) | When `true`, the `clinical_copilot_worker` cron may call Gemini for pre-visit warm, QA sweep, and QA-driven reruns. **Defaults to off** — narration runs on user-facing doc/chat/regenerate only. Set `true` only when you want headless pre-warm in production. | `CLINICAL_COPILOT_WORKER_LLM_ENABLED=true` |

None of these are set in this environment by default — the module ships
configured to degrade cleanly (see below).

### Model selection (API-key path)

`LlmRuntimeConfig::reduceAndChatModel()` centralizes the model string synthesis
and chat pass to the LLM:

- **Vertex** (`CLINICAL_COPILOT_GCP_PROJECT_ID` set) → `gemini-2.5-pro`
- **API key** → `gemini-2.5-flash` by default, overridable via
  `CLINICAL_COPILOT_GEMINI_API_MODEL`

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
export CLINICAL_COPILOT_GEMINI_API_MODEL="gemini-2.5-flash"
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
