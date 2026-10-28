# Growvanta CRM — Deployment Guide (PHP + MySQL)

This is the PHP/MySQL edition of Growvanta CRM. It runs on ordinary shared
hosting — cPanel, Plesk, Hostinger, or any Apache/LiteSpeed box with PHP and
MySQL. **No Node.js, no Composer, no build step.** Upload the files, import one
`.sql` file, fill in `.env`, and it runs.

---

## 1. Requirements

| Requirement | Minimum | Notes |
|---|---|---|
| PHP | 7.4 | 8.1+ recommended; tested on 8.4 |
| MySQL / MariaDB | MySQL 5.7 / MariaDB 10.3 | Needs the native `JSON` column type |
| PHP extensions | `pdo_mysql`, `openssl`, `json`, `curl`, `fileinfo`, `mbstring` | All standard on cPanel |
| Apache modules | `mod_rewrite` | `mod_headers` and `mod_deflate` optional but recommended |
| Disk | ~15 MB + uploads | |

On cPanel, check extensions under **Select PHP Version → Extensions**. Tick
`pdo_mysql`, `openssl`, `curl`, `fileinfo`, `mbstring` if any are unticked.

> **Why curl?** Outbound webhooks and the Meta Graph API calls use it. Without
> it the CRM still runs; those two features log an error instead of pretending
> to have sent something.

---

## 2. Upload the files

Upload the **contents** of this folder into your web root — on cPanel that is
`public_html`, or `public_html/crm` for a subfolder install.

```
public_html/
├── .htaccess           ← must be present; the app will not route without it
├── .env                ← you create this in step 4
├── index.html          ← the CRM shell
├── login.html
├── favicon.svg
├── public-form.js
├── css/                ← frontend (unchanged from the original build)
├── js/
├── api/                ← REST API (front controller + route handlers)
├── lib/                ← internals — blocked from HTTP access
├── services/           ← internals — blocked from HTTP access
├── database/           ← schema.sql + seed.php — blocked from HTTP access
├── cron/               ← sweep.php — blocked from HTTP access
├── webhooks/           ← meta_leads.php — publicly reachable by design
└── uploads/            ← file storage — blocked from HTTP access
```

If you install into a **subfolder**, set `APP_URL` to the full subfolder URL
(e.g. `https://example.com/crm`) and add `RewriteBase /crm/` to `.htaccess`
directly under `RewriteEngine On`.

### Permissions

```bash
chmod 755 uploads
chmod 600 .env
```

On cPanel, PHP runs as your own user, so `755` on `uploads/` is enough — do not
use `777`.

---

## 3. Create the database

**cPanel → MySQL® Databases:**

1. Create a database, e.g. `yourusr_growvanta`.
2. Create a user, e.g. `yourusr_crm`, with a strong password.
3. Add the user to the database with **All Privileges**.

**Import the schema — cPanel → phpMyAdmin:**

Import **two files, in this order**:

1. `database/schema.sql` — the base tables.
2. `database/migrations/001_realestate_saas.sql` — the multi-tenant real-estate
   layer (projects, project sharing, per-user permissions, WhatsApp/email
   configuration, message templates and delivery logs, export audit).
3. `database/migrations/002_subscriptions_and_form_projects.sql` — subscription
   state per client, the sign-in block, and per-project web forms.

In phpMyAdmin: select the database, **Import** tab → **Choose File** → **Go**,
once per file, in that order.

Or from a shell:

```bash
mysql -u yourusr_crm -p yourusr_growvanta < database/schema.sql
mysql -u yourusr_crm -p yourusr_growvanta < database/migrations/001_realestate_saas.sql
mysql -u yourusr_crm -p yourusr_growvanta < database/migrations/002_subscriptions_and_form_projects.sql
```

You should end up with **51 tables**.

> The two files are kept separate rather than merged so that an existing
> Growvanta install can take the migration on its own without touching data
> that is already there. Every statement in the migration adds a column or a
> table; none drops or rewrites anything.

> If phpMyAdmin rejects a file for size, gzip it first (`.sql.gz`) —
> phpMyAdmin accepts compressed dumps.

---

## 4. Configure `.env`

Copy `.env.example` to `.env` and fill it in:

```bash
cp .env.example .env
```

The three that matter most:

```ini
APP_URL=https://crm.yourdomain.com
APP_KEY=<64 random hex characters>
DB_NAME=yourusr_growvanta
DB_USER=yourusr_crm
DB_PASS=your-database-password
```

