#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
repository_root=$(cd -- "$script_dir/.." && pwd)
environment_file=${ENV_FILE:-$repository_root/.env.example}
calendar_url=${CALENDAR_BASE_URL:-http://127.0.0.1:8089}
demo_user=${DAVYRO_SMOKE_USER:-t-demo-m-demo}
demo_password=${DAVYRO_SMOKE_PASSWORD:-replace-demo-password}
compose=(docker compose --env-file "$environment_file" -f "$repository_root/compose.standalone.yaml")

temporary_directory=$(mktemp -d)
event_url="http://127.0.0.1/dav.php/calendars/$demo_user/default/davyro-standalone-smoke.ics"
event_created=false

cleanup() {
    if [[ $event_created == true ]]; then
        "${compose[@]}" exec -T baikal curl -fsS -o /dev/null \
            -u "$demo_user:$demo_password" -X DELETE "$event_url" || true
    fi
    rm -rf -- "$temporary_directory"
}
trap cleanup EXIT

"${compose[@]}" config --quiet

for service in database baikal calendar; do
    container_id=$("${compose[@]}" ps -q "$service")
    [[ -n $container_id ]] || {
        printf 'Service %s is not running\n' "$service" >&2
        exit 1
    }

    health=$(docker inspect \
        --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
        "$container_id")
    [[ $health == healthy ]] || {
        printf 'Service %s is not healthy: %s\n' "$service" "$health" >&2
        exit 1
    }
done

curl -fsS -c "$temporary_directory/cookies" \
    -o "$temporary_directory/login.html" "$calendar_url/login"

csrf_token=$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' \
    "$temporary_directory/login.html" | head -n 1)
[[ -n $csrf_token ]] || {
    printf 'No CSRF token found on the login page\n' >&2
    exit 1
}

login_status=$(curl -sS -b "$temporary_directory/cookies" \
    -c "$temporary_directory/cookies" -o "$temporary_directory/login-response.html" \
    -w '%{http_code}' -X POST "$calendar_url/login" \
    --data-urlencode "_token=$csrf_token" \
    --data-urlencode "user=$demo_user" \
    --data-urlencode "password=$demo_password" \
    --data-urlencode 'login=Anmelden')
[[ $login_status == 302 ]] || {
    printf 'Login failed with HTTP %s\n' "$login_status" >&2
    exit 1
}

curl -fsS -b "$temporary_directory/cookies" \
    -o "$temporary_directory/calendar.html" "$calendar_url/"
grep -Fq '<title>Davyro Kalender</title>' "$temporary_directory/calendar.html"
grep -Fq 'href="http://localhost:8088"' "$temporary_directory/calendar.html"

"${compose[@]}" exec -T baikal test -f /var/www/baikal/Specific/INSTALL_DISABLED
"${compose[@]}" exec -T baikal curl -fsS http://127.0.0.1/admin/install/ \
    | grep -Fq 'Installation was already completed'

"${compose[@]}" exec -T baikal curl -fsS \
    -u "$demo_user:$demo_password" -X PROPFIND \
    -H 'Depth: 1' -H 'Content-Type: application/xml; charset=utf-8' \
    --data-binary '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>' \
    "http://127.0.0.1/dav.php/calendars/$demo_user/" \
    | grep -Fq 'Mein Kalender'

ical_data=$'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Davyro//Standalone Smoke Test//DE\r\nBEGIN:VEVENT\r\nUID:davyro-standalone-smoke@example.test\r\nDTSTAMP:20260909T160300Z\r\nDTSTART:20260910T080000Z\r\nDTEND:20260910T083000Z\r\nSUMMARY:Davyro Standalone Smoke Test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n'
put_status=$("${compose[@]}" exec -T baikal curl -fsS -o /dev/null -w '%{http_code}' \
    -u "$demo_user:$demo_password" -X PUT \
    -H 'Content-Type: text/calendar; charset=utf-8' \
    --data-binary "$ical_data" "$event_url")
[[ $put_status == 201 || $put_status == 204 ]] || {
    printf 'CalDAV PUT failed with HTTP %s\n' "$put_status" >&2
    exit 1
}
event_created=true

"${compose[@]}" exec -T baikal curl -fsS \
    -u "$demo_user:$demo_password" "$event_url" \
    | grep -Fq 'SUMMARY:Davyro Standalone Smoke Test'

delete_status=$("${compose[@]}" exec -T baikal curl -fsS -o /dev/null -w '%{http_code}' \
    -u "$demo_user:$demo_password" -X DELETE "$event_url")
[[ $delete_status == 204 ]] || {
    printf 'CalDAV DELETE failed with HTTP %s\n' "$delete_status" >&2
    exit 1
}
event_created=false

printf 'Davyro Kalender standalone smoke test passed\n'
