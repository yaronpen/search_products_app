#!/usr/bin/env bash
# PostToolUse hook: after Claude edits a file under backend/, run the test suite in Docker.
# Passing tests are silent; failures go to stderr with exit code 2, which feeds them back to Claude.

file=$(jq -r '.tool_input.file_path // .tool_response.filePath // empty')
case "$file" in
  "$CLAUDE_PROJECT_DIR"/backend/*) ;;
  *) exit 0 ;;
esac

cd "$CLAUDE_PROJECT_DIR" || exit 0
if ! docker compose ps --status running --services 2>/dev/null | grep -qx app; then
  echo "Backend tests skipped: the app container is not running (docker compose up -d)." >&2
  exit 2
fi

# The suite exits 1 on any failure; strip colour codes so the summary reads cleanly
output=$(docker compose exec -T app php /var/www/backend/tests/run.php 2>&1 | sed $'s/\e\\[[0-9;]*m//g')
if [ "${PIPESTATUS[0]}" -ne 0 ] || ! grep -q ' 0 failed' <<<"$output"; then
  {
    echo "Backend tests FAILED after editing ${file#$CLAUDE_PROJECT_DIR/}:"
    grep -E '✗|Fatal|Error|passed' <<<"$output" | tail -25
  } >&2
  exit 2
fi
exit 0
