# Entwicklung, Test-Image und Issue-Governance

## Reproduzierbares Test-Image

Der Docker-Build besitzt neben dem produktiven `runtime`-Target ein getrenntes
`test`-Target. Seine PHP-, Composer-, Node- und npm-Basis ist per Version und
Image-Digest festgeschrieben. PHP- und Node-Abhängigkeiten werden ausschließlich
aus `composer.lock` und `package-lock.json` installiert. Das Image enthält
PHPUnit, PHP-CS-Fixer und EditorConfig Checker; das produktive Apache-Image
enthält weder Tests noch Entwicklungsabhängigkeiten.

Das Test-Image kann unabhängig vom lokalen PHP- oder Node-Setup ausgeführt
werden:

```bash
docker build --target test --tag davyro-calendar-test:phase0 .
docker run --rm davyro-calendar-test:phase0
```

## Baïkal-0.12.1-Vertrag

`tests/phase0_contract.sh` ist das verbindliche Phase-0-Gate. Es führt folgende
Prüfungen aus:

1. Das reproduzierbare Test-Image wird neu gebaut.
2. Installierte Entwicklungswerkzeuge und der vollständige PHPUnit-Lauf werden
   im Image geprüft.
3. Ein flüchtiger MariaDB-Dienst und das echte Baïkal-Image werden gestartet.
4. Das Baïkal-Image muss die Version `0.12.1` ausweisen. Release-Archiv und
   SHA-256-Prüfsumme sind im Docker-Build festgeschrieben.
5. Der Integrationstest prüft die vom direkten Provisionierungsadapter
   verwendeten Tabellen, Spalten und Unique-Indizes gegen das echte Schema.
6. Provisionierung, erneute idempotente Provisionierung, Migration eines
   Legacy-Termins, eingeschränkte Objektsuche, Share-Empfänger und endgültiges
   Löschen eines Postfachkalenders werden real gegen MariaDB ausgeführt.

```bash
bash tests/phase0_contract.sh
```

Der Test akzeptiert ausschließlich eine Datenbank, deren explizit erwarteter
Name auf `_contract_test` endet. Dadurch kann ein versehentlich gesetzter DSN
nicht zum Löschen von Produktionsdaten führen. Der Compose-Stack veröffentlicht
keine Ports und wird nach dem Test einschließlich seiner Daten entfernt.

Bei einem geplanten Baïkal-Upgrade müssen Versionsnummer und Prüfsumme im
Baïkal-Dockerfile sowie im Contract-Compose gemeinsam geändert werden. Der
Schema- und Provisionierungstest muss in demselben Pull Request bewusst an das
neue Upstream-Schema angepasst werden. Ein unbemerkter Versionswechsel ist
damit nicht zulässig.

## Umgang mit bestehenden Änderungen

Vor Beginn eines Planpunkts werden Branch, Tracking-Stand, `git status` und
`git diff --stat` dokumentiert. Vorhandene Änderungen werden nicht verworfen
oder durch einen Neuaufbau ersetzt. Zusammengehörige Änderungen werden nach
Testbasis, Datenvertrag, Produktfunktion und Betrieb getrennt committed, damit
Review und Rücknahme ohne Verlust unabhängiger Arbeiten möglich bleiben.

## Issue- und PR-Governance

Das Standalone-v1-Epic ist die übergeordnete Produktsicht. Jeder umgesetzte
Planpunkt benötigt ein verknüpftes Issue mit Phase, klarer Abgrenzung und
prüfbaren Abnahmekriterien. Ein Pull Request nennt dieses Issue mit `Closes #…`
und dokumentiert die tatsächlich ausgeführten Tests.

Ein Punkt gilt erst als erledigt, wenn:

- Implementierung, automatisierte Tests und betroffene Dokumentation im selben
  Review-Stand vorliegen,
- der CI-Lauf des aktuellen Commits erfolgreich ist,
- manuelle oder externe Abnahmen ausdrücklich mit Ergebnis dokumentiert sind,
- offene Restarbeiten nicht als erledigte Checkbox dargestellt, sondern als
  eigenes Folge-Issue geführt werden,
- Merge, Release und Deployment als getrennte Zustände behandelt werden.

Die GitHub-Checkboxen werden erst nach erfolgreichem CI aktualisiert. Änderungen
an GitHub-Issues gehören nicht in den Docker-Testlauf und werden von einem
Maintainer nach Review des zugehörigen Commits vorgenommen.
