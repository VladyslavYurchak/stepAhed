<?php

namespace Tests\Feature;

use App\Services\Backups;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupsTest extends TestCase
{
    private string $directory;
    private string $originalStorage;
    private string $originalEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/stepahead-backup-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/storage', 0700);
        $this->originalStorage = $this->app->storagePath();
        $this->originalEnv = $this->app->environmentPath();
        $this->app->useStoragePath($this->directory.'/storage');
        $this->app->useEnvironmentPath($this->directory);
        $key = random_bytes(32);
        file_put_contents($this->directory.'/.env', 'APP_KEY=base64:'.base64_encode($key)."\n");
        touch($this->directory.'/live.sqlite');
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>$this->directory.'/live.sqlite']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force'=>true])->assertSuccessful();
        DB::table('bot_settings')->insert(['key'=>'token','value'=>(new Encrypter($key, 'AES-256-CBC'))->encryptString('test-token')]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        $this->app->useStoragePath($this->originalStorage);
        $this->app->useEnvironmentPath($this->originalEnv);
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_snapshot_restores_original_data_and_key_without_changing_live_database(): void
    {
        $backups = app(Backups::class);
        $id = $backups->create();
        DB::table('bot_settings')->insert(['key'=>'after_backup','value'=>'live-only']);
        $result = $backups->verify($id);
        $this->assertSame('ok', $result['integrity']);
        $this->assertSame('ok', $result['token_decryption']);
        $this->assertSame(1, $result['rows']['bot_settings']);
        $this->assertSame(2, DB::table('bot_settings')->count());
        $this->assertSame([], glob($backups->root().'/.restore-*'));
    }

    public function test_corrupt_configuration_fails_verification_and_keeps_live_data(): void
    {
        $backups = app(Backups::class);
        $id = $backups->create();
        file_put_contents($backups->root().'/'.$id.'/.env', "APP_KEY=broken\n");
        try {
            $backups->verify($id);
            $this->fail('Corrupt backup must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Restore verification failed.', $e->getMessage());
        }
        $this->assertSame(1, DB::table('bot_settings')->count());
        $this->assertSame([], glob($backups->root().'/.restore-*'));
    }
}
