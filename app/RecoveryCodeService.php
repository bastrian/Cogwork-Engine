<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class RecoveryCodeService
{
    public function __construct(private readonly PDO $db) {}

    /** @return list<string> */
    public function regenerate(string $userId,int $count=10): array
    {
        $count=max(5,min(20,$count));$this->db->prepare('DELETE FROM recovery_codes WHERE user_id=?')->execute([$userId]);$insert=$this->db->prepare('INSERT INTO recovery_codes (id,user_id,code_hash,used_at,created_at) VALUES (?,?,?,?,?)');$codes=[];
        for($i=0;$i<$count;$i++){$raw=strtoupper(bin2hex(random_bytes(5)));$code=substr($raw,0,5).'-'.substr($raw,5);$insert->execute([Database::id(),$userId,password_hash($this->normalize($code),PASSWORD_DEFAULT),null,Database::now()]);$codes[]=$code;}return$codes;
    }

    public function consume(string $userId,string $code): bool
    { $stmt=$this->db->prepare('SELECT id,code_hash FROM recovery_codes WHERE user_id=? AND used_at IS NULL');$stmt->execute([$userId]);foreach($stmt->fetchAll()as$row)if(password_verify($this->normalize($code),(string)$row['code_hash'])){$claim=$this->db->prepare('UPDATE recovery_codes SET used_at=? WHERE id=? AND used_at IS NULL');$claim->execute([Database::now(),$row['id']]);return$claim->rowCount()===1;}return false; }
    public function remaining(string $userId): int
    { $stmt=$this->db->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id=? AND used_at IS NULL');$stmt->execute([$userId]);return(int)$stmt->fetchColumn(); }
    private function normalize(string $code): string
    { return strtoupper(preg_replace('/[^A-Z0-9]/i','',$code)??''); }
}
