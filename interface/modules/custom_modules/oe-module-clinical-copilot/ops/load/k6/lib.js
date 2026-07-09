// Clinical Co-Pilot -- shared k6 helpers for the load scripts in this
// directory (R8-R9, ARCHITECTURE.md §3.6). Not a standalone test; imported
// by doc-read-load.js and chat-turn-load.js.
//
// k6 does not share JS module state across VUs (each VU gets its own copy
// of the script), so `loginAndGetCsrf()` is called once per VU on its
// first iteration and its result (cookie jar is implicit per-VU in k6's
// default http client; the csrf token is returned) is cached in a
// module-level variable for that VU's remaining iterations -- mirroring a
// physician's browser logging in once and reusing the session for the
// rest of clinic.

import http from 'k6/http';
import { check } from 'k6';

export const BASE_URL = __ENV.BASE_URL || 'https://localhost:9300';
export const SITE = __ENV.SITE || 'default';
export const USERNAME = __ENV.USERNAME || 'admin';
export const PASSWORD = __ENV.PASSWORD || 'pass';
// Comma-separated pool of seeded pids to spread load across (U2 seed:
// CCP-001..CCP-004 = pid 1..4 on a freshly seeded dev stack) -- spreading
// VUs across several patients avoids every VU hammering one row and
// exercises the digest/cache-key space more realistically than a single pid.
export const PIDS = (__ENV.PIDS || '1,2,3,4').split(',').map((s) => s.trim());

const MODULE_BASE = '/interface/modules/custom_modules/oe-module-clinical-copilot/public';

export function pidForVu(vuId) {
  return PIDS[vuId % PIDS.length];
}

/**
 * Logs in (same handler + field names as a browser -- see
 * ops/bruno/00 - Auth Bootstrap) and scrapes a CSRF token off a seeded
 * patient's doc.php render, exactly like the Bruno bootstrap folder.
 * Returns `{ jar, csrfToken }`; k6 http.CookieJar makes the session
 * cookie ride along automatically on every subsequent request that
 * passes `{ jar }` in its params.
 */
export function loginAndGetCsrf(pid) {
  const jar = http.cookieJar();

  http.get(`${BASE_URL}/interface/login/login.php?site=${SITE}`, { jar });

  http.post(
    `${BASE_URL}/interface/main/main_screen.php?auth=login&site=${SITE}`,
    {
      new_login_session_management: '1',
      languageChoice: '1',
      authUser: USERNAME,
      clearPass: PASSWORD,
      facility: 'user_default',
    },
    { jar, redirects: 5 },
  );

  const docRes = http.get(`${BASE_URL}${MODULE_BASE}/doc.php?pid=${pid}`, { jar });
  const match = docRes.body && docRes.body.match(/id="ccpChatCsrf"\s+value="([^"]*)"/);
  const csrfToken = match ? match[1] : null;

  check(csrfToken, {
    'csrf token scraped from doc.php': (t) => t !== null,
  });

  if (csrfToken === null) {
    throw new Error(
      `Could not scrape a CSRF token from doc.php for pid=${pid} -- ` +
        'login likely failed, or this pid has no seeded synthesis doc yet ' +
        '(run tests/Seed/SeedClinicalCopilot.php against this stack first).',
    );
  }

  return { jar, csrfToken };
}

export function docUrl(pid) {
  return `${BASE_URL}${MODULE_BASE}/doc.php?pid=${pid}`;
}

export function chatUrl() {
  return `${BASE_URL}${MODULE_BASE}/chat.php`;
}
