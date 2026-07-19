# Cogwork Engine feature ideas

These ideas were identified while planning work that remains useful when Modrinth is unavailable.

1. **Pack validation report** — Detect missing or duplicate files, invalid hashes and URLs, incorrect client/server inclusion, missing loader dependencies, incompatible game/loader selections, and unsafe pack paths before building.
2. **Better mod management** — Search cached mods, show project metadata, filter by environment, detect duplicate projects, and add multiple mods at once.
3. **Offline catalog and background synchronization** — Persist projects, versions, compatibility metadata, loaders, and update results with visible synchronization timestamps.
4. **Pack diagnostics dashboard** — Summarize compatible mods, exclusions, missing files, available updates, and synchronization health.
5. **Import review** — Preview metadata, environments, overrides, malformed entries, and filename conflicts before accepting an `.mrpack` import.
6. **Server-pack configuration** — Manage `server.properties`, EULA state, JVM memory guidance, start scripts, Java requirements, loader bootstrapping, and server exclusions.
7. **Pack profiles** — Produce client, dedicated-server, lightweight, development, and optional-mod variants from one pack.
8. **Local mod uploads** — Upload non-Modrinth JARs, calculate hashes, assign environment compatibility, and include them in builds.
9. **Export history and reproducibility** — Record inputs, hashes, catalog timestamps, targets, options, and warnings so releases can be rebuilt identically.
10. **Backup and migration** — Export the database, pack definitions, overrides, and cached metadata, with secrets excluded by default.
11. **Minecraft and loader migration workbench** — Compare newer Minecraft and loader combinations, identify direct upgrades and cross-loader replacements, rank safe targets, and create a separately validated migrated pack without modifying the source.

Implementation starts with the pack validation report because it supports safer imports and builds and does not require a live external service.
