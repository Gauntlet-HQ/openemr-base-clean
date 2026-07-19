# AgentForge — Clinical Co-Pilot

> A **fork of [OpenEMR](https://open-emr.org)** that embeds a production-minded
> **Clinical Co-Pilot** — an AI agent that gives an outpatient endocrinologist
> a fast, cited, pre-visit synthesis of each patient on today's schedule, plus a
> multi-turn chat pinned to that patient. **The LLM narrates and navigates; it
> never extracts.** Every clinical fact is pulled, parsed, and cited by
> deterministic PHP, then verified before it ever reaches the physician.
>
> Built for the Gauntlet AI — Austin Admission Track (AgentForge case study).

**🚀 Live deployment:** https://abundant-art-production-d560.up.railway.app
&nbsp;·&nbsp; login `admin` / `pass` &nbsp;·&nbsp; *synthetic patients only (no real PHI)*

---

## What this is

A physician has ~90 seconds between patient rooms to recall who they're seeing,
what changed, and what matters today. The Clinical Co-Pilot turns the four-tab
chart shuffle (labs, meds, vitals, last note) into one prioritized, cited
synthesis per patient — and answers follow-ups in a patient-pinned chat.

Two surfaces over one deterministic fact layer:

1. **Pre-visit synthesis** — a pre-warmed, cited summary per scheduled patient:
   what changed, whether control is on target, whether the regimen is working,
   what's overdue, what's in flight — prioritized.
2. **Patient-pinned chat** — a multi-turn agent seeded with the exact facts and
   narrative the physician is reading, answering drill-downs by invoking the
   same deterministic capabilities as read-only, patient-pinned tools.

The engineering thesis: an LLM reading a semantically-untyped EHR directly will
hallucinate values, units, and stale data. So the model never touches raw rows.
Five deterministic PHP capabilities produce **typed facts with citations**; the
model only writes prose over facts it is handed and *requests* which capability
to run next. A deterministic **verification gate** then checks every claim
(citation resolves, number grounded, right patient, no banned clinical claims)
before any prose is shown. See the architecture docs below for why.

## The project deliverables (read in this order)

These four documents are the case-study hard gates. Each traces to the next.

| Document | What it is |
|---|---|
| **[AUDIT.md](AUDIT.md)** | Code-level pre-build audit of the OpenEMR fork — security, performance, architecture, data-quality, and compliance/HIPAA findings, each cited to `file:line`, with a one-page summary. This is *why* the agent is built the way it is. |
| **[USERS.md](USERS.md)** | The one deliberately narrow target user (an outpatient endocrinologist), her workflow, and use cases UC1–UC6 — each with an explicit "why an agent" answer. The source of truth every capability traces back to. |
| **[ARCHITECTURE.md](ARCHITECTURE.md)** | The agent design: chat (§1), verification (§2), observability (§3), authorization/PHI/trust boundaries (§4), evaluation (§5), and the failure model (§6), with a one-page high-level summary and a case-study compliance map. |
| **[ARCHITECTURE_COMPLETE.md](ARCHITECTURE_COMPLETE.md)** · **[docs/clinical-copilot-tradeoffs.md](docs/clinical-copilot-tradeoffs.md)** | Companions: the complete build spec (fact schema, lab contract, module tables, build units) and the decision record (T1–T22, with rejected alternatives). |

## Where the agent lives

The co-pilot is one **additive** OpenEMR custom module — no core files changed:

```
interface/modules/custom_modules/oe-module-clinical-copilot/
```

It has its own [module README](interface/modules/custom_modules/oe-module-clinical-copilot/README.md)
covering install/enable/uninstall, the real endpoint URLs, ACL sections, the
worker cron requirement, the SELECT-only MySQL user recommendation, the test
suites, the CI additivity gates, and the ops artifacts (Bruno collection,
load/baseline harness, [AI cost analysis](interface/modules/custom_modules/oe-module-clinical-copilot/ops/cost-analysis.md)).

## Week 2 — Multimodal Evidence Agent

Everything above describes the **Week 1 baseline**: a strictly read-only
co-pilot — deterministic PHP pulls and cites chart facts, the LLM only
narrates, and nothing is ever written to the chart. **Week 2 adds the inverse
path**, still inside the same additive module:

- **Document ingestion** for three patient-attached types (`lab_pdf`,
  `intake_form`, `medication_list`) with strict JSON schemas, an
  `insert → verify → lock` human-review lifecycle, and per-field
  page/quote/**bbox** citations rendered as a click-to-source overlay —
  plus a fourth, knowledge-corpus type for guideline documents.
- **Audited chart write-back** through a single sanctioned writer
  (`ChartWriter`), enforced by a module-scoped PHPStan rule.
- **Hybrid RAG** over a PHI-free endocrinology guideline corpus — sparse
  TF-IDF fused with optional dense (pgvector) retrieval, then reranked.
- **Agent graph** behind `public/agent.php` — a deterministic supervisor
  routing to intake-extractor and evidence-retriever workers, with a critic
  stage that runs the V1–V6 verifier over every drafted answer.
- **Eval gate** — a 50-case golden set with boolean rubrics, PR-blocking in CI.
- **Observability** — a 4-level trace waterfall and per-doc-type extraction
  accuracy on the dashboard.

Design docs: **[W2_PRD.md](W2_PRD.md)** (what/why, acceptance criteria,
milestones) and **[W2_ARCHITECTURE.md](W2_ARCHITECTURE.md)** (how), plus the
module's [data model / lineage reference](interface/modules/custom_modules/oe-module-clinical-copilot/docs/W2_DATA_MODEL.md)
and [backup & recovery plan](interface/modules/custom_modules/oe-module-clinical-copilot/docs/W2_BACKUP_RECOVERY.md).
The API surface is specified in the module's `ops/api/openapi.yaml` with a
runnable [Bruno collection](interface/modules/custom_modules/oe-module-clinical-copilot/ops/bruno/).

**Which branch to check out.** Week 2 is finalized on **`FINAL_REVIEW`**
(also carried by the `claude/clinical-copilot-week-2-*` working branches) —
that is the branch a grader should check out. Honest caveat: the Railway
deploy fetches branch **`main`** (see the module's
[`docs/HANDOFF.md`](interface/modules/custom_modules/oe-module-clinical-copilot/docs/HANDOFF.md)),
so the live URL above reflects Week 2 only as far as `main` has been
fast-forwarded to it — when in doubt, trust `FINAL_REVIEW` plus a local
stack over the deployed app.

**Week 2 entry points** — all module pages under
`interface/modules/custom_modules/oe-module-clinical-copilot/public/`,
session-authenticated (CSRF + ACL) unless noted:

| Surface | Page | Where it appears in the UI |
|---|---|---|
| Intake upload (creates the patient) | `intake_upload.php` | "Co-Pilot Intake Upload" under both the Reports and Patient top menus |
| Lab upload / manual entry | `lab_upload.php?pid=<pid>` | the "Labs (Co-Pilot)" patient-chart tab |
| Medication-list upload | `medication_upload.php?pid=<pid>` | direct URL only (no menu item yet); extract + review only — locking never writes the chart's medication tables |
| Knowledge (guideline) upload | `knowledge_upload.php` | "Co-Pilot Maintenance" top menu, admin-gated |
| Extraction review (verify → lock) | `extraction_review.php?extraction_id=<id>` | redirect target of every upload above |
| Agent run (supervisor + workers + critic) | `agent.php` | POST-only JSON API (OpenAPI spec + Bruno collection above) |
| Guideline evidence panel | `evidence.php?pid=<pid>` | the "Guideline Evidence" patient-chart tab |
| Dashboard (trace waterfall, accuracy) | `dashboard.php` | admin-gated |
| Liveness / readiness | `health.php` / `ready.php` | unauthenticated probes |

**Environment variables.** The complete table (every variable, default, and a
copy-pasteable example) is the module's
[`docs/configuration.md`](interface/modules/custom_modules/oe-module-clinical-copilot/docs/configuration.md).
The no-key default described in "Setup" below extends to Week 2: with nothing
configured, vision extraction degrades to a blank draft the reviewer completes
by hand, and retrieval runs sparse-only over the committed in-repo corpus —
no flow dead-ends.

**Eval gate.** Run it locally — deterministic, no model or DB required:

```bash
php interface/modules/custom_modules/oe-module-clinical-copilot/ops/eval/run-evals.php
```

CI runs the same gate PR-blocking in
[`.github/workflows/w2-eval-gate.yml`](.github/workflows/w2-eval-gate.yml),
alongside the additivity gate and the module's isolated PHPUnit suite. The
golden set and baseline live in
[`ops/eval/`](interface/modules/custom_modules/oe-module-clinical-copilot/ops/eval/).

## Setup — run it locally

The dev stack is Docker-first; you need no host PHP/Node. One command brings up
OpenEMR + MySQL, installs and enables the module, seeds synthetic diabetes
patients, and prints the demo doc URLs:

```bash
interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/setup.sh
```

Then open **https://localhost:9300** (login `admin` / `pass`) and follow the
printed links, e.g. `…/oe-module-clinical-copilot/public/doc.php?pid=<pid>`.

**LLM narration is optional.** With no API key configured, synthesis renders
**facts-only** and chat is a **facts browser** — both fully usable, and the
default in this environment. To enable narration, export a key *before* running
`setup.sh` (dev/test, **synthetic data only**):

```bash
export CLINICAL_COPILOT_GEMINI_API_KEY=your_ai_studio_key   # dev/test fast-path
# ...or the Vertex AI production path (HIPAA-eligible under a BAA):
export CLINICAL_COPILOT_GCP_PROJECT_ID=your-gcp-project
export CLINICAL_COPILOT_GCP_LOCATION=us-central1
```

Full local bring-up notes:
[`ops/local/README.md`](interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/README.md)
· LLM config surface:
[`docs/configuration.md`](interface/modules/custom_modules/oe-module-clinical-copilot/docs/configuration.md).

For orienting in the base OpenEMR codebase, see [ONBOARDING.md](ONBOARDING.md)
(first-read guide) and [INDEX.md](INDEX.md) (terse repo map).

## Tests

```bash
openemr-cmd phpunit-isolated   # fast, no DB (fact/lab/verify/chat logic)
openemr-cmd clean-sweep-tests  # full DB-backed + isolated suites
openemr-cmd code-quality       # PHPStan L10, PSR-12, Rector, codespell, …
```

The module ships ~80 tests including adversarial (cross-patient forged
citations, prompt-injection), boundary (LLM-down, chain-budget exhaustion,
empty record), and invariant (wrong-number, wrong-patient) coverage. See the
module README's "Running the test suites" section for the per-suite commands.

## Deployment

The live app is deployed on **Railway** from `docker/railway/Dockerfile`
(config in [`railway.toml`](railway.toml); CI in [`.gitlab-ci.yml`](.gitlab-ci.yml)).
The final agent deploys to the same infrastructure.

- **Live URL:** https://abundant-art-production-d560.up.railway.app

---

## Built on OpenEMR

This project is a fork of [OpenEMR](https://github.com/openemr/openemr), a Free
and Open Source electronic health records and medical practice management
application. The upstream project and its community own everything under the
base application; this fork adds the Clinical Co-Pilot module and the
case-study documents above.

- **Upstream project:** https://open-emr.org · [OpenEMR on GitHub](https://github.com/openemr/openemr)
- **Contributing to upstream:** [CONTRIBUTING.md](CONTRIBUTING.md)
- **API:** [API_README.md](API_README.md) · **FHIR:** [FHIR_README.md](FHIR_README.md) · **Docker:** [DOCKER_README.md](DOCKER_README.md)
- **Security policy:** [SECURITY.md](.github/SECURITY.md)

### Building base OpenEMR from source

If using OpenEMR directly from the code repository (Node.js 24.* required):

```shell
composer install --no-dev
npm install
npm run build
composer dump-autoload -o
```

### License

[GNU GPL v3](LICENSE) — inherited from upstream OpenEMR.
