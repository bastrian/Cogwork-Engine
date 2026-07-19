# Cogwork Engine implementation checklist

Phases 1–13 are complete and retained as a development archive so earlier
decisions remain auditable. Original product suggestions are archived in
[FEATURE_IDEAS.md](FEATURE_IDEAS.md).

## Phase 1 — Safety and daily usability

- [x] Add dependent Minecraft/loader selectors and server-side compatibility validation to pack settings.
- [x] Run validation automatically before builds and block builds containing errors.
- [x] Add job cancellation and safe retry controls.
- [x] Add search, environment filters, and pagination to large mod tables.
- [x] Show cached project names, icons, versions, and cache timestamps in the mod table.

## Phase 2 — Better mod management

- [x] Search the offline catalog and Modrinth when available.
- [x] Display project metadata and compatibility before adding a mod.
- [x] Detect duplicate projects and filenames before insertion.
- [x] Add multiple selected mods in one operation.
- [x] Support local/private JAR uploads with automatic hashes and explicit environment metadata.

## Phase 3 — Import and diagnostics

- [x] Add a staged import review before a pack is permanently created.
- [x] Preview metadata, dependencies, overrides, environments, conflicts, and warnings.
- [x] Diagnose override and configuration-layer conflicts.
- [x] Store and display catalog refresh history.

## Phase 4 — Server packs and profiles

- [x] Add per-pack Java version and JVM memory settings.
- [x] Add editable server properties and safe EULA handling.
- [x] Add configurable Linux/Windows startup scripts and loader bootstrap guidance.
- [x] Add server exclusions and override controls.
- [x] Add reusable client, server, lightweight, development, and optional-mod profiles.

## Phase 5 — Reproducibility and portability

- [x] Store complete build manifests: inputs, hashes, catalog timestamps, target, options, and warnings.
- [x] Rebuild previous releases from recorded manifests.
- [x] Export packs, database data, overrides, packages, and cached metadata.
- [x] Restore validated backups with secrets excluded by default.

## Phase 6 — Quality and identity

- [x] Include PHPUnit in the development container and automate the full test suite.
- [x] Add a proper favicon and application branding assets.
- [x] Complete a security, accessibility, and responsive-layout review.
- [x] Update installation, operations, backup, cron, and recovery documentation.

## Phase 7 — Minecraft and loader migration workbench

### Migration data and compatibility analysis

- [x] Add persistent migration-scan tables for candidate Minecraft versions, loaders, project-version matches, scan timestamps, and errors.
- [x] Fetch and cache newer stable Minecraft releases and their compatible loader versions without replacing the pack's current offline catalog.
- [x] Scan every Modrinth project against each selected Minecraft/loader combination using resumable, rate-limit-aware background jobs.
- [x] Classify every pack entry as directly compatible, alternative version available, replacement suggested, incompatible, unknown/local, or requiring manual review.
- [x] Resolve target-version dependencies and report dependencies that are added, removed, incompatible, or unavailable for the target.
- [x] Treat required, optional, client-only, server-only, and profile-excluded mods separately in compatibility totals.
- [x] Record incomplete and stale scans clearly so Modrinth outages never appear as confirmed incompatibility.

### Recommendations and comparison UI

- [x] Add a **Migration planner** action to each pack without changing the existing pack.
- [x] Let the administrator choose candidate Minecraft releases, release/beta policy, and loaders to compare.
- [x] Compare same-loader upgrades by default and offer an explicit **Explore other loaders** mode for Forge, NeoForge, Fabric, and Quilt where available.
- [x] Display a comparison table for every Minecraft/loader target with portable, replacement, blocked, unknown, and manual-review counts and percentages.
- [x] Add expandable per-target details showing the current mod version, proposed version, compatibility evidence, dependency changes, and blocker reason.
- [x] Allow mods to be marked essential, optional, or replaceable and recalculate recommendations immediately.
- [x] Rank a **Safest next upgrade** and **Best overall upgrade**, heavily penalizing missing essential mods and preferring stable releases and direct upgrades.
- [x] Explain every recommendation score and never present unknown or stale data as verified compatibility.

### Cross-loader alternatives

- [x] Detect when the same Modrinth project publishes a build for the target loader.
- [x] Add a curated, administrator-editable mapping of equivalent projects for genuine cross-loader replacements.
- [x] Show replacement confidence and evidence, and require explicit approval for each different-project substitution.
- [x] Include loader APIs and bootstrap dependencies automatically when required, and identify obsolete loader-specific dependencies for removal.
- [x] Flag loader-specific configuration, scripts, overrides, and startup settings that require manual review.

### Safe migration application

