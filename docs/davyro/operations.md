# Betrieb, Backup und Restore

## Konsistentes Backup

Das Kalenderbackup umfasst beide MariaDB-Datenbanken, Baïkal-Konfiguration und
den persistenten AgenDAV-/Davyro-Zustand. Das Zielverzeichnis muss absichtlich
angegeben werden und leer sein:

```bash
scripts/backup.sh --output /sicherer/pfad/calendar-2026-09-10 --env .env
scripts/verify-backup.sh /sicherer/pfad/calendar-2026-09-10
```

`SHA256SUMS` schützt vor unbemerkten unvollständigen oder beschädigten Dateien.
Das Backup enthält sensible Kalenderinhalte und muss außerhalb des Hosts
verschlüsselt sowie zugriffsbeschränkt aufbewahrt werden.

## Restore

Ein Restore überschreibt beide Datenbanken und die persistenten Dateivolumes.
Er verlangt deshalb den expliziten Schalter `--confirm-restore`, stoppt alle
schreibenden Kalenderdienste, prüft zuerst Format und Prüfsummen und startet die
Dienste anschließend wieder:

```bash
scripts/restore.sh \
  --backup /sicherer/pfad/calendar-2026-09-10 \
  --env .env \
  --confirm-restore
bash tests/standalone_smoke.sh
```

Der Restore-Test ist mindestens monatlich auf einem getrennten Docker-Host
beziehungsweise mit einem eigenen Compose-Projektnamen auszuführen. Ein Backup
gilt erst dann als verwendbar, wenn Login, Primärkalender, Terminobjekte,
Freigaben, WebCal-Zustände, Veröffentlichungen, Availability und ausstehende
Mail-Aufträge nach dem Restore geprüft wurden. Die Mail-Outbox und eingehenden
Einladungen liegen in der Davyro-Mail-Datenbank und gehören deshalb zusätzlich
in den Backup-/Restore-Lauf von `davyro-mail`.

## Überwachung

Mindestens folgende Signale werden alarmiert:

- Container-Health von `database`, `baikal`, `calendar` und `webcal-worker`,
- Alter der Datei `/tmp/davyro-webcal-worker-heartbeat`,
- HTTP-Fehlerquote und Latenz der Kalender-API,
- fehlgeschlagene Provisionierungen und überfällige Archivlöschungen,
- Alter und Fehlversuche der Mail-Outbox im Davyro-Mail-Portal,
- WebCal-Status `error` sowie Cachealter oberhalb von 24 Stunden,
- Datenbank- und Backup-Fehler sowie ein fehlgeschlagener Restore-Test.

Logs dürfen keine Passwörter, vollständigen ICS-Inhalte, Mailtexte,
Veröffentlichungstokens oder entschlüsselten WebCal-URLs enthalten.
