<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class TotpService
{
    public function __construct(private readonly PDO $db,private readonly string $applicationKey)
    { if(strlen($applicationKey)<16)throw new \InvalidArgumentException('Application encryption key is too short.'); }

    /** @return array{secret:string,uri:string,sealed:string} */
    public function begin(string $userId,string $account,string $issuer='Cogwork Engine'): array
    {
        $secret=$this->base32Encode(random_bytes(20));
        return$this->enrollment($secret,$account,$issuer)+['sealed'=>$this->encrypt($secret)];
    }

    /** @return array{secret:string,uri:string} */
    public function resume(string$sealed,string$account,string$issuer='Cogwork Engine'):array
    { return$this->enrollment($this->decrypt($sealed),$account,$issuer); }

    public function confirmEnrollment(string$userId,string$sealed,string$code,?int$timestamp=null):bool
    { if(!preg_match('/^\d{6}$/',$code))return false;$secret=$this->decrypt($sealed);$counter=intdiv($timestamp??time(),30);$accepted=null;foreach([-1,0,1]as$offset)if(hash_equals($this->code($secret,$counter+$offset),$code)){$accepted=$counter+$offset;break;}if($accepted===null)return false;$this->db->beginTransaction();try{$this->db->prepare('DELETE FROM user_totp WHERE user_id=?')->execute([$userId]);$this->db->prepare('INSERT INTO user_totp (user_id,secret_ciphertext,confirmed_at,last_counter,created_at) VALUES (?,?,?,?,?)')->execute([$userId,$this->encrypt($secret),Database::now(),$accepted,Database::now()]);$this->db->commit();return true;}catch(\Throwable$e){$this->db->rollBack();throw$e;} }

    public function verify(string $userId,string $code,?int $timestamp=null,bool $requireConfirmed=true): bool
    {
        $stmt=$this->db->prepare('SELECT * FROM user_totp WHERE user_id=?');$stmt->execute([$userId]);$row=$stmt->fetch();if(!$row||($requireConfirmed&&$row['confirmed_at']==='')||!preg_match('/^\d{6}$/',$code))return false;
        $counter=intdiv($timestamp??time(),30);$secret=$this->decrypt((string)$row['secret_ciphertext']);
        foreach([-1,0,1] as$offset){$candidate=$counter+$offset;if($row['last_counter']!==null&&$candidate<=(int)$row['last_counter'])continue;if(hash_equals($this->code($secret,$candidate),$code)){$claim=$this->db->prepare('UPDATE user_totp SET last_counter=? WHERE user_id=? AND (last_counter IS NULL OR last_counter<?)');$claim->execute([$candidate,$userId,$candidate]);return$claim->rowCount()===1;}}
        return false;
    }

    public function remove(string $userId): void
    { $this->db->prepare('DELETE FROM user_totp WHERE user_id=?')->execute([$userId]); }

    public function codeForTesting(string $secret,int $timestamp): string
    { return$this->code($secret,intdiv($timestamp,30)); }

    private function code(string $secret,int $counter): string
    { $binary=$this->base32Decode($secret);$high=intdiv($counter,4294967296);$low=$counter%4294967296;$hash=hash_hmac('sha1',pack('N2',$high,$low),$binary,true);$offset=ord($hash[19])&15;$value=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff;return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT); }

    private function encrypt(string $plain): string
    { $key=hash('sha256',$this->applicationKey,true);$nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,'cogwork-totp');if($cipher===false)throw new \RuntimeException('Could not encrypt TOTP secret.');return base64_encode($nonce.$tag.$cipher); }
    private function decrypt(string $encoded): string
    { $raw=base64_decode($encoded,true);if($raw===false||strlen($raw)<29)throw new \RuntimeException('Invalid encrypted TOTP secret.');$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',$this->applicationKey,true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'cogwork-totp');if($plain===false)throw new \RuntimeException('Could not decrypt TOTP secret.');return$plain; }
    /** @return array{secret:string,uri:string} */
    private function enrollment(string$secret,string$account,string$issuer):array
    { $label=rawurlencode($issuer.':'.$account);return['secret'=>$secret,'uri'=>'otpauth://totp/'.$label.'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30']; }
    private function base32Encode(string $data): string
    { $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($data)as$c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,5)as$chunk)$out.=$alphabet[bindec(str_pad($chunk,5,'0'))];return$out; }
    private function base32Decode(string $data): string
    { $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split(strtoupper($data))as$c){$pos=strpos($alphabet,$c);if($pos===false)throw new \InvalidArgumentException('Invalid base32 secret.');$bits.=str_pad(decbin($pos),5,'0',STR_PAD_LEFT);}$out='';foreach(str_split($bits,8)as$chunk)if(strlen($chunk)===8)$out.=chr(bindec($chunk));return$out; }
}
