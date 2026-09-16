# Upstream-Strategie

## AgenDAV

- Upstream: <https://github.com/agendav/agendav>
- Git-Remote: `upstream`
- Davyro-Fork: <https://github.com/wannemuellerit/davyro-calendar>
- Ausgangscommit: `9b446df07c44a223693212c153e61edd46367980`
- Lizenz: GPL-3.0-or-later

Upstream-Änderungen werden niemals direkt auf Davyro `main` gezogen. Ein
Sync-Branch wird gegen `upstream/main` aktualisiert und durchläuft Build,
Migrationen, Kalender-E2E, Navigation und Security-Smokes in einem Pull Request.

## Baïkal

- Release: 0.12.1
- Artefakt: <https://github.com/sabre-io/Baikal/releases/download/0.12.1/baikal-0.12.1.zip>
- SHA-256: `0449abb72b151d39d9c08c63cb83a05d9e9adb065b1165ef6786b0b6a13d203c`
- Lizenz: GPL-3.0-only

Das bewegliche Community-Image aus AgenDAVs Development-Compose wird für den
Davyro-Standalone-Stack nicht verwendet. Das Releaseartefakt wird im eigenen
Dockerfile heruntergeladen und vor dem Entpacken gegen die feste Prüfsumme
verifiziert.

## Container-Basis

- AgenDAV Builder: `php:8.5.10-cli-bookworm@sha256:b80dfc7d2bc0fc97755620a0dfb3d5e8e9cbf70a2970ea2d5c9dc64154b31422`
- AgenDAV Runtime: `php:8.5.10-apache-bookworm@sha256:824adc2ce556dd5e05e816b1597cad90948e44b0b36ac2642f7449b801fb8dbd`
- Baïkal Runtime: `php:8.4.25-apache-bookworm@sha256:25d70665acee86d7231af7bc5464794abd14585f80210f85f22dfb0713ac8ec7`
- MariaDB: `mariadb:11.8.9@sha256:2439dcd7d14010ecd1ff7a4e1c5abe8e208c34fe35290744deeeaac3569043c3`
