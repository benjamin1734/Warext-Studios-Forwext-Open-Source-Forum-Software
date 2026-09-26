#!/bin/sh
set -eu

LIMIT="${FORWEXT_WEBHOOK_WORKER_LIMIT:-100}"
SLEEP="${FORWEXT_WORKER_IDLE_SLEEP:-2}"

case "$LIMIT" in
  ''|*[!0-9]*) echo "Invalid FORWEXT_WEBHOOK_WORKER_LIMIT" >&2; exit 2 ;;
esac
case "$SLEEP" in
  ''|*[!0-9]*) echo "Invalid FORWEXT_WORKER_IDLE_SLEEP" >&2; exit 2 ;;
esac

while true; do
  php /app/bin/webhook-worker.php "$LIMIT"
  sleep "$SLEEP"
done
