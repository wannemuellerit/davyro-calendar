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
Launch-Ticket. Davyro Kalender legt den technischen Baïkal-Principal beim
ersten Aufruf automatisch an und übernimmt Mandant, Benutzer sowie alle
verbundenen Absenderadressen in die Sitzung. Ein separates Kalenderpasswort
oder eine zweite Anmeldung gibt es in diesem Ablauf nicht.

Termine mit Teilnehmenden werden als iCalendar/iTIP-Nachrichten über das
verbundene SMTP-Postfach des Organisators versendet. Eingehende `REQUEST`-,
`REPLY`- und `CANCEL`-Abläufe verwenden dieselbe interne, mit Bearer-Secret
geschützte Mail-Brücke. Angenommene Termine, Aktualisierungen und Absagen
werden in Baïkal gespeichert beziehungsweise entfernt.

## WebCal/ICS-Abonnements

Abonnements sind im Davyro-Standalone-Profil aktiviert. `webcal://` wird nach
`https://` normalisiert. Jeder Zielhost und jede Weiterleitung wird erneut
aufgelöst; private, reservierte und nicht standardmäßige Ziele werden
blockiert. Abrufe haben Zeit-, Größen- und Weiterleitungslimits und werden
fünf Minuten zwischengespeichert. Mit
`CALENDAR_SUBSCRIPTION_ALLOWED_DOMAINS` kann der Administrator zusätzlich eine
kommagetrennte Positivliste festlegen.

## Noch nicht produktionsfertig

- kein öffentlicher direkter CalDAV-Endpunkt,
- die abschließende Navigation und Rechtevergabe im Davyro Hub selbst,
- Backup/Restore, Monitoring und produktive Secret-Verteilung.
