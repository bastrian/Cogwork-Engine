# Cogwork Engine implementation checklist

This is the active implementation order. Completed work remains listed so progress is auditable.

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
