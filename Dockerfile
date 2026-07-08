# syntax=docker/dockerfile:1
# ============================================================================
# Railway deployment image for THIS OpenEMR fork (OpenEMR 8.2.0-dev / master)
# ----------------------------------------------------------------------------
# "Approach B": a self-contained, prebuilt image that layers this fork's source
# onto OpenEMR's official *flex* base image and bakes PHP + frontend
# dependencies at IMAGE-BUILD time, so container startup is fast (no runtime
# git clone, no runtime composer/npm build).
#
# Why the flex base (and not a versioned openemr/openemr:X.Y.Z tag): this fork
# tracks master (version.php reports 8.2.0-dev, DB schema v541). The published
# versioned images are older stable releases (7.0.x) whose bundled vendor/,
# assets, and DB schema do NOT match this source tree — layering onto them
# would break. The flex base carries the exact PHP 8.5 / Alpine 3.23 toolchain
# this version targets (it is the same image this repo's dev stack runs), so we
# build this fork's own dependencies on top of it.
#
# A small entrypoint (railway/entrypoint.sh) seeds the sites/ directory, runs
# OpenEMR's one-time auto-configuration against the Railway MySQL service on
# first boot, then execs Apache (PHP is served in-process by mod_php).
#
# IMPORTANT: build context MUST be the repository root.
#   docker build -t openemr-railway .
# See DEPLOY.md for the full Railway walkthrough.
# ============================================================================

# Pinned to the same flex digest this repo's docker/development-easy stack uses
# (PHP 8.5, Alpine 3.23) for a reproducible, version-matched toolchain.
# Override at build time with --build-arg OPENEMR_FLEX_IMAGE=openemr/openemr:flex
ARG OPENEMR_FLEX_IMAGE=openemr/openemr:flex@sha256:1d2c8345a320cf2985c9d02138951267739ec498559ca724b3d5397e4568482e
FROM ${OPENEMR_FLEX_IMAGE}

# Optional GitHub token to avoid Composer/npm GitHub API rate limits during the
# dependency build (pass with --build-arg GITHUB_TOKEN=ghp_...). Not persisted.
ARG GITHUB_TOKEN=

# The flex base already provides apache2 + php8.5 (+ all required extensions) +
# composer + npm + build-base + git + mariadb-client + su-exec, plus the helper
# scripts we reuse at runtime (ssl.sh and /root/devtoolsLibrary.source). We only
# add this fork's code and its built dependencies.
WORKDIR /var/www/localhost/htdocs/openemr

# Copy the fork source. Host-built vendor/, node_modules/, and .git are excluded
# via .dockerignore so we build clean, production dependencies below.
COPY . /var/www/localhost/htdocs/openemr/

RUN set -eux; \
    git config --global --add safe.directory /var/www/localhost/htdocs/openemr || true; \
    if [ -n "${GITHUB_TOKEN}" ]; then \
        composer config --global --auth github-oauth.github.com "${GITHUB_TOKEN}"; \
    fi; \
    # --- PHP dependencies (production only) --- \
    composer install --no-dev --no-interaction --optimize-autoloader --no-progress; \
    composer dump-autoload --optimize --apcu --no-interaction; \
    # --- Frontend build (webpack + sass -> public/) --- \
    npm install --unsafe-perm --no-audit --no-fund; \
    npm run build; \
    # node_modules are build-time only; mod_php serves the built public/ assets. \
    rm -rf node_modules ccdaservice/node_modules /root/.npm /root/.composer/cache /root/.composer/auth.json; \
    # --- Production file permissions (mirrors docker/release/Dockerfile) --- \
    find . -type d -exec chmod 500 {} +; \
    find . -type f -exec chmod 400 {} +; \
    chmod 666 sites/default/sqlconf.php; \
    chmod 700 sites/default; \
    if [ -d sites/default/documents ]; then \
        find sites/default/documents -type d -exec chmod 700 {} +; \
        find sites/default/documents -type f -exec chmod 600 {} +; \
    fi; \
    chown -R apache:apache .; \
    # Pristine sites/ skeleton so the entrypoint can seed an empty Railway volume \
    # mounted over sites/ (Railway volumes start empty and shadow image content). \
    cp -a sites /opt/openemr-sites-skel; \
    chown -R apache:apache /opt/openemr-sites-skel

# HTTP (80) is what Railway proxies to (TLS terminated at Railway's edge).
# 443 is exposed too; the self-signed vhost only needs to load for httpd start.
EXPOSE 80 443

COPY railway/entrypoint.sh /usr/local/bin/openemr-railway-entrypoint.sh
RUN chmod 555 /usr/local/bin/openemr-railway-entrypoint.sh

WORKDIR /var/www/localhost/htdocs
# Clear the flex base image CMD (./openemr.sh) so Railway does not inherit a
# dev-runtime startup when a custom startCommand is applied.
ENTRYPOINT ["/usr/local/bin/openemr-railway-entrypoint.sh"]
CMD []
