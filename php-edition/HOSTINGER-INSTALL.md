# Growvanta CRM — installing on Hostinger

Shared hosting (Premium / Business / Cloud). Nothing here needs SSH, though
having it makes two steps easier. Allow about 20 minutes.

`DEPLOYMENT.md` is the full reference — this is the short path for hPanel.

---

## 1. PHP version and extensions

**hPanel → Websites → Manage → Advanced → PHP Configuration**

- **PHP version:** 8.1 or newer (8.2/8.3 are good choices).
- **PHP extensions** tab — make sure these are ticked:

  `pdo_mysql` · `openssl` · `mbstring` · `fileinfo` · `curl` · `json` · `zlib`

  On Hostinger all seven are usually on by default. `curl` is the one worth
  checking: without it outbound webhooks and Meta Lead Ads stop working, though
  the rest of the CRM runs fine.

> Hostinger runs **LiteSpeed**, which reads `.htaccess` the same way Apache
> does. The rewrite rules in this package work as-is.

---

## 2. Create the database

**hPanel → Databases → Management**

1. Create a database and a user (Hostinger creates both together and prefixes
   them, e.g. `u123456789_growvanta` / `u123456789_crm`).
2. Use the generated strong password, or set your own. **Copy it now** — it is
   shown once.
3. Note the host. On Hostinger shared hosting it is `localhost`.

---

## 3. Upload the files

**hPanel → Files → File Manager**, or FTP.

Upload the **contents** of this package into `public_html` — so that
`index.html` sits directly in `public_html`, not inside a subfolder.

Two things people miss:

- **`.htaccess` is a hidden file.** In File Manager, turn on
  *Settings → Show hidden files* before uploading, or it silently will not
  arrive and every page but the homepage 404s.
- Uploading the `.zip` and using File Manager's **Extract** is far faster than
  dragging hundreds of files over FTP.

> ### Upgrading an install that already works
>
> **Do not touch `.env`, and do not rename `.env.example` a second time.**
> Your `.env` holds the database password and `APP_KEY`; the package ships
> `.env.example` beside it, and renaming or overwriting puts the example values
> back. The site then stops with *"APP_KEY is missing or too short"* and
> *"Access denied for user 'cpaneluser_crm'"* - those are the example's own
> placeholders, not your settings.
>
> Extract the new files over the old ones, leave `.env` alone, and delete the
> uploaded `.zip` afterwards - a package left in `public_html` is downloadable
> by anyone and contains `database/data.sql`.

**Serving from a subfolder instead** (e.g. `yourdomain.com/crm`): upload into
`public_html/crm`, and set `APP_URL=https://yourdomain.com/crm` in step 5. No
other change is needed.

---

## 4. Import the database

**hPanel → Databases → phpMyAdmin**, select your database, **Import** tab.

Import these files **in this order**, one at a time:

1. `database/schema.sql`
2. `database/migrations/001_realestate_saas.sql`
3. `database/migrations/002_subscriptions_and_form_projects.sql`
4. `database/migrations/003_staff_lead_notifications.sql`
5. `database/data.sql` — **your existing content**

You should end up with **51 tables**. If phpMyAdmin rejects a file for size,
gzip it first — it accepts `.sql.gz`.

**About `data.sql`.** It carries the companies, users, roles, projects, lead
statuses, sources, templates and settings from the system this release was built
from — so the site comes up already configured rather than blank. It contains no
login sessions and no rate-limit counters: those belong to the machine they were
created on.

It does contain **password hashes**, so everyone signs in with the password they
already use. Hashes cannot be reversed, but they are still credentials — do not
post this file anywhere public, and delete the uploaded copy once imported.

Skip file 4 if you want an empty system instead. In that case you must do step 7;
with `data.sql` imported you can skip step 7, because the Super Admin already
exists.

---

## 5. Configure `.env`

In File Manager, rename `.env.example` to **`.env`** and edit it:

```ini
APP_ENV=production
APP_DEBUG=0
APP_URL=https://crm.yourdomain.com     # no trailing slash, must match reality
APP_KEY=                                # see below

DB_HOST=localhost
DB_NAME=u123456789_growvanta
DB_USER=u123456789_crm
DB_PASS=your-database-password
```

**APP_KEY** must be 32+ random characters. It derives session tokens and
encrypts stored integration secrets, so **changing it later invalidates both**
— set it once and keep it.

- With SSH: `php -r "echo bin2hex(random_bytes(32));"`
- Without: use any password generator set to 64 hex characters.

Leave the rest at their defaults for now. SMTP and Meta can be filled in later
from inside the CRM.

---

## 6. Check it before you sign in

Open **`https://your-domain.com/install-check.php`**.

