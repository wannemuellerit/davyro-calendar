.. _subscriptions:

ICS feed subscriptions
======================

AgenDAV can display external iCal feeds as read-only calendars in the sidebar.
This lets users follow public calendars - national holidays, sports schedules,
team shared calendars published as `.ics` URLs - without leaving AgenDAV.

Subscribed calendars are stored in the AgenDAV database, not on the CalDAV
server. They are read-only: events from the feed cannot be edited or deleted.

Enabling subscriptions
----------------------

Subscriptions are disabled by default. Enable them in ``config/settings.php``::

    $app['calendar.subscriptions'] = true;

The Davyro fork validates every target and redirect before fetching it.
Private and reserved addresses, credentials in URLs and ports other than 80
and 443 are rejected. Responses are subject to connection, total-size and
redirect limits and are cached. ``webcal://`` links are converted to HTTPS.
Administrators can optionally set a domain allowlist through
``calendar.subscriptions.allowed_domains``.

Adding a subscription (user)
-----------------------------

1. Click the **+** button next to "Calendars" in the sidebar.
2. Select **Subscribe to iCal feed**.
3. Paste the `.ics` URL into the URL field.
4. Choose a display name and colour.
5. Click **Save**.

The feed appears in the sidebar immediately. Its events are refreshed after
the configured cache interval.

Example: subscribing to a Nextcloud shared calendar
----------------------------------------------------

Nextcloud can publish any calendar as a public iCal feed.

1. In the Nextcloud Calendar app, open the three-dot menu of the calendar.
2. Choose **Copy public link**.
3. In AgenDAV, add a new iCal subscription and paste that link.

The calendar will appear read-only in AgenDAV. Changes made in Nextcloud
are reflected on the next page load.

Example: subscribing to a public holiday calendar
--------------------------------------------------

Many providers publish `.ics` holiday feeds, for example::

    https://calendar.google.com/calendar/ical/en.german%23holiday%40group.v.calendar.google.com/public/basic.ics

Paste the URL directly into the subscription dialog. No authentication is
supported for external feeds - the URL must be publicly accessible.

Removing a subscription
------------------------

Open the calendar's context menu in the sidebar and choose **Delete**. This
removes the subscription from the AgenDAV database. It does not affect the
original iCal feed or its source.
