# What was removed before publishing

This is the source of the **live** Growvanta CRM — the PHP/MySQL edition that
currently runs the product. It is complete and working.

Four things were deliberately left out, because this repository is public.
Nothing else was changed.

| Removed | Why |
| --- | --- |
| `.env` | The live install's real credentials: database password, `APP_KEY`, Meta app secret, SMTP password. `.env.example` is here instead, with every variable name and empty values. |
| `database/data.sql` | A dump of the real tenant database — organisations, users, leads, activities, audit logs, and the integration rows that hold encrypted access tokens. This is customer personal data; it must never be published, and it must never be imported over a live install either. |
| `uploads/imports/` | Files uploaded by real users through the CSV import screen. |
| `dist/` | Deployment bundles from past releases (~9.4 MB). They are build output, not source, and several of them carry their own copy of `database/data.sql`. |

The `.env.example` in this folder also had its `SMTP_HOST`, `SMTP_USER` and
`SMTP_FROM` replaced with placeholders. They previously named the real alert
mailbox, which is not a secret but does not belong in a public file.

## To run this edition

Requires PHP 8.1+ with PDO MySQL, and MySQL 8 or MariaDB 10.4+. No Composer
dependencies.

```bash
cp .env.example .env          # then fill it in
php database/seed.php         # creates the schema and a first admin
php -S 127.0.0.1:8000 dev-server.php
```

`DEPLOYMENT.md` and `HOSTINGER-INSTALL.md` cover a real deployment.

**`APP_KEY` matters.** It encrypts the stored integration tokens. If you are
restoring an existing database, the `APP_KEY` must be the same value the data
was written with, or those tokens cannot be decrypted and every integration
has to be reconnected.

## Relationship to the Node/React tree

The repository root holds an in-progress conversion of this codebase to
Node + React. See `../docs/PORT-STATUS.md` for what has been converted so far.
This PHP tree is the reference implementation to port *from*, and the one
actually in production — it is not legacy.