It verifies PHP version, extensions, `.env`, the database connection, whether
all 51 tables arrived, and that the upload directory is writable. Fix anything
red, reload, then continue.

> The check answers over the web until the first successful sign-in, then
> returns 404 by itself. Delete the file once you are done anyway.

Then open **`https://your-domain.com/api/health`**. You want:

```json
{"data":{"status":"ok","database":"up","environment":"production"}}
```

A 404 here means `.htaccess` did not upload (see step 3).

---

## 7. Create the Super Admin

> **Skip this step if you imported `database/data.sql`** — the Super Admin came
> with it, and you sign in with your existing password. This step is for a blank
> install only.

This creates the platform owner — the account that creates client companies.

**With SSH** (hPanel → Advanced → SSH Access):

```bash
cd ~/public_html
SUPER_ADMIN_EMAIL=you@yourdomain.com SUPER_ADMIN_PASSWORD='a-strong-password' \
  php database/platform_bootstrap.php
```

**Without SSH**, use a one-off cron job — hPanel → Advanced → Cron Jobs:

- Command — keep the two variables, so you choose the password rather than
  having to read it back out of cron output:

  ```
  SUPER_ADMIN_EMAIL=you@yourdomain.com SUPER_ADMIN_PASSWORD='a-strong-password' /usr/bin/php /home/uXXXXXXXXX/public_html/database/platform_bootstrap.php
  ```

- Schedule: every minute
- Let it run once, sign in to confirm, then **delete the cron job**.

Replace `uXXXXXXXXX` with your account username (visible in File Manager's path).

**If you supply no password at all**, the script generates a random one and
prints it — once, nowhere else. Over SSH you will see it on screen. Via cron you
would have to dig it out of the cron email, which is why the command above sets
it explicitly. The account is created as `superadmin@growvanta.com` unless you
pass `SUPER_ADMIN_EMAIL`.

> The `SEED_PASSWORD` value in `.env` is demo-data only and is **never** used
> for the Super Admin on a `production` install — it ships in `.env.example`, so
> it is public knowledge.

---

## 8. The follow-up cron

This raises follow-up reminders and processes queued work. Once every 15
minutes is plenty.

**hPanel → Advanced → Cron Jobs → Create:**

- Command: `/usr/bin/php /home/uXXXXXXXXX/public_html/cron/sweep.php`
- Schedule: **Custom** → `*/15 * * * *`

If your plan restricts PHP cron, set `CRON_TOKEN` in `.env` to a random string
and point any URL-fetching cron at:

```
https://your-domain.com/cron/sweep.php?token=YOUR_CRON_TOKEN
```

(`cron/.htaccess` blocks that directory by default — remove it only if you need
the URL trigger.)

---

## 9. Lock it down

- **Force HTTPS:** hPanel → Websites → Manage → Security → SSL. Turn on the
  free SSL certificate and **Force HTTPS**. Sessions use secure cookies once
  the site is served over HTTPS.
- **Delete `install-check.php`.**
- Confirm `https://your-domain.com/.env` returns **403**, and that
  `/database/schema.sql` does too. Both are blocked by `.htaccess`; if either
  downloads, `.htaccess` is not being read and you should stop and fix that
  before going further.
- Change any password you set from a default.

---

## 10. First run

1. Sign in at `https://your-domain.com/login.html` as the Super Admin.
2. **Platform overview → Create new client** — company name, type, logo, and
   one admin contact. Their whole workspace is provisioned automatically.
3. Give that admin their sign-in details. They take it from there.

---

## Troubleshooting

**Every page except the homepage 404s**
`.htaccess` did not upload. It is hidden — enable *Show hidden files* in File
Manager and check it sits next to `index.html`.

**`/api/health` returns 500**
Almost always the database. Re-check `DB_NAME` / `DB_USER` / `DB_PASS` against
hPanel, remembering Hostinger's `uXXXXXXXXX_` prefix on both name and user.

**"Application key is not configured"**
`APP_KEY` is empty or shorter than 32 characters. See step 5.

**Uploads fail**
Set the upload directory to 755 in File Manager (right-click → Permissions), or
set `UPLOAD_DIR` in `.env` to an absolute path such as
`/home/uXXXXXXXXX/public_html/uploads`.

**WhatsApp or email test fails with an SSL error**
The host cannot verify the provider's certificate. On Hostinger this is rare,
but if it happens the fix is a CA bundle in `php.ini` — see the Troubleshooting
section of `DEPLOYMENT.md`. Do not disable certificate verification.

**Everything works but nobody receives email**
Expected until you configure it. In the CRM: **WhatsApp & Email → Email →
Complete configuration**, using your Hostinger mailbox SMTP details
(`smtp.hostinger.com`, port 587, STARTTLS). Then press **Test email** — the
channel stays amber until a test actually lands.
