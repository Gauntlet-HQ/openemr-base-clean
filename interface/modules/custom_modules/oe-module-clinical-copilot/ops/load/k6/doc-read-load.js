// Clinical Co-Pilot -- synthesis read load test (R8-R9, ARCHITECTURE.md
// §3.6). Hits `GET doc.php?pid=<pid>` (the warm read path -- U8, no LLM
// call on a cache hit, I2) repeatedly at a fixed VU count and records
// p50/p95/p99 + error rate.
//
// Run at BOTH required concurrency levels and keep both summaries:
//   k6 run --vus 10 --duration 3m -e BASE_URL=https://localhost:9300 doc-read-load.js
//   k6 run --vus 50 --duration 3m -e BASE_URL=https://localhost:9300 doc-read-load.js
//
// Env vars (all optional, see lib.js for defaults): BASE_URL, SITE,
// USERNAME, PASSWORD, PIDS (comma-separated pool of seeded pids).
//
// This measures the READ path only (a doc already computed/warmed by U9's
// worker for each pid) -- it intentionally never POSTs `action=regenerate`,
// since that would force every VU's every iteration onto the cold
// reduce+verify path and this script would then be measuring LLM latency
// under load, not the read path's own DB/render cost. Cold-path timing is
// captured once, unloaded, by ../baseline/capture-baseline.sh -- that is
// the correct place for it (§3.6: baseline is a single-request snapshot;
// this script is the concurrent-load measurement).

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { pidForVu, docUrl, loginAndGetCsrf } from './lib.js';

export const options = {
  thresholds: {
    // Placeholders per ARCHITECTURE.md §1.3 ("a placeholder until R8
    // baselines replace it") -- tighten these once RESULTS.md has real
    // numbers for this stack's hardware.
    http_req_failed: ['rate<0.01'],
    doc_read_duration: ['p(95)<3000'],
  },
};

const docReadDuration = new Trend('doc_read_duration', true);

// Per-VU session cache (see lib.js's doc comment: k6 gives each VU its own
// copy of this module, so a module-level `let` persists across that VU's
// iterations without re-logging-in every request).
let session = null;
let pid = null;

export default function () {
  if (session === null) {
    pid = pidForVu(__VU);
    session = loginAndGetCsrf(pid);
  }

  const res = http.get(docUrl(pid), { jar: session.jar, tags: { name: 'doc_read' } });
  docReadDuration.add(res.timings.duration);

  check(res, {
    'doc.php status is 200': (r) => r.status === 200,
    'renders the synthesis page': (r) => r.body && r.body.includes('Pre-Visit Synthesis'),
  });

  sleep(1); // a physician does not hammer refresh; paces load to something realistic
}
