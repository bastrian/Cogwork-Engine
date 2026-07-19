<?php

declare(strict_types=1);

namespace Modright;

final class SecretStore
{
    /** @return array<string,string> */ public function all(): array{$config=Config::load();$secrets=$config['secrets']??[];return is_array($secrets)?array_map('strval',$secrets):[];}
    public function get(string$key,string$default=''):string{return$this->all()[$key]??$default;}
    /** @param array<string,string|null> $changes */ public function update(array$changes):void{$config=Config::load();$secrets=$this->all();foreach($changes as$key=>$value){if(!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/',$key))throw new \InvalidArgumentException('Invalid secret key.');if($value===null||$value==='')unset($secrets[$key]);elseif($value!=='[redacted]')$secrets[$key]=$value;}$config['secrets']=$secrets;Config::write($config);}
}