- [x] Create migrations only as a new pack copy/profile, preserving the original pack and its files.
- [x] Provide a review screen where proposed updates, replacements, dependency changes, and unresolved items can be accepted individually; removals remain explicit after-copy decisions.
- [x] Apply approved metadata changes atomically with source fingerprinting, duplicate suppression, and rollback of partial copies on failure.
- [x] Update the copied pack's Minecraft version, loader, loader version, Java guidance, dependencies, and launch configuration together.
- [x] Run the existing validation pipeline after migration and block release builds while migration errors remain.
- [x] Produce a migration report and reproducible manifest containing decisions, source/target versions, evidence timestamps, warnings, and hashes.
- [x] Offer client and server-pack test builds plus a launch-test checklist covering worlds, configs, scripts, and backups.

### Verification and documentation

- [x] Unit-test compatibility classification, stale/unknown handling, recommendation scoring, essential-mod penalties, and replacement confidence.
- [x] Integration-test resumable scans, API failures, dependency changes, same-loader migration, cross-loader mappings, cancellation safety, and rollback boundaries.
- [x] Add fixtures covering local JARs, abandoned mods, optional dependencies, client/server-only projects, and incomplete Modrinth responses.
- [x] Verify that analysis is read-only and that applying a migration never mutates the source pack.
- [x] Test the complete analysis workflow against all 142 entries of the imported older pack before enabling migration application by default.
- [x] Document declared-versus-runtime compatibility limitations, cross-loader risks, backups, world migration, and the recommended launch-test procedure.

## Phase 8 — Localization

### Localization foundation

- [x] Add a protected `lang/` directory containing `en_US.php` and `de_DE.php`, with both files returning the same set of translation keys.
- [x] Create a translation service with parameter interpolation, plural handling, HTML-safe output conventions, and `en_US` fallback for missing keys.
- [x] Whitelist supported locale identifiers so request or cookie values can never select an arbitrary PHP file.
- [x] Add language selection to login and account settings, store the preference per user, and remember pre-login selection in a secure cookie.
- [x] Localize dates, times, numbers, percentages, and file sizes while keeping stored pack names and other user-entered content unchanged.
- [x] Replace hard-coded interface text in routes, forms, navigation, jobs, validation, migration, status messages, and errors with translation keys.
- [x] Store durable job/audit data as language-independent codes and parameters so it can be rendered in the viewer's selected language.
- [x] Add automated checks that both locale files contain matching keys, placeholders are consistent, fallback works, and rendered translations remain escaped.
- [x] Document how additional locales such as `en_GB` can be added without changing application code.

### Localization release verification

- [x] Run the complete PHP syntax, PHPUnit, security-boundary, accessibility, responsive-layout, and translation-key suites.
- [x] Verify locale selection and fallback behavior on login, account settings, jobs, errors, validation, and migration pages.
- [x] Verify protected locale files cannot be downloaded directly from Apache or other supported web-server configurations.
- [x] Update README and contributor documentation for locale selection, translation maintenance, and adding another language.

## Phase 9 — Multi-user accounts and pack sharing

### User accounts and ownership migration

- [x] Replace the single-purpose administrator model with a `users` model supporting `admin` and `user` roles, enabled/disabled state, locale, tutorial progress, and timestamps.
- [x] Migrate the existing administrator safely into the new user table without changing credentials or invalidating the installation.
- [x] Add an owner to every pack and assign all existing packs to the migrated administrator.
- [x] Associate jobs, packages, backups, migration scans/copies, uploads, and audit events with the initiating user where appropriate.
- [x] Build administrator-only account management for creating, editing, disabling, re-enabling, and resetting user accounts.
- [x] Add self-service account settings for display name, locale, and password changes.
- [x] Retain disabled accounts for audit history and require ownership transfer before responsibility can move to another account.
- [x] Update sessions and login throttling for multiple accounts, regenerate sessions after privilege changes, and revoke sessions when an account is disabled.

### Central authorization and pack sharing

- [x] Create one authorization service used by every route, service mutation, job action, download, backup, and API response.
- [x] Define pack capabilities for viewing, metadata editing, mod management, synchronization, updates, migration, builds/downloads, server settings, sharing, and deletion.
- [x] Add sharing presets for Viewer, Contributor, Maintainer, and Custom permissions.
- [x] Store per-user pack grants and provide an owner-facing sharing screen for granting, changing, and revoking access.
- [x] Prevent users from granting capabilities they do not hold; restrict ownership transfer to the owner or an administrator.
- [x] Ensure administrators retain global access while ordinary users see only owned or explicitly shared packs.
- [x] Make copied, imported, and migrated packs private to their creator until explicitly shared.
- [x] Record the acting user, affected pack, permission changes, ownership transfers, and sensitive actions in the audit log.
- [x] Make navigation and controls reflect permissions without relying on hidden UI as the security boundary.
- [x] Return consistent 403 responses without revealing whether unauthorized pack IDs, jobs, packages, or backups exist.
- [x] Add authorization tests proving users cannot access another user's routes, downloads, jobs, files, backups, packages, scans, or guessed identifiers.
- [x] Test privilege escalation boundaries, grant delegation, disabled accounts, ownership transfers, concurrent jobs, and administrator recovery.

