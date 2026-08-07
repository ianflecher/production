# Hosted deployment settings (Vercel + Aiven)

Everything here is set in **Vercel → Project → Settings → Environment Variables**,
then redeploy. None of it affects the office PC, which keeps its own `.env`.

The office is fast because the database is on the same machine and the code is
compiled once. Hosted, neither is true by default, and the app pays for that on
every single request — including the login page, which barely touches the data.

## Required

| Variable | Value | Why |
|---|---|---|
| `APP_KEY` | `base64:…` | Without it every page fails. Generate with `php artisan key:generate --show`. |
| `APP_URL` | your Vercel URL | Links and redirects are built from it. |
| `TRUSTED_PROXIES` | `*` | Vercel terminates HTTPS and forwards. Untrusted, Laravel reads the request as plain HTTP and writes `http://` links onto an `https://` page — the browser then blocks the stylesheet and the app renders unstyled. |
| `DB_*` | the Aiven details | host, port, database, username, password. |

## Speed

| Variable | Value | Why |
|---|---|---|
| `DB_PERSISTENT` | `true` | Opening a connection to Aiven is TCP + TLS + auth — several round trips, ~400ms, and PHP throws it away after each request. This keeps it open between requests in the same process. |
| `SESSION_DRIVER` | `cookie` | The default keeps sessions in the database, so **every request** — the login page included — makes a round trip to Aiven before it does anything. A cookie session is encrypted and travels with the request. |
| `CACHE_STORE` | `file` | Same reasoning: the default caches into the database, which is the slow thing here. |
| `LOG_CHANNEL` | `stderr` | Otherwise errors go to a file inside a container you cannot open. This puts them in Vercel's log viewer. |

## Demo only

| Variable | Value | Why |
|---|---|---|
| `DEMO_LOGINS` | `true` | Lists an account per role on the login page, with one click to fill the form, so somebody being shown the system can switch roles without being handed seven logins one at a time. |

**Set this only where the data is invented.** It prints working credentials on
the front door. It is off unless a deployment says otherwise, and the office
must never have it — nothing about it reaches the page when it is off, not the
accounts, not the password, not even the stylesheet.

## The one thing settings cannot fix

If the Aiven database and the Vercel deployment are in **different regions**, every
round trip crosses that distance and no amount of tuning removes it. Check both are
in the same region — it is the single biggest lever, and it is on Aiven's side, not
in this repo.

## Checking it worked

Sign in and open `/db-test`. It reports opening a connection and running a query
separately, so you can see which one is costing you:

- **"Opening a connection" is high, "one query" is low** → the database is far away
  or connections are not being reused. Set `DB_PERSISTENT=true`; if it is already
  set, the regions do not match.
- **Both are low but pages still feel slow** → it is not the database. Check that
  the deploy actually picked up the new image (opcache and the caches below).

## What the image already does

Set in `application/Dockerfile.vercel`, no configuration needed:

- **opcache** — keeps compiled PHP in memory. Without it the framework is
  recompiled from source on every request. Measured on the same entrypoint the
  container uses, the login page went **172ms → 39ms**.
- **`view:cache` and `route:cache`** at build time — templates and the route
  table compiled into the image.
- **`config:cache` at start-up** — not at build, because the environment only
  exists once the container runs.
- **`migrate --force` before serving** — so the schema matches the code. Without
  it, every column added since the database was created is missing and pages 500.

## Known limit

The container serves with PHP's built-in server, which handles requests one at a
time. It is fine while the shop is small, but it is a ceiling: with everyone on at
once, requests queue. Moving to php-fpm + nginx or FrankenPHP is the next step if
that starts to bite.
