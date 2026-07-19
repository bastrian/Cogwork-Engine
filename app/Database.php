<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class Database
{
    public const LATEST_MIGRATION='20260719-validation-reports';
    private static ?PDO $connection = null;

    /** @param array<string, mixed>|null $config */
    public static function connect(?array $config = null): PDO
    {
        $useShared = $config === null;
        if ($config === null && self::$connection !== null) {
            return self::$connection;
        }
        $config ??= Config::load();
        $db = $config['database'];
        if ($db['driver'] === 'sqlite') {
            $path = self::sqlitePath((string) ($db['path'] ?? 'storage/data/modright.sqlite'));
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new \RuntimeException('Could not create the SQLite database directory.');
            }
            $pdo = new PDO('sqlite:' . $path);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
            $pdo = new PDO($dsn, $db['user'], $db['password']);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($useShared) {
            self::$connection = $pdo;
        }
        return $pdo;
    }

    public static function sqlitePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0") || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            throw new \InvalidArgumentException('Enter a valid SQLite filesystem path.');
        }
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return $path;
        }
        $relative = ltrim(str_replace('\\', '/', $path), '/');
        if (in_array('..', explode('/', $relative), true)) {
            throw new \InvalidArgumentException('Relative SQLite paths cannot leave the application directory. Use an absolute path instead.');
        }
        return MODRIGHT_ROOT . '/' . $relative;
    }

    public static function migrate(PDO $pdo): void
    {
        $statements = [
            'CREATE TABLE IF NOT EXISTS schema_migrations (migration_id VARCHAR(100) PRIMARY KEY, applied_at VARCHAR(30) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS admins (id VARCHAR(36) PRIMARY KEY, username VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at VARCHAR(30) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS admin_preferences (admin_id VARCHAR(36) PRIMARY KEY, locale VARCHAR(10) NOT NULL DEFAULT \'en_US\', updated_at VARCHAR(30) NOT NULL, FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS users (id VARCHAR(36) PRIMARY KEY, username VARCHAR(100) NOT NULL UNIQUE, display_name VARCHAR(200) NOT NULL, email VARCHAR(254) NULL, email_verified_at VARCHAR(30) NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, locale VARCHAR(10) NOT NULL DEFAULT \'en_US\', tutorial_status VARCHAR(20) NOT NULL DEFAULT \'not_started\', tutorial_step INTEGER NOT NULL DEFAULT 0, session_version INTEGER NOT NULL DEFAULT 1, last_login_at VARCHAR(30) NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS packs (id VARCHAR(36) PRIMARY KEY, name VARCHAR(200) NOT NULL, slug VARCHAR(200) NOT NULL UNIQUE, version_id VARCHAR(100) NOT NULL, summary TEXT NOT NULL, game_version VARCHAR(100) NOT NULL, loader VARCHAR(30) NOT NULL, loader_version VARCHAR(100) NOT NULL, index_json TEXT NOT NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS jobs (id VARCHAR(36) PRIMARY KEY, pack_id VARCHAR(36) NULL, type VARCHAR(40) NOT NULL, status VARCHAR(20) NOT NULL, payload TEXT NOT NULL, result TEXT NOT NULL, progress_current INTEGER NOT NULL DEFAULT 0, progress_total INTEGER NOT NULL DEFAULT 0, error TEXT NOT NULL, lock_token VARCHAR(64) NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL, FOREIGN KEY(pack_id) REFERENCES packs(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS backups (id VARCHAR(36) PRIMARY KEY, pack_id VARCHAR(36) NOT NULL, version_id VARCHAR(100) NOT NULL, path TEXT NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(pack_id) REFERENCES packs(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS packages (id VARCHAR(36) PRIMARY KEY, pack_id VARCHAR(36) NOT NULL, version_id VARCHAR(100) NOT NULL, path TEXT NOT NULL, size INTEGER NOT NULL, sha256 VARCHAR(64) NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(pack_id) REFERENCES packs(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS audit_log (id VARCHAR(36) PRIMARY KEY, action VARCHAR(100) NOT NULL, context TEXT NOT NULL, created_at VARCHAR(30) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS project_catalog (project_id VARCHAR(100) PRIMARY KEY, project_json TEXT NOT NULL, versions_json TEXT NOT NULL, game_version VARCHAR(100) NOT NULL, loader VARCHAR(30) NOT NULL, synced_at VARCHAR(30) NULL, last_error TEXT NOT NULL, updated_at VARCHAR(30) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS import_reviews (id VARCHAR(36) PRIMARY KEY, archive_path TEXT NOT NULL, index_json TEXT NOT NULL, source VARCHAR(200) NOT NULL, created_at VARCHAR(30) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS pack_options (pack_id VARCHAR(36) PRIMARY KEY, options_json TEXT NOT NULL, updated_at VARCHAR(30) NOT NULL, FOREIGN KEY(pack_id) REFERENCES packs(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS build_manifests (id VARCHAR(36) PRIMARY KEY, pack_id VARCHAR(36) NOT NULL, version_id VARCHAR(100) NOT NULL, manifest_json TEXT NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(pack_id) REFERENCES packs(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS migration_scans (id VARCHAR(36) PRIMARY KEY, pack_id VARCHAR(36) NOT NULL, status VARCHAR(20) NOT NULL, options_json TEXT NOT NULL, summary_json TEXT NOT NULL, source_fingerprint VARCHAR(64) NOT NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL, FOREIGN KEY(pack_id) REFERENCES packs(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS migration_results (scan_id VARCHAR(36) NOT NULL, target_game VARCHAR(100) NOT NULL, target_loader VARCHAR(30) NOT NULL, loader_version VARCHAR(100) NOT NULL, project_id VARCHAR(100) NOT NULL, file_index INTEGER NOT NULL, classification VARCHAR(30) NOT NULL, evidence_json TEXT NOT NULL, checked_at VARCHAR(30) NOT NULL, error TEXT NOT NULL, PRIMARY KEY(scan_id,target_game,target_loader,file_index), FOREIGN KEY(scan_id) REFERENCES migration_scans(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS migration_replacements (source_project_id VARCHAR(100) NOT NULL, target_loader VARCHAR(30) NOT NULL, replacement_project_id VARCHAR(100) NOT NULL, confidence VARCHAR(20) NOT NULL, note TEXT NOT NULL, updated_at VARCHAR(30) NOT NULL, PRIMARY KEY(source_project_id,target_loader))',
            'CREATE TABLE IF NOT EXISTS migration_manifests (id VARCHAR(36) PRIMARY KEY, scan_id VARCHAR(36) NOT NULL, source_pack_id VARCHAR(36) NOT NULL, target_pack_id VARCHAR(36) NOT NULL, manifest_json TEXT NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(scan_id) REFERENCES migration_scans(id) ON DELETE CASCADE, FOREIGN KEY(source_pack_id) REFERENCES packs(id), FOREIGN KEY(target_pack_id) REFERENCES packs(id))',
            'CREATE TABLE IF NOT EXISTS pack_owners (pack_id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(pack_id) REFERENCES packs(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id))',
            'CREATE TABLE IF NOT EXISTS pack_grants (pack_id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, preset VARCHAR(30) NOT NULL, permissions_json TEXT NOT NULL, granted_by VARCHAR(36) NOT NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL, PRIMARY KEY(pack_id,user_id), FOREIGN KEY(pack_id) REFERENCES packs(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(granted_by) REFERENCES users(id))',
            'CREATE TABLE IF NOT EXISTS job_actors (job_id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id))',
            'CREATE TABLE IF NOT EXISTS import_review_owners (review_id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, FOREIGN KEY(review_id) REFERENCES import_reviews(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id))',
            'CREATE TABLE IF NOT EXISTS user_sessions (id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, label VARCHAR(200) NOT NULL, ip_hash VARCHAR(64) NOT NULL, created_at VARCHAR(30) NOT NULL, last_seen_at VARCHAR(30) NOT NULL, expires_at VARCHAR(30) NOT NULL, revoked_at VARCHAR(30) NULL, auth_methods TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS auth_attempts (id VARCHAR(36) PRIMARY KEY, scope VARCHAR(30) NOT NULL, subject_hash VARCHAR(64) NOT NULL, ip_hash VARCHAR(64) NOT NULL, succeeded INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(30) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS password_reset_tokens (id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, requested_ip_hash VARCHAR(64) NOT NULL, expires_at VARCHAR(30) NOT NULL, used_at VARCHAR(30) NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS email_verification_tokens (id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, email VARCHAR(254) NOT NULL, expires_at VARCHAR(30) NOT NULL, used_at VARCHAR(30) NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS user_totp (user_id VARCHAR(36) PRIMARY KEY, secret_ciphertext TEXT NOT NULL, confirmed_at VARCHAR(30) NOT NULL, last_counter INTEGER NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS recovery_codes (id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, code_hash VARCHAR(255) NOT NULL, used_at VARCHAR(30) NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS webauthn_credentials (id VARCHAR(255) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, label VARCHAR(200) NOT NULL, public_key TEXT NOT NULL, sign_count INTEGER NOT NULL DEFAULT 0, transports TEXT NOT NULL, created_at VARCHAR(30) NOT NULL, last_used_at VARCHAR(30) NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS auth_challenges (id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36) NULL, purpose VARCHAR(30) NOT NULL, challenge_hash VARCHAR(64) NOT NULL, payload TEXT NOT NULL, expires_at VARCHAR(30) NOT NULL, used_at VARCHAR(30) NULL, created_at VARCHAR(30) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS security_events (id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36) NULL, event_type VARCHAR(100) NOT NULL, severity VARCHAR(20) NOT NULL, context TEXT NOT NULL, created_at VARCHAR(30) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS notifications (id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, category VARCHAR(50) NOT NULL, severity VARCHAR(20) NOT NULL, title VARCHAR(200) NOT NULL, message TEXT NOT NULL, target_url TEXT NOT NULL, read_at VARCHAR(30) NULL, acknowledged_at VARCHAR(30) NULL, archived_at VARCHAR(30) NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS notification_preferences (user_id VARCHAR(36) NOT NULL, category VARCHAR(50) NOT NULL, in_app INTEGER NOT NULL DEFAULT 1, email INTEGER NOT NULL DEFAULT 0, updated_at VARCHAR(30) NOT NULL, PRIMARY KEY(user_id,category), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS announcements (id VARCHAR(36) PRIMARY KEY, severity VARCHAR(20) NOT NULL, audience VARCHAR(30) NOT NULL, title_en TEXT NOT NULL, message_en TEXT NOT NULL, title_de TEXT NOT NULL, message_de TEXT NOT NULL, target_url TEXT NOT NULL, dismissible INTEGER NOT NULL DEFAULT 1, starts_at VARCHAR(30) NULL, ends_at VARCHAR(30) NULL, archived_at VARCHAR(30) NULL, activated_at VARCHAR(30) NULL, expired_at VARCHAR(30) NULL, created_by VARCHAR(36) NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS announcement_dismissals (announcement_id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, dismissed_at VARCHAR(30) NOT NULL, PRIMARY KEY(announcement_id,user_id), FOREIGN KEY(announcement_id) REFERENCES announcements(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS pack_activity (id VARCHAR(36) PRIMARY KEY, pack_id VARCHAR(36) NOT NULL, actor_user_id VARCHAR(36) NULL, action VARCHAR(100) NOT NULL, result VARCHAR(20) NOT NULL, summary TEXT NOT NULL, context TEXT NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(pack_id) REFERENCES packs(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS validation_reports (id VARCHAR(36) PRIMARY KEY, pack_id VARCHAR(36) NOT NULL, report_json TEXT NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(pack_id) REFERENCES packs(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS update_cache (cache_key VARCHAR(100) PRIMARY KEY, payload TEXT NOT NULL, etag VARCHAR(255) NOT NULL, last_modified VARCHAR(100) NOT NULL, checked_at VARCHAR(30) NOT NULL, last_error TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS cron_runs (id VARCHAR(36) PRIMARY KEY, status VARCHAR(20) NOT NULL, processed INTEGER NOT NULL DEFAULT 0, error TEXT NOT NULL, started_at VARCHAR(30) NOT NULL, finished_at VARCHAR(30) NOT NULL)',
        ];
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
        try {
            $pdo->exec('CREATE INDEX jobs_status_idx ON jobs(status, updated_at)');
        } catch (\PDOException) {
            // Index already exists.
        }
        try { $pdo->exec('CREATE INDEX migration_scans_pack_idx ON migration_scans(pack_id, created_at)'); } catch (\PDOException) {}
        self::addColumn($pdo,'users','email','VARCHAR(254) NULL');
        self::addColumn($pdo,'users','email_verified_at','VARCHAR(30) NULL');
        self::addColumn($pdo,'users','last_login_at','VARCHAR(30) NULL');
        self::addColumn($pdo,'users','recovery_ack_at','VARCHAR(30) NULL');
        self::addColumn($pdo,'announcements','archived_at','VARCHAR(30) NULL');
        self::addColumn($pdo,'announcements','activated_at','VARCHAR(30) NULL');
        self::addColumn($pdo,'announcements','expired_at','VARCHAR(30) NULL');
        try { $pdo->exec('CREATE UNIQUE INDEX users_email_unique ON users(email)'); } catch (\PDOException) {}
        try { $pdo->exec('CREATE INDEX auth_attempts_lookup_idx ON auth_attempts(scope, subject_hash, ip_hash, created_at)'); } catch (\PDOException) {}
        try { $pdo->exec('CREATE UNIQUE INDEX auth_challenges_purpose_hash_unique ON auth_challenges(purpose, challenge_hash)'); } catch (\PDOException) {}
        try { $pdo->exec('CREATE INDEX notifications_user_idx ON notifications(user_id, archived_at, created_at)'); } catch (\PDOException) {}
        try { $pdo->exec('CREATE INDEX security_events_user_idx ON security_events(user_id, created_at)'); } catch (\PDOException) {}
        try { $pdo->exec('CREATE INDEX pack_activity_pack_idx ON pack_activity(pack_id, created_at)'); } catch (\PDOException) {}
        self::migrateUsers($pdo);
        $migration=self::LATEST_MIGRATION;$exists=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration_id=?');$exists->execute([$migration]);if((int)$exists->fetchColumn()===0)$pdo->prepare('INSERT INTO schema_migrations (migration_id,applied_at) VALUES (?,?)')->execute([$migration,self::now()]);
    }

    private static function addColumn(PDO $pdo,string $table,string $column,string $definition): void
    {
        try{$pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");}catch(\PDOException){}
    }

    private static function migrateUsers(PDO $pdo): void
    {
        $admins=$pdo->query('SELECT * FROM admins')->fetchAll();$copy=$pdo->prepare('INSERT INTO users (id,username,display_name,password_hash,role,enabled,locale,tutorial_status,tutorial_step,session_version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($admins as$admin){$exists=$pdo->prepare('SELECT COUNT(*) FROM users WHERE id=?');$exists->execute([$admin['id']]);if((int)$exists->fetchColumn()>0)continue;$pref=$pdo->prepare('SELECT locale FROM admin_preferences WHERE admin_id=?');$pref->execute([$admin['id']]);$locale=(string)($pref->fetchColumn()?:'en_US');$copy->execute([$admin['id'],$admin['username'],$admin['username'],$admin['password_hash'],'admin',1,$locale,'not_started',0,1,$admin['created_at'],$admin['created_at']]);}
        $adminId=$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY created_at LIMIT 1")->fetchColumn();if($adminId){$packs=$pdo->query('SELECT id FROM packs')->fetchAll();$owner=$pdo->prepare('INSERT INTO pack_owners (pack_id,user_id,created_at) VALUES (?,?,?)');foreach($packs as$pack){$exists=$pdo->prepare('SELECT COUNT(*) FROM pack_owners WHERE pack_id=?');$exists->execute([$pack['id']]);if((int)$exists->fetchColumn()===0)$owner->execute([$pack['id'],$adminId,self::now()]);}}
    }

    public static function id(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function now(): string
    {
        return gmdate('c');
    }
}
