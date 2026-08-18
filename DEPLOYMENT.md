# ThalVital deployment runbook

This runbook adapts the observed SarkariDoctor VPS release model to this server-rendered PHP/MySQL application. It intentionally contains no real credentials or salts.

## A. Local setup and testing

1. Install PHP 8.1+ and MySQL/MariaDB, or use XAMPP. Place this folder under the Apache document root, for example `C:\xampp\htdocs\thalvital`, so it is available at `http://localhost/thalvital/`.
2. Create an empty `thalvital` database with `utf8mb4` encoding. Copy/edit the untracked `config.php` with local database values and a new 64-character **local** `AADHAAR_SALT`.
3. Run PHP lint before using the application:

   ```powershell
   Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
   ```

4. Visit `setup.php` once or run it through local Apache. It creates the schema, IHTM organization, seven centers, lookup data, and temporary administrator. Remove or rename `setup.php` immediately after it succeeds.
5. Verify the seven centers: SMS, Trauma, JKLoan, Mahila, Zenana, SCI, and SK Hospital (Sikar). Use only synthetic/demo records locally; never restore production patient data onto a developer machine.
6. The session cookie's `Secure` flag must remain conditional on HTTPS. Local HTTP otherwise cannot retain a session. Production must always use HTTPS.

> [!warning]
> The deployment target must use its own permanent production salt. Never copy a production database to a local system; if environments share a database, their salt must be identical. Once any patient exists, that salt must never change.

## B. Live deployment: Hostinger VPS release model

The proven SarkariDoctor mechanism is **not Git pull or hPanel deploy**. It packages a validated local runtime, transfers it with SSH/SFTP, creates a fresh timestamped release, retains shared configuration/data outside releases, then atomically switches a `current` symlink. Reuse this model only on a Hostinger **VPS** with SSH and a web-server document root pointed at `current/public` (or equivalent). Hostinger shared hosting cannot normally provide the symlinked release layout.

### Recommended remote layout

```text
/var/www/thalvital/
  releases/<timestamp>/public/   # immutable PHP release
  shared/config/config.php       # production credentials and permanent salt
  shared/backups/                # encrypted/controlled backup destination
  current -> releases/<timestamp>
```

Point Apache/Nginx to `/var/www/thalvital/current/public`. If the provider requires `public_html`, use a documented deployment-specific target and preserve `config.php` outside the release tree.

### This box (Hostinger VPS `<vps-ip>`) — prerequisites & landmines
The shared VPS (`<vps-hostname>`, **Ubuntu 25.04 plucky — END-OF-LIFE**) runs Node/Python apps behind Apache + PM2 + Certbot, with PostgreSQL 17. ThalVital adds a **new stack**; treat these as hard prerequisites (see wiki `[[hostinger-quirks]]`):

- **No MySQL/MariaDB on the box yet** (only PostgreSQL). Install MariaDB — because plucky is EOL, verify the package path first (`apt-get install --dry-run mariadb-server`), prefer the Ubuntu-native package. Create `thalvital` (utf8mb4) and run `scripts/prod-db-user.sql`.
- **No PHP on the box yet.** Install PHP 8.1+ with `pdo_mysql`, `mbstring`, `openssl`, `php-fpm`. Same EOL caveat — verify the install path before assuming a PPA works.
- **Apache is shared by ~5 apps.** Add only ThalVital's vhost; `apache2ctl configtest` then **`systemctl reload apache2` — NEVER `restart`**; never touch a neighbour's vhost.
- **Web-root hardening.** The release tree (`git archive --prefix=public/`) puts the whole repo under docroot, so `schema.sql`/`seed.sql`/`includes/` would be reachable. The committed `.htaccess` denies them; if the vhost uses `AllowOverride None`, replicate it as the authoritative `<Directory>` block:
  ```apache
  <Directory /var/www/thalvital/current/public>
    Options -Indexes
    Require all granted
    <FilesMatch "\.(sql|md|ps1|sh|sample\.php)$">Require all denied</FilesMatch>
    <Files "config.php">Require all denied</Files>
  </Directory>
  <DirectoryMatch "/var/www/thalvital/current/public/(includes|scripts)/">Require all denied</DirectoryMatch>
  ```
- **Env lives outside the release** at `/var/www/thalvital/shared/config/config.php`, symlinked in by `release-tag.ps1`; never committed.
- **DNS is at GoDaddy**, not Hostinger — point the ThalVital domain's A record at `<vps-ip>`, then run Certbot once DNS resolves.
- **Deploy from a mobile hotspot** — SMS-hospital / government / office LANs filter outbound port 22, so `scp`/`ssh` (and `release-tag.ps1`) time out on them.
- **No rsync on the Windows deploy machine** — `release-tag.ps1` already uses `scp` + remote `tar`, so this is handled.

