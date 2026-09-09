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

## Noch nicht produktionsfertig

- kein Davyro-SSO-Consumer und keine Hub-Anbindung,
- noch keine mandantenbegrenzte Freigabeoberfläche,
- WebCal/ICS aus Sicherheitsgründen deaktiviert,
- kein öffentlicher direkter CalDAV-Endpunkt,
- Backup/Restore und Monitoring noch nicht vollständig umgesetzt.
