#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MOCK_PORT="${DAVYRO_LOAD_MOCK_PORT:-19089}"
K6_IMAGE="${DAVYRO_LOAD_K6_IMAGE:-grafana/k6:0.54.0}"

node "${SCRIPT_DIR}/mock-server.mjs" &
MOCK_PID=$!
trap 'kill "${MOCK_PID}" 2>/dev/null || true; wait "${MOCK_PID}" 2>/dev/null || true' EXIT

for _ in $(seq 1 50); do
  if curl --fail --silent "http://127.0.0.1:${MOCK_PORT}/ready" >/dev/null 2>&1; then
    break
  fi
  sleep 0.1
done
curl --fail --silent --show-error "http://127.0.0.1:${MOCK_PORT}/ready" >/dev/null

docker run --rm --network host \
  --volume "${SCRIPT_DIR}:/load:ro" \
  --env DAVYRO_LOAD_ACTORS=/load/actors.example.json \
  --env DAVYRO_LOAD_CALENDAR_URL="http://127.0.0.1:${MOCK_PORT}/calendar-app" \
  --env DAVYRO_LOAD_PORTAL_URL="http://127.0.0.1:${MOCK_PORT}" \
  --env DAVYRO_LOAD_SHARED_SECRET=load-smoke-secret \
  --env DAVYRO_LOAD_CONFIRM_NON_PRODUCTION=1 \
  --env DAVYRO_LOAD_SMOKE=1 \
  --env DAVYRO_LOAD_PAUSE_SECONDS=0 \
  "${K6_IMAGE}" run /load/calendar-api.js
