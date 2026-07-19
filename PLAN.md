# Restricted-Hosting PHP Rebuild Plan

## Goal and constraints

Rebuild the Python CLI as a PHP 8.3+ multi-user web application for restricted
Apache shared hosting. The deployed application must require no shell, daemon,
Node.js process, server-side Composer, or custom document root. It must manage
multiple isolated and shareable packs with SQLite by default and MySQL as an
installer option.

## Architecture

- Use a front controller, small application router, service layer, PDO
  repositories, server-rendered HTML, and vanilla JavaScript/CSS.
- Protect source, configuration, tests, dependencies, and all generated data
  with root and directory-level `.htaccess` rules.
- Store the standard `modrinth.index.json` document as the pack interchange
  model and keep jobs, packages, backups, credentials, and audit events in SQL.
- Divide imports, synchronization, update checks, update application, and builds
  into persistent, idempotent HTTP job steps. Process them from an open browser
  or an optional token-authenticated cron request.
- Stream remote files into bounded temporary files, permit only approved HTTPS
  Modrinth hosts, validate sizes and hashes, and publish successful downloads
  atomically.
- Validate archive entry paths, counts, and expanded sizes before extracting
  overrides. Build packages with only the index and overrides required by the
  Modrinth format.

## Product workflows

- Browser installer: environment checks, database choice, administrator
  creation, secret generation, migrations, and permanent installer lock.
- Authentication: administrator and user roles, pack ownership and granular
  sharing permissions, secure native sessions, CSRF validation, login
  throttling, password changes, and POST-only mutations.
- Packs: create, import by upload or Modrinth version ID, edit dependencies and
  environment flags, add/remove mods, synchronize local files, and delete with
  isolated storage cleanup.
- Updates: discover the newest compatible project versions, detect missing or
  corrupt files, show a review, back up the current index, then download and
  apply each accepted change.
- Builds: preview with a mutation-free dry run or back up, update version and
  summary, generate `.mrpack`, record checksum/size, and provide authenticated
  downloads.
- Recovery: persist progress after every unit of work, release stale locks,
  log errors with safe reference identifiers, retain recent packages/backups,
  and allow index restoration.

## Verification

- Unit-test URL and path boundaries, archive traversal rejection, Modrinth index
  validation, and environment values.
- Integration-test SQLite repository behavior and prove that dry-run builds
  create no backup/package and do not change the pack version.
- Validate PHP syntax and run PHPUnit under PHP 8.3 with cURL, ZIP, mbstring,
  OpenSSL, and PDO SQLite; repeat database migrations against MySQL before a
  production release.
- On an Apache staging host, verify sensitive URLs return 403/404, complete the
  installer, and exercise create/import/sync/check/apply/build/restore flows.

See `README.md` for deployment and operation instructions.
