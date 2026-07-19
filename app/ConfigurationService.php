<?php

declare(strict_types=1);

namespace Modright;

final class ConfigurationService
{
    private const LOCAL_ONLY=[
        'security'=>['canonical_url','trusted_proxies','logout_redirect'],
        'mail'=>['host','port','username','from_address','from_name'],
        'connectivity'=>['proxy_host','proxy_port','proxy_username','bypass'],
        'outbound'=>['proxy_host','proxy_port','proxy_username'],
        'recaptcha'=>['site_key'],
    ];
    public function __construct(private readonly SystemSettings $settings) {}

    /** @return array<string,mixed> */
    public function export():array{$configuration=$this->settings->export();foreach(self::LOCAL_ONLY as$group=>$keys)foreach($keys as$key)unset($configuration[$group][$key]);return['product'=>'Cogwork Engine','exported_at'=>Database::now(),'configuration'=>$configuration,'omitted_local_settings'=>self::LOCAL_ONLY];}

    /** @param array<string,mixed> $document @return array<string,array<string,mixed>> */
    public function preview(array$document,string$mode='merge'):array
    {
        if(!in_array($mode,['merge','replace'],true))throw new \InvalidArgumentException('Configuration import mode must be merge or replace.');
        if(($document['product']??'')!=='Cogwork Engine'||!is_array($document['configuration']??null)||($document['configuration']['schema']??null)!==1)throw new \InvalidArgumentException('Unsupported configuration export.');
        $changes=[];
        foreach(array_keys(SystemSettings::DEFAULTS)as$group){$incoming=$document['configuration'][$group]??null;if(!is_array($incoming)){if($mode==='merge')continue;$incoming=[];}$this->rejectSecrets($incoming);$incoming=array_intersect_key($incoming,SystemSettings::DEFAULTS[$group]);foreach(self::LOCAL_ONLY[$group]??[]as$key)if(array_key_exists($key,$incoming))throw new \InvalidArgumentException("Local deployment setting {$group}.{$key} cannot be imported.");$this->validateTypes($group,$incoming);$current=$this->settings->group($group);$after=$mode==='replace'?array_replace(SystemSettings::DEFAULTS[$group],$incoming):$incoming;if($mode==='replace')foreach(self::LOCAL_ONLY[$group]??[]as$key)$after[$key]=$current[$key];$before=$mode==='replace'?$current:array_intersect_key($current,$incoming);if($after!==$before)$changes[$group]=['before'=>$before,'after'=>$after];}
        $prospective=[];foreach(array_keys(SystemSettings::DEFAULTS)as$group)$prospective[$group]=$changes[$group]['after']??$this->settings->group($group);$features=$prospective['features'];$security=$prospective['security'];$mail=$prospective['mail'];foreach(['password_recovery','two_factor','passkeys','recaptcha']as$feature)if(!empty($features[$feature])&&!str_starts_with((string)$security['canonical_url'],'https://'))throw new \InvalidArgumentException('Imported security features require a canonical HTTPS URL.');if(!empty($features['password_recovery'])&&(string)$mail['from_address']==='')throw new \InvalidArgumentException('Imported password recovery requires a mail sender.');if(!empty($features['passkeys'])&&empty($features['two_factor']))throw new \InvalidArgumentException('Imported passkeys require two-factor authentication.');return$changes;
    }

    /** @param array<string,mixed> $document */
    public function import(array$document,string$mode='merge'):int
    { $changes=$this->preview($document,$mode);return$this->settings->transaction(function()use($changes,$mode):int{$this->settings->set('system.configuration_import_backup',$this->export());foreach($changes as$group=>$change){$value=$mode==='replace'?$change['after']:array_replace($this->settings->group($group),$change['after']);$this->settings->setGroup($group,$value);}return count($changes);}); }

    private function rejectSecrets(array$data):void
    { foreach($data as$key=>$value){if(preg_match('/^(?:password|.*_password|secret|.*_secret|token|.*_token|credential|.*_credential|private_key|app_key)$/i',(string)$key))throw new \InvalidArgumentException('Configuration exports cannot contain secrets.');if(is_array($value))$this->rejectSecrets($value);} }

    /** @param array<string,mixed> $incoming */
    private function validateTypes(string$group,array$incoming):void
    { foreach($incoming as$key=>$value){$default=SystemSettings::DEFAULTS[$group][$key];if($default!==null&&get_debug_type($value)!==get_debug_type($default)&&!(is_int($default)&&is_float($value)))throw new \InvalidArgumentException("Invalid type for {$group}.{$key}.");}if($group==='security'){if(($incoming['canonical_url']??'')!==''){$parts=parse_url((string)$incoming['canonical_url']);if(!is_array($parts)||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))throw new \InvalidArgumentException('Canonical URL must be absolute HTTPS.');}if(($incoming['logout_redirect']??'')!==''&&!$this->safeRedirect((string)$incoming['logout_redirect']))throw new \InvalidArgumentException('Invalid post-logout redirect.');}if(in_array($group,['connectivity','outbound'],true)&&!empty($incoming['proxy_enabled'])){if(!preg_match('/^[A-Za-z0-9.-]+$/',(string)($incoming['proxy_host']??''))||(int)($incoming['proxy_port']??0)<1||(int)($incoming['proxy_port']??0)>65535)throw new \InvalidArgumentException('Invalid imported proxy configuration.');}if($group==='updates'&&isset($incoming['channel'])&&!in_array($incoming['channel'],['stable','prerelease'],true))throw new \InvalidArgumentException('Invalid update release channel.');if($group==='recaptcha'&&isset($incoming['minimum_score'])&&((float)$incoming['minimum_score']<0||(float)$incoming['minimum_score']>1))throw new \InvalidArgumentException('Invalid reCAPTCHA score.');if($group==='notification_defaults'){foreach(['in_app','email']as$kind){if(!isset($incoming[$kind]))continue;if(!is_array($incoming[$kind])||array_diff(array_keys($incoming[$kind]),NotificationService::CATEGORIES)!==[])throw new \InvalidArgumentException('Invalid notification-default category.');foreach($incoming[$kind]as$value)if(!is_bool($value))throw new \InvalidArgumentException('Notification defaults must be booleans.');}}}

    private function safeRedirect(string$value):bool
    { return RequestSecurity::logoutTarget($value,'')===$value;}
}
