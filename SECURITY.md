# Security policy

## Supported versions

Security fixes are applied to the latest release of Cogwork Engine. Users
should upgrade to the newest available version before reporting an issue that
may already have been corrected.

## Reporting a vulnerability

Please do not disclose suspected vulnerabilities in a public issue. Use
GitHub's private vulnerability reporting feature for this repository. Include
the affected version, configuration, reproduction steps, and potential impact.
Do not include real credentials, private modpacks, access tokens, or personal
data in a report.

## Deployment responsibility

Cogwork Engine must be served over HTTPS. Confirm that `app/`, `config/`,
`lang/`, `storage/`, `tests/`, `vendor/`, repository metadata, and development
files cannot be downloaded through the web server. Keep PHP and the host
operating system supported and patched, use strong unique passwords, and keep
independent backups of application data and Minecraft worlds.

Forwarded HTTPS headers are trusted only from administrator-configured proxy IPs
or CIDR networks. Configure the canonical HTTPS origin before enabling recovery,
2FA, passkeys, or reCAPTCHA. Keep SMTP, proxy, CAPTCHA, application, cron,
session, reset, and factor secrets out of issue reports and diagnostic uploads.

Login and recovery endpoints use persistent account, network, and installation
throttling. CAPTCHA supplements these controls; it does not replace passwords,
2FA, or rate limiting. Permanent user deletion removes identity and factor data
while retained operational history uses the neutral “Deleted User” identity.
