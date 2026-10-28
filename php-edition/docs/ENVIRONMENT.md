# Environment configuration

Everything in this file lives in `.env`, next to `config.php`, on the server.
None of it is editable from inside the CRM, and that is deliberate: these values
belong to the *install*, not to a company using it. `.env` is blocked from HTTP
by `.htaccess`.

Copy `.env.example` to `.env` and fill it in. **Do not rename `.env.example`** —
keep the template, or the next upgrade has nothing to compare against.

After any change to `.env`, the next request picks it up. There is no cache to
clear and nothing to restart.

---

## Application

| Variable | Required | Notes |
|---|---|---|
| `APP_ENV` | yes | `production` disables debug output and **refuses to boot** without a real `APP_KEY`. |
| `APP_DEBUG` | yes | `0` in production. Errors go to the PHP error log either way. |
| `APP_URL` | yes | No trailing slash. Must match the address people actually type. |
| `APP_KEY` | yes | 32+ random characters. |

`APP_URL` is not decoration. It builds the form embed snippet, invite links,
the Meta webhook URL and the OAuth redirect URI. If it is wrong, those four
things are wrong and nothing else complains.

`APP_KEY` derives session tokens and encrypts every stored integration secret
(Meta page tokens, per-company SMTP passwords). **Changing it signs everyone
out and makes the stored secrets undecryptable.** Generate one once:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

## Database

`DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` — from cPanel > MySQL
Databases. On Hostinger and most cPanel hosts `DB_HOST` is `localhost`, and the
database and user names both carry your account prefix.

## Sessions

`SESSION_TTL_HOURS` (default 12) and `REMEMBER_ME_TTL_DAYS` (default 30). Both
are enforced server-side; shortening them takes effect on the next request, not
retroactively.

## Uploads

`UPLOAD_DIR` (absolute path preferred, must be writable by PHP) and
`MAX_UPLOAD_MB`. `MAX_UPLOAD_MB` cannot exceed what PHP itself allows — if
`upload_max_filesize` or `post_max_size` in your host's PHP settings is smaller,
that smaller number wins and the browser gets a truncated request, not a
friendly error.

## Rate limiting

`RATE_LIMIT_WINDOW_MIN` `RATE_LIMIT_MAX` `AUTH_RATE_LIMIT_MAX`
`PUBLIC_CAPTURE_RATE_LIMIT_MAX`. Counted in the `rate_limit_hits` table, so they
work across processes without any cache server.

`AUTH_RATE_LIMIT_MAX` is the brute-force limit on sign-in. Raising it a lot is a
decision, not a convenience.

<a id="smtp"></a>

## SMTP — the platform mailbox

One mailbox sends for the whole install: invitations, password resets, and every
staff alert (new lead, follow-up due, the morning summary) for every company on
this server. Customers configure nothing for those to work.

| Variable | Example |
|---|---|
| `SMTP_HOST` | `smtp.hostinger.com` |
| `SMTP_PORT` | `587` for TLS, `465` for SSL |
| `SMTP_ENCRYPTION` | `TLS` or `SSL` |
| `SMTP_USER` | `alert@growvantacrm.online` |
| `SMTP_PASS` | the **mailbox password**, set where the mailbox was created |
| `SMTP_FROM` | `"Growvanta CRM <alert@growvantacrm.online>"` |

Two things people get wrong here:

- **`SMTP_PASS` is the mailbox's own password**, the one set in the hosting
  panel when the mailbox was created. It is not the hosting account password and
  not a separate "SMTP password" — on Hostinger they are the same thing.
- **The mailbox has to exist first.** Creating it in the panel is a separate
  step from writing it here.

Leave `SMTP_HOST` empty and the CRM still runs: alerts stay in the in-app
notification centre, the delivery log records that the platform mailer is not
configured, and invite and reset links are written to the PHP error log so you
can still hand them out.

This is **not** the address buyers hear from. That one is per company, set
inside the CRM under *WhatsApp & Email*, and is a different mailbox with its own
credentials.

<a id="meta"></a>

## Meta — Facebook & Instagram Lead Ads

From developers.facebook.com > your app > Settings > Basic.

| Variable | Notes |
|---|---|
| `META_APP_ID` | App ID. Public; it appears in the authorisation URL. |
| `META_APP_SECRET` | **Secret.** Never paste it into a browser, a form, or a chat. |
| `META_VERIFY_TOKEN` | Any string you choose. Paste the same value into Meta's webhook form. |
| `META_GRAPH_VERSION` | `v19.0` unless you have a reason. |

Three things must be registered in the Meta app dashboard, and they must match
`APP_URL` exactly:

1. **Facebook Login > Settings > Valid OAuth Redirect URIs**
   `https://your-domain.com/webhooks/meta_oauth.php`
2. **Webhooks > Page > `leadgen`**, callback URL
   `https://your-domain.com/webhooks/meta_leads.php`, verify token
   `META_VERIFY_TOKEN`.
3. **App Review** for `pages_show_list`, `pages_read_engagement`,
   `pages_manage_metadata` and `leads_retrieval`. Until the app is approved,
   only people listed as admins, developers or testers of the app can complete
   *Connect Facebook*. Your own company can connect on day one; other companies
   cannot, and the failure looks like a permissions error on Facebook's screen
   rather than anything in this CRM.

Once approved, each client company presses **Connect Facebook** on its own
Integrations page, authorises on its own Facebook account, and picks its pages.
Nobody handles a page id or an access token by hand.

## Cron

`CRON_TOKEN` — only needed if your host's cron can fetch a URL but cannot run a
PHP binary. Generate one:

```bash
php -r "echo bin2hex(random_bytes(16));"
```

Then point the scheduler at either form, once every 15 minutes:

```bash
/usr/local/bin/php /home/USER/public_html/cron/sweep.php
```

```
https://your-domain.com/cron/sweep.php?token=YOUR_CRON_TOKEN
```

The sweep raises follow-up and task alerts, sends the daily summary, and clears
abandoned Facebook authorisations. It is safe to run more often than needed — it
will not send the same alert twice.

## Seed data

`SEED_PASSWORD` — the password every demo account gets from
`database/seed.php`. Only meaningful on a fresh install, and worth emptying once
the real accounts exist.
