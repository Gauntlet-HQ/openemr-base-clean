#!/usr/bin/env bash
#
# Clinical Co-Pilot I9 additivity gate -- repo-diff test 1
# (ARCHITECTURE_COMPLETE.md "Additivity invariant (enforced, see U9)": "1.
# Repo diff outside the module directory (and the case-study root docs) is
# empty.").
#
# Fails (exit 1) if the diff between a base ref and the current working tree
# touches ANY file outside interface/modules/custom_modules/oe-module-clinical-copilot/
# other than the whitelisted case-study root docs (ALLOWED_SPEC_DOCS below:
# README.md, AUDIT.md, USERS.md/USER.md, ARCHITECTURE.md,
# ARCHITECTURE_COMPLETE.md, docs/clinical-copilot-tradeoffs.md -- the
# submission deliverables the case study requires at the repo root). Also
# fails on any new, untracked file outside those paths -- a brand-new file is
# just as much an additivity violation as a modified one.
#
# Usage:
#   ops/ci/check-additivity.sh [base-ref]
#
# base-ref resolution order: the first CLI argument, then $ADDITIVITY_BASE_REF,
# then origin/$GITHUB_BASE_REF (set automatically by GitHub Actions on
# pull_request events), then origin/master, then master. Comparison is against
# the merge-base of that ref and HEAD (not the ref's tip), so a base branch
# that has moved on since this branch forked does not produce false positives
# for commits that landed on the base branch after the fork point.
#
# CI invocation -- this module cannot edit core .github/workflows/ (that
# would itself violate the very invariant this script enforces), so wiring
# this into CI is host-side, documented here rather than shipped as a diff:
# add a step to whatever workflow builds/tests a Clinical Co-Pilot branch,
# e.g. in a GitHub Actions job:
#
#   - name: Clinical Co-Pilot additivity gate
#     run: interface/modules/custom_modules/oe-module-clinical-copilot/ops/ci/check-additivity.sh "origin/${{ github.base_ref }}"
#
# or locally, from the repo root, before opening a PR:
#
#   interface/modules/custom_modules/oe-module-clinical-copilot/ops/ci/check-additivity.sh origin/master
#
# See docs/clinical-copilot-tradeoffs.md / ARCHITECTURE_COMPLETE.md for why
# this invariant exists, and the module README for the full list of CI gates
# (this script + the PHPStan forbidden-write rule, see
# ../../phpstan.neon and ../../tests/PHPStan/Rules/).

set -euo pipefail

MODULE_DIR="interface/modules/custom_modules/oe-module-clinical-copilot"
ALLOWED_SPEC_DOCS=(
  "README.md"
  "AUDIT.md"
  "USERS.md"
  "USER.md"
  "ARCHITECTURE.md"
  "ARCHITECTURE_COMPLETE.md"
  "docs/clinical-copilot-tradeoffs.md"
  # Plain-text copies of the root deliverables, for upload/review tools that
  # only scan for .txt. Same content as the .md source; keep them in sync.
  "README.txt"
  "AUDIT.txt"
  "USERS.txt"
  "USER.txt"
  "ARCHITECTURE.txt"
  "ARCHITECTURE_COMPLETE.txt"
  # Repo tooling that maintains the .txt copies above (not a deliverable).
  "scripts/sync-txt.sh"
)

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

BASE_REF="${1:-${ADDITIVITY_BASE_REF:-}}"
if [ -z "$BASE_REF" ]; then
  if [ -n "${GITHUB_BASE_REF:-}" ] && git rev-parse --verify "origin/${GITHUB_BASE_REF}" >/dev/null 2>&1; then
    BASE_REF="origin/${GITHUB_BASE_REF}"
  elif git rev-parse --verify origin/master >/dev/null 2>&1; then
    BASE_REF="origin/master"
  else
    BASE_REF="master"
  fi
fi

if ! git rev-parse --verify "$BASE_REF" >/dev/null 2>&1; then
  echo "check-additivity: base ref '$BASE_REF' not found in this checkout (fetch it first, or pass an explicit ref as \$1)" >&2
  exit 2
fi

MERGE_BASE="$(git merge-base "$BASE_REF" HEAD)"

# Tracked changes since the fork point, plus any new untracked file --
# together these are every file this branch's diff introduces or touches.
CHANGED_FILES="$(git diff --name-only "$MERGE_BASE" -- 2>/dev/null || true)"
UNTRACKED_FILES="$(git ls-files --others --exclude-standard)"

ALL_FILES="$(printf '%s\n%s\n' "$CHANGED_FILES" "$UNTRACKED_FILES" | sed '/^$/d' | sort -u)"

VIOLATIONS=()
while IFS= read -r f; do
  [ -z "$f" ] && continue

  case "$f" in
    "$MODULE_DIR"/*)
      continue
      ;;
  esac

  allowed=0
  for doc in "${ALLOWED_SPEC_DOCS[@]}"; do
    if [ "$f" = "$doc" ]; then
      allowed=1
      break
    fi
  done

  if [ "$allowed" -eq 0 ]; then
    VIOLATIONS+=("$f")
  fi
done <<< "$ALL_FILES"

if [ "${#VIOLATIONS[@]}" -gt 0 ]; then
  {
    echo "check-additivity: FAILED -- diff against ${BASE_REF} (merge-base ${MERGE_BASE}) touches"
    echo "files outside ${MODULE_DIR}/ and the whitelisted spec docs:"
    printf '  %s\n' "${VIOLATIONS[@]}"
  } >&2
  exit 1
fi

echo "check-additivity: OK -- diff against ${BASE_REF} is confined to ${MODULE_DIR}/ and the whitelisted spec docs."
