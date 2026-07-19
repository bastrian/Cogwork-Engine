# Cogwork Engine

*Build, maintain, and migrate modpacks.*

Cogwork Engine is a self-hosted PHP application for creating, importing,
maintaining, sharing, and migrating Modrinth modpacks. It is designed for
restricted shared hosting and processes long-running work in short, resumable
HTTP steps—no daemon, shell access, Node.js, or production Composer install is
required.

**[Download a release](https://github.com/bastrian/Cogwork-Engine/releases)** ·
**[Read the documentation](https://github.com/bastrian/Cogwork-Engine/wiki)**

## Highlights

- Create or import `.mrpack` projects and synchronize verified mod files.
- Discover compatible updates and build reproducible client or server packages.
- Compare Minecraft and loader migration targets, blockers, and replacements.
- Manage administrators, users, pack ownership, and granular sharing rights.
- Use SQLite by default or connect to MySQL.
- Work in English or German with an integrated tutorial and help center.
- Continue background jobs in the browser or through an optional cron request.

## Requirements

- Apache 2.4 with `.htaccess` support
- PHP 8.3+ with cURL, JSON, mbstring, OpenSSL, PDO, and ZIP
- PDO SQLite or PDO MySQL
- A writable `storage/` directory

## Quick installation

1. Download the latest release and upload its contents to a dedicated directory
   such as `public_html/cogwork-engine`.
2. Make `storage/` writable by PHP. Prefer `0770` when the hosting setup allows
   it.
3. Confirm that URLs below `app/`, `config/`, `storage/`, and `vendor/` return
   HTTP 403 or 404.
4. Open the application in a browser and complete the installer.
5. Configure HTTPS before making the installation publicly accessible.

SQLite is recommended for a simple installation. MySQL requires an empty
database and user created through the hosting control panel first.

For complete deployment, upgrade, permission, cron, and recovery instructions,
see the **[Cogwork Engine wiki](https://github.com/bastrian/Cogwork-Engine/wiki)**.

## Development and testing

```bash
composer install
composer test
```

Without a local PHP environment:

```bash
docker compose --profile test run --rm test
```

The test suite uses temporary data and does not require the live Modrinth API.
Operational details are also retained in [OPERATIONS.md](OPERATIONS.md), and
planned work is tracked in [TODO.md](TODO.md).

## Security

Do not expose Cogwork Engine without HTTPS and verified `.htaccess` protection.
Report suspected vulnerabilities privately as described in
[SECURITY.md](SECURITY.md); never include credentials or private modpack data in
a public issue.

## License

Cogwork Engine is licensed under the
[GNU Affero General Public License v3.0](LICENSE).
