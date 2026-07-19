<?php

declare(strict_types=1);

namespace Modright;

final class FeatureControl
{
    public const DEFINITIONS=[
        'modrinth'=>['category'=>'Connectivity','label'=>'Modrinth connectivity','description'=>'Live API, status, search, catalog, icon, import, update, and download access. Cached and local workflows remain available when disabled.','requires'=>[]],
        'update_checks'=>['category'=>'Connectivity','label'=>'GitHub update checks','description'=>'Checks the fixed public Cogwork Engine Releases endpoint; it never installs updates automatically.','requires'=>[]],
        'password_recovery'=>['category'=>'Account security','label'=>'Password recovery','description'=>'Single-use emailed password reset links.','requires'=>['https','mail']],
        'two_factor'=>['category'=>'Account security','label'=>'Two-factor authentication','description'=>'TOTP, recovery codes, and compatible email challenges.','requires'=>['https']],
        'passkeys'=>['category'=>'Account security','label'=>'Passkeys / Windows Hello','description'=>'WebAuthn second factors using platform or roaming authenticators.','requires'=>['https','two_factor']],
        'recaptcha'=>['category'=>'Account security','label'=>'Google reCAPTCHA','description'=>'Optional score-based abuse signal for login and recovery.','requires'=>['https']],
        'announcements'=>['category'=>'Communication','label'=>'Announcements','description'=>'Scheduled localized notices for selected audiences.','requires'=>[]],
        'notifications'=>['category'=>'Communication','label'=>'Notifications','description'=>'Durable per-user security and workflow notices.','requires'=>[]],
    ];
    public function __construct(private readonly SystemSettings$settings,private readonly ?AuditService$audit=null){}
    /** @param list<string> $proposedEnabled @return list<string> */ public function validate(string$feature,bool$enabled,array$proposedEnabled=[]):array{if(!isset(self::DEFINITIONS[$feature]))throw new \InvalidArgumentException('Unknown feature.');if(!$enabled)return[];$errors=[];$security=$this->settings->group('security');$mail=$this->settings->group('mail');foreach(self::DEFINITIONS[$feature]['requires']as$requirement){if($requirement==='https'&&!str_starts_with((string)$security['canonical_url'],'https://'))$errors[]='Configure a canonical HTTPS URL first.';if($requirement==='mail'&&(string)$mail['from_address']==='')$errors[]='Configure a sender email address first.';if($requirement==='two_factor'&&!($proposedEnabled!==[]?in_array('two_factor',$proposedEnabled,true):$this->settings->feature('two_factor')))$errors[]='Enable two-factor authentication first.';}return array_values(array_unique($errors));}
    public function set(string$feature,bool$enabled,?string$actor=null,string$reason=''):void{$errors=$this->validate($feature,$enabled);if($errors)throw new \InvalidArgumentException(implode(' ',$errors));$before=$this->settings->feature($feature);$this->settings->setFeature($feature,$enabled);$this->audit?->record('feature.changed',['feature'=>$feature,'before'=>$before,'after'=>$enabled,'reason'=>$reason],$actor);}
}
