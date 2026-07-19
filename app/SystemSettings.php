<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class SystemSettings
{
    public const DEFAULTS = [
        'features' => [
            'modrinth' => true,
            'update_checks' => true,
            'password_recovery' => false,
            'two_factor' => false,
            'passkeys' => false,
            'recaptcha' => false,
            'announcements' => true,
            'notifications' => true,
        ],
        'security' => [
            'canonical_url' => '',
            'trusted_proxies' => [],
            'two_factor_policy' => 'optional',
            'logout_redirect' => '',
            'session_idle_minutes' => 720,
            'session_absolute_hours' => 168,
            'password_reset_minutes' => 30,
        ],
        'mail' => [
            'transport' => 'mail', 'host' => '', 'port' => 587, 'encryption' => 'starttls',
            'username' => '', 'from_address' => '', 'from_name' => 'Cogwork Engine',
            'timeout_seconds' => 8, 'mail_fallback' => true,
        ],
        'recaptcha' => [
            'site_key' => '', 'protect_login' => false, 'protect_forgot' => false,
            'minimum_score' => 0.5, 'failure_policy' => 'continue',
        ],
        'connectivity' => [
            'proxy_enabled' => false, 'proxy_type' => 'http', 'proxy_host' => '', 'proxy_port' => 8080,
            'proxy_username' => '', 'connect_timeout' => 10, 'bypass' => [],
        ],
        'outbound' => [
            'proxy_enabled' => false, 'proxy_type' => 'http', 'proxy_host' => '', 'proxy_port' => 8080,
            'proxy_username' => '', 'connect_timeout' => 10,
        ],
        'maintenance' => [
            'enabled' => false,
            'message_en' => '',
            'message_de' => '',
            'starts_at' => null,
            'ends_at' => null,
            'pause_jobs' => true,
            'pause_new_jobs' => true,
            'pause_running_jobs' => true,
            'pause_cron' => true,
            'pause_mail' => false,
            'allow_status' => false,
        ],
        'updates' => ['channel' => 'stable', 'interval_hours' => 24],
        'notification_defaults' => [
            'in_app' => ['security'=>true,'jobs'=>true,'builds'=>true,'migrations'=>true,'maintenance'=>true,'announcements'=>true,'permissions'=>true,'backups'=>true,'updates'=>true],
            'email' => ['security'=>true,'jobs'=>false,'builds'=>false,'migrations'=>false,'maintenance'=>false,'announcements'=>false,'permissions'=>false,'backups'=>false,'updates'=>false],
        ],
        'retention' => [
            'jobs_days' => 30,
            'audit_days' => 730,
            'notifications_days' => 90,
            'security_events_days' => 365,
            'temporary_hours' => 24,
            'catalog_days' => 180,
            'packages_keep' => 5,
            'backups_keep' => 5,
        ],
    ];

    public function __construct(private readonly PDO $db) {}

    public function get(string $key,mixed $default=null): mixed
    {
        $stmt=$this->db->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $stmt->execute([$key]);$value=$stmt->fetchColumn();
        if($value===false)return $default;
        try{return json_decode((string)$value,true,512,JSON_THROW_ON_ERROR);}catch(\JsonException){return$default;}
    }

    public function set(string $key,mixed $value): void
    {
        if(!preg_match('/^[a-z][a-z0-9_.-]{0,99}$/',$key))throw new \InvalidArgumentException('Invalid setting key.');
        $json=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $this->db->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$key]);
        $this->db->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?)')->execute([$key,$json]);
    }

    /** @return array<string,mixed> */
    public function group(string $group): array
    {
        $defaults=self::DEFAULTS[$group]??[];$stored=$this->get('system.'.$group,[]);
        if($group==='maintenance'&&is_array($stored)&&array_key_exists('pause_jobs',$stored)){
            // Upgrade older installations without changing their prior policy.
            $stored['pause_new_jobs']??=(bool)$stored['pause_jobs'];$stored['pause_running_jobs']??=(bool)$stored['pause_jobs'];
        }
        return array_replace($defaults,is_array($stored)?$stored:[]);
    }

    /** @param array<string,mixed> $value */
    public function setGroup(string $group,array $value): void
    {
        if(!array_key_exists($group,self::DEFAULTS))throw new \InvalidArgumentException('Unknown settings group.');
        // Callers frequently update one policy field at a time. Preserve the
        // rest of the stored group; explicit replace imports already provide a
        // complete defaults-expanded value.
        $this->set('system.'.$group,array_replace($this->group($group),$value));
    }

    public function feature(string $feature): bool
    {
        $features=$this->group('features');return array_key_exists($feature,$features)&&(bool)$features[$feature];
    }

    public function setFeature(string $feature,bool $enabled): void
    {
        $features=$this->group('features');if(!array_key_exists($feature,$features))throw new \InvalidArgumentException('Unknown feature.');
        $features[$feature]=$enabled;$this->setGroup('features',$features);
    }

    public function transaction(callable $operation):mixed
    {
        $started=!$this->db->inTransaction();if($started)$this->db->beginTransaction();
        try{$result=$operation();if($started)$this->db->commit();return$result;}catch(\Throwable$e){if($started&&$this->db->inTransaction())$this->db->rollBack();throw$e;}
    }

    /** @return array<string,mixed> */
    public function export(): array
    {
        $result=['schema'=>1];foreach(array_keys(self::DEFAULTS)as$group)$result[$group]=$this->group($group);return$result;
    }
}