### Multi-user release verification

- [x] Run additive SQLite and MySQL/MariaDB migrations, including repeat/idempotency checks, and verify rollback/recovery instructions.
- [x] Confirm existing packs, credentials, jobs, packages, backups, manifests, and migration data survive the account/ownership migration.
- [x] Exercise administrator, owner, shared maintainer, contributor, viewer, disabled-user, and unauthorized-user workflows through automated tests and staging HTTP checks.
- [x] Verify sensitive user data, pack storage, downloads, jobs, packages, backups, and migration records remain inaccessible without authorization.
- [x] Update README and operations documentation for account administration, sharing, ownership transfer, permission recovery, and audit review.

## Phase 10 — Guided tutorial and user help

### First-login tutorial

- [x] Add per-user tutorial state with not-started, in-progress, skipped, and completed states plus the last completed step.
- [x] Show a short, optional tutorial after a user's first successful login and allow it to be skipped, resumed, or restarted.
- [x] Cover navigation, creating/importing packs, catalog refresh, synchronization, updates, validation, builds, migration, sharing, permissions, and backups.
- [x] Make tutorial steps responsive, keyboard-accessible, screen-reader-friendly, and robust when the user lacks permission for a demonstrated feature.
- [x] Keep tutorial content localized through the same translation system as the application.
- [x] Add tests for first-login triggering, progress persistence, skip/resume/restart behavior, locale changes, and permission-dependent steps.

### User-facing help center

- [x] Add a persistent Help control in the top-right navigation with context-sensitive links for the current page.
- [x] Build local, searchable help covering every user-facing feature without requiring internet access.
- [x] Separate approachable user guides from administrator operations and technical/reference documentation.
- [x] Add task-based guides for imports, synchronization, updates, profiles, server packs, migration, sharing, permissions, backups, and recovery.
- [x] Add a glossary for loaders, `.mrpack`, environments, overrides, profiles, dependencies, compatibility, and migration terminology.
- [x] Link validation findings, job failures, migration classifications, and warnings through contextual Help topics.
- [x] Provide a tutorial restart action and clearly display the application version and documentation version.
- [x] Localize all initial help content in English and German and validate translation keys and contextual anchors during tests.

### Tutorial and help release verification

- [x] Run tutorial state, documentation-search, internal-link, localization, accessibility, keyboard-navigation, and responsive-layout tests.
- [x] Exercise onboarding as an administrator, pack owner, shared maintainer, contributor, viewer, and user without any packs.
- [x] Verify skipped, partially completed, completed, restarted, and locale-switched tutorials resume at the correct place.
- [x] Confirm help remains usable offline and documentation sources cannot expose application internals or unsafe markup.
- [x] Review all English and German user guides for accuracy against the released interface.
- [x] Update README and operations documentation for tutorial customization, help maintenance, documentation versions, and recovery of tutorial state.

## Already completed

- [x] PHP/shared-hosting application, installer, authentication, and pack management.
- [x] `.mrpack` import/export and standalone server-pack builds.
- [x] File synchronization and cryptographic verification.
- [x] Pack validation reports.
- [x] Offline/stale Minecraft and loader catalogs.
- [x] Offline project/version metadata and resumable refresh jobs.
- [x] Pack diagnostics dashboard.
- [x] Detailed job progress and terminal navigation.
- [x] Modrinth status indicator and Modrinth-inspired visual design.

## Phase 11 — Navigation and administration

This section records the completed roadmap. Implementation evidence includes
the relevant automated tests, user documentation, and deployment checks.

### Header and account navigation

- [x] Replace the crowded account-related header links with one accessible user dropdown showing the current display name or username.
- [x] Keep Modrinth status and Help visible while moving language selection into the user dropdown.
- [x] Add Account, Change password, conditional Admin dashboard, and POST-only Sign out actions to the dropdown.
- [x] Support keyboard navigation, focus management, escape/outside-click closing, screen readers, touch input, narrow screens, and reduced motion.
- [x] Remove the separate Users, Admin, and Account links after their destinations are available through the unified navigation.
- [x] Display the installed Cogwork Engine version from `VERSION` in Help and the administration area.

### Administration dashboard

- [x] Replace the separate Users and Admin pages with one administrator-only dashboard using Packs, Users, System, and Audit tabs.
- [x] Show every pack with its owner, explicitly shared users, Minecraft version, loader, status, and direct management actions.
- [x] Show every user with username, display name, email, role, enabled state, last successful login, owned packs, and shared packs.
- [x] Distinguish global administrator access from pack ownership and explicit pack assignments.
- [x] Add a user detail screen for profile changes, password resets, role and enabled-state changes, owned/shared packs, and security state.
- [x] Let administrators assign a user to a pack, apply Viewer/Contributor/Maintainer presets or custom capabilities, change existing grants, and revoke access.
- [x] Let administrators transfer pack ownership while preventing a pack from being left without a valid owner.
- [x] Move backups, migration replacement mappings, cron configuration, mail/security configuration, and version information into the System tab.
- [x] Provide an Audit tab with filters for account, pack, action, date, and security event without exposing secrets or reset/authentication tokens.

