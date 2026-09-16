# Lasttest der Davyro Calendar API

Dieses Harness bildet den verbindlichen Abnahmefall mit 100 gleichzeitig
aktiven Benutzern, je vier Postfächern und zwölf sichtbaren Kalendern ab. Es
misst ausschließlich die Browser-API-Aufrufe `GET /api/v1/context` und
`GET /api/v1/events`. Der Ereignisabruf enthält immer die zwölf vorab
ermittelten opaken Kalender-IDs.

## Was der Test prüft

- Vor der Messung wird je virtuellem Benutzer eine eigene Kalendersitzung
  angelegt.
- Der Topologie-Preflight verlangt standardmäßig genau vier Postfächer und
  mindestens zwölf aktive sichtbare Kalender. Nur die ersten zwölf Kalender
  gehen in den Ereignisabruf ein.
- Authentifizierung, Ticketausstellung und Topologie-Preflight sind mit
  `phase=bootstrap|topology` markiert und fließen nicht in die API-p95 ein.
- `http_req_duration{api:calendar}` muss p95 kleiner als 1.000 ms halten.
- HTTP-Fehlerquote muss unter 1 % und die fachliche Erfolgsquote über 99 %
  bleiben. Ein Topologiefehler bricht den Test ab.

Das Profil verändert keine Kalender oder Termine. Die Anmeldung verbraucht
Einmal-Tickets und kann über den normalen idempotenten Sitzungsablauf fehlende
Primärkalender provisionieren. Deshalb darf der Test ausschließlich gegen eine
isolierte Staging-Umgebung oder einen wiederhergestellten, anonymisierten
Produktionsstand laufen.

## Actor-Datei

Die lokale JSON-Datei enthält mindestens einen eindeutig benannten Actor mit
eigenen Zugangsdaten pro VU und wird nicht eingecheckt. Doppelt verwendete
Tickets oder Bridge-Sitzungen werden abgewiesen. Kopiere
`actors.example.json` nach `actors.local.json`. Ein Actor verwendet entweder
ein kurzlebiges, 64-stelliges Kalender-Einmal-Ticket:

```json
{
  "name": "load-user-001",
  "ticket": "64-stelliges-einmal-ticket"
}
```

oder eine bestehende, 80-stellige Davyro-Mail-Bridge-Sitzung und die interne ID
eines aktiven Postfachs:

```json
{
  "name": "load-user-001",
  "bridge_token": "80-stelliger-bridge-token",
  "initial_mail_account_id": 123
}
```

Die zweite Variante lässt das Harness unmittelbar vor dem Kalenderlogin über
`POST /internal/calendar/session-tickets` ein frisches Einmal-Ticket ausstellen.
Dafür wird das gemeinsame Bridge-Secret benötigt. Actor-Datei, Bridge-Tokens,
Tickets, Secret und Ergebnisdatei sind sensible Betriebsdaten. Sie gehören nur
auf den Lastgenerator, müssen restriktive Dateirechte besitzen und nach dem Lauf
gelöscht werden.

## Lokaler Smoke-Test

Der Smoke-Test startet nur einen lokalen Node-Mock und zwei Iterationen mit
einem VU. Er beweist Script-Syntax, HMAC-Ticketausstellung, Cookie-Sitzung,
Topologieprüfung, Abfrageparameter und k6-Schwellen – nicht die Kapazität der
Anwendung oder Hardware:

```bash
bash tests/load/smoke.sh
```

Das verwendete Image kann über `DAVYRO_LOAD_K6_IMAGE` geändert werden.

## Abnahmelauf

Native k6-Ausführung:

```bash
DAVYRO_LOAD_CONFIRM_NON_PRODUCTION=1 \
DAVYRO_LOAD_ACTORS=./tests/load/actors.local.json \
DAVYRO_LOAD_CALENDAR_URL=https://mail-staging.example/calendar-app \
DAVYRO_LOAD_PORTAL_URL=https://mail-staging.example \
DAVYRO_LOAD_SHARED_SECRET_FILE=/run/secrets/davyro-mail-bridge \
k6 run --summary-export tests/load/results.local.json \
  tests/load/calendar-api.js
```

Falls k6 als Container läuft, müssen Actor-Datei und Secret read-only in den
Container gemountet werden. Die URL muss aus Sicht des Lastgenerators erreichbar
sein. `DAVYRO_LOAD_PORTAL_TICKET_TARGET` enthält den exakten, signierten
Request-Target einschließlich eines möglichen Reverse-Proxy-Präfixes; der
Standard ist `/internal/calendar/session-tickets`.

Konfigurierbare Werte:

| Variable | Standard | Bedeutung |
|---|---:|---|
| `DAVYRO_LOAD_VUS` | `100` | konstante parallele Benutzer |
| `DAVYRO_LOAD_DURATION` | `5m` | Dauer des Messfensters |
| `DAVYRO_LOAD_EXPECTED_MAILBOXES` | `4` | exakt erwartete Postfächer je Benutzer |
| `DAVYRO_LOAD_VISIBLE_CALENDARS` | `12` | abgefragte Kalender je Benutzer |
| `DAVYRO_LOAD_P95_MS` | `1000` | p95-Grenze der Calendar API |
| `DAVYRO_LOAD_PAST_DAYS` | `7` | Beginn des Ereigniszeitraums relativ zu heute |
| `DAVYRO_LOAD_FUTURE_DAYS` | `35` | Ende des Ereigniszeitraums relativ zu heute |
| `DAVYRO_LOAD_PAUSE_SECONDS` | `1` | Denkzeit zwischen zwei Iterationen |
| `DAVYRO_LOAD_REQUEST_TIMEOUT` | `10s` | Timeout eines einzelnen Requests |

Für den verbindlichen Abnahmelauf bleiben VUs, Postfachzahl, Kalenderzahl und
p95-Grenze auf den Standardwerten. Vor dem Lauf wird ein repräsentativer und
fixierter Datenbestand benötigt. Das Abnahmeprotokoll hält mindestens Commit,
Container-Images, Zielhardware, Ereignismenge je Kalender, Testdauer,
Netzwerkstandort und die vollständige k6-Zusammenfassung fest. Nur ein Lauf auf
der festgelegten Zielhardware kann den Produktions-Abnahmepunkt erfüllen.
