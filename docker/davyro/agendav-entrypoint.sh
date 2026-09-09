#!/bin/sh
set -eu

php -r '
foreach (["AGENDAV_CSRF_SECRET", "AGENDAV_SESSION_KEY"] as $name) {
    $value = getenv($name);
    if (!is_string($value) || preg_match("/\\A[0-9a-fA-F]{64}\\z/", $value) !== 1) {
        fwrite(STDERR, "$name must contain exactly 64 hexadecimal characters\n");
        exit(1);
    }
}
'

# These three AgenDAV 1.x-only migrations intentionally skip on a fresh
# database. Doctrine otherwise keeps seeing them as pending on every restart
# and may abort its all-or-nothing migration batch. Preserve the real upgrade
# path when the legacy AgenDAV 1.x "migrations" table is present.
if php /app/docker/davyro/should-mark-legacy-migrations.php; then
    for version in Version20140812113548 Version20140812200547 Version20140812203419; do
        php /app/bin/agendavcli migrations:version \
            "AgenDAV\\DB\\Migrations\\$version" \
            --add --no-interaction >/dev/null 2>&1 || true
    done
fi

php /app/bin/agendavcli migrations:migrate --no-interaction --allow-no-migration

exec apache2-foreground
