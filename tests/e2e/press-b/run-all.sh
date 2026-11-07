#!/bin/zsh
# Runs every script of this folder in turn. See README.md for what has to be running.
cd "$(dirname "$0")" || exit 1
fail=0
for f in present presenter media share collab comments history import export package responsive recovery; do
  echo "== $f"
  node $f.mjs 2>&1 | grep -E "FAIL|passed|failed|Error" || true
  [ ${pipestatus[1]} -eq 0 ] || fail=1
done
exit $fail