Generate `APP_KEY`:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

No shell? Use any 64-character random hex string from a password generator.

> **`APP_KEY` is load-bearing.** It derives session tokens and encrypts stored
> integration secrets (Meta page access tokens). Changing it later signs
> everyone out and makes stored Meta tokens unreadable — you would have to
> reconnect each Meta form. Set it once, back it up, leave it alone.

The app **refuses to boot** in `production` if `APP_KEY` is missing or shorter
than 32 characters. That is deliberate: a guessable key means forgeable
sessions.

---

## 5. Create the platform owner

This is a **multi-tenant SaaS platform**: one Super Admin at the top, and any
number of client companies (developers, brokers, channel partners, agencies)
underneath, each with a completely isolated CRM workspace.

Run the bootstrap once, after importing the SQL:

```bash
php database/platform_bootstrap.php
```

It creates the platform organisation, the Super Admin account, and brings any
pre-existing organisation up to the new schema. It prints the sign-in details
when it finishes. It is safe to run more than once — every step checks for its
own result first.

Set the credentials before running it if you want something other than the
defaults:

```bash
SUPER_ADMIN_EMAIL=you@yourcompany.com SUPER_ADMIN_PASSWORD='a-strong-password'   php database/platform_bootstrap.php
```

No shell access? cPanel → **Terminal**, or add a one-off cron job with that
command, let it run once, then delete the job.

**Sign in as the Super Admin, then create your first client:**

*Platform overview → Create new client.* Fill in the company, pick the company
type, upload their logo, and enter one admin contact. Everything that company
needs — roles, the hierarchy, lead statuses, lead sources, scoring
rules, both messaging channels and the template library — is provisioned
automatically. Their admin can sign in immediately at the same login page.

---

## 5b. Demo data (optional)

You have two options for populating a workspace.

### Option A — demo data (recommended for evaluating)

```bash
php database/seed.php
```

Builds a deliberately small, legible world rather than a big one:

| | |
|---|---|
| **5 client companies** | one of each business type, so project visibility can actually be demonstrated |
| **5 projects** | 2 private to their developer, 2 global, 1 shared with named partners |
| **10 users** | spread across the companies, in a real reporting hierarchy |
| **25 leads** | spread so every screen has something in it |
| **1 demo user** | `demo@prestige-estates.example` — owns exactly 5 leads and can see only those |

The demo user's 5-lead limit is not special-cased anywhere: they are a Sales
Executive, whose data scope is `OWN`, so the scope engine confines them to what
they own. That is the same rule that governs every executive in production.

It prints the sign-in details when it finishes. Running it again wipes and
rebuilds **every client company**; the platform organisation and the Super Admin
are left alone.

No shell access? cPanel → **Terminal**, or add a one-off cron job with the
command above, let it run once, then delete the job.

### Option B — start empty

Open `https://your-domain.com/login.html`, click **Create an organisation**, and
register. The first account becomes Super Admin and the organisation is
provisioned with default roles, statuses, sources and scoring rules.

**Either way:** change the seeded passwords before this touches real data.

---

## 6. How the platform is organised

### Tenancy

Every private record carries an `organization_id`, and every query is scoped to
it server-side. There is no way to reach another company's data by changing an
id in a request: a lead id from another tenant returns **404**, not the record.

| Level | Who | Sees |
|---|---|---|
| Platform | Super Admin | Every client company; can open any client's CRM |
| Tenant | Company Admin | Everything inside their own company |
| Hierarchy | Managers, team leaders, executives | Only what their role's data scope allows |

A Super Admin working inside a client sees an orange **Inside: {company}** bar
in the header, and entering that context is written to that client's own audit
log.

### Company types

The type chosen when creating a client changes how projects behave:

- **Developer** — sells its own stock. Its projects stay private to it, and it
  does **not** see the global project master at all: a developer has no business
  browsing other developers' towers.
- **Broker / Channel Partner / Agency** — sells other people's stock, so these
  are the companies the global project master exists for.

This is the business type doing real work, not a label. A developer that should
see a particular shared project can still be granted it by name; the type gates
the global master, not named sharing.

### Projects and the global project master

A project is owned by one company and shared outward by its **visibility**:

| Visibility | Who can use it | Who can edit it |
|---|---|---|
| `PRIVATE` | The owning company only | The owner |
| `SELECTED` | The owner plus companies the Super Admin named | The owner |
| `GLOBAL` | Every broker, channel partner and agency | The owner |

