# OSFishing — Server & Deploy Brief

Infrastructure for OSFishing is **already provisioned** on the shared VPS. Nothing in
this document needs to be run again — it describes what exists so you can deploy into
it. It was set up to mirror the sibling Fertility app, with full isolation between
the two.

> Written on 2026-09-14 by the Claude session that provisioned the server, from the
> Fertility repo (where the provisioning scripts live). The server work is done; this
> describes it and lists what OSFishing still needs to decide before it deploys.

---

## Server

| | |
|---|---|
| Host | `flamesfall.net` (InterServer VPS, `162.35.169.109`, Los Angeles) |
| OS | Debian 13 |
| Stack | Nginx, PHP 8.4-FPM, MariaDB |
| Live URL | `https://osfishing.flamesfall.net` — served at the **domain root**, no subpath |

**This VPS also hosts the Fertility app.** The two are fully isolated (separate Unix
users, SSH keys, FPM pools, databases, directory trees). Do not reach into
`/var/www/fertility` or the `fertility` database — you will be denied, by design.

---

## SSH

```bash
ssh -i ~/.ssh/osfishing_deploy osfishing@flamesfall.net "<command>"
```

Key-based only; root login and password auth are disabled server-wide. The private
key is at `~/.ssh/osfishing_deploy` on Matthew's workstation. It is **not** the same
key as fertility's — each project has its own.

Consider adding to `~/.ssh/config` so the `-i` flag can be dropped:

```
Host osfishing.flamesfall.net flamesfall-osfishing
    HostName flamesfall.net
    User osfishing
    IdentityFile ~/.ssh/osfishing_deploy
    IdentitiesOnly yes
```

---

## Paths

| What | Where |
|---|---|
| App root | `/var/www/osfishing` |
| Repo checkout | `/var/www/osfishing/repo/` *(not cloned yet — see Setup)* |
| Live web tree | `/var/www/osfishing/Web/` *(see "Layout mismatch" — likely wants changing)* |
| Web root (nginx) | `/var/www/osfishing/Web/public/` *(repo has no `public/`; see below)* |
| App config | `web/config.php`, gitignored — copy from `config.example.php` |
| Deploy script | `/var/www/osfishing/deploy/deploy.sh` (move into the repo, see Setup) |
| DB credentials | `/home/osfishing/secrets/db.txt` (mode 600, osfishing-only) |
| Backups dir | `/var/backups/osfishing` (created; **no backup job installed yet**) |

> **Layout mismatch — read this before deploying.** The provisioned paths above were
> modelled on the sibling Fertility app (`Web/public/`, `.env`, Composer). The
> OSFishing repo is shaped differently: docroot is `web/` with no `public/`
> subdirectory, config is `web/config.php` (PHP constants, not `.env`), and there is
> no `composer.json`. The nginx vhost and `deploy.sh` both need adjusting to match.
> See "Recommended changes" at the end — nothing here has been tailored to the repo,
> deliberately, so the OSFishing project owns those decisions.

---

## Database

| | |
|---|---|
| Engine | MariaDB on localhost |
| Database | `osfishing` |
| User | `osfishing`@`localhost` |
| Charset | `utf8mb4` / `utf8mb4_unicode_ci` |
| Password | randomly generated at provision time — read it from `/home/osfishing/secrets/db.txt` |

The `osfishing` DB user is granted privileges on the `osfishing` database **only**.
It cannot see or touch `fertility`. Verified.

```bash
# Connect (reads the password from the secrets file, never type it inline)
ssh -i ~/.ssh/osfishing_deploy osfishing@flamesfall.net \
  '. ~/secrets/db.txt && mysql -u osfishing -p"$DB_PASSWORD" osfishing -e "SHOW TABLES;"'
```

This app reads credentials from `web/config.php` (`define('DB_PASS', ...)`), copied
from `config.example.php` — there is no `.env`. Keep that file mode `640` owned
`osfishing:osfishing`, and do **not** make it group-readable by `www-data`: every
deploy user on this box is in `www-data`, so that would expose it to the other app's
deploy user. (That exact mistake existed on Fertility and was fixed — see "Isolation".)

