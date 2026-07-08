# Deploying this OpenEMR fork to Railway

This repo ships a Railway-ready, **self-contained image** (Approach B): the root
[`Dockerfile`](./Dockerfile) layers this fork's source onto OpenEMR's official
*flex* base and bakes PHP + frontend dependencies at build time, so containers
start fast with no runtime clone/build. A small entrypoint
([`railway/entrypoint.sh`](./railway/entrypoint.sh)) seeds `sites/`, runs
OpenEMR's one-time auto-configuration against a managed MySQL service, then
starts Apache.

> **Version note:** this fork is **OpenEMR 8.2.0-dev** (tracks `master`, DB
> schema v541). That is why the image builds from *this* source on the flex
> base rather than layering onto a versioned `openemr/openemr:7.0.x` tag — those
> stable images bundle an older `vendor/`, assets, and schema that do not match
> this tree.

---

## What you'll deploy

```
Internet ──HTTPS──► Railway edge (TLS termination) ──HTTP:80──► OpenEMR service (this image)
                                                                     │
                                                          MySQL (Railway managed service)
                                                                     │
                                                   Volume mounted at .../openemr/sites  (persists config + keys + uploads)
```

- **OpenEMR service** — built from this repo's `Dockerfile`; Apache serves on port 80 (mod_php).
- **MySQL service** — Railway's managed MySQL/MariaDB (system of record).
- **Volume** — persists `sites/` (DB config `sqlconf.php`, encryption keys, uploaded documents) across redeploys.

---

## Prerequisites

- A [Railway](https://railway.app) account and a project.
- The Railway CLI (recommended for a self-hosted GitLab origin like
  `labs.gauntletai.com`, which Railway can't auto-connect):
  ```bash
  npm i -g @railway/cli   # or: brew install railway
  railway login
  ```

---

## Step 1 — Add a MySQL service

In your Railway project: **New → Database → Add MySQL**. It provisions a MySQL
service exposing (on the project's private network) the variables
`MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD`, and
`MYSQL_ROOT_PASSWORD`.

## Step 2 — Create the OpenEMR service from this repo

**Option A — Railway CLI (works with any git host, including the labs GitLab):**

```bash
# from the repo root (this directory)
railway link            # select your project
railway up              # uploads the build context and builds the Dockerfile
```

**Option B — GitHub integration:** push this fork to a GitHub repo, then in
Railway: **New → GitHub Repo → (select it)**. Railway auto-detects the root
`Dockerfile`. (Railway's git integration targets GitHub, not self-hosted
GitLab.)

> If Railway ever fails to find the Dockerfile, set the service variable
> `RAILWAY_DOCKERFILE_PATH=Dockerfile`.

## Step 3 — Set the OpenEMR service variables

In the OpenEMR service **Variables** tab, add the following. The `${{MySQL.*}}`
references wire it to the MySQL service you created (rename `MySQL` if your
service has a different name):

| Variable | Value | Notes |
|---|---|---|
| `MYSQL_HOST` | `${{MySQL.MYSQLHOST}}` | required |
| `MYSQL_PORT` | `${{MySQL.MYSQLPORT}}` | usually `3306` |
| `MYSQL_ROOT_USER` | `root` | |
| `MYSQL_ROOT_PASS` | `${{MySQL.MYSQL_ROOT_PASSWORD}}` | required; used once to create the DB + app user |
| `MYSQL_DATABASE` | `openemr` | created on first boot |
| `MYSQL_USER` | `openemr` | app DB user, created on first boot |
| `MYSQL_PASS` | *(a strong secret)* | app DB password |
| `OE_USER` | `admin` | initial OpenEMR admin login |
| `OE_PASS` | *(a strong secret)* | initial admin password — **do not leave as `pass`** |

Optional: any `OPENEMR_SETTING_<global>=<value>` variables are applied to the
`globals` table on first configure (e.g. `OPENEMR_SETTING_rest_api=1`).

## Step 4 — Add a persistent volume for `sites/`

In the OpenEMR service: **Settings → Volumes → Add Volume**, mount path:

```
/var/www/localhost/htdocs/openemr/sites
```

This preserves the site config, encryption keys, and uploaded documents across
redeploys. The entrypoint auto-seeds this directory the first time it's empty,
so it's safe to attach before the first deploy.

## Step 5 — Expose the service

**Settings → Networking → Generate Domain.** Set the **target port to `80`**
(Apache listens on 80; Railway's edge provides HTTPS to the public domain).

## Step 6 — Deploy & log in

Deploy (or `railway up` again). Watch the logs for:

```
[entrypoint] MySQL is ready.
[entrypoint] Configuring OpenEMR (first boot)...
[entrypoint] OpenEMR configured.
[entrypoint] Starting Apache...
```

Then open your Railway domain and log in with `OE_USER` / `OE_PASS`.

---

## Loading the sample patients (optional)

Once deployed, the sample-data generator can be run inside the running service
container from the Railway shell (`railway run` / service **Shell**):

```bash
su -s /bin/sh apache -c 'php /var/www/localhost/htdocs/openemr/contrib/util/generate_sample_patients.php'
```

---

## Rebuilding for a specific flex/toolchain

The base image is pinned by digest for reproducibility. To track the latest
flex base or a different PHP/Alpine toolchain, override at build time:

```bash
docker build --build-arg OPENEMR_FLEX_IMAGE=openemr/openemr:flex -t openemr-railway .
```

To avoid GitHub API rate limits during the dependency build, pass a token:

```bash
docker build --build-arg GITHUB_TOKEN=ghp_xxx -t openemr-railway .
```

(On Railway, set `GITHUB_TOKEN` as a **build-time** variable if needed.)

---

## Troubleshooting

- **`MYSQL_HOST is required` / setup loops** — the DB variable references aren't
  resolving. Confirm the MySQL service name in the `${{...}}` references and that
  both services share the project's private network.
- **`MySQL unreachable ... after ~2m`** — the MySQL service isn't up/healthy yet,
  or the port/password is wrong. Check the MySQL service logs and variables.
- **Login works but assets look broken** — the webpack build failed during image
  build; check the build logs for the `npm run build` step.
- **Data gone after a redeploy** — the `sites/` volume (Step 4) wasn't attached,
  so encryption keys regenerated and no longer match the DB. Re-attach a volume
  and redeploy fresh, or restore the prior `sites/`.
- **First build is slow** — expected: composer + webpack run once at image build.
  Subsequent container starts are fast (deps are baked in).

---

## Security / compliance reminders

This deploys a system designed to hold **PHI**. Before using real data, review
[`AUDIT.md`](./AUDIT.md) and at minimum:

- Set strong, unique `OE_PASS`, `MYSQL_PASS`, and `MYSQL_ROOT_PASS` (never ship
  the `admin`/`pass` defaults).
- Keep the MySQL service private (no public TCP proxy) and rely on Railway's
  HTTPS edge for transport encryption.
- Treat this as a demo/staging deployment unless the HIPAA gaps documented in
  `AUDIT.md` (audit logging, at-rest encryption, BAA for any LLM provider) have
  been addressed.
