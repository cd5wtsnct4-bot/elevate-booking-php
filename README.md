# Elevate SJC — Booking Portal

A login-only booking portal for Elevate SJC clients. Clients see live
availability merged from two Microsoft 365 calendars (`admin@seanp.co.za`
and the `info@seanp.co.za` shared mailbox) and submit meeting/training
requests, which sit as **pending** until an admin approves them. Approving a
request creates a matching all-day event on the chosen calendar; declining,
cancelling, or deleting removes it again.

There is no public sign-up — an admin creates every client account from the
**Clients** admin page, and the client gets a one-time link to set their own
password.

**Stack:** plain PHP (PDO/MySQL, native sessions, cURL for the Microsoft
Graph API) + vanilla HTML/CSS/JS. No Composer, no build step — deploys by
copying files to any PHP+MySQL shared host. See [DEPLOY.md](DEPLOY.md) for
the full cPanel + Azure App Registration walkthrough.

## Local development

Requires PHP 8.1+ with the `pdo_mysql`, `curl`, and `openssl` extensions
(all standard), and a local MySQL server.

```bash
mysql -u root -e "CREATE DATABASE elevate_booking_dev;"
mysql -u root elevate_booking_dev < sql/schema.sql

cp includes/config.example.php includes/config.php
# edit includes/config.php: DB_* to match your local MySQL, APP_ENCRYPTION_KEY
# to `php -r "echo bin2hex(random_bytes(32));"`, APP_BASE_URL to
# http://localhost:8000, and FORCE_SECURE_COOKIES to false.

php -S localhost:8000 -t public
```

Visit `http://localhost:8000/` — since no admin account exists yet, you'll
be prompted to create one directly. Microsoft Graph calendar sync is
optional locally; the app works fully (manual blocking + booking requests)
without `MS_CLIENT_ID`/`MS_CLIENT_SECRET` set.

## How availability is worked out

For a given date, in order:

1. Past dates are always unavailable.
2. Dates admin has manually blocked (**Blocked Dates**) are unavailable.
3. Dates with an **approved** booking are unavailable.
4. Dates with an event on any connected calendar (live Graph lookup) are
   unavailable.
5. Dates with someone else's **pending** request show as "requested".
6. A client's own pending request on a day shows distinctly to them.
7. Everything else is open for booking.

## Project layout

```
public/            document root — pages, assets, and the two JSON API endpoints
  client/           client-facing calendar + booking form
  admin/            client management, booking approval, blocked dates, calendar sync
  api/              availability.php + book.php (JSON, used by assets/js/calendar.js)
includes/           config, DB connection, session/auth helpers — not web-accessible
lib/                MicrosoftGraph.php, Availability.php, BookingSync.php, Mailer.php
sql/schema.sql      MySQL schema
```
