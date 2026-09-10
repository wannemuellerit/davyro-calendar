# Davyro-Kalender-Architektur

```text
Browser
  |
  v
Davyro Mail/Cypht
  |-- IMAP/SMTP ------------------------------> Kunden-Mailserver
  |-- Einladungen und Outbox ----------------> Davyro-Mail-Portal
  `-- native Vue-Kalenderoberfläche
                |
                v
        Davyro Calendar API
          |-- internes CalDAV ---------------> Baïkal 0.12.1 --> Baïkal-Datenbank
          |-- sichere interne Bridge --------> Davyro-Mail-Portal --> SMTP/iMIP
          `-- abgesicherter Abruf ------------> öffentliche WebCal/ICS-Feeds
```

## Sicherheitsgrenzen

- Baïkal und MariaDB haben im Standalone-Stack keinen Host-Port.
- AgenDAV ist der einzige Webdienst mit einem veröffentlichten Port.
- AgenDAV und Baïkal besitzen getrennte Datenbanken und DB-Benutzer.
- Browser-URLs und interne Container-URLs sind getrennt.
- App-Navigation ist keine Autorisierung.
- Browser verwenden nur opake IDs; CalDAV-URLs, Datenbankkennungen und
  Mailpasswörter werden nicht ausgegeben.
- Interne Mail-/Kalender-Aufrufe verwenden Bearer-Secret, Timestamp, Nonce und
  eine Signatur über Methode, exaktes Request-Target und den unveränderten Body.
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
