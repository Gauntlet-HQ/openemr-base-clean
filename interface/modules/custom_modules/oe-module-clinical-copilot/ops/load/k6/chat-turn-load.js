// Clinical Co-Pilot -- chat turn load test (R8-R9, ARCHITECTURE.md §3.6,
// §1.3, T20 vertical-first scaling). Each iteration submits ONE turn to an
// existing chat session and records its latency -- this is the expensive
// path (a synchronous LLM call inside the request, §1.3: "the request IS
// the turn", a PHP-FPM worker held for the whole turn) and therefore the
// one that determines PHP-FPM worker / RAM sizing per concurrent user
// (T20's "the load-test saturation numbers tell us the worker/RAM sizing
// per N users").
//
// Run at BOTH required concurrency levels:
//   k6 run --vus 10 --duration 5m -e BASE_URL=https://localhost:9300 chat-turn-load.js
//   k6 run --vus 50 --duration 5m -e BASE_URL=https://localhost:9300 chat-turn-load.js
//
// 50 concurrent chat VUs means 50 held PHP-FPM workers for the ~30s turn
// deadline each -- undersized `pm.max_children` will show up here as
// queuing/timeouts before the app server's own CPU saturates. Watch
// `docker stats` (PHP-FPM container) and the FPM status page (if enabled)
// alongside this run, and record both in RESULTS.md, not just k6's own
// numbers -- k6 sees client-side latency, not server-side saturation.
//
// Env vars: see lib.js. Additionally MAX_TURNS_PER_VU caps how many turns
// a single VU's session submits before the script starts a fresh session
// (chat.php enforces MAX_TURNS_PER_SESSION = 30 server-side -- see
// ChatController -- so a long k6 duration WILL hit that ceiling
// eventually; default here is a conservative 10 to re-exercise
// "start session" cost periodically too, same as a real clinic day where
// physicians open fresh sessions per patient far more often than they run
// 30 turns deep on one).

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Counter } from 'k6/metrics';
import { pidForVu, chatUrl, loginAndGetCsrf } from './lib.js';

export const options = {
  thresholds: {
    // Matches ARCHITECTURE.md §1.3's stated p95 placeholder ("15s ... a
    // placeholder until R8 baselines replace it") -- once RESULTS.md has
    // real p95 numbers for this stack, replace this with the measured
    // value plus headroom, not the design doc's a-priori guess.
    http_req_failed: ['rate<0.02'],
    chat_turn_duration: ['p(95)<15000'],
  },
};

const MAX_TURNS_PER_VU = parseInt(__ENV.MAX_TURNS_PER_VU || '10', 10);

// A small rotation of questions spanning 0/1/3-tool-call shapes (see
// ../baseline/capture-baseline.sh for why these particular questions
// exercise different tool-call counts) -- a real clinic mixes all three,
// so the load test should too rather than measuring only the cheapest or
// only the most expensive turn shape.
const MESSAGES = [
  'What is her most recent A1c?',
  'What were her labs from a year before that?',
  'Since her last medication dose change, how have her labs, vitals, and weight all trended?',
  'How does her current regimen compare to six months ago?',
];

const chatTurnDuration = new Trend('chat_turn_duration', true);
const degradedTurns = new Counter('chat_turns_degraded');
const frozenTurns = new Counter('chat_turns_frozen');

let session = null;
let pid = null;
let sessionId = null;
let turnsThisSession = 0;

function startSession() {
  const res = http.post(
    chatUrl(),
    { action: 'start', pid: String(pid), csrf_token_form: session.csrfToken },
    { jar: session.jar, tags: { name: 'chat_start' } },
  );
  const data = res.json();
  check(data, { 'session started': (d) => d && d.ok === true });
  sessionId = data && data.ok ? data.session_id : null;
  turnsThisSession = 0;
}

export default function () {
  if (session === null) {
    pid = pidForVu(__VU);
    session = loginAndGetCsrf(pid);
    startSession();
  }
  if (sessionId === null || turnsThisSession >= MAX_TURNS_PER_VU) {
    startSession();
  }
  if (sessionId === null) {
    // synthesis unavailable for this pid, or some other start failure --
    // don't spin a tight failure loop against the server.
    sleep(2);
    return;
  }

  const message = MESSAGES[turnsThisSession % MESSAGES.length];
  const res = http.post(
    chatUrl(),
    {
      action: 'turn',
      session_id: String(sessionId),
      message,
      csrf_token_form: session.csrfToken,
    },
    { jar: session.jar, tags: { name: 'chat_turn' }, timeout: '35s' },
  );
  chatTurnDuration.add(res.timings.duration);
  turnsThisSession += 1;

  const data = res.status === 200 ? res.json() : null;

  check(res, {
    'chat.php status is 200': (r) => r.status === 200,
  });
  check(data, {
    'turn completed (ok or a named degradation, never a crash)': (d) => d && d.ok === true,
  });

  if (data && data.ok) {
    if (data.verify_status === 'degraded') degradedTurns.add(1);
    if (data.frozen) {
      frozenTurns.add(1);
      sessionId = null; // frozen sessions cannot be resumed -- next iteration starts fresh
    }
  }

  sleep(2); // pacing between a physician's follow-up questions
}