**The rule that matters most:** a project can be shared, a lead never is. Two
brokers selling the same tower each see only their own buyers, and the lead
count shown on a shared project is always the count for the company looking at
it.

Only a Super Admin can publish a project `GLOBAL` or grant it to named
companies (*Projects → open a project → Shared with*).

### Subscriptions and cutting off access

Each client carries its own subscription state, managed by the Super Admin at
*Clients → open a client → Billing & access*:

| Field | What it is for |
|---|---|
| Billing status | `Active` · `Trial` · `Payment overdue` · `Cancelled` |
| Plan, seats, monthly fee | What they are on, for the platform MRR figure |
| Trial ends / Renews on | A renewal date in the past marks them overdue automatically |
| Last payment, Billing notes | The PO number, the billing contact, whatever you need |

**Blocking sign-in is a separate, deliberate act.** Marking a client overdue
does *not* lock anyone out — being a day late should not stop a sales floor
working. When you do want that to stop, *Block sign-in for non-payment*:

- everyone at that company is signed out **immediately**, open sessions included;
- new sign-ins are refused with the reason you typed, so the customer reads
  *"Access to this workspace is paused: Invoice INV-2043 unpaid for 32 days"*
  rather than an unexplained error and a support ticket;
- **you keep access.** A platform admin can still open a blocked client, which
  is exactly when you need to get in and fix the billing;
- their data is untouched, and *Restore sign-in* undoes it in one click;
- both the block and the restore are written to that client's audit log.

This is separate from suspending a company (*Lifecycle* tab). Suspension is an
operational decision; the block is a billing lever, and they are undone by
different people for different reasons.

### What this CRM deliberately does not have

Contacts, accounts, opportunities, pipelines, tasks and campaigns were removed.
This is a real-estate CRM: the buyer **is** the lead, the deal **is** a booking
recorded as a lead status, and attribution lives on the lead's own source and
UTM fields.

The removal is structural, not cosmetic. Their API handlers and front-end views
are deleted, their routes are absent from the front controller, and their
permission modules are gone from the catalogue — so `/api/accounts` is a 404
with no code path behind it, for every role including the Super Admin.

Two things survive the cut, because a real-estate team needs them:

- **Follow-ups** live on the lead (`next_follow_up_at`), not in a task table.
  The Leads list, the dashboard queue and the due-date cron all read that field.
- **Campaign attribution** lives on the lead's `utm_campaign` and Meta
  ad/adset/campaign ids. The marketing report groups on exactly those.

Their database tables are left in place and simply unreferenced, so an existing
install keeps its history. Drop them only once you are sure you want that data
gone.

### Permissions

Three independent things decide whether an action is allowed, and all three are
checked on the server:

1. **Role permission** — does this role have `leads:reassign` at all?
2. **User override** — has this person been granted or revoked it individually?
   (*Users → ⋯ → Special access*)
3. **Data scope** — is the record, and the target user, inside what they manage?

Scope can differ per action: a manager can be allowed to *read* the whole
region but *export* only their own team.

Lead permissions are deliberately fine-grained, because in a real-estate
business these decide whose numbers move:

`read` · `create` · `update` · `delete` · `export` · `assign` · `reassign` ·
`import` · `merge` · `change_status` · `change_project` · `change_source`

`assign` places a lead that nobody owns. `reassign` takes one off somebody.

The platform-owner role (`super_admin`) exists **only** in the platform
organisation. A client company's roles start at Admin, because a role their
administrator can neither hold nor grant has no business sitting in their Roles
screen. `database/platform_bootstrap.php` removes it from tenants created by
earlier versions, and leaves it alone if anyone is somehow assigned to it.

*Roles & Permissions* has two tabs: **Roles**, which is what each role can do
and how much of the company it can see, and **People & roles**, which is who
holds which role. A role can be changed from there directly — sending someone
to a different screen for one dropdown is how permissions end up stale. The
user count on a role card is a link into that list.

---

## 7. Set up the cron job

Task reminders and follow-up alerts need a periodic sweep. The Node version ran
this on an in-process timer; PHP has no long-lived process, so it goes in cron.

**cPanel → Cron Jobs → Add New Cron Job**

- **Common settings:** Every 15 minutes (`*/15 * * * *`)
- **Command:**