### System health dashboard

- [x] Add a System health page summarizing PHP and extension versions, database connectivity, migrations, writable directories, storage usage, and free disk space.
- [x] Show HTTPS, canonical URL, trusted proxy, secure-cookie, mail, cron, Modrinth, outbound proxy, update service, and background-job health in one place.
- [x] Distinguish Healthy, Degraded, Misconfigured, Disabled, and Unknown states with accessible explanations and recommended corrective actions.
- [x] Show last successful and failed cron runs, mail tests, catalog refreshes, update checks, backups, and long-running or repeatedly failing jobs.
- [x] Keep checks bounded and cached so loading the dashboard cannot create slow external requests or exhaust disk/database resources.
- [x] Add administrator-triggered safe diagnostics while preventing the page from exposing paths, credentials, tokens, database details, or private proxy responses.
- [x] Provide a downloadable redacted diagnostic report suitable for support requests.

### Pack activity timeline

- [x] Add a chronological activity timeline per pack covering metadata, mods, synchronization, validation, updates, migration, builds, restores, ownership, and permissions.
- [x] Show actor or anonymized Deleted User, timestamp, action, result, and a concise human-readable summary without recording secrets or full private file contents.
- [x] Link timeline entries to retained jobs, builds, migration manifests, validation reports, and audit events when the viewer has permission.
- [x] Add filters for action type, result, actor, and date plus pagination for long-lived packs.
- [x] Enforce pack authorization on every timeline query and retained-detail link.

### GitHub-backed update checker

- [x] Add an update panel to the administrator dashboard showing the installed version from `VERSION`, newest known release, publication date, release channel, and check time.
- [x] Query only the public `bastrian/Cogwork-Engine` GitHub Releases API and require no GitHub token for normal update checks.
- [x] Support Stable only and Include pre-releases channels, defaulting preview installations to the channel matching their installed version.
- [x] Compare normalized semantic versions without treating drafts, malformed tags, or unrelated repository tags as updates.
- [x] Cache the last valid response, ETag or Last-Modified validator, rate-limit headers, and check timestamp to avoid unnecessary GitHub requests.
- [x] Use conditional requests and a descriptive Cogwork Engine user agent; respect GitHub rate limits and `Retry-After` without aggressive retries.
- [x] Run automatic checks at a conservative configurable interval and provide an administrator-only Check now action with its own rate limit.
- [x] Let administrators disable automatic update checks independently from Modrinth connectivity and other application features.
- [x] Show a non-blocking unavailable/stale state when GitHub, DNS, TLS, or an outbound proxy fails while retaining the last valid result.
- [x] Display release title, concise notes, compatibility warnings, and trusted links to the GitHub release and wiki without rendering arbitrary release HTML.
- [x] Identify the expected shared-hosting ZIP and SHA-256 asset, showing whether both are present and whether GitHub reports a digest.
- [x] Never download, extract, replace, or execute release content automatically in the initial implementation; updates remain administrator-approved manual operations.
- [x] Route update checks through a separately validated general outbound proxy setting if one is later enabled, without reusing Modrinth-only proxy rules implicitly.
- [x] Record update-check configuration changes and manual checks in the audit log without logging IP addresses, proxy credentials, or GitHub response bodies.
- [x] Add fake-provider tests for newer/equal/older versions, stable and pre-release selection, ETag 304 responses, rate limits, malformed data, missing assets, timeouts, and stale-cache fallback.
- [x] Document the GitHub request, privacy implications, release channels, cached behavior, and manual upgrade process in the admin help and wiki.

## Phase 12 — Email, recovery, and authentication security

### Required email addresses and mail delivery

- [x] Add a required, normalized, case-insensitively unique email address to user accounts.
- [x] Require an administrator email during new installation and require email when creating or editing users.
- [x] Add a safe upgrade path that prompts existing accounts to provide an email before continuing normal work.
- [x] Track email verification state and require verification when an address is added or changed.
- [x] Add SMTP as the preferred delivery method with host, port, encryption mode, username, password, sender address, and sender name settings.
- [x] Add PHP `mail()` as an explicit fallback when SMTP is unavailable or not configured.
- [x] Store mail credentials in protected application configuration, redact them after saving, and exclude them from logical backups and logs.
- [x] Add an administrator-only test-email action with clear success/failure diagnostics that never displays credentials.
- [x] Queue or bound outbound mail work so slow mail servers cannot hold an application request indefinitely.

### Forgot-password and administrator password resets

