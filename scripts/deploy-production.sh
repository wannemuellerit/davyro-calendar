#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
cd "$(dirname "$0")/.."

mail_dir=${DAVYRO_MAIL_DIR:-/var/www/modules/mail}
mail_env="$mail_dir/.env"
env_file=.env.production
compose() {
    docker compose --env-file "$env_file" -f compose.standalone.yaml -f compose.production.yaml "$@"
}

test -r "$mail_env"
docker network inspect davyro-mail_mail-internal >/dev/null
secret=$(sed -n 's/^MAIL_BRIDGE_SHARED_SECRET=//p' "$mail_env" | tail -n 1)
[[ "$secret" =~ ^[a-fA-F0-9]{64}$ ]] || {
    echo 'Expected the existing Mail bridge secret in hex format; refusing to start.' >&2
    exit 1
}

if [ ! -e "$env_file" ]; then
    if [ -n "$(docker volume ls -q --filter label=com.docker.compose.project=davyro-calendar)" ]; then
        echo 'Calendar volumes already exist. Restore the original production env before deploying.' >&2
        exit 1
    fi
    temporary=$(mktemp .env.production.XXXXXX)
    trap 'test ! -e "$temporary" || unlink "$temporary"' EXIT
    {
        printf '%s\n' \
            'COMPOSE_PROJECT_NAME=davyro-calendar' \
            'DAVYRO_MAIL_NETWORK=davyro-mail_mail-internal' \
            'DAVYRO_MAIL_INTERNAL_URL=http://portal' \
            'DAVYRO_CALENDAR_URL=https://mail.davyro.de/calendar-app' \
            'DAVYRO_MAIL_URL=https://mail.davyro.de' \
            'DAVYRO_HUB_URL=https://app.davyro.de' \
            'AGENDAV_BASE_PATH=/calendar-app' \
            'AGENDAV_ENVIRONMENT=prod'
        printf 'MAIL_BRIDGE_SHARED_SECRET=%s\n' "$secret"
        for key in CALENDAR_DB_ROOT_PASSWORD AGENDAV_DB_PASSWORD BAIKAL_DB_PASSWORD BAIKAL_ADMIN_PASSWORD BAIKAL_ENCRYPTION_KEY AGENDAV_CSRF_SECRET AGENDAV_SESSION_KEY CALENDAR_PRINCIPAL_SECRET CALENDAR_SUBSCRIPTION_KEY; do
            printf '%s=%s\n' "$key" "$(openssl rand -hex 32)"
        done
    } > "$temporary"
    mv "$temporary" "$env_file"
fi
chmod 600 "$env_file"
grep -Fxq "MAIL_BRIDGE_SHARED_SECRET=$secret" "$env_file" || {
    echo 'Calendar and Mail bridge credentials do not match.' >&2
    exit 1
}
unset secret

compose config --quiet
compose build --pull baikal calendar webcal-worker
compose up -d --wait --wait-timeout 240
compose ps
