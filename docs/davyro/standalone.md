# Standalone-Betrieb

## Start

```bash
cp .env.example .env
# alle replace-Werte ändern
docker compose -f compose.standalone.yaml config --quiet
docker compose -f compose.standalone.yaml up --build -d
docker compose -f compose.standalone.yaml ps
```

Den vollständigen lokalen Login- und CalDAV-Smoke-Test startet:

```bash
bash tests/standalone_smoke.sh
```

Das von lokalen PHP-/Node-Installationen unabhängige Phase-0-Gate baut das
separate Test-Image, führt die PHPUnit-Suite darin aus und prüft den direkten
Provisionierungsadapter gegen das echte, gepinnte Baïkal-0.12.1-Schema:

```bash
bash tests/phase0_contract.sh
```

Details zum Datenvertrag und zum sicheren Upgrade-Ablauf stehen unter
[Entwicklung, Test-Image und Issue-Governance](development.md).

Die Oberfläche läuft standardmäßig auf <http://localhost:8089>. Für lokale
Entwicklung stellt der explizite Demo-Bootstrap den Benutzer
`t-demo-m-demo` bereit. Produktion muss `DAVYRO_CREATE_DEMO_USER=false` setzen.
Außerdem muss Produktion `AGENDAV_ENVIRONMENT=prod` verwenden und die
Oberfläche ausschließlich über HTTPS bereitstellen.

Ein Entwicklungsvolume, in dem das Demo-Konto einmal angelegt wurde, darf
nicht als Produktionsvolume weiterverwendet werden. Der Baïkal-Container
verweigert in diesem Fall den Produktionsstart, statt ein Konto mit bekanntem
Testpasswort unbemerkt aktiv zu lassen.

## Interne Verknüpfung

`DAVYRO_MAIL_URL` und `DAVYRO_HUB_URL` werden als Links im Browser gerendert.
Sie müssen deshalb öffentliche Browser-URLs enthalten, niemals Namen wie
`http://baikal` oder `http://calendar`. HTTP wird nur für Loopback-Ziele und nur
bei `DAVYRO_ALLOW_INSECURE_LOCAL_LINKS=true` akzeptiert.

AgenDAV verwendet dagegen ausschließlich
`BAIKAL_INTERNAL_BASE_URL=http://baikal/dav.php/` für interne CalDAV-Aufrufe.

Davyro Mail öffnet den Kalender über ein signiertes, einmalig verwendbares
Launch-Ticket. Bereits beim erfolgreichen Speichern eines Postfachs legt ein
persistenter, idempotenter Hintergrundauftrag den technischen Baïkal-Principal
und den Primärkalender an. Der Kalender-Login prüft diesen Zustand nur lesend
und repariert fehlende Principal-, Kalender- oder Bindungsdaten einmalig. Er
ist nicht der reguläre Provisionierungspfad. Mandant, Benutzer sowie alle
verbundenen Absenderadressen werden in die Sitzung übernommen; ein separates
Kalenderpasswort oder eine zweite Anmeldung gibt es nicht.

Termine mit Teilnehmenden werden als iCalendar/iTIP-Nachrichten über das
verbundene SMTP-Postfach des Organisators versendet. Eingehende `REQUEST`-,
`REPLY`- und `CANCEL`-Abläufe verwenden dieselbe interne, mit Bearer-Secret
und zeitbegrenzter HMAC-Signatur geschützte Mail-Brücke. Nonces werden nur
einmal akzeptiert. Angenommene Termine, Aktualisierungen und Absagen werden in
Baïkal gespeichert beziehungsweise entfernt.

## WebCal/ICS-Abonnements

Abonnements sind im Davyro-Standalone-Profil aktiviert. `webcal://` wird nach
`https://` normalisiert. Jeder Zielhost und jede Weiterleitung wird erneut
aufgelöst; private, reservierte und nicht standardmäßige Ziele werden
blockiert. Abrufe haben Zeit-, Größen- und Weiterleitungslimits und werden
standardmäßig alle 15 Minuten mit Jitter aktualisiert. Bei temporären Fehlern
bleibt die letzte erfolgreiche Fassung höchstens 24 Stunden sichtbar und wird
als veraltet gekennzeichnet. Mit
`CALENDAR_SUBSCRIPTION_ALLOWED_DOMAINS` kann der Administrator zusätzlich eine
kommagetrennte Positivliste festlegen.

Zusätzlich können Benutzer eine lokale `.ics`-Datei bis 2 MiB zunächst prüfen
und anschließend gezielt in einen beschreibbaren Kalender importieren. UID und
`RECURRENCE-ID` steuern die Duplikatbehandlung. Der Import versendet niemals
automatisch eine Zu- oder Absage und dient auch als Fallback für Einladungen,
die ein Mailanbieter nicht korrekt als `text/calendar` ausliefert.

## Backup, Restore und Überwachung

Die ausführbaren Backup-, Prüfsummen- und Restore-Abläufe sowie die
verbindlichen Überwachungssignale stehen in
[operations.md](operations.md).

## Noch nicht produktionsfertig

- kein öffentlicher direkter CalDAV-Endpunkt,
- die abschließende Navigation und Rechtevergabe im Davyro Hub selbst,
- produktive Secret-Verteilung über die Zielinfrastruktur,
- reale Abnahme der iMIP-Abläufe mit Apple-, Google- und Microsoft-Testkonten.