- [x] Add a Forgot password link and a localized request form to the login screen.
- [x] Always return the same response and comparable timing regardless of whether an email address exists.
- [x] Generate cryptographically secure, single-use reset tokens and store only token hashes.
- [x] Expire reset links after a short configurable period, defaulting to 30 minutes, and invalidate older tokens when a new one is issued or used.
- [x] Rate-limit reset requests and token verification per account, email, IP/network, and installation.
- [x] Invalidate all account sessions and outstanding reset tokens after a successful password reset.
- [x] Notify the account by email after its password or email address changes.
- [x] Keep administrator-initiated password resets auditable and require the user to choose a new password rather than sending a password by email.

### Persistent brute-force and abuse protection

- [x] Replace session-only login throttling with persistent database-backed limits that survive cookie clearing and application restarts.
- [x] Combine per-account, per-IP/network, and installation-wide attempt tracking without revealing valid usernames.
- [x] Add increasing delays and temporary cooldowns while avoiding permanent attacker-controlled account lockouts.
- [x] Apply appropriate throttling to login, forgot-password, reset-token, email-code, TOTP, recovery-code, and passkey endpoints.
- [x] Record security events and make suspicious activity visible to administrators without storing submitted passwords, OTPs, CAPTCHA tokens, or reset tokens.
- [x] Add optional email notifications for significant account-security events with notification rate limits.
- [x] Clear or decay failure state after successful strong authentication without allowing token regeneration to reset active limits.

### Session and device management

- [x] Store individual authenticated sessions with creation time, last activity, authentication methods, approximate device/browser label, and revocation state.
- [x] Let users view their active sessions, distinguish the current session, revoke another session, or sign out everywhere.
- [x] Let administrators revoke sessions for a user without viewing session tokens or impersonating the account.
- [x] Store only hashed session identifiers and minimize IP/user-agent retention according to the deletion and privacy policy.
- [x] Rotate the session identifier after login, password changes, privilege changes, recovery, and strong-authentication events.
- [x] Expire idle and absolute session lifetimes according to administrator policy, with stricter defaults for administrators.
- [x] Make password reset, account disablement/deletion, email recovery, and factor reset invalidate all affected sessions reliably.
- [x] Notify users of important new sessions or mass revocation without creating an email-notification flood.

### Two-factor authentication policy

- [x] Add an administrator policy with Disabled, Optional, Required for administrators, and Required for everyone modes.
- [x] Provide a guided enrollment flow and a recovery-readiness check before enforcing 2FA on an account.
- [x] Require recent password or strong-authentication confirmation before adding, replacing, or removing an authentication factor.
- [x] Prevent administrators from silently registering a factor as another user.
- [x] Show each account's enrolled factor types and last use without exposing factor secrets.
- [x] Add a controlled administrator recovery process that is prominent in the audit log and invalidates existing sessions.

### TOTP and recovery codes

- [x] Implement RFC 6238-compatible TOTP enrollment with a QR code and manual setup key.
- [x] Encrypt TOTP secrets at rest using an application-derived key and never include them in logical backups or logs.
- [x] Require a valid code to complete enrollment and allow only a small documented clock-skew window.
- [x] Prevent replay of an accepted TOTP time step and rate-limit failed codes.
- [x] Generate single-use recovery codes, display them only once, and store only strong hashes.
- [x] Let users regenerate recovery codes after recent strong authentication, invalidating the previous set.

### Account recovery kit

- [x] Present recovery-code download/print guidance immediately after successful 2FA enrollment and require acknowledgement before enforcement.
- [x] Add a user-facing recovery status showing remaining recovery-code count without revealing code values.
- [x] Provide an administrator recovery guide covering lost TOTP devices, unavailable passkeys, inaccessible email, disabled mail delivery, and final-administrator recovery.
- [x] Add protected emergency procedures for maintenance mode, canonical URL, trusted proxy, SMTP, logout redirect, and mandatory-2FA misconfiguration.
- [x] Make every emergency recovery action explicit, strongly confirmed, session-invalidating, and highly visible in the audit log.
- [x] Never place factor secrets, password-reset tokens, session tokens, or reusable credentials in a downloadable recovery document.

### Passkeys, WebAuthn, and Windows Hello

- [x] Implement WebAuthn/passkey registration and authentication on the configured HTTPS origin and relying-party ID.
- [x] Support Windows Hello, platform passkeys, roaming FIDO2 security keys, and compatible phone-based authenticators through the browser standard.
- [x] Use an actively maintained, security-reviewed WebAuthn library rather than implementing attestation and signature verification manually.
- [x] Generate single-use, short-lived challenges bound to the session, account, origin, relying party, and intended ceremony.
- [x] Require user verification where supported and validate origin, RP ID hash, challenge, flags, signature counter behavior, and credential ownership server-side.
- [x] Allow users to name, view, and revoke their passkeys after recent strong authentication.
- [x] Start with passkeys as a second factor and evaluate passwordless login only after broader production testing and recovery review.

### Email verification codes

