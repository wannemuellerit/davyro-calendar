# Davyro-Kalender-Architektur

```text
Browser
  |
  v
Davyro Mail/Cypht -- Postfach-Ticket --> Davyro Kalender (eingebettet)
     ^                                      |
     |                                      +---- internes CalDAV ----> Baïkal 0.12.1
     |                                      |                              |
     +---- SMTP/iMIP <----------------------+                              +---- Baïkal-Datenbank
     |
     +---- Cypht erkennt Einladungen ----> Mail-Portal ----> Kalender-API

Davyro Kalender ---- abgesicherter Abruf ----> öffentliche WebCal/ICS-Feeds
```

## Sicherheitsgrenzen

- Baïkal und MariaDB haben im Standalone-Stack keinen Host-Port.
- AgenDAV ist der einzige Webdienst mit einem veröffentlichten Port.
- AgenDAV und Baïkal besitzen getrennte Datenbanken und DB-Benutzer.
- Browser-URLs und interne Container-URLs sind getrennt.
- App-Navigation ist keine Autorisierung.
- Externe ICS-Abonnements prüfen Ursprungsziel und Weiterleitungen gegen
  private/reservierte IP-Netze, verwenden ausschließlich HTTP(S) auf 80/443,
  begrenzen Zeit und Größe und werden zwischengespeichert.
- Principal-Suche und Freigabe-POSTs erzwingen den aktuellen Mandantenpräfix.
- iMIP-Mail wird nur über ein verifiziertes Postfach gesendet, dessen Adresse
  dem Organisator beziehungsweise antwortenden Teilnehmer entspricht.
- Ein späterer öffentlicher CalDAV-Zugang benötigt ein tenanterzwingendes
  Gateway; der direkte Baïkal-Port wird nicht freigegeben.

## Identitäten

Die technische Zielkennung basiert auf stabilen Davyro-IDs:
`t<tenant_id>-u<user_id>`. E-Mail-Adressen sind Attribute und niemals die
Mandantengrenze. Davyro Mail liefert Principal, Mandantenpräfix und die
ausgewählte `mail_account_id` über das Einmal-Ticket. Davyro Kalender
provisioniert deterministisch mindestens einen nicht löschbaren Kalender
`mailbox-<account_id>` je verifiziertem Postfach. Zusätzliche Kalender werden
mit demselben Postfachpräfix angelegt; Termine und Einladungsantworten landen
dadurch im richtigen Postfachkontext.
