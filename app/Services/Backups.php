<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SQLite3;

class Backups
{
    public function root(): string
    {
        return storage_path('app/private/bot-backups');
    }

    public function create(): string
    {
        if (config('database.default') !== 'sqlite' || !is_file(app()->environmentFilePath())) {
            throw new RuntimeException('Backup requires SQLite and a local .env file.');
        }
        $root = $this->root();
        if (!is_dir($root) && !mkdir($root, 0700, true)) throw new RuntimeException('Cannot create backup directory.');
        chmod($root, 0700);
        $id = gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        $dir = $root.'/.partial-'.$id;
        if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create snapshot directory.');
        try {
            $source = new SQLite3(DB::connection()->getDatabaseName(), SQLITE3_OPEN_READONLY);
            $destination = new SQLite3($dir.'/database.sqlite');
            $source->busyTimeout(10000);
            $destination->busyTimeout(10000);
            try {
                if (!$source->backup($destination)) throw new RuntimeException('SQLite snapshot failed.');
            } finally {
                $destination->close();
                $source->close();
            }
            if (!copy(app()->environmentFilePath(), $dir.'/.env')) throw new RuntimeException('Configuration backup failed.');
            chmod($dir.'/database.sqlite', 0600);
            chmod($dir.'/.env', 0600);
            $manifest = ['created_at'=>gmdate('c'), 'files'=>[]];
            foreach (['database.sqlite', '.env'] as $file) $manifest['files'][$file] = hash_file('sha256', $dir.'/'.$file);
            if (file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Cannot write backup manifest.');
            }
            chmod($dir.'/manifest.json', 0600);
            $this->verifyDirectory($dir);
            if (!rename($dir, $root.'/'.$id)) throw new RuntimeException('Cannot publish backup.');
            return $id;
        } catch (\Throwable) {
            $this->removeDirectory($dir);
            throw new RuntimeException('Backup failed; no verified snapshot was published.');
        }
    }

    public function verify(string $id): array
    {
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/D', $id)) throw new RuntimeException('Invalid snapshot ID.');
        $source = $this->root().'/'.$id;
        if (!is_dir($source) || is_link($source)) throw new RuntimeException('Snapshot not found.');
        // Restore into an isolated directory, never over the live database or configuration.
        $restore = $this->root().'/.restore-'.bin2hex(random_bytes(8));
        if (!mkdir($restore, 0700)) throw new RuntimeException('Cannot create restore directory.');
        try {
            foreach (['database.sqlite', '.env', 'manifest.json'] as $file) {
                if (is_link($source.'/'.$file) || !copy($source.'/'.$file, $restore.'/'.$file)) throw new RuntimeException('Restore copy failed.');
                chmod($restore.'/'.$file, 0600);
            }
            return $this->verifyDirectory($restore);
        } catch (\Throwable) {
            throw new RuntimeException('Restore verification failed.');
        } finally {
            $this->removeDirectory($restore);
        }
    }

    public function prune(): int
    {
        $root = $this->root();
        if (!is_dir($root)) return 0;
        if (is_link($root)) throw new RuntimeException('Backup root must not be a symlink.');
        $removed = 0;
        $cutoff = now()->utc()->subDays(14)->format('Ymd-His');
        foreach (scandir($root) as $id) {
            if (!preg_match('/^(\d{8}-\d{6})-[a-f0-9]{8}$/D', $id, $match) || strcmp($match[1], $cutoff) > 0) continue;
            $dir = $root.'/'.$id;
            if (is_link($dir) || !is_dir($dir)) continue;
            $entries = array_diff(scandir($dir), ['.', '..']);
            if (array_diff($entries, ['database.sqlite', '.env', 'manifest.json'])) continue;
            foreach ($entries as $file) if (is_link($dir.'/'.$file) || !is_file($dir.'/'.$file)) continue 2;
            $this->removeDirectory($dir);
            $removed++;
        }
        return $removed;
    }

    private function verifyDirectory(string $dir): array
    {
        $manifest = json_decode(file_get_contents($dir.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['database.sqlite', '.env'] as $file) {
            if (!hash_equals($manifest['files'][$file] ?? '', hash_file('sha256', $dir.'/'.$file))) throw new RuntimeException('Checksum mismatch.');
        }
        $db = new SQLite3($dir.'/database.sqlite', SQLITE3_OPEN_READONLY);
        try {
            if ($db->querySingle('PRAGMA integrity_check') !== 'ok' || $db->query('PRAGMA foreign_key_check')->fetchArray()) {
                throw new RuntimeException('Database integrity check failed.');
            }
            $counts = [];
            foreach (['migrations','bot_profiles','commitments','occurrences','bot_settings','bot_outbox'] as $table) {
                $counts[$table] = $db->querySingle('SELECT count(*) FROM '.$table);
                if ($counts[$table] === false) throw new RuntimeException('Missing database table.');
            }
            $env = \Dotenv\Dotenv::parse(file_get_contents($dir.'/.env'));
            $key = $env['APP_KEY'] ?? '';
            if (str_starts_with($key, 'base64:')) $key = base64_decode(substr($key, 7), true);
            $cipher = $env['APP_CIPHER'] ?? 'AES-256-CBC';
            if (!is_string($key) || !Encrypter::supported($key, $cipher)) throw new RuntimeException('Invalid backup encryption key.');
            $token = $db->querySingle("SELECT value FROM bot_settings WHERE key = 'token'");
            if ($token !== null && $token !== false) (new Encrypter($key, $cipher))->decryptString($token);
            return ['integrity'=>'ok', 'token_decryption'=>$token ? 'ok' : 'not_configured', 'rows'=>$counts];
        } finally {
            $db->close();
        }
    }

    private function removeDirectory(string $dir): void
    {
        // Only fixed filenames in the private directory created by this service.
        foreach (['database.sqlite', '.env', 'manifest.json'] as $file) if (is_file($dir.'/'.$file)) unlink($dir.'/'.$file);
        if (is_dir($dir)) rmdir($dir);
    }
}