- [x] Offer email codes as a clearly labeled compatibility/recovery factor rather than presenting them as equivalent to phishing-resistant passkeys.
- [x] Generate random single-use codes, store only hashes, use a short expiry, and prevent replay.
- [x] Rate-limit code sending and verification; generating another code must not reset accumulated verification failures.
- [x] Redact destination addresses in authentication screens and avoid revealing account existence.
- [x] Define how email fallback interacts with administrator-enforced TOTP/passkey requirements and account recovery.

### Optional Google reCAPTCHA protection

- [x] Add a CAPTCHA-provider boundary so another provider can be added later without rewriting authentication flows.
- [x] Implement optional Google score-based reCAPTCHA (v3/current score-based keys) for the login page.
- [x] Let administrators separately protect login and forgot-password requests.
- [x] Add settings for enabled state, site key, protected actions, minimum score (default `0.5`), and provider-failure policy.
- [x] Store the reCAPTCHA secret in protected configuration, redact it after saving, and exclude it from backups and logs.
- [x] Load Google scripts only on protected unauthenticated pages and update CSP only when the provider is enabled.
- [x] Verify tokens on the server and require success, expected hostname, expected action, acceptable age, single use, and the configured score.
- [x] Never treat a client-side result as authorization and never log reCAPTCHA tokens.
- [x] Use suspicious scores to require stronger verification where possible; reject invalid tokens with generic messaging.
- [x] Default to continuing with persistent rate limiting and required 2FA during a provider outage so Google cannot lock out all administrators.
- [x] Add an administrator test action and usage/error visibility without exposing keys or user-level tracking data.
- [x] Document Google privacy implications, required disclosures, free-tier limits, and the fact that CAPTCHA supplements rather than replaces throttling and 2FA.

### User deletion and historical anonymization

- [x] Add permanent user deletion as a separate, strongly confirmed action from disabling an account.
- [x] Require every owned pack to be transferred or deleted before deleting its owner.
- [x] Delete the account, email, password hash, sessions, reset tokens, factor secrets, recovery codes, CAPTCHA/security state, and pack grants.
- [x] Replace user references in retained operational and audit records with a neutral Deleted User identity.
- [x] Remove usernames, display names, email addresses, IP addresses, and other personal details from retained event metadata where they identify the deleted account.
- [x] Preserve non-personal action type, timestamp, affected pack, and result so pack and security history remains structurally understandable.
- [x] Ensure deletion cannot orphan jobs, packages, backups, migration records, audit events, or database foreign keys.

### Configurable post-logout redirect

- [x] Add one administrator-configured global logout destination.
- [x] Default to the Cogwork Engine login interface when no destination is configured.
- [x] Allow safe relative paths and explicitly configured absolute HTTPS URLs while rejecting credentials, dangerous schemes, malformed URLs, and control characters.
- [x] Never accept a logout destination from a request/query parameter and never reflect an untrusted destination into a redirect.
- [x] Keep logout POST-only, destroy the session before redirecting, and test same-site and external targets.

## Phase 13 — Feature controls, connectivity, and operations

### HTTPS awareness and secure-feature requirements

- [x] Detect whether the effective public request is HTTPS, including deployments behind explicitly trusted reverse proxies.
- [x] Never trust forwarded-protocol headers from arbitrary public clients; support a configured canonical application URL and trusted proxy addresses/networks.
- [x] Show HTTPS status in the installer and explain which account-security features require a secure origin.
- [x] Let installation continue over HTTP for local evaluation, but show a persistent administrator warning until the canonical application URL is HTTPS.
- [x] Disable passkey/WebAuthn enrollment and authentication when the effective origin is not secure.
- [x] Disable 2FA enrollment, password-reset links, email verification links, and external authentication integrations over insecure public HTTP to prevent secret or token exposure.
- [x] Keep secure-cookie behavior correct for direct TLS and trusted TLS-terminating proxies, and reject configurations that would create redirect loops.
- [x] Add a System diagnostic showing canonical URL, detected scheme, forwarded-header trust, secure-cookie state, and whether security-sensitive features are available.
- [x] Re-evaluate HTTPS-dependent feature availability on configuration changes without allowing a request parameter to override the detected origin.
- [x] Document shared-hosting TLS, the optional Caddy profile, third-party reverse proxies, trusted-proxy configuration, and recovery from an incorrect canonical URL.

### Administrator feature controls

