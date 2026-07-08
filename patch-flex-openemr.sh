#!/bin/sh
# Replace flex openemr.sh git-clone block with Railway bootstrap (fork-safe, restart-safe).
set -eu

OPENEMR_SH=/var/www/localhost/htdocs/openemr.sh
OUT="${OPENEMR_SH}.patched"

if [ ! -f "${OPENEMR_SH}" ]; then
    echo "patch-flex-openemr: ${OPENEMR_SH} not found" >&2
    exit 1
fi

: > "${OUT}"
skip_clone_body=0

while IFS= read -r line || [ -n "${line}" ]; do
    if [ "${skip_clone_body}" -eq 1 ]; then
        case "${line}" in
            *'cd /var/www/localhost/htdocs/'*)
                printf '%s\n' \
                    '    /usr/local/bin/railway-flex-bootstrap.sh' \
                    "${line}" >> "${OUT}"
                skip_clone_body=0
                ;;
        esac
        continue
    fi

    case "${line}" in
        *'echo "Configuring a new flex openemr docker"'*)
            printf '%s\n' "${line}" >> "${OUT}"
            skip_clone_body=1
            ;;
        *)
            printf '%s\n' "${line}" >> "${OUT}"
            ;;
    esac
done < "${OPENEMR_SH}"

if ! grep -Fq '/usr/local/bin/railway-flex-bootstrap.sh' "${OUT}"; then
    echo "patch-flex-openemr: bootstrap injection failed" >&2
    exit 1
fi

if grep -Fq 'git clone "${FLEX_REPOSITORY}" --branch "${FLEX_REPOSITORY_BRANCH}" --depth 1' "${OUT}"; then
    echo "patch-flex-openemr: upstream git clone still present" >&2
    exit 1
fi

mv "${OUT}" "${OPENEMR_SH}"
echo "patch-flex-openemr: replaced flex git clone with railway-flex-bootstrap.sh"