### Generating the permanent production salt (do this yourself; never commit it)
```bash
php -r 'echo bin2hex(random_bytes(32)), "\n";'   # 64 hex chars
```
Paste into `shared/config/config.php` as `AADHAAR_SALT`, store it in your password manager, mark immutable. Once any real patient exists it must never change.

### First production deployment

1. Create a dedicated database and a least-privilege application user (CRUD only; schema provisioning may temporarily use a separate migration user). Enable PHP 8.1+ with `pdo_mysql`, `mbstring`, and `openssl`.
2. Transfer the release payload without `config.php`, `.env*`, local uploads, backups, or `setup.php`. This repository's `.gitignore` excludes these files; `config.sample.php` is the tracked placeholder. For a first install, securely upload `setup.php` separately, run it once, and remove it immediately.
3. Create `/var/www/thalvital/shared/config/config.php` from the application configuration template, with masked values supplied manually: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and a randomly generated 64-character production `AADHAAR_SALT`. Store the salt in the password manager and mark it immutable.
4. Symlink or securely copy the shared production config into the release where PHP expects `config.php`. Ensure the web-server user can read it but it is not web-downloadable.
5. Execute `schema.sql` and then `setup.php` once through the secured initial-deploy path. Record the temporary administrator credentials only in the password manager; delete `setup.php` immediately and change the administrator password.
6. Enable a valid SSL certificate and permanent HTTP-to-HTTPS redirect before entering any actual patient data. Verify session login over HTTPS.
7. Confirm the public availability page, one staff login, one patient portal login, and the acceptance checks in the [wiki test page](D:\project-wiki\10-systems\thalvital\thalvital-tests.md).

### Later releases

1. Review and test changes locally; run PHP lint and the acceptance checklist.
2. Back up the production database before every schema or clinical-workflow change. Never replace the production database merely to deploy code.
3. Create a new immutable release from a clean tag archive, retain `shared/config/config.php`, and activate it by atomically repointing `current` only after file and health checks succeed.
4. Apply schema changes as reviewed **additive migrations** (new migration SQL files), not by re-running destructive setup or importing a local database. Record the exact migration and backup identifier.
5. On failure, repoint `current` to the prior known-good release. For data migrations, restore only from a tested database backup after clinical/operational approval.

### Backups and rollback

- Enable Hostinger daily database backups and retain an independent encrypted backup according to the hospital retention policy.
- Before releases, capture a timestamped `mysqldump` (or Hostinger database export) and validate its non-zero size; test restoration in a non-production environment.
- Keep at least the prior application release. Roll back code by moving `current` to that release; database rollback is a separate, riskier operation requiring a verified backup.

### Pre-go-live checklist

- [ ] PHP lint is clean and the six-center setup has succeeded in staging.
- [ ] Aadhaar duplicate block, bag issue/crossmatch, center/org scoping, public availability, EN/HI, and audit-log checks pass.
- [ ] `config.php`, database credentials, and the Aadhaar salt are not committed or web-accessible.
- [ ] HTTPS redirect, secure cookies, least-privilege DB access, and daily backups are verified.
- [ ] `setup.php` has been deleted and the default administrator password changed.
- [ ] **All synthetic/validation data is purged** before any real patient exists — demo/UAT bags (`DEMO-*`, `UAT-*`), synthetic patients (`PAT-*` test rows), their phenotypes/alloantibodies/crossmatches/visits, and any seeded demo inventory. Synthetic fixtures are kept during validation to prove the acceptance checks; they must never survive into the production database (same discipline as generating the permanent production salt).
- [ ] Phenotype extended-match checks 1–7 pass in staging (see `thalvital-tests` wiki page): NULL-never-negative on both patient and bag sides, antibody hard-block forces C2, unverifiable-antibody doctor acknowledgement, prophylactic amber acknowledge.

## Release workflow (solo, main + tags)

Production is deployed **only from an annotated tag**, never from the untagged tip of `main`. Use semantic versions: `vMAJOR.MINOR.PATCH`.

1. Work and commit on `main`.
2. Run PHP lint and the local acceptance checks in the [wiki test page](D:\project-wiki\10-systems\thalvital\thalvital-tests.md).
3. Back up the production database and record the backup identifier.
4. Tag and push the exact release commit:

   ```powershell
   git tag -a v1.0.0 -m "ThalVital v1.0.0"
   git push origin v1.0.0
   ```