- [x] Add a System → Features screen with categorized, searchable application feature toggles and clear descriptions of their effects.
- [x] Define feature flags centrally and enforce them in routes, services, jobs, cron, downloads, navigation, and APIs rather than only hiding controls.
- [x] Define dependencies and conflicts so enabling a feature automatically validates required HTTPS, mail, database, external-service, or permission configuration.
- [x] Prevent administrators from disabling core authentication, administrator recovery, database migrations, security logging, or the feature-control screen itself.
- [x] Record feature changes with actor, timestamp, previous state, new state, and reason in the audit log.
- [x] Make disabled features return a consistent localized unavailable response without revealing protected resources.
- [x] Decide safe behavior for queued/running jobs when their feature is disabled: finish, pause, cancel, or require an explicit administrator choice.
- [x] Provide conservative defaults for new installations and preserve current behavior during upgrades unless a feature has a new security requirement.
- [x] Show configuration validation, dependencies, and restart/reload requirements before saving feature changes.
- [x] Include feature states in system diagnostics and protected administrative backups while excluding secrets.

### Optional Modrinth connectivity and outbound proxy

- [x] Add a master administrator toggle for all live Modrinth API, status, metadata, search, import, update, and download connections.
- [x] Define a clear offline/local mode that continues to support existing packs, cached metadata, local JARs, validation, backups, and builds where required files already exist.
- [x] Hide or disable actions that inherently require Modrinth while explaining why they are unavailable and when cached results are being used.
- [x] Prevent queued jobs, cron, status checks, catalog refreshes, icon fetches, and background retries from bypassing the disabled Modrinth setting.
- [x] Keep HTTPS host allowlists, redirect restrictions, size limits, and hash verification active when Modrinth connectivity is enabled through a proxy.
- [x] Add optional outbound HTTP, HTTPS, and SOCKS5 proxy support for Modrinth requests through the existing cURL client boundary.
- [x] Support proxy host, port, type, optional username/password, connection timeout, and an explicit bypass list limited to administrator-controlled values.
- [x] Store proxy credentials in protected configuration, redact them after saving, exclude them from logical backups/logs, and never place them in URLs shown to users.
- [x] Route every applicable Modrinth API, status, catalog, icon, and file request consistently through the configured proxy rather than implementing it per screen.
- [x] Add an administrator connection test reporting DNS, proxy connection, TLS, HTTP, API, and timeout failures without exposing credentials or sensitive proxy responses.
- [x] Make proxy failure behavior explicit: use valid cached data where safe, show degraded/offline status, and never silently skip cryptographic verification.
- [x] Prevent proxy configuration from becoming a general-purpose request or SSRF facility; destinations must remain fixed or allowlisted by the application.
- [x] Audit changes to Modrinth connectivity and proxy configuration and expose only non-secret status in diagnostics.

### Maintenance mode

- [x] Add administrator-controlled maintenance mode with an optional start time, end time, localized message, and estimated return time.
- [x] Allow enabled administrators to sign in and use the administration area during maintenance so the installation cannot lock itself permanently.
- [x] Provide an emergency configuration-file method to disable maintenance mode when the database or admin UI is unavailable.
- [x] Decide separately whether maintenance pauses new jobs, running browser jobs, cron processing, mail delivery, and API/status endpoints; show the selected behavior before activation.
- [x] Reject ordinary user mutations consistently during maintenance and serve an appropriate HTTP 503 response with `Retry-After` when an end time is known.
- [x] Avoid redirect loops between login, logout, maintenance, password recovery, and administrator bypass routes.
- [x] Show a preview of the maintenance page and a prominent administrator-only banner while maintenance mode is active.
- [x] Audit maintenance scheduling, activation, edits, cancellation, and bypass use.

### Administrator announcements

- [x] Add an announcement manager for creating, editing, scheduling, activating, expiring, and archiving application-wide notices.
- [x] Support informational, success, warning, maintenance, and critical visual severities with accessible color and icon treatment.
- [x] Support audience targeting for everyone, authenticated users, administrators, pack owners, or selected users without exposing audience membership.
- [x] Keep announcement content plain text with safe line breaks and links; do not permit arbitrary administrator HTML or scripts.
- [x] Support separate English and German text with a documented fallback when a translation is missing.
- [x] Allow an announcement to link to an internal help topic or a validated HTTPS URL.
- [x] Support dismissible and non-dismissible notices, storing per-user dismissal without letting dismissal hide critical maintenance/security warnings.
- [x] Display active announcements consistently on desktop and mobile without crowding the redesigned header or obscuring forms.
- [x] Audit announcement creation, edits, activation, expiry, deletion, and audience changes.
- [x] Test overlapping schedules, time zones, expired notices, deleted users, localization, permissions, accessibility, and maintenance-mode interaction.

### In-application notification center

- [x] Add a per-user notification center for security events, jobs, builds, migrations, maintenance, announcements, permissions, backups, and available updates.
- [x] Distinguish unread, read, acknowledged, and archived notifications with bulk mark-read and safe retention behavior.
- [x] Link notifications only to routes and resources the current user is still authorized to view.
- [x] Use in-application delivery as the durable record when SMTP or `mail()` delivery fails, while avoiding duplicate notification floods.
- [x] Let users configure supported notification categories and email preferences without allowing them to suppress mandatory security notices.
- [x] Redact deleted users and removed resources gracefully while preserving understandable notification text.
- [x] Add pagination, accessible live-region behavior for new notices, mobile layout, localization, and unread-count display without crowding the header.

