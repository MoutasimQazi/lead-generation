# Movenetics Lead Search — deployment

Authenticated lead search over MariaDB, with admin-managed dataset uploads.
PHP 8.1+, no composer, no build step.

---

## What changed, and why

The site used to be a single static page that called the n8n webhook directly
from the browser, keeping the webhook URL and API key in `localStorage`. That
made a login pointless — anyone who could open the page could read the key and
call n8n themselves.

Now a small PHP backend sits in front:

| Before | Now |
|---|---|
| Webhook URL + API key in the browser | Held in `.env`, attached server-side by `/api/search` |
| Anyone with the URL could search | Session login, `admin` and `employee` roles |
| One hard-coded table | Any number of uploaded datasets, each searchable or not |
| No way to add data | Admin uploads CSV/XLSX; a table is created and loaded |

---

## Requirements

- **PHP 8.1 or newer** (cPanel → MultiPHP Manager). The code uses `never` return
  types and readonly properties; 8.0 will not parse it.
- **Extensions:** `pdo_mysql`, `mbstring`, `curl`, `json`, `zip`, `xml`, `iconv`.
  `zip` and `xml` are needed only for `.xlsx` — without them CSV still works and
  spreadsheet uploads report a clear error.
- **MariaDB** with a user holding `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER,
  DROP, INDEX, REFERENCES` on `movenetics_lead`.

`CREATE`/`DROP`/`ALTER` are genuinely required — the upload feature creates
tables. That is a real privilege, and the reason uploads are admin-only.

---

## 1. Upload the files

`app/` must sit **outside** the document root. On a normal cPanel account:

Everything goes in **one folder**: the document root of the subdomain
(`lead.moveneticsdigital.com`). Upload the repo's contents as-is.

```
<document root>/
├── .htaccess             ← REQUIRED. Blocks app/, var/ and .env.
├── .user.ini             ← PHP upload limits
├── .env                  ← your credentials, chmod 600
├── index.php             ← API front controller
├── setup.php             ← delete after first run
├── index.html  login.html  datasets.html  dataset.html  upload.html  users.html
├── app.js  styles.css  logo.png
├── app/                  ← keep this name; has its own deny-all .htaccess
│   ├── config.php  db.php  http.php  auth.php  migrations.php
│   └── lib/  routes/  scripts/  tests/
└── var/uploads/          ← created automatically, chmod 770
```

> **`.htaccess` is load-bearing in this layout.** Because `app/`, `var/` and
> `.env` live inside the web root, those files are only private because Apache
> is told to refuse them. If `.htaccess` is missing, or the host has
> `AllowOverride None`, your database password becomes downloadable at
> `/.env`. Verify after deploying — see the check below.
>
> `app/.htaccess` is a second, independent lock on the same door. Upload it too.

**Check this immediately after uploading.** All four must fail:

```bash
curl -I https://lead.moveneticsdigital.com/.env            # expect 403
curl -I https://lead.moveneticsdigital.com/app/config.php  # expect 403
curl -I https://lead.moveneticsdigital.com/DEPLOY.md       # expect 403
curl -s  https://lead.moveneticsdigital.com/ | head -5     # expect the app, not "Index of /"
```

If any returns `200`, stop and fix the server config before putting real
credentials in `.env`. Ask your host to set `AllowOverride All` for this
directory.

If your host forces everything into `public_html`, the shipped `.htaccess`
blocks `app/`, `var/` and `.env` — but outside the docroot is strictly safer.

---

## 2. Configure

```bash
cp .env.example .env
chmod 600 .env
```

Fill in the database credentials and generate a session secret:

```bash
php -r "echo bin2hex(random_bytes(48));"
```

Two things worth care:

- **Quote the password with single quotes.** It contains `$`, `[` and `^`,
  which the parser would otherwise mangle:
  `DB_PASSWORD='your-password-here'`
- **`.env.example` is committed to git; `.env` is not.** Never put the real
  password in `.env.example`.

---

## 3. Create the tables and first admin

With shell access:

```bash
php app/scripts/migrate.php
php app/scripts/create_admin.php
```

Without shell access, open `https://your-domain/setup.php` in a browser. It
creates the tables and your admin account, then locks itself permanently once an
active admin exists.

**Delete `setup.php` afterwards either way.**

Migration also registers the existing 248k-row leads table (whatever
`LEADS_TABLE` names) as a *protected* dataset: searchable and exportable through
the UI, but impossible to edit or drop.

---

## 4. Update the n8n workflow

`/api/search` now posts:

```json
{
  "question": "roofers in Oklahoma with mobile numbers",
  "schemas": [
    { "table": "leads", "label": "Leads (master)", "row_count": 248179,
      "columns": [{ "name": "business_name", "type": "TEXT" }] }
  ],
  "asked_by": "someone@moveneticsdigital.com"
}
```

The workflow must feed `schemas` into its NL-to-SQL prompt. **Until you do this,
the per-dataset "searchable" toggle has no effect** — the AI will keep querying
only the tables its prompt already knows about. Everything else works regardless.

Since only the server calls n8n now, you can also tighten the Webhook node's
Allowed Origins, or take it off the public internet entirely.

---

## 5. Rotate the database password

The password was shared in plain text during development, so treat it as known.
Change it in cPanel → MySQL Databases, update `.env`, reload the page.

---

## Testing it works

## Large imports (multi-GB and 100 GB collections)

