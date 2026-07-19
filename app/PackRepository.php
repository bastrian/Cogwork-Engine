<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class PackRepository
{
    public function __construct(private readonly PDO $db) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM packs ORDER BY updated_at DESC')->fetchAll();
    }

    /** @return array<string, mixed> */
    public function find(string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM packs WHERE id = ?');
        $stmt->execute([$id]);
        $pack = $stmt->fetch();
        if (!$pack) {
            throw new HttpException(404, 'Modpack not found.');
        }
        $pack['index'] = json_decode($pack['index_json'], true, 512, JSON_THROW_ON_ERROR);
        return $pack;
    }

    /** @param array<string, mixed> $index */
    public function create(array $index): string
    {
        self::validateIndex($index);
        $id = Database::id();
        $name = trim((string) $index['name']);
        $slug = $this->uniqueSlug($name);
        [$loader, $loaderVersion] = self::loader($index['dependencies']);
        $now = Database::now();
        $stmt = $this->db->prepare('INSERT INTO packs (id,name,slug,version_id,summary,game_version,loader,loader_version,index_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$id, $name, $slug, $index['versionId'], $index['summary'] ?? '', $index['dependencies']['minecraft'], $loader, $loaderVersion, self::encode($index), $now, $now]);
        $userId=(string)($_SESSION['user_id']??$_SESSION['admin_id']??'');if($userId!==''){$owner=$this->db->prepare('INSERT INTO pack_owners (pack_id,user_id,created_at) VALUES (?,?,?)');$owner->execute([$id,$userId,$now]);}
        Storage::ensurePack($id);
        Storage::writeIndex($id, $index);
        $this->audit('pack.created', ['pack_id' => $id, 'name' => $name]);
        return $id;
    }

    /** @param array<string, mixed> $index */
    public function updateIndex(string $id, array $index): void
    {
        self::validateIndex($index);
        [$loader, $loaderVersion] = self::loader($index['dependencies']);
        $stmt = $this->db->prepare('UPDATE packs SET name=?,version_id=?,summary=?,game_version=?,loader=?,loader_version=?,index_json=?,updated_at=? WHERE id=?');
        $stmt->execute([$index['name'], $index['versionId'], $index['summary'] ?? '', $index['dependencies']['minecraft'], $loader, $loaderVersion, self::encode($index), Database::now(), $id]);
        Storage::writeIndex($id, $index);
        $this->audit('pack.updated', ['pack_id' => $id, 'version' => $index['versionId']]);
    }

    public function delete(string $id): void
    {
        $this->find($id);
        $stmt=$this->db->prepare('DELETE FROM migration_manifests WHERE source_pack_id=? OR target_pack_id=?');$stmt->execute([$id,$id]);
        foreach (['backups', 'packages'] as $table) {
            $paths = $this->db->prepare("SELECT path FROM {$table} WHERE pack_id = ?");
            $paths->execute([$id]);
            foreach ($paths->fetchAll() as $row) {
                if (is_file($row['path'])) @unlink($row['path']);
            }
        }
        $stmt = $this->db->prepare('DELETE FROM packs WHERE id = ?');
        $stmt->execute([$id]);
        Storage::deleteTree(Storage::packPath($id));
        $this->audit('pack.deleted', ['pack_id' => $id]);
    }

    /** @param array<string, mixed> $index */
    public static function validateIndex(array $index): void
    {
        foreach (['name', 'versionId', 'dependencies', 'files'] as $key) {
            if (!array_key_exists($key, $index)) {
                throw new \InvalidArgumentException("Missing modrinth.index.json field: {$key}");
            }
        }
        if (!is_array($index['dependencies']) || empty($index['dependencies']['minecraft']) || !is_array($index['files'])) {
            throw new \InvalidArgumentException('Invalid dependencies or files in modrinth.index.json.');
        }
        self::loader($index['dependencies']);
        foreach ($index['files'] as $file) {
            if (!is_array($file) || empty($file['path']) || !is_array($file['downloads'] ?? null)) {
                throw new \InvalidArgumentException('Invalid file entry in modrinth.index.json.');
            }
            self::assertRelativePath((string) $file['path']);
            if (empty($file['hashes']['sha1']) || empty($file['hashes']['sha512'])) {
                throw new \InvalidArgumentException('Every pack file requires SHA-1 and SHA-512 hashes.');
            }
            if (empty($file['downloads']) && empty($file['local'])) throw new \InvalidArgumentException('Every remote pack file requires a download URL.');
            foreach ($file['downloads'] as $download) {
                if (!is_string($download)) throw new \InvalidArgumentException('Invalid file download URL.');
                ModrinthClient::assertUrl($download);
            }
            foreach (['client', 'server'] as $side) {
                if (isset($file['env'][$side]) && !in_array($file['env'][$side], ['required', 'optional', 'unsupported'], true)) {
                    throw new \InvalidArgumentException('Invalid mod environment value.');
                }
            }
        }
    }

    public static function assertRelativePath(string $path): void
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new \InvalidArgumentException('Invalid pack file path.');
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '..' || $part === '') throw new \InvalidArgumentException('Invalid pack file path.');
        }
    }

    /** @param array<string, mixed> $dependencies @return array{string,string} */
    private static function loader(array $dependencies): array
    {
        foreach (['fabric-loader' => 'fabric', 'forge' => 'forge', 'neoforge' => 'neoforge', 'quilt-loader' => 'quilt'] as $key => $name) {
            if (!empty($dependencies[$key])) {
                return [$name, (string) $dependencies[$key]];
            }
        }
        foreach ($dependencies as $key => $version) {
            if ($key !== 'minecraft' && is_string($version) && $version !== '') return [(string) $key, $version];
        }
        throw new \InvalidArgumentException('A mod loader dependency is required.');
    }

    /** @param array<string, mixed> $value */
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        $base = $base !== '' ? $base : 'pack';
        $slug = $base;
        $counter = 2;
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM packs WHERE slug = ?');
        while (true) {
            $stmt->execute([$slug]);
            if ((int) $stmt->fetchColumn() === 0) return $slug;
            $slug = $base . '-' . $counter++;
        }
    }

    /** @param array<string, mixed> $context */
    private function audit(string $action, array $context): void
    {
        $context['actor_user_id']=$_SESSION['user_id']??$_SESSION['admin_id']??null;
        $stmt=$this->db->prepare('INSERT INTO audit_log (id,action,context,created_at) VALUES (?,?,?,?)');
        $stmt->execute([Database::id(),$action,json_encode($context,JSON_THROW_ON_ERROR),Database::now()]);
    }
}
