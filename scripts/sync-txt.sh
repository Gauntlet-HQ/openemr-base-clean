#!/usr/bin/env bash
#
# Regenerate the plain-text (.txt) copies of the case-study root deliverables
# from their markdown (.md) sources, for upload/review tools that only scan
# for .txt files ("NO TEXT FILES FOUND"). Markdown is already plain text, so
# this is a straight copy -- the .txt is byte-identical to its .md source.
#
# Run from anywhere (operates on the repo root):
#   scripts/sync-txt.sh            # rewrite every <doc>.txt from <doc>.md
#   scripts/sync-txt.sh --check    # exit 1 if any .txt is stale (for CI/hooks)
#
set -euo pipefail

# The root deliverables that need a .txt twin. Add a name here (no extension)
# and it is covered by both the rewrite and --check paths.
DOCS=(AUDIT USERS USER ARCHITECTURE ARCHITECTURE_COMPLETE README)

REPO_ROOT="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
cd "$REPO_ROOT"

check_only=0
[ "${1:-}" = "--check" ] && check_only=1

drift=0
for f in "${DOCS[@]}"; do
  if [ ! -f "$f.md" ]; then
    echo "sync-txt: missing source $f.md" >&2
    exit 2
  fi
  if [ "$check_only" = 1 ]; then
    if ! cmp -s "$f.md" "$f.txt"; then
      echo "sync-txt: OUT OF SYNC -> $f.txt (run scripts/sync-txt.sh)" >&2
      drift=1
    fi
  else
    cp "$f.md" "$f.txt"
    echo "sync-txt: wrote $f.txt"
  fi
done

if [ "$check_only" = 1 ] && [ "$drift" = 1 ]; then
  exit 1
fi
