# Basisimage-Sicherheitskorrektur vom 27.10.2026

Kalender, Baikal und die Entwicklungs-/Buildziele verwenden gepinnte Alpine-3.24-Basen. PHP bleibt bei 8.5.12 für AgenDAV und 8.4.27 für Baikal. Apache arbeitet über einen nur lokal erreichbaren PHP-FPM-Port. Bestehende Rewrites, Auth-Header, geschützte Verzeichnisse und queryfreie Logs bleiben erhalten. Der Prozessstarter beendet beide Dienste beim Ausfall eines Dienstes und reagiert auf `SIGTERM`.

Baikal 0.12.1 enthält im Release eine betroffene Symfony-YAML-Version. Nur dieses Paket wird anhand eines eigenen Composer-Lockfiles auf 7.4.21 aktualisiert. Der Metadaten-Patch verweigert unbekannte Upstream-/Ersatzversionen und prüft sowohl Composer-Inventar als auch Parser-Aufruf. Anwendung, übrige Abhängigkeiten und Autoload-Inventar bleiben erhalten.

Der separate Builder verwendet npm 12.2.1 mit den unten beschriebenen Paketkorrekturen; unbenutztes Corepack ist entfernt. Die zuvor dokumentierte, befristete Bootstrap-Frontend-Ausnahme wird durch diesen Basisimage-Scan nicht aufgehoben.
## npm im Entwicklungs-/Buildimage

Das von Node 22 mitgelieferte npm enthielt weitere betroffene Pakete. npm ist auf 12.2.1 aktualisiert; dessen weiterhin betroffene Pakete `ip-address` und `undici` sind anhand eines eigenen Integritäts-Lockfiles auf 10.7.4 und 6.29.1 korrigiert. Der Austausch verweigert unbekannte Ausgangsversionen. Unbenutztes Corepack wird entfernt. Diese Pakete gelangen nicht in die Produktionsimages.

## Lokale Prüfung

366 Backend-Tests mit 1.341 Assertions bestanden, zwei zuvor bestehende umgebungsabhängige Tests übersprungen. Zusätzlich zwei echte Baikal-Provisionierungs-Vertragstests mit 161 Assertions in einer separaten MariaDB-/Baikal-Umgebung bestanden. Vollständiger Produktions-Build, Entwicklerimage und Builder-Build einschließlich Composer/npm-Installation und sämtlicher Asset-Buildschritte bestanden. HTTP-Laufzeittests für Kalender und Baikal prüfen PHP, Rewrites, Authorization, Umgebungsvariablen, Schutz versteckter Dateien, Query-Redaktion, UID/GID 33, sauberes Stoppen und Ausfallweitergabe. Kalender, Baikal und Worker laufen lokal mit den neuen Images gesund; die native Mail-Kalenderansicht wurde im Browser geprüft.

## Scannergebnis und Umfang

Alle in [der Image-Inventur](2026-10-27-base-images-scan.json) aufgeführten finalen Images melden über sämtliche Schweregrade **null bekannte Befunde**. Es wurden keine Befunde ignoriert oder als „unfixed“ ausgeblendet. Image-IDs, Plattform und Betriebssystem sind enthalten. Die bisherige Inventur vom selben Tag bleibt als Vorher-Nachweis erhalten und ist für diese Images durch diesen Bericht abgelöst. Die numerische UID/GID 33 von `www-data` bleibt für vorhandene Datenvolumes erhalten.

Die Tests und Builds liefen ausschließlich lokal. Rohberichte, Build-/Testlogs und Browsernachweise liegen im übergeordneten Workspace unter `.local/base-image-security/`. Push und Merge verwenden `[skip ci] [skip deploy]`; es wird kein GitHub-CI-Lauf als Testnachweis behauptet. Keine Images oder Pakete veröffentlicht und keine Produktion ausgerollt. Die lokale Prüfung ersetzt nicht die spätere Abnahme der konkreten Zielumgebung.
