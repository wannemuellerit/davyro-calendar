#!/bin/sh

set -eu

usage() {
    echo "Verwendung: $0 --output VERZEICHNIS [--env DATEI]" >&2
    exit 64
}

output=''
env_file='.env'
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output)
            [ "$#" -ge 2 ] || usage
            output=$2
            shift 2
            ;;
        --env)
            [ "$#" -ge 2 ] || usage
            env_file=$2
            shift 2
            ;;
        *) usage ;;
    esac
done

[ -n "$output" ] || usage
[ -f "$env_file" ] || { echo "Env-Datei fehlt: $env_file" >&2; exit 66; }
case "$output" in
    /|.|..|'') echo 'Unsicheres Backup-Ziel abgelehnt.' >&2; exit 64 ;;
esac

compose_file='compose.standalone.yaml'
mkdir -p "$output"
[ -z "$(find "$output" -mindepth 1 -maxdepth 1 -print -quit)" ] || {
    echo "Backup-Ziel muss leer sein: $output" >&2
    exit 73
}

compose() {
    docker compose --env-file "$env_file" -f "$compose_file" "$@"
}

compose exec -T database sh -c \
    'exec mariadb-dump --user=root --password="$MARIADB_ROOT_PASSWORD" --single-transaction --routines --events --hex-blob --databases "$MARIADB_DATABASE" "$BAIKAL_DB_NAME"' \
    > "$output/databases.sql"
compose exec -T baikal tar -C /var/www/baikal -czf - config Specific \
    > "$output/baikal-files.tar.gz"
compose exec -T calendar tar -C /app -czf - var \
    > "$output/calendar-var.tar.gz"

cat > "$output/metadata.txt" <<EOF
format=davyro-calendar-backup-v1
created_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
compose_project=$(compose config --format json | sha256sum | cut -d' ' -f1)
EOF

(
    cd "$output"
    sha256sum databases.sql baikal-files.tar.gz calendar-var.tar.gz metadata.txt > SHA256SUMS
)

echo "Backup vollständig: $output"