```
/usr/local/bin/php /home/YOURUSER/public_html/cron/sweep.php
```

Check your PHP binary path under cPanel → **Select PHP Version**; it is
sometimes `/opt/cpanel/ea-php82/root/usr/bin/php`.

Running it more often than needed is harmless — nobody is notified twice about
the same task.

**If your host's cron can only fetch a URL:** set `CRON_TOKEN` in `.env`, delete
`cron/.htaccess`, and point the cron at:

```
https://your-domain.com/cron/sweep.php?token=YOUR_CRON_TOKEN
```

---

## 8. Website form integration

Two ways to get website leads into the CRM, both configured in the app under
**Forms**.

### Embed script (recommended)

Build the form in the CRM, then paste the snippet it gives you into your site:

```html
<script src="https://crm.yourdomain.com/embed/YOUR-FORM-KEY.js" async></script>
<div id="growvanta-form-YOUR-FORM-KEY"></div>
```

It renders the form, captures UTM parameters, `gclid` and `fbclid` from the
page URL automatically, and posts straight into the CRM.

### Hosted page / iframe

```html
<iframe src="https://crm.yourdomain.com/f/YOUR-FORM-KEY"
        style="width:100%;max-width:560px;height:640px;border:0"></iframe>
```

### Post directly from your own form

```
POST https://crm.yourdomain.com/api/public/forms/YOUR-FORM-KEY/submit
Content-Type: application/json
```

**Set the origin allow-list** on each form (Forms → *your form* → Allowed
origins) to the domains that may submit it. Leaving it blank allows any origin,
which is convenient while testing and worth tightening afterwards. Every form
also has a honeypot field and a per-IP rate limit.

---

## 9. Meta (Facebook & Instagram) Lead Ads

Leads from Lead Ads arrive in real time. Meta's webhook only tells you *that* a
lead exists, so the CRM fetches the answers from the Graph API using a page
access token — which is why each connected form stores one.

### 9.1 Create the Meta app

