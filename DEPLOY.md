# Deploying to seanp.co.za (cPanel, PHP-only shared hosting)

This app is plain PHP + MySQL — no Composer, no build step, no shell access
required. Everything below can be done through cPanel's web UI.

## 1. Create the MySQL database

In cPanel → **MySQL® Databases**:

1. Create a database (e.g. `seanp_booking`).
2. Create a database user with a strong password.
3. Add that user to the database with **All Privileges**.
4. Open **phpMyAdmin**, select the new database, go to **Import**, and
   upload `sql/schema.sql` from this project.

## 2. Upload the files

The site is deployed into a **subfolder** so it's reachable at
`https://seanp.co.za/bookings` rather than the domain root. This project has
three top-level pieces:

- `public/` — its *contents* go into `public_html/bookings/` (create that
  folder if it doesn't exist).
- `includes/`, `lib/`, `sql/` — must sit as **siblings of the `bookings/`
  folder** (i.e. directly inside `public_html/`, next to `bookings/` — not
  inside it). Every file under `public/` reaches `includes/` via a fixed
  number of `../` segments counted from `public/` itself (e.g.
  `public/admin/*.php` uses `__DIR__ . '/../../includes/...'`), so once
  `public/`'s contents are nested one level deeper (inside `bookings/`),
  `includes/`, `lib/`, and `sql/` must move one level deeper too, to stay
  the same relative distance away.

So the layout on the server is:

```
public_html/
  bookings/             <- contents of this project's public/ folder
    index.php
    admin/ ...
  includes/             <- sibling of bookings/, both directly inside public_html/
  lib/
  sql/                  <- only needed for the phpMyAdmin import in step 1;
                           safe to delete after
```

Because `includes/`, `lib/`, and `sql/` end up inside `public_html/` (the
web-servable document root) in this layout — unlike a root deployment, where
they'd usually sit one level above it — the `.htaccess` with
`Require all denied` that ships in each of those three folders isn't just
defense in depth here, it's the thing actually keeping them from being
served directly. Confirm after upload that visiting
`https://seanp.co.za/includes/config.php` returns a 403, not your config
file.

Upload via cPanel **File Manager** (zip the project, upload, extract) or FTP.

## 3. Configure the app

In `includes/`, copy `config.example.php` to `config.php` and fill in:

- `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` — from step 1.
- `APP_BASE_URL` — `https://seanp.co.za/bookings` (no trailing slash). This
  must include the `/bookings` subfolder — every internal link, redirect,
  and asset path in the app is generated relative to this value (via the
  `url()` helper in `includes/functions.php`), so getting this one setting
  right is what makes the whole site resolve correctly under the subfolder.
- `APP_ENCRYPTION_KEY` — generate with:
  ```bash
  php -r "echo bin2hex(random_bytes(32));"
  ```
  (If you don't have shell access, ask your host to run this, or generate 64
  random hex characters with any password generator set to hex output.)
- `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` — used for client set-password
  emails. Use an address on your own domain (e.g. `no-reply@seanp.co.za`) to
  avoid spam filtering.
- `MS_CLIENT_ID` / `MS_CLIENT_SECRET` / `MS_TENANT_ID` — from step 4 below.

## 4. Create the Azure App Registration (Microsoft Graph)

1. Go to [portal.azure.com](https://portal.azure.com) → **Microsoft Entra
   ID** → **App registrations** → **New registration**.
2. Name it anything (e.g. "Elevate SJC Booking"). Supported account types:
   "Accounts in this organizational directory only" is fine if both
   `claudette@seanp.co.za` and `info@seanp.co.za` are on the same Microsoft
   365 tenant.
3. **Redirect URIs** → Web → add **both** of these (one app registration,
   two callback pages — one for connecting a calendar while already logged
   in, one for the "Sign in with Microsoft" button on the login page):
   - `https://seanp.co.za/bookings/admin/calendar-settings.php?action=callback`
   - `https://seanp.co.za/bookings/auth/microsoft-callback.php`
4. After creation, copy the **Application (client) ID** → `MS_CLIENT_ID`.
5. **Certificates & secrets** → **New client secret** → copy the secret
   **value** (not the ID) → `MS_CLIENT_SECRET`.
6. **API permissions** → **Add a permission** → **Microsoft Graph** →
   **Delegated permissions** → add `Calendars.ReadWrite`, `offline_access`,
   `User.Read`. Click **Grant admin consent** if available.
7. Set `MS_TENANT_ID` to your tenant ID (found on the app's Overview page),
   or `common` if you want personal Microsoft accounts to work too.

### Granting access to the info@ shared mailbox

For the admin account to connect `info@seanp.co.za`'s calendar, it needs
**Full Access** delegate permission on that shared mailbox. In the Microsoft
365 admin center: **Teams & groups** → **Shared mailboxes** → select
`info@seanp.co.za` → **Manage mailbox delegation** → add
`claudette@seanp.co.za` under **Full access**. (Or via Exchange PowerShell:
`Add-MailboxPermission -Identity info@seanp.co.za -User claudette@seanp.co.za -AccessRights FullAccess`.)

> **To-do:** this permission was previously granted to `admin@seanp.co.za`.
> If `claudette@seanp.co.za` is a different Microsoft 365 account, it needs
> this same Full Access + Calendar folder Editor permission granted to it
> separately before "Sign in with Microsoft" or shared-calendar sync will
> work for her — this has **not** been set up yet as part of this change.

## 5. First run

1. Visit `https://seanp.co.za/bookings/index.php` — since no admin account exists
   yet, you'll be prompted to create one directly (name, email, password).
   This is the only account ever created without an invite link. **Use
   `claudette@seanp.co.za` as the email** — Microsoft sign-in (next step)
   only works if it exactly matches an existing admin account's email.
2. Log in, go to **Calendar Sync**, and connect **info@seanp.co.za** as a
   shared mailbox: label it "Info", enter `info@seanp.co.za` in the shared
   mailbox field, and sign in as `claudette@seanp.co.za` (per the delegate
   permission granted in step 4).
   - You don't need to separately connect `claudette@seanp.co.za`'s own
     calendar here — signing in with the **"Sign in with Microsoft"**
     button on the login page (see below) does that in the same step.
3. Go to **Clients** → **Add client** to create your first client account.
   They'll get a one-time set-password link — copy/send it manually if the
   server's outbound `mail()` doesn't deliver (common on shared hosting
   until SPF/DKIM is configured for your domain).

## Signing in as admin with Microsoft

Once `MS_CLIENT_ID`/`MS_CLIENT_SECRET` are set, the login page shows a
**"Sign in with Microsoft"** button alongside the normal email/password
form. It authenticates against your Microsoft 365 tenant and, in the same
step, connects the signed-in account's own calendar (equivalent to
connecting "Admin" on the Calendar Sync page). It only works for emails that
already have an **active admin account** in this app — it never creates a
new admin account on its own, so a former employee or other tenant user
signing in with their own Microsoft account can't get in this way. The
`info@seanp.co.za` shared mailbox still needs its own separate "Connect
another" step on Calendar Sync, since there's no way to sign in directly as
a shared mailbox.

## Notes

- Booking granularity is whole days (a "Meeting" or a full-day "Training
  Session"), matching how Elevate SJC actually books onsite work — there's
  no time-of-day slot picker.
- If Microsoft Graph calls fail (expired consent, revoked permission,
  network issue), the site keeps working — client-submitted requests, admin
  approval, and blocked dates all work purely against the local database. A
  warning banner appears in the admin dashboard when a connected calendar
  has a sync error.
- There is no cron requirement — access tokens refresh on demand the next
  time they're needed.
