#!/bin/sh
set -eu

interval="${WEBCAL_WORKER_INTERVAL_SECONDS:-60}"
case "$interval" in
    ''|*[!0-9]*)
        echo "WEBCAL_WORKER_INTERVAL_SECONDS must be a positive integer" >&2
        exit 1
        ;;
esac
if [ "$interval" -lt 10 ]; then
    echo "WEBCAL_WORKER_INTERVAL_SECONDS must be at least 10" >&2
    exit 1
fi

while true; do
    php /app/bin/agendavcli imip:dispatch-due --limit="${IMIP_WORKER_BATCH_SIZE:-100}"
    php /app/bin/agendavcli webcal:refresh-due --limit="${WEBCAL_WORKER_BATCH_SIZE:-100}"
    date +%s > /tmp/davyro-webcal-worker-heartbeat
    sleep "$interval"
done