`config.php` is gitignored, so it survives `deploy.sh`'s `rsync --delete` only if the
rsync excludes it. Confirm that before the first deploy, or a deploy will delete the
live config. See "Recommended changes".

---

## PHP-FPM — dedicated pool

OSFishing has its **own FPM pool**, separate from Fertility's:

| | |
|---|---|
| Pool config | `/etc/php/8.4/fpm/pool.d/osfishing.conf` |
| Runs as | user `osfishing`, group `osfishing` |
| Socket | `/run/php/php8.4-fpm-osfishing.sock` |
| Error log | `/var/log/php8.4-fpm-osfishing.log` |
| `open_basedir` | `/var/www/osfishing:/tmp:/usr/share/php` |
| Session store | `/var/lib/php/sessions-osfishing` (private to this pool) |

**Sessions must use that private directory — it is already configured, do not
override it.** The distro default (`/var/lib/php/sessions`) is mode `1733` with files
owned by whichever user wrote them, so a pool running as a non-`www-data` user cannot
read sessions there. The failure is quiet and misleading: login appears to succeed,
then the redirect lands the user back on the home page still logged out, with only a
`session_start(): Permission denied` warning in the error log. This bit Fertility
during setup and is now fixed for both pools. `SESSION_LIFETIME` in `config.php` is
unaffected; `/usr/lib/php/sessionclean` picks the path up from the pool config, so GC
works normally.

`open_basedir` confines this app's PHP to its own tree — it physically cannot open
`/var/www/fertility`, even if code tried. If you add a legitimate path outside that
tree (an upload dir, a shared cache), you must extend `open_basedir` or file opens
will fail with a confusing permissions error.

The pool is `pm = dynamic`, `pm.max_children = 10`. Raise it if OSFishing gets real
traffic; both pools share the VPS's RAM.

---

## Nginx

| | |
|---|---|
| Vhost | `/etc/nginx/sites-available/osfishing.flamesfall.net` (symlinked into `sites-enabled/`) |
| Routing snippet | `/etc/nginx/snippets/osfishing-limits.conf` |
| Access log | `/var/log/nginx/osfishing.flamesfall.net.access.log` |
| Error log | `/var/log/nginx/osfishing.flamesfall.net.error.log` |

Routing lives in the separate included snippet so rate limiting can be swapped in
later without rewriting the vhost. There are currently **no app-level rate limits and
no fail2ban jails** — do not explain a connectivity problem by rate limiting.

The vhost currently denies direct access to `/.env`, `/src`, `/templates`, `/storage`,
`/vendor`, `/bin`, `/tests`, `/scripts` and any dotfile — a Fertility-shaped list that
does **not** cover OSFishing's `includes/` or `migrations/`. Front-controller routing
assumes `public/index.php`, which this repo does not have.

**The repo's `web/.htaccess` does nothing here.** nginx never reads `.htaccess`, so the
rewrite rules (`/api` → `api/index.php`) and the `deny` rules protecting `includes/`
and `config.php` are all inert. They must be reimplemented as nginx `location` blocks.
See "Recommended changes" — one of these is a live data-exposure issue.

After any nginx change:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## Deploying

```bash
git push
ssh -i ~/.ssh/osfishing_deploy osfishing@flamesfall.net \
  'bash /var/www/osfishing/repo/deploy/deploy.sh <branch>'
```

`deploy.sh` (already written, currently at `/var/www/osfishing/deploy/deploy.sh`):

1. `git fetch` + `git reset --hard origin/<branch>`
2. `composer install --no-dev` (skipped when there is no `composer.json`)
3. `rsync -a --delete` from `repo/Web/` → `Web/`, preserving `.env` and `storage/`
4. `php -l` syntax-checks every deployed PHP file **before** it goes live
5. `sudo systemctl reload php8.4-fpm` to clear OPcache

**Deploy whole branches, never individual files.** Copying files one at a time was a
documented, recurring source of "the server is silently running stale code" bugs on
the sibling app. If a bug looks like "this method doesn't exist," confirm the deploy
actually ran before suspecting the code.

