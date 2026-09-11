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
ausgewählte `mail_account_id` über das Einmal-Ticket. Nach dem erfolgreichen
Speichern provisioniert die persistente Mailbox-Lifecycle-Queue deterministisch
einen nicht löschbaren Kalender `mailbox-<account_id>` je verifiziertem
Postfach. Der Login bleibt ein idempotenter Reparaturpfad für ausgebliebene
Aufträge. Primär- und Zusatzkalender werden als exakte Postfachbindungen
persistiert; URI-Präfixe sind weder Lese- noch Löschberechtigung. Dadurch
landen Termine und Einladungsantworten ausschließlich im gebundenen
Postfachkontext. Baïkal-Felder werden vor dem Schreiben auf die gepinnten
Schema-Grenzen normalisiert: funktionale E-Mail-Adressen dürfen höchstens 80
Byte umfassen, Anzeigenamen werden UTF-8-sicher auf 80 Zeichen beim Principal
und 100 Zeichen beim Kalender gekürzt.

Ändert sich eine Postfachadresse, liefert Davyro Mail die ausschließlich aus
früheren verifizierten Adressen gebildeten `organizer_aliases`. Der
Provisionierungsauftrag ersetzt `ORGANIZER` nur in exakt an dieses Postfach
gebundenen Terminen, lässt UID und fremde Organisatoren unverändert und erhöht
`SEQUENCE` je betroffener Serie genau einmal. Serien mit Teilnehmenden erzeugen
anschließend genau einen neuen `REQUEST` in der persistenten iMIP-Outbox. Ein
identischer Retry findet keine alte Organisatoradresse mehr und ist ein No-op.

Archiv- und Purge-Aufträge benötigen den vom Mail-Portal signierten
`purge_after`-Zeitpunkt. Ein abgelaufener Purge-Auftrag kann eine ausgebliebene
Archivzustellung sicher nachholen, verkürzt aber niemals eine bereits
persistierte spätere Aufbewahrungsfrist. Dieselbe `lifecycle_version` ist fest
an genau eine Aktion gebunden; nur ein identischer Retry bleibt idempotent.
Jede erfolgreiche Antwort bestätigt `mailbox_id`, die angeforderte
`lifecycle_version`, `action` und `status`. Bei `stale_ignored` wird zusätzlich
`current_lifecycle_version` ausgegeben.

Launch-Tickets und interne Einladungsaufrufe tragen dieselbe
`lifecycle_version`. Vor Reparatur, Provisionierung oder CalDAV-Schreibzugriff
sperrt die Calendar API den persistierten Lifecycle-Datensatz und verwirft
veraltete beziehungsweise archivierte Postfachzustände. Auch bestehende
Browser-Sitzungen leiten sichtbare Postfächer nur noch aus aktiven,
persistierten Primärbindungen ab. Kalender-, Availability- und WebCal-
Mutationen laufen unter derselben Sperre; nach Archivierung oder Purge liefert
die API neutrale 404-Antworten, ein vollständig veralteter Kontext 410.
