#!/bin/sh

set -eu

[ "$#" -eq 1 ] || { echo "Verwendung: $0 BACKUP-VERZEICHNIS" >&2; exit 64; }
backup=$1
[ -d "$backup" ] || { echo "Backup-Verzeichnis fehlt: $backup" >&2; exit 66; }

for file in databases.sql baikal-files.tar.gz calendar-var.tar.gz metadata.txt SHA256SUMS; do
    [ -f "$backup/$file" ] || { echo "Backup-Datei fehlt: $file" >&2; exit 65; }
done

grep -qx 'format=davyro-calendar-backup-v1' "$backup/metadata.txt" || {
    echo 'Unbekanntes Backup-Format.' >&2
    exit 65
}
(
    cd "$backup"
    sha256sum -c SHA256SUMS
)
tar -tzf "$backup/baikal-files.tar.gz" >/dev/null
tar -tzf "$backup/calendar-var.tar.gz" >/dev/null
grep -q '^-- MariaDB dump' "$backup/databases.sql" || {
    echo 'Der Datenbank-Dump ist nicht plausibel.' >&2
    exit 65
}

echo "Backup ist vollständig und lesbar: $backup"
