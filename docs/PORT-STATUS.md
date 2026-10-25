# Port status

This tree is a conversion of the PHP edition (`GrowVanta-PHP`) to Node + React.
It is **in progress**. This file is the honest inventory: what is written and
verified, and what is not here yet.

Nothing in this repository ships mock data, placeholder records or simulated
API responses. A screen that cannot yet get real data from a ported endpoint is
not in the build at all.

Last verified: 2026-10-25, against MariaDB 10.4 on port 3402, 52 tables.

---

## Done and verified

### Database

| Item | State |
| --- | --- |
| `database/schema.sql` | Copied unchanged — the product stays on MySQL |
| `database/migrations/001–008` | Copied unchanged |
| `server/scripts/migrate.js` | Written. Applies schema + migrations, records them in `schema_migrations`, idempotent |
| `server/scripts/seed.js` | Written. Platform org + super admin, optional `--demo` tenant |

Verified: a clean database migrates to **52 tables**; re-running reports every
file as already applied and changes nothing.

### Backend foundation (`server/src/lib`, `server/src/middleware`)

| Module | Replaces | Notes |
| --- | --- | --- |
| `config/index.js` | `config.php` | Refuses to boot in production on a weak `APP_KEY` |
| `lib/db.js` | `lib/db.php` | Pool, `dbOne/dbAll/dbExec/dbInsert/dbScalar`, transactions via `AsyncLocalStorage` |
| `lib/response.js`, `lib/errors.js` | `lib/response.php`, `lib/errors.php` | The `{data, meta}` / `{error}` envelope |
| `lib/util.js` | `lib/util.php` | ids, camelize, safe JSON, SQL datetimes |
| `lib/security.js` | `lib/security.php` | bcrypt, AES-256-GCM, TOTP, token hashing |
| `lib/auth.js` | `lib/auth.php` | DB-backed session tokens, actor assembly |
| `lib/permissions.js` | `lib/permissions.php` | 23-module catalogue, effective permissions |
| `lib/scope.js` | `lib/scope.php` | Hierarchy visibility, per-request cache |
| `lib/tenancy.js` | `lib/tenancy.php` | Multi-tenant guards, branding, billing state |
| `lib/rate_limit.js` | `lib/rate_limit.php` | DB-backed, so several workers share one allowance |
| `lib/audit.js` | `lib/audit.php` | Audit rows |
| `lib/apikey.js` | `lib/apikey.php` | API-key auth for `/api/v1` |
| `lib/validate.js` | `lib/validate.php` | Field validation → 422 with `details` |
| `lib/xlsx.js` | `lib/xlsx.php` | Zero-dependency XLSX writer |
| `middleware/index.js` | `api/index.php` guards | CSRF, rate limit, `requireAuth`, `requirePermission`, error handler |

### Services

| Module | Replaces | State |
| --- | --- | --- |
| `services/provisioning.js` | `services/Provisioning.php` (467 ln) | Done — roles, statuses, sources, scoring rules, templates, integrations |
| `services/comms.js` | `services/Comms.php` — templating part only | Partial: `templateVariables`, `renderTemplate`, unmapped-variable check. Sending is not ported |
| `services/mailer.js` | PHP `mail()` wrapper | Done — nodemailer; reports `SMTP_NOT_CONFIGURED` instead of throwing |

### API routes

| Route file | Replaces | Endpoints |
| --- | --- | --- |
| `routes/auth.js` | `api/auth.php` (420 ln) | 16: register, login (+2FA), logout, logout-all, me, sessions (list/revoke), forgot/reset/change password, send-verification, verify-email, 2FA setup/enable/disable, accept-invite |
| `app.js` | `api/index.php` | `/api/health`, CORS, cookie and CSRF wiring, guarded router |

### Client

| Page | State |
| --- | --- |
| `/login` | Done — including the two-factor step |
| `/register` | Done — provisions a whole tenant |
| `/forgot-password`, `/reset-password` | Done |
| `/accept-invite`, `/verify-email` | Done |
| `/` (dashboard) | Done — shows only session facts, no business metrics yet |
| `/profile` | Done — read-only, with the effective permission matrix |
| `/security` | Done — password change, 2FA setup/enable/disable, resend verification, sign out everywhere |
| `/sessions` | Done — list and revoke |

The design system (`client/src/styles/app.css`) is the PHP edition's own
stylesheet, carried over unchanged, so the two builds look identical.

### Verification performed

Against the running server and the real database:

```
GET  /api/health                      -> {"status":"ok","database":"up"}
POST /api/auth/login  (correct)       -> user, role Admin, scope ORGANIZATION, 21 modules
POST /api/auth/login  (wrong pass)    -> 401 UNAUTHENTICATED
POST /api/auth/login  (empty body)    -> 422 VALIDATION_ERROR + per-field details
GET  /api/auth/me     (no cookie)     -> 401 UNAUTHENTICATED
POST ...              (no CSRF header)-> 403 FORBIDDEN
POST /api/auth/logout-all (with CSRF) -> {"sessionsRevoked":1}
GET  /api/auth/me     (after revoke)  -> 401   (revocation is immediate)
```