5. From a clean working tree, run the release tool with the tag and approved VPS connection values:

   ```powershell
   .\scripts\release-tag.ps1 -Tag v1.0.0 -RemoteHost <host> -RemoteUser <user>
   ```

   The tool uses `git archive --format=tar <tag>` rather than the working directory, adds `RELEASE.txt` containing the tag, commit SHA, and build timestamp, transfers the archive, creates `releases/<timestamp>`, links the shared production config, and atomically repoints `current`.
6. Run HTTPS post-checks. On a code failure, repoint `current` to the previous `releases/<timestamp>` directory.

### Rollback levels

- **Instant runtime rollback:** point `current` back to the previous timestamped release. This does not alter database data.
- **Source-level rollback:** deploy an earlier annotated tag through `release-tag.ps1`, creating a new timestamped release with that tag's contents.
- **Database rollback:** separate, backup-driven, and clinically/operationally approved; never assume a code rollback reverses a schema or clinical-data change.

## Appendix: observed SarkariDoctor workflow

### Observed workflow

1. Source lives at `E:\projects\sarkaridoctor`; a Vite frontend builds to `frontend\dist`.
2. `scripts\update-theme.ps1` builds and copies assets (and optionally theme/MU-plugin PHP) into a local XAMPP WordPress runtime at `C:\xampp\htdocs\sarkaridoctor\cms`.
3. `scripts\vps_release_sync.py` checks source/XAMPP parity, packages a fresh runtime payload, and transfers it with Paramiko SSH/SFTP to a VPS.
4. The script creates `/var/www/sarkaridoctor-staging/releases/<timestamp>`, preserves shared uploads, provisions shared `.htaccess` and `wp-config.php` from templates/environment inputs, then atomically switches `current`.
5. The prior release stays available for symlink rollback. The documented local backup script uses `mysqldump` and a WordPress content copy before migrations.

| Tool / mechanism | Purpose | Observed location |
|---|---|---|
| `update-theme.ps1` | Build and normalize local WP runtime | `scripts/update-theme.ps1` |
| Paramiko SSH/SFTP release sync | Fresh VPS releases and symlink activation | `scripts/vps_release_sync.py` |
| Shared config templates | Keep live DB credentials, salts, URLs out of release payload | `scripts/templates/` |
| `mysqldump` backup | Pre-migration local backup | `scripts/backup-before-migration.ps1` |
| `.env.local` | VPS/site/local paths and credentials | project root, ignored |

### Found and not found

- Found: ignored `.env.local`; a source-controlled WordPress config template; shared config/uploads; HTTPS redirect in the shared `.htaccess` template; an explicit rollback command; MySQL backup script; SSH/SFTP release tooling.
- Not found: `.git` repository metadata or a Git remote; Git-based deployment; Hostinger hPanel/Git deployment configuration; CI workflow files; a documented WordPress database migration or serialized URL rewrite command; a confirmed hosting plan/PHP-version pin; documented scheduled backups/cron.
- Masked secret locations requiring human handling: `E:\projects\sarkaridoctor\.env.local` contains VPS, WordPress audit/login, and local-path settings; live WordPress credentials/salts are rendered from `SD_WP_*` inputs into the shared VPS config. No values were copied here.

### WP-to-ThalVital mapping

| Concern | SarkariDoctor approach | ThalVital equivalent |
|---|---|---|
| Version control | No `.git` metadata found; `.env.local` ignored | Keep code in Git if adopted; ignore `config.php` and secret env files |
| Config | Shared template-rendered `wp-config.php` | Shared untracked `config.php` with DB values and permanent Aadhaar salt |
| Database | Backup script found; migration/URL rewrite not documented | `schema.sql` plus reviewed additive migrations; do not copy patient DB to local |
| URLs | WP home/site URL settings; serialized rewrite process not found | No serialized URL issue; use environment-appropriate base paths |
| File transfer | Paramiko SSH/SFTP, fresh releases, `current` symlink | Same VPS mechanism, with PHP source payload and shared config |
| Routing | WP `.htaccess` fallback and HTTPS redirect | HTTPS redirect only unless PHP routes require rewrite rules |
| Backups/rollback | `mysqldump`, retained releases, symlink rollback | Daily DB backup, retained releases, rollback code separately from data |

## Wiki follow-up

`WIKI_SYNC.md` is active. Create and register a `thalvital-deployment.md` wiki page using the project-wiki guide and link it from the ThalVital overview; do not treat this repository document as the sole living deployment record.
