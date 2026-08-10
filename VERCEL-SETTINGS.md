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

`DEMO_LOGINS=true` is **already set in `Dockerfile.vercel`**, so there is nothing
to do in the dashboard — this deployment lists an account per role on its login
page, one click filling the form.

**It only belongs where the data is invented.** If this deployment is ever
pointed at the real shop, delete that line from the Dockerfile. Setting
`DEMO_LOGINS` in the Vercel dashboard overrides the image either way.

It is off everywhere else, and the office must never have it. When off, nothing
about it reaches the page — not the accounts, not the password, not even the
stylesheet.

## The one thing settings cannot fix

If the Aiven database and the Vercel deployment are in **different regions**, every
round trip crosses that distance and no amount of tuning removes it. It is the
single biggest lever by a wide margin.

**Run the function next to the database, not next to the user.** Aiven is in
Bangalore. With no region set, Vercel runs the function in `iad1` (Washington
D.C.) by default — a quarter of a second of ocean on *every query*. A page runs
about twenty-five queries, so that alone was ten seconds of waiting. Measured:
419 ms for a `SELECT 1` that does no work, against 94 ms of real network latency
from the Manila office to the same database.

`vercel.json` now pins `"regions": ["bom1"]` (Mumbai — the closest Vercel region
to Bangalore). That adds one slower hop for the person using the site and removes
the distance from all twenty-five queries.

**Do not read the region out of an error page.** The `sin1` in a Vercel request ID
is the edge PoP nearest the visitor, not where the function ran. Check
Project → Settings → Functions.

If the database is ever moved, move the function region with it. They must stay
together, and which one they are near matters far more than which one the user
is near.

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
- **`migrate --force` behind the port, not in front of it** — Vercel gives the
  container **15 seconds to start listening**. Migrating first blew that budget
  (connecting to Aiven plus twenty `ALTER`s), so the container was killed part
  way: the port never opened, *and* the schema was left half-changed with no
  record the migration had run, which made every later boot die on "duplicate
  column". The server now binds the port immediately and the migration runs
  behind it. The migrations only add columns that are missing, so an
  interrupted run finishes itself on the next boot.

## If the build fails on "push was denied"

The image built, but Vercel would not accept it into the project's container
registry. Nothing in this repo causes it and nothing here fixes it — create the
repository under **Project → Sandboxes → Container Registry** (it takes its name
from the service in `vercel.json`, so `laravel`), then re-run the build.

## If the deployment will not start

**"could not connect to $PORT"** means nothing was listening within 15 seconds.
Anything added to the start command that touches the network — a migration, a
warm-up, a health check against the database — has to run *after* the port is
handed over, or it will do this again.

**A crash page on every URL** (not just one page) is almost always the start
command failing rather than the app. Check the runtime log for the container's
first few lines, not the page itself.

## Known limit

The container serves with PHP's built-in server, which handles requests one at a
time. It is fine while the shop is small, but it is a ceiling: with everyone on at
once, requests queue. Moving to php-fpm + nginx or FrankenPHP is the next step if
that starts to bite.