In the browser, against the same server: sign-in, dashboard, profile
permission matrix, session list, and the full two-factor cycle — setup,
enable with a real TOTP code, disable with password confirmation.

---

## Not in this build

Each item below needs the API module named beside it. None of them are stubbed
or faked in the UI; they are simply absent.

### Services still to port (~5,350 lines of PHP)

| Service | Lines | Why it matters |
| --- | --- | --- |
| `Comms.php` (sending) | 721 | Email/WhatsApp delivery, queueing |
| `Reports.php` | 684 | Every figure on the reporting screens |
| `MetaConnect.php` | 623 | Facebook page/form connection, token storage |
| `LeadService.php` | 506 | **The lead funnel.** Every creation path goes through it |
| `StaffNotify.php` | 505 | Internal notifications |
| `Automation.php` | 375 | Rule engine |
| `MetaLeads.php` | 303 | Lead-ad ingestion |
| `Projects.php` | 235 | Project/inventory logic |
| `Filters.php` | 207 | Saved-view filter compilation |
| `Duplicates.php` | 203 | Duplicate detection and merge |
| `Assignment.php` | 175 | Round-robin / rule-based assignment |
| `Conditions.php` | 173 | Condition evaluation for rules |
| `Scoring.php` | 165 | Lead scoring |
| `Webhooks.php` | 142 | Outbound webhook delivery |
| `CustomFields.php` | 122 | Per-tenant custom fields |
| `Notifications.php` | 114 | In-app notifications |
| `Events.php` | 71 | Domain events |
| `SequenceCodes.php` | 27 | Human-readable record codes |

`LeadService.php` must be ported before any route that can create a lead.
Direct `INSERT INTO leads` is a bug in this codebase — scoring, assignment,
duplicate detection and events all hang off the one funnel.

### API routes still to port (~6,850 lines of PHP)

`leads` (622), `users` (531), `publicCapture` (511), `platform` (504),
`projects` (467), `automation` (448), `settings` (345), `imports` (328),
`integrations` (228), `targets` (216), `teams` (215), `email` (209),
`metaForms` (203), `templates` (196), `roles` (175), `publicApi` (158),
`activities` (157), `webhooks` (149), `whatsapp` (147), `forms` (129),
`documents` (124), `search` (113), `reports` (106), `savedViews` (102),
`audit` (93), `apikeys` (82), `notifications` (51).

### Entry points still to port

| File | Lines | Purpose |
| --- | --- | --- |
| `webhooks/meta_leads.php` | 113 | Meta lead-ad webhook receiver |
| `webhooks/meta_oauth.php` | 60 | Facebook OAuth callback |
| `cron/sweep.php` | 69 | Scheduled sweep → `server/src/jobs/sweep.js` |

### Client screens still to port (~9,450 lines of JS)

`automation` (971), `communication` (681), `leads` (558), `leadDetail` (553),
`settings` (544), `integrations` (520), `clientDetail` (488), `developers`
(466), `users` (392), `forms` (377), `roles` (367), `clients` (365),
`leadForm` (360), `projects` (348), `projectDetail` (320), `imports` (302),
`teams` (244), `reports` (240), `performance` (240), `dashboard` (230),
`userPermissions` (189), `shared` (170), `platformDashboard` (155), `audit`
(144), `activities` (124), `notifications` (105).

---

## External dependencies not satisfied here

These are configuration the receiving developer must supply. They are named in
`server/.env.example` with empty values; nothing in the code invents them.

| Dependency | Needed for | State |
| --- | --- | --- |
| SMTP host/user/password | Password reset, invitations, verification mail | **Not configured.** `mailer.js` returns `SMTP_NOT_CONFIGURED` and the API logs the link instead of silently succeeding |
| `META_APP_ID`, app secret | Facebook page connection and lead ads | **Not configured.** Also blocked upstream: the Facebook app holds Standard Access for `pages_show_list`, which cannot read Pages owned by another business portfolio |
| `META_VERIFY_TOKEN` | Meta webhook handshake | **Not configured** |
| WhatsApp Business credentials | WhatsApp templates and sending | **Not configured** |
| Production `APP_KEY` | Decrypting tokens written by the PHP edition | Must be **the same value** as the live PHP install, or stored Meta tokens become unreadable |

## Known differences from the PHP edition

1. **Transactions** carry their connection through `AsyncLocalStorage`. PHP had
   one PDO handle per request, so a transaction covered every nested helper
   automatically; a Node pool does not, and a nested helper on a second
   connection would survive a rollback.
2. **Runtime requirement.** The PHP edition was built for Hostinger shared
   hosting, which has no Node runtime. This tree needs Node 20+ — a VPS,
   a container, or any host that runs a long-lived process.
3. **`exceljs` was not used.** It pulls a vulnerable `uuid`. The PHP edition's
   own XLSX writer was ported instead, which also keeps export bytes identical.