1. [developers.facebook.com](https://developers.facebook.com) → **My Apps** →
   **Create App** → type **Business**.
2. Add the **Webhooks** and **Facebook Login** products.
3. **App Settings → Basic** — copy the **App ID** and **App Secret**.

### 9.2 Fill in `.env`

```ini
META_APP_ID=your-app-id
META_APP_SECRET=your-app-secret
META_VERIFY_TOKEN=any-long-random-string-you-choose
META_GRAPH_VERSION=v19.0
```

`META_VERIFY_TOKEN` is yours to invent — it just has to match what you type into
Meta in the next step.

### 9.3 Subscribe the webhook

**Meta app → Webhooks → Page → Subscribe to this object:**

- **Callback URL:** `https://crm.yourdomain.com/webhooks/meta_leads.php`
- **Verify Token:** the same `META_VERIFY_TOKEN` value
- Click **Verify and Save**, then subscribe to the **`leadgen`** field.

Meta immediately GETs that URL with a challenge. If verification fails, check
that `.env` is saved, `APP_URL` uses https, and the URL is publicly reachable.

### 9.4 Get a page access token

In **Graph API Explorer**, select your app and your page, request the
`leads_retrieval`, `pages_show_list` and `pages_manage_metadata` permissions,
and generate a token. Use a **long-lived** page token — a short-lived one stops
working in about an hour.

### 9.5 Connect the form in the CRM

`POST /api/meta-forms` (or the Integrations screen) with:

| Field | Where it comes from |
|---|---|
| `metaPageId` | Your Facebook Page ID |
| `metaFormId` | The Lead Ads form ID (Meta → Publishing Tools → Lead Ads Forms) |
| `pageAccessToken` | The token from 8.4 — **encrypted at rest with `APP_KEY`** |
| `sourceId` | Which CRM lead source these become (defaults to *Meta Ads*) |
| `assignToUserId` / `assignToTeamId` | Optional — routing override |

Without a mapping the webhook records the delivery as `UNMAPPED` and does
nothing, rather than silently dropping a lead you paid for.

### 9.6 What gets captured

Every Meta lead is stored with its **`ad_id`, `adset_id`, `campaign_id`, `form_id`
and `page_id`**, so the marketing report can tie revenue back to the exact ad.
Meta's own `leadgen_id` is stored as the idempotency key — Meta retries
deliveries, and a retry will never create a second lead.

Unrecognised form questions are kept as custom-field values rather than dropped.

### 9.7 Testing and troubleshooting

Use **Meta's Lead Ads Testing Tool** to fire a test lead. Then check
`GET /api/meta-forms/events`, which logs every delivery with its status:

| Status | Meaning |
|---|---|
| `PROCESSED` | Lead created |
| `DUPLICATE` | Meta retried a lead already imported |
| `UNMAPPED` | No connected form for that page/form ID — connect it (8.5) |
| `NO_TOKEN` / `FETCH_FAILED` | Token missing or expired — regenerate it (8.4) |
| `INCOMPLETE` | The Meta form collected neither an email nor a phone number |

---

## 10. WhatsApp and email automation

Each client company configures its own channels under **WhatsApp & Email**. A
Super Admin can configure a client on their behalf from
*Clients → open a client → Messaging*.

### The three states

A channel is never described as working until it demonstrably can:

| State | Meaning | Will it send? |
|---|---|---|
| 🔴 **OFF** | The company switched the automation off | No |
| 🟠 **CONFIGURATION INCOMPLETE** | Switched on, but something required is missing or unproven | No |
| 🟢 **ACTIVE** | Switched on, complete, and proven by a successful test send | Yes |

Turning the switch on produces **orange**, not green. Green requires every item
on the checklist, including a test message that actually reached the provider.
Changing a credential clears that proof and drops the channel back to orange
until it is retested.

This is enforced on the server, not in the browser: `check_whatsapp_configuration()`
and `check_email_configuration()` re-run before **every** message. A channel
that is not green records a `SKIPPED` row in the delivery log with the reason,
rather than failing silently — see *WhatsApp & Email → Delivery log*.

### WhatsApp (Meta Cloud API)

1. In Meta Business Manager, add **WhatsApp** to your app and get a permanent
   access token, the phone number ID and the WhatsApp Business Account ID.
2. Register your welcome template with Meta and wait for approval. Business-
   initiated messages **must** use an approved template.
3. In the CRM: *WhatsApp & Email → Complete configuration*. Paste the token
   (stored AES-256-GCM encrypted, never shown again), the IDs and the sender
   number, and pick the template.
4. Mark the template **Approved** under the Templates tab once Meta approves it.
5. **Test whatsapp** → send to your own number. Only a delivered test turns the
   card green.

### Email (SMTP)

1. *WhatsApp & Email → Email → Complete configuration*.
2. SMTP host, port, encryption, username and password (encrypted at rest), plus
   the From address and From name.
3. Pick the welcome email template, then **Test email**.

SMTP goes out over a raw socket with STARTTLS or implicit TLS — no PHPMailer,
no Composer. Certificate verification is on, so the host needs a CA bundle
(see Troubleshooting if tests fail with an SSL error).

### Templates

Templates use `{{variable}}` placeholders. Supported out of the box:

`{{customer_name}}` `{{first_name}}` `{{last_name}}` `{{phone}}` `{{email}}`
`{{project_name}}` `{{project_location}}` `{{company_name}}`
`{{sales_person_name}}` `{{sales_person_phone}}` `{{lead_code}}`
`{{lead_source}}` `{{budget}}` `{{configuration}}`

A template referencing anything else is flagged as unmapped and will keep the
channel orange, rather than quietly rendering a blank into a customer's message.

---

## 11. REST API for other integrations

For Zapier, Make, n8n, landing-page builders or a custom site, create an API key
under **Settings → API Keys** and post to `/api/v1/leads`:

```bash
curl -X POST https://crm.yourdomain.com/api/v1/leads \
  -H "X-API-Key: gv_your_key_here" \
  -H "Content-Type: application/json" \
  -d '{
        "name": "Aarav Sharma",
        "email": "aarav@example.com",
        "phone": "+91 98765 43210",
        "company": "Northwind Traders",
        "utm_source": "google",
        "utm_campaign": "brand-search"
      }'
```

Field names accept camelCase **or** snake_case, and common aliases
(`mobile`, `email_address`, `company_name`, `message`, …) are mapped
automatically. `GET /api/v1/metadata` returns the full alias list plus your
sources, statuses and projects. A `project` field accepts a project **name**,
not just an id, so a portal feed can post what it already knows.

Other endpoints: `POST /api/v1/leads/batch` (up to 200 at once),
`POST /api/v1/leads/check-duplicate`, `GET /api/v1/leads?since=...`,
`GET /api/v1/ping`.

---

## 12. Going live checklist

- [ ] `APP_ENV=production` and `APP_DEBUG=0` in `.env`
- [ ] `APP_KEY` is 64 random hex characters, and backed up somewhere safe
- [ ] HTTPS is on (cPanel → SSL/TLS Status → **Run AutoSSL**) — session cookies
      only get the `Secure` flag over https
- [ ] Seeded demo passwords changed, or the demo organisation deleted
- [ ] `chmod 600 .env`, `chmod 755 uploads`
- [ ] Cron job for `cron/sweep.php` is running (check its output once)
- [ ] SMTP filled in, or you have accepted that notifications stay in-app
- [ ] Origin allow-lists set on public web forms
- [ ] `GET /api/health` returns `{"data":{"status":"ok","database":"up"}}`
- [ ] Database backups scheduled (cPanel → Backup, or a nightly `mysqldump` cron)

---

## 13. Running it locally (optional)

Apache is not required to try it on your own machine. PHP's built-in server
ignores `.htaccess`, so `dev-server.php` reproduces those rewrites:

```bash
php -S 127.0.0.1:4500 -t . dev-server.php
```

Then open `http://127.0.0.1:4500/login.html`. You still need a MySQL/MariaDB
server running and `.env` pointed at it. `dev-server.php` is development-only —
it is never used on Apache/LiteSpeed and can be deleted before uploading.

---

## 14. Troubleshooting

**A WhatsApp or email test fails with an SSL / certificate error**

The server has no CA bundle configured, so PHP cannot verify the provider's
certificate. Point PHP at one in `php.ini`:

```ini
curl.cainfo = "/etc/ssl/certs/ca-certificates.crt"
openssl.cafile = "/etc/ssl/certs/ca-certificates.crt"
```

(On cPanel this is usually already set; on a bare local PHP install it is not.)
Certificate verification is deliberately **not** disabled in the code — turning
it off would expose the access token to anyone on the network path.


**500 error, blank page**
`display_errors` is off by design so stack traces never leak. Check the PHP
error log — cPanel → **Errors**, or `error_log` in the CRM's directory.

**`{"code":"CONFIG_ERROR"}`**
`APP_KEY` is missing or under 32 characters. See step 4.

**`{"code":"DATABASE_ERROR"}`**
Database credentials are wrong, or the user was not added to the database with
All Privileges. On most shared hosts the real name is prefixed with your cPanel
username (`yourusr_growvanta`, not `growvanta`).

**Login works, then every request 401s**
Cookies are being dropped. Check that `APP_URL` exactly matches the address in
the browser — `https://crm.example.com` and `https://www.crm.example.com` are
different origins.

**404 on every `/api/...` call**
`.htaccess` is missing, or `AllowOverride` is off. On shared hosting it is
almost always the former — confirm the file uploaded (dotfiles are hidden by
default in most FTP clients and in cPanel's File Manager until you enable
**Show Hidden Files**).

**Uploads fail**
`uploads/` is not writable, or the file exceeds `MAX_UPLOAD_MB` /
`upload_max_filesize` / `post_max_size`. The last two are PHP settings —
cPanel → **Select PHP Version → Options**.

**Automation or webhooks do not fire**
They run *after* the response is flushed, which needs PHP-FPM or LiteSpeed. On
classic mod_php they still run, just before the response — slower, not broken.
Check `workflow_runs` and `webhook_deliveries`; both record every attempt with
its error.

---

## 15. What changed from the Node version

The CRM's behaviour, screens and API contract are unchanged — the frontend files
are byte-identical to the Node build, and every endpoint returns the same
`{data, meta}` / `{error}` envelope. What changed is underneath:

| Concern | Node | PHP |
|---|---|---|
| Runtime | Express, one long-lived process | PHP-FPM, process per request |
| Database | Prisma + SQLite | PDO + MySQL, prepared statements throughout |
| Sessions | Signed JWT | Opaque token in a DB-backed `sessions` table — revocation is instant |
| Rate limiting | In-memory | `rate_limit_hits` table (shared hosting rarely has Redis) |
| Background work | In-process timer | `fastcgi_finish_request()` for side effects + cron for the sweep |
| Dependencies | npm | **none** — no Composer, no vendor directory |
| Integration secrets | Plain JSON | AES-256-GCM encrypted at rest |

New in this edition: Meta Lead Ads ingestion (`webhooks/meta_leads.php`,
`/api/meta-forms`) and the ad-level attribution columns that make it useful.
