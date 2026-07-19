# Clinical Co-Pilot -- local-dev OpenEMR image with the Postgres PDO driver.
#
# OpenEMR core speaks only MySQL/MariaDB, so the stock openemr/openemr:flex image
# ships WITHOUT pdo_pgsql. The knowledge store (a SEPARATE pgvector Postgres, not
# OpenEMR's DB) needs that driver on the app container to connect. This image adds
# it and nothing else -- it is a pure driver layer, changing nothing about how
# OpenEMR boots (the base ENTRYPOINT/CMD/ENV/VOLUMEs are all inherited).
#
# LOCAL DEVELOPMENT ONLY. Deployed instances install pdo_pgsql in their own image
# build; this Dockerfile exists so `docker compose ... up` gives a working
# knowledge flow with no extra host setup. Built automatically by
# ops/local/compose.knowledge.yml (and ops/local/knowledge-up.sh).
FROM openemr/openemr:flex

USER root

# Alpine (flex is Alpine) path first, official-php-image path as a fallback. The
# trailing `php -m | grep` makes a wrong package name fail the BUILD loudly rather
# than degrade silently at runtime (KnowledgeBaseConnection::isAvailable() would
# just report the store unavailable and fall back to the offline corpus).
RUN set -eux; \
    if command -v apk >/dev/null 2>&1; then \
        PHPVER="$(php -r 'echo PHP_MAJOR_VERSION, PHP_MINOR_VERSION;')"; \
        apk add --no-cache "php${PHPVER}-pdo_pgsql" "php${PHPVER}-pgsql" \
        || apk add --no-cache php-pdo_pgsql php-pgsql; \
    elif command -v docker-php-ext-install >/dev/null 2>&1; then \
        apt-get update; \
        apt-get install -y --no-install-recommends libpq-dev; \
        docker-php-ext-install pdo_pgsql pgsql; \
        rm -rf /var/lib/apt/lists/*; \
    else \
        echo "Unsupported base image: neither apk nor docker-php-ext-install found" >&2; \
        exit 1; \
    fi; \
    php -m | grep -qi pdo_pgsql
