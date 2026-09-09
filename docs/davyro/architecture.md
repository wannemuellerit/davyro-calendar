# Davyro-Kalender-Architektur

```text
Browser
  |
  v
Davyro Kalender (AgenDAV-Fork) ---- Browser-Link ----> Davyro Mail
  |                                  später: Hub
  |
  +---- internes CalDAV ----> Baïkal 0.12.1
  |                              |
  +---- AgenDAV-Datenbank        +---- Baïkal-Datenbank
```

## Sicherheitsgrenzen

- Baïkal und MariaDB haben im Standalone-Stack keinen Host-Port.
- AgenDAV ist der einzige Webdienst mit einem veröffentlichten Port.
- AgenDAV und Baïkal besitzen getrennte Datenbanken und DB-Benutzer.
- Browser-URLs und interne Container-URLs sind getrennt.
- App-Navigation ist keine Autorisierung.
- Externe ICS-Abonnements bleiben deaktiviert, bis SSRF-Schutz, Limits und
  Cache implementiert sind.
- Ein späterer öffentlicher CalDAV-Zugang benötigt ein tenanterzwingendes
  Gateway; der direkte Baïkal-Port wird nicht freigegeben.

## Identitäten

Die technische Zielkennung basiert auf stabilen Davyro-IDs, beispielsweise
`t<tenant_id>-m<membership_id>`. E-Mail-Adressen sind Attribute und niemals die
Mandantengrenze. Der lokale Demo-Principal ist nur ein Fixture; produktive
Provisionierung und Launch Tickets folgen in eigenen Issues.
