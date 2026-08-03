# Imprint Production — Windows Host Guide

Internal production-tracking system for Imprint Customs. Everything runs on
one Windows PC; employees reach it over the office network, or from anywhere
through a Cloudflare Quick Tunnel link.

## Setting it up on a new PC

1. Install **XAMPP** — <https://www.apachefriends.org> (gives you PHP + MySQL)
2. Install **Composer** — <https://getcomposer.org/download>
3. Double-click **`start.bat`**

That's it. The first run installs dependencies, creates the settings file,
generates the app key, creates and migrates the database, adds the staff
accounts, and opens the system in your browser. It takes a couple of minutes
the first time and a few seconds after that.

Sign in with **`admin@imprintcustoms.ph`** / **`imprint123`**, then change that
password.

`start.bat` is safe to run again any time — each step is skipped once it has
been done, and it never touches data that already exists.

## Daily operation

| To do this | Double-click |
|---|---|
| Start everything (works on a fresh copy too) | `start.bat` |
| Office network **and** public link together | `start-all.bat` |
| Office network only, no internet needed | `start-offline.bat` |
| Public tunnel link only | `start-imprint.bat` |
| Stop the app and tunnel (MySQL stays up) | `stop-imprint.bat` |
| Restart everything with a fresh link | `restart-imprint.bat` |
| See what is running + the current link | `check-imprint-status.bat` |

`start-imprint.bat` starts MySQL, the Laravel application (port 8000), and the
Quick Tunnel, then shows the public address and saves it to
`current-tunnel-url.txt`. **Send that address to the employees** — it is their
only way in from outside the office.

Keep the minimized taskbar windows open: **Imprint MySQL**, **Imprint
Laravel**, **Imprint Tunnel**. Closing them stops the system.

### The public address changes

Every time the tunnel restarts, Cloudflare generates a **new random address**
like `https://random-words.trycloudflare.com` and the old one dies
permanently. After any restart or reboot: run `start-imprint.bat`, copy the
new address, send it to the agents. There is nothing to configure — logins,
sessions, and uploads follow the new address automatically.

## Availability warning

The application is reachable remotely **only while all of these are true**:

- this PC is powered on and Windows is running
- it has a working internet connection
- MySQL, Laravel, and cloudflared are running (check with `check-imprint-status.bat`)

Cloudflare does not store or host anything — it only forwards traffic to this
PC. PC off = system off.

## Quick Tunnel limitations

- The address is random and changes on every tunnel restart.
- No guaranteed uptime; employees may need a new link after a restart.
- Quick Tunnel is intended primarily for testing/development use and has a
  limit on concurrent in-flight requests.
- Server-Sent Events are not supported (the app uses polling instead).

## Security notes

- Every page except the login page requires an account; roles (Super Admin /
  Leader / Agent) are enforced server-side.
- 5 wrong passwords locks the account+IP pair out for 60 seconds.
- Keep `APP_DEBUG=false` in `application\.env` while employees use the system.
- MySQL port 3306 is never exposed through the tunnel; do not expose
  phpMyAdmin either.
- Change the seeded starter passwords before real use, and deactivate
  accounts of people who leave.

## Folders

```
C:\ImprintProduction
├── application\             Laravel app (code + uploads + storage)
├── cloudflared\             cloudflared.exe
├── logs\                    laravel.log, cloudflared.log
├── backups\                 database/file backups (Phase 4)
├── current-tunnel-url.txt   the current public link
└── *.bat                    the scripts in the table above
```

## Optional: start automatically when Windows boots

Press `Win+R`, type `shell:startup`, press Enter, and place a **shortcut to
`start-imprint.bat`** in the folder that opens. On every boot the system
starts itself; you still need to send the newly generated link to the agents.