The `osfishing` user's sudo rights are limited to exactly the FPM reload — nothing
else. `sudo -l` to confirm.

---

## Setup still to do

1. ~~**Add the DNS record.**~~ **Done.** `osfishing.flamesfall.net` → `A` →
   `162.35.169.109`, confirmed resolving on Comcast, Cloudflare and Google. Requests
   reach the correct vhost; it currently returns 404 because the docroot is empty.

2. ~~**Add the GitHub deploy key.**~~ **Done** — registered on `Parsalin/OSFishing` as
   `osfishing-vps-deploy (read-only)`. Verified: the server authenticates to GitHub and
   can clone; push is correctly refused. Read-only is deliberate — the server only ever
   pulls. A Claude session pushes from the workstation using your own `gh` credential,
   which is separate from this key and already has write access.

   For reference, the keypair generated on the server at
   `/home/osfishing/.ssh/id_ed25519_github` (separate from fertility's). Add this
   public key to the OSFishing GitHub repo under **Settings → Deploy keys**:

   ```
   ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIoYBiNrQ6chlkQM1jNFoE4NzDulmjun8FgLhkF4KozL osfishing-deploy@flamesfall
   ```

   Grant write access only if something on the server needs to push; read-only is
   enough for deploys. `~/.ssh/config` already maps `github.com` to this key.

3. **Clone the repo:**
   ```bash
   ssh -i ~/.ssh/osfishing_deploy osfishing@flamesfall.net \
     'git clone git@github.com:<owner>/<repo>.git /var/www/osfishing/repo'
   ```

4. **Move `deploy.sh` into the repo** at `deploy/deploy.sh` and commit it, so it is
   version-controlled rather than a loose server file. The copy at
   `/var/www/osfishing/deploy/deploy.sh` is a bootstrap; the deploy command above
   expects the repo path.

5. **Create `config.php`** from `config.example.php`, with the DB credentials from
   `~/secrets/db.txt` and a freshly generated `HUD_SECRET`
   (`php -r "echo bin2hex(random_bytes(32));"`). Mode `640`, owner
   `osfishing:osfishing`. Place it where the code expects it — `require_once
   __DIR__ . '/../config.php'` from `web/api/index.php` resolves to `web/config.php`.

6. **Apply the migrations** in `web/migrations/` to the `osfishing` database. There are
   20+ `.sql` files and the apply order is not evident from the filenames — the
   OSFishing project knows the intended sequence, so this was deliberately left alone.

7. **Settle the routing and docroot questions** in "Recommended changes" *before*
   issuing TLS, then:
   ```bash
   sudo certbot --nginx -d osfishing.flamesfall.net -m <email> --agree-tos
   ```
   Until then the site is HTTP-only. Certbot rewrites the vhost to add the 443 server
   block and an HTTP→HTTPS redirect.

8. **Install a cron job**, if OSFishing needs one, under the `osfishing` crontab
   (`crontab -u osfishing -e`). Log to a path the osfishing user can write.

9. **Backups are not configured** for either app. `/var/backups/osfishing` exists but
   nothing writes to it.

---

## Isolation — what was verified

Tested directly, not assumed:

- `osfishing` **cannot** read `/home/fertility/.ssh/` → permission denied
- `osfishing` **cannot** read fertility's `.env` → permission denied
- `osfishing` DB user **cannot** access the `fertility` database → access denied
- OSFishing's PHP pool runs as `osfishing`, and `open_basedir` blocks it from reading
  fertility's tree → confirmed `false` from a live HTTP request
- Fertility's site and deploy still work unchanged after the isolation hardening

### One caveat worth knowing

Fertility's `.env` was originally mode `640` owned `fertility:www-data`. Because every
deploy user is a member of `www-data`, that file was readable by any other app's
deploy user. It has been tightened to `fertility:fertility` plus a POSIX ACL granting
`www-data` read (Fertility's pool still runs as `www-data`).

**Do not grant `www-data` group-read on OSFishing's `.env`.** Since OSFishing's pool
runs as `osfishing`, it has no need for it, and doing so would re-open the same
cross-app leak in the other direction.

Fertility still shares the default `www-data` pool. Giving it a dedicated pool the way
OSFishing has one would complete the separation; it is not required for OSFishing to
work, and is noted here as a known remaining asymmetry rather than a blocker.

---

## Recommended changes

Findings from inspecting the repo against the provisioned server. Nothing below has
been applied — these are the OSFishing project's calls to make. Items 1 and 2 are
security issues that should be settled **before** the site is publicly reachable.

### 1. `.sql` migration files are served as plain text — verified, not theoretical

Tested against the live vhost with a canary file: a request for
`/migrations/schema.sql` returned the file contents with HTTP 200.

`.php` files are safe — PHP executes them, so `config.php` and `includes/*.php` return
empty rather than leaking source. The exposure is **non-PHP** files: this repo ships
`web/migrations/*.sql` (20+ files), which would hand any visitor your full schema.

Fix — deny by directory and by extension, not just by filename:

```nginx
location ~ ^/(includes|migrations)/ { deny all; return 404; }
location ~ \.(sql|md|example\.php)$ { deny all; return 404; }
location ~ /\.            { deny all; return 404; }
```

The belt-and-braces version is to keep non-servable files out of the docroot entirely,
which is what the `public/` convention buys you (item 4).

### 2. `.htaccess` rewrites are inert under nginx — `/api` will 404

`web/.htaccess` routes `/api` → `api/index.php` and blocks `includes/` and
`config.php`. nginx ignores all of it. Without a translation the HUD's API calls fail,
and the protective rules that *look* present in the repo are not actually in force.

```nginx
location = /api  { try_files $uri /api/index.php$is_args$args; }
location ^~ /api { try_files $uri /api/index.php$is_args$args; }
location = /setup { try_files $uri /setup.php$is_args$args; }
```

### 3. `deploy.sh` will delete the live `config.php`

The script rsyncs with `--delete` and excludes `.env` — a name this repo never uses.
Since `config.php` is gitignored, it is absent from the source tree, so `--delete`
removes it from the live tree on the first deploy and the site breaks.

Add the real filename to the excludes:

```bash
--exclude 'config.php'
```

Also: `deploy.sh` currently syncs `${REPO_DIR}/Web/` (capital W) and syntax-checks
`src public bin`. Point it at `web/` and check the directories that exist. The
Composer step already self-skips when there is no `composer.json`.

### 4. Consider adopting a `public/` docroot

Right now `config.php`, `includes/`, and `migrations/` all live inside the served
directory, so their safety depends entirely on getting deny-rules right — one missed
extension is a leak (item 1). Moving the entry points into `web/public/` and keeping
everything else a level above makes exposure impossible by construction rather than by
configuration, and matches how Fertility is laid out.

This is a repo change, not a server change, and the larger of the suggestions here.
Worth it if OSFishing is going to keep growing; skip it if you want the smallest
possible diff, in which case item 1's deny rules become load-bearing and should be
tested after any vhost edit.

### 5. ~~Give Fertility its own FPM pool too~~ — done

Resolved on 2026-09-14. Fertility now has its own pool running as the `fertility`
user with the same `open_basedir` confinement, and its `.env` is `600` owner-only
(the `www-data` ACL it briefly needed has been removed). Isolation is symmetric and
verified in both directions: neither deploy user can read the other's secrets.

The two apps are now configured the same way. If you change the pool or permission
model on the OSFishing side, mirroring it back to Fertility keeps them aligned.

### 6. Set up TLS once the vhost is final

DNS resolves correctly (`osfishing.flamesfall.net` → `162.35.169.109`, confirmed on
Comcast, Cloudflare and Google resolvers). The cert has **not** been issued yet,
deliberately: `certbot --nginx` rewrites the vhost, so it is cleaner to finish the
routing changes above first and issue the cert against the final config.

```bash
sudo certbot --nginx -d osfishing.flamesfall.net -m <email> --agree-tos
```

### 7. Not configured for either app

Backups (`/var/backups/osfishing` exists but nothing writes to it) and any app cron
job. `deploy/03-backup.sh` and `04-harden.sh` in the Fertility repo are written but
have never been run on this server — there are currently no app-level rate limits and
no fail2ban jails for either site.
