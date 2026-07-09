#!/bin/sh
# Idempotent flex source fetch for Railway (survives container restarts).
set -eu

HTDOCS=/var/www/localhost/htdocs

if [ ! -f "${HTDOCS}/auto_configure.php" ]; then
    exit 0
fi

if [ -f "${HTDOCS}/openemr/composer.json" ]; then
    echo "Railway flex: OpenEMR source already present."
    exit 0
fi

# Drop partial trees from a crashed prior start.
if [ -d "${HTDOCS}/openemr" ]; then
    echo "Railway flex: removing incomplete openemr tree."
    rm -rf "${HTDOCS}/openemr"
fi

REPO="${FLEX_REPOSITORY:-https://github.com/GiantBeaver9/openemr-base-clean.git}"
BRANCH="${FLEX_REPOSITORY_BRANCH:-main}"

echo "Railway flex: fetching ${BRANCH} from ${REPO}"
cd /
rm -rf openemr-base-clean openemr
git clone --branch "${BRANCH}" --depth 1 "${REPO}" openemr
rsync --ignore-existing --recursive --links --exclude .git openemr "${HTDOCS}/"
rm -rf openemr openemr-base-clean
echo "Railway flex: source copied to ${HTDOCS}/openemr"