### Retention and storage policies

- [x] Add administrator retention settings for completed/failed jobs, audit/security logs, notifications, temporary files, packages, generated backups, cached icons/metadata, and expired authentication records.
- [x] Always retain a configurable minimum number of recent packages and backups and prevent retention from deleting records needed by active jobs or reproducibility manifests.
- [x] Show a dry-run estimate of records/files and bytes before applying a cleanup policy manually.
- [x] Run cleanup in bounded resumable steps through cron or an administrator job with cancellation, progress, and audit history.
- [x] Detect orphaned database rows and storage files conservatively without deleting uncertain or user-owned content automatically.
- [x] Warn administrators about low disk space and recommend cleanup without starting destructive work silently.
- [x] Exclude Minecraft worlds and external server data from application cleanup and document independent retention responsibilities.

### Non-secret configuration export and import

- [x] Export a versioned, human-readable configuration containing feature flags, retention rules, notification preferences, maintenance defaults, release channel, and other non-secret system behavior.
- [x] Exclude passwords, API/SMTP/proxy secrets, app keys, cron tokens, session/factor material, personal data, absolute private paths, and environment-specific credentials.
- [x] Validate schema version, types, dependencies, URLs, and feature requirements before importing configuration.
- [x] Preview every proposed change and require explicit administrator confirmation rather than applying an export blindly.
- [x] Support merge and replace modes only where their effects are unambiguous, with a backup and audit event before changes.
- [x] Keep exports portable across SQLite/MySQL and shared-hosting/Docker installations where a setting is meaningful.

### Upgrade readiness and release preparation

- [x] Add an administrator preflight check for PHP version/extensions, database driver and pending migrations, writable paths, free disk space, backup recency, cron health, and current jobs.
- [x] Compare the selected GitHub release's documented requirements with the current installation when structured release metadata is available.
- [x] Require or strongly recommend a fresh application/database backup before presenting manual upgrade instructions.
- [x] Detect local application-file modifications where practical and warn that overwriting them may lose changes.
- [x] Generate a redacted upgrade-readiness report with blockers, warnings, current version, target version, and rollback guidance.
- [x] Do not perform automatic self-updates initially; keep download, verification, file replacement, migration, and rollback under administrator control.
- [x] Document a tested rollback process for application files and databases and distinguish rollback-safe additive migrations from irreversible changes.

### Database migration and compatibility

- [x] Design additive SQLite and MySQL migrations for emails, last login, mail configuration references, rate limits, reset tokens, factors, passkeys, recovery codes, security events, anonymized users, feature flags, maintenance state, announcements, and dismissals.
- [x] Preserve existing credentials, pack ownership, grants, sessions where safe, jobs, packages, backups, migration data, and audit history during upgrade.
- [x] Make migrations idempotent and test upgrades from the current `v0.1.0` schema on SQLite and MariaDB/MySQL.
- [x] Define backup/restore behavior for new non-secret security metadata while continuing to exclude credentials and factor secrets.

### Phases 11–13 verification and documentation

- [x] Add automated authorization and abuse tests for every new account, admin, recovery, factor, CAPTCHA, deletion, and redirect endpoint.
- [x] Test email enumeration resistance, replay prevention, token expiry, clock skew, concurrent requests, rate-limit bypass attempts, and provider outages.
- [x] Test Viewer, Contributor, Maintainer, owner, administrator, disabled, deleted, and recovery-state users across the redesigned UI.
- [x] Test WebAuthn ceremonies on supported browsers and Windows Hello in a real HTTPS staging environment (live Windows Hello registration/authentication confirmed by the operator; remaining device-specific defects move to GitHub issues).
- [x] Add end-to-end browser tests for installation, login, account navigation, administration, permissions, pack creation, maintenance, announcements, password recovery, 2FA, passkeys, feature flags, and logout redirects.
- [x] Run a small cross-browser matrix covering Chromium, Firefox, and WebKit plus targeted Windows Hello staging verification; release sign-off accepts issue-based follow-up for unavailable physical Safari coverage.
- [x] Capture screenshots, console errors, failed network requests, and accessibility findings as CI artifacts when an end-to-end test fails.
- [x] Test SMTP success/failure/timeouts and PHP `mail()` fallback without sending mail during the normal unit suite.
- [x] Test reCAPTCHA success, low scores, wrong actions/hostnames, expired/replayed tokens, timeouts, and fail-open behavior through a fake provider.
- [x] Verify accessibility, responsive layout, keyboard behavior, localization, CSP, secure cookies, and no-JavaScript failure messaging.
- [x] Update English and German translations, the offline help center, tutorial, wiki, README, operations guide, security policy, and changelog.
- [x] Convert the completed phases and feature-ideas document into a development archive and make Phases 11–13 the active roadmap.
