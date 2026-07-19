# End-to-end demo (`ops/demo/`)

`run-demo.php` drives the whole Week 2 flow as one narrated run — **no dev
stack, no DB, no network, no OpenEMR core** — and writes the console output to
`transcript.txt` so the run is reviewable without re-executing it.

It exists to answer the specific gap that the Week 2 demo *did not* show
verification, observability metrics, or eval results. This run shows all three,
plus ingestion→extraction→citation, guideline retrieval, and measured cost.

```bash
php ops/demo/run-demo.php
```

## What it demonstrates, in order

1. **Ingest + extract** — a scanned lab PDF (stub VLM) becomes strict-schema,
   per-field-cited typed facts (FR-1/2/3/4); a malformed document is correctly
   **refused** at the schema gate (safe_refusal, no partial write).
2. **Verify** — the read-path discipline: a grounded claim set passes V1–V6; a
   wrong-patient claim trips **V3 (sev-1, chat frozen)**; an uncited lab value
   is caught by **V2**. This is the "verification" the demo was missing.
3. **Retrieve** — hybrid RAG returns cited guideline evidence, kept separate
   from patient-fact citations (FR-8).
4. **Observability** — the metric bag + full alert evaluation for a healthy and
   an incident trace population (via `../load/bench/dashboard-demo.php`), with
   four alerts firing under the incident. Rendered dashboards land in
   `../load/bench/results/dashboard-{healthy,incident}.html`.
5. **Eval gate** — the 54-case boolean-rubric HARD GATE (`../eval/run-evals.php`).
6. **Cost** — measured per-call cost tied to the real prompt sizes
   (`../load/bench/measure-tokens.php`).

The full-stack variant (driving the real UI through Selenium against the dev
stack) is described in the top-level `README.md` / `CLAUDE.md`; this script is
the stack-free version that runs anywhere and produces a durable transcript.

## Live multi-agent endpoint (deployed stack)

The supervisor graph itself is wired into the running app at
`public/agent.php` — the live counterpart of step 3 above, and the fastest
way to show supervisor routing with logged handoffs on the deployed demo:

1. **Hit the endpoint** — run `06 - Week 2 Agent / 1 - Ask the Agent` in the
   Bruno collection (`ops/bruno/`, after `00 - Auth Bootstrap`), or POST
   `pid`, `question` (optionally `tags`, and `doc_type` + `document` to also
   exercise the intake-extractor worker) with `csrf_token_form` to
   `public/agent.php`. The JSON response carries `routed` (which workers the
   supervisor invoked, in order), the critic-gated `claims` + `verdicts` —
   every clinical claim citing the chart facts it is grounded in
   (`citation_ids`) — separate cited guideline `evidence`, and a
   `correlation_id`.
2. **Show the handoff tree** — open
   `public/dashboard.php?correlation_id=<that id>` (admin ACL): the span
   waterfall shows the `supervisor` root span, one `worker` child per routed
   worker (evidence retrieval, and intake extraction when a document was
   attached), and the critic's `verify` span — the whole
   supervisor → worker(s) → critic handoff graph reconstructed from the one
   correlation id.
3. **Show the hard gate** — a question the model cannot ground (or a draft
   with an uncited/unsafe claim) comes back as
   `answer_status: "refused"` with the module's standard refusal message and
   the failing verdicts on record; the rejected draft's text never surfaces.
