<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class PackOptions
{
    public function __construct(private readonly PDO $db) {}
    /** @return array<string,mixed> */
    public function get(string $packId): array
    { $defaults=['java_version'=>17,'memory_min'=>1024,'memory_max'=>4096,'server_properties'=>"motd=A Cogwork Engine Server\nmax-players=20\nonline-mode=true\n",'eula_accepted'=>false,'linux_command'=>'java -Xms{MIN}M -Xmx{MAX}M -jar server.jar nogui','windows_command'=>'java -Xms{MIN}M -Xmx{MAX}M -jar server.jar nogui','exclusions'=>[],'include_common_overrides'=>true,'include_server_overrides'=>true];$stmt=$this->db->prepare('SELECT options_json FROM pack_options WHERE pack_id=?');$stmt->execute([$packId]);$json=$stmt->fetchColumn();$stored=$json?json_decode((string)$json,true):[];return array_replace($defaults,is_array($stored)?$stored:[]); }
    /** @param array<string,mixed> $options */
    public function save(string $packId,array $options): void
    { $json=json_encode($options,JSON_THROW_ON_ERROR);$now=Database::now();$stmt=$this->db->prepare('UPDATE pack_options SET options_json=?,updated_at=? WHERE pack_id=?');$stmt->execute([$json,$now,$packId]);if($stmt->rowCount()===0){$stmt=$this->db->prepare('INSERT INTO pack_options (options_json,updated_at,pack_id) VALUES (?,?,?)');$stmt->execute([$json,$now,$packId]);} }
}
