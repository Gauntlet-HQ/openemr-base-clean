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