The Upload page sends CSV and XLSX files up to 2 GB as resumable 10 MB chunks. Each
request remains below PHP's normal upload limit; after the last chunk, the cron
worker assembles the file and queues it automatically. Selecting the same file
again resumes an interrupted upload from its completed chunks.

SFTP remains available as a fallback. Place CSV or XLSX files into:

```text
<document root>/var/inbox/
```

SFTP files must finish uploading at least 60 seconds before they appear in the
Upload page. The worker deliberately skips newer files so it cannot ingest an
incomplete transfer. Browser-uploaded files are registered immediately after
background assembly.

Run the migration once after deploying this version:

```bash
php app/scripts/migrate.php
```

In cPanel → Cron Jobs, run the resumable worker every minute (replace the path
with the account's real document root):

```cron
* * * * * /usr/local/bin/php /home/ACCOUNT/public_html/lead/app/scripts/bulk_import_worker.php 50 >> /home/ACCOUNT/large-import.log 2>&1
```

The final `50` is the maximum number of seconds one cron invocation works.
An advisory MariaDB lock prevents overlapping workers. Progress and the byte
offset are committed after each database batch, so a process kill or server
restart resumes rather than starting over. Completed source files are moved to
`var/imported/`; failed files remain in `var/inbox/` for inspection and retry.

Large XLSX uploads are converted to CSV by the CLI worker, outside PHP-FPM.
Conversion is streamed, but the server still needs temporary space for the
workbook, its chunks, and the expanded CSV. CSV remains considerably faster and
more space-efficient for very large exports. The browser uploader requires
approximately five times the XLSX size in free disk space before it starts.

For a 100 GB collection, provision enough space for both MariaDB and the source
archive. Keep `var/inbox` and `var/imported` outside backups if the same source
files are already backed up elsewhere.

**Libraries** — run on the server; these two carry the security weight:

```bash
php app/tests/run.php
```

**Auth**

- Signed out, `curl -i https://your-domain/api/datasets` → `401`
- Sign in as an employee → Upload and Users are absent from the nav, and
  `curl` to `/api/uploads/stage` returns `403`
- Deactivate a user while they are signed in → their next click bounces to login
- 11 wrong passwords in a row → `429`

**Upload** — test with the real files, in this order:

1. `startup.gallery.csv` (~500 KB, 9 columns) — confirm screen shows 9
   sanitized columns; commit; row count should match `wc -l` minus the header
2. `YC-Companies.xlsx` — exercises the spreadsheet reader
3. `leads.csv` (48 MB, 248k rows) — the one that matters. Watch the progress bar
   advance and memory stay flat. Time it.
4. Create a folder, upload two files with **identical** headers → the second is
   offered as an append → one table, both filenames under Source files
5. Same folder, a file with **different** headers → offered as a new table

**Editing**

- Click a cell, change it, reload — the change persisted
- Rename a column, then sort by it
- Retype a text column holding `N/A` to Whole number → refused, with a count
- Source files → Remove rows → only that file's rows go
- Try to edit or delete the master leads dataset → refused

**Search**

- Search from the UI, then check DevTools → Network and Application → Local
  Storage. **No webhook URL and no API key should appear anywhere.** That is the
  whole point of the proxy.

---

## Troubleshooting

**"The upload exceeded this server's post_max_size"**
`.user.ini` sets 256M/260M. If the host ignores per-directory ini files, set the
same values in cPanel → MultiPHP INI Editor. `post_max_size` must be larger than
`upload_max_filesize`, or PHP silently discards the entire request body.

**Import bar stops moving**
Each tick ingests as many rows as fit in `max_execution_time`. If the host kills
requests early, lower `IMPORT_ROWS_PER_REQUEST` and `IMPORT_BATCH_SIZE` in
`.env`. Progress is stored server-side as a byte offset, so nothing is lost —
reopening the dataset resumes from where it stopped.

**"A value was longer than its column allows"**
Type inference samples the first 500 rows, so a much longer value further down
can exceed the chosen width. Widen that column (or set it to Long text) on the
dataset page, then re-upload. Inference deliberately errs wide to make this rare.

**Blank white page**
Almost always a PHP version below 8.1, or a syntax error. Check the cPanel error
log; `display_errors` is off by design.

**Dates from Excel arrive as numbers like 45521**
The reader converts cells whose *style* marks them as dates. A sheet that stores
dates as plain numbers has no way to be detected. Format the column as a date in
Excel, or export to CSV.

---

## Layout

```
app/
  config.php          .env parsing, validation, PHP version gate
  db.php              PDO pool and query helpers
  http.php            JSON responses, request parsing, error handlers
  auth.php            sessions, roles, CSRF, login rate limiting
  migrations.php      schema creation, leads-table registration
  lib/
    identifiers.php   SECURITY-CRITICAL: SQL identifier allowlisting
    inference.php     column type inference and value casting
    reader.php        CSV/TSV/XLSX → normalized UTF-8 CSV
    importer.php      resumable chunked import
    audit.php         append-only change log
  routes/             one file per resource
  scripts/            migrate.php, create_admin.php
  tests/run.php       dependency-free test runner
public_html/
  index.php           API front controller and route table
  app.js              shared frontend: session, API wrapper, nav
  *.html              one page per screen
  styles.css          design tokens + components
```

**Read `lib/identifiers.php` before touching any SQL that builds a table or
column name.** Identifiers cannot be bound as parameters, so they are
allowlisted against `^[a-z][a-z0-9_]{0,62}$` rather than escaped. Everything
derived from a filename or CSV header goes through it, twice — once at stage
time and again on commit, because the browser echoes column names back and that
echo is not trusted.
