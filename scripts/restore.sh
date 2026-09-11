#!/bin/sh

set -eu

usage() {
    echo "Verwendung: $0 --backup VERZEICHNIS --confirm-restore [--env DATEI]" >&2
    exit 64
}

backup=''
env_file='.env'
confirmed=false
while [ "$#" -gt 0 ]; do
    case "$1" in
        --backup)
            [ "$#" -ge 2 ] || usage
            backup=$2
            shift 2
            ;;
        --env)
            [ "$#" -ge 2 ] || usage
            env_file=$2
            shift 2
            ;;
        --confirm-restore)
            confirmed=true
            shift
            ;;
        *) usage ;;
    esac
done

[ "$confirmed" = true ] || {
    echo 'Restore nicht bestätigt. Bestehende Kalenderdaten würden überschrieben.' >&2
    usage
}
[ -n "$backup" ] || usage
[ -f "$env_file" ] || { echo "Env-Datei fehlt: $env_file" >&2; exit 66; }
"$(dirname "$0")/verify-backup.sh" "$backup"
backup=$(cd "$backup" && pwd -P)

compose_file='compose.standalone.yaml'
compose() {
    docker compose --env-file "$env_file" -f "$compose_file" "$@"
}

compose up -d --wait database
compose stop calendar webcal-worker baikal

compose exec -T database sh -c \
    'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD"' \
    < "$backup/databases.sql"

compose run --rm --no-deps -T --entrypoint sh \
    -v "$backup/baikal-files.tar.gz:/restore/baikal-files.tar.gz:ro" baikal -c \
    'find /var/www/baikal/config /var/www/baikal/Specific -mindepth 1 -delete && tar -C /var/www/baikal -xzf /restore/baikal-files.tar.gz'
compose run --rm --no-deps -T --entrypoint sh \
    -v "$backup/calendar-var.tar.gz:/restore/calendar-var.tar.gz:ro" calendar -c \
    'find /app/var -mindepth 1 -delete && tar -C /app -xzf /restore/calendar-var.tar.gz'

compose up -d --wait baikal calendar webcal-worker
compose ps

echo 'Restore eingespielt. Führe jetzt tests/standalone_smoke.sh aus.'
