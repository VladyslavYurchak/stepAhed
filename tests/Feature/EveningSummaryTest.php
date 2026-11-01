<?php

namespace Tests\Feature;

use App\Services\EveningSummary;
use App\Services\Telegram;
use Carbon\CarbonImmutable as Time;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class EveningSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Time::parse('2026-11-01 20:29:00','UTC'));
        DB::table('bot_profiles')->insert(['id'=>11,'name'=>'Тест','timezone'=>'Europe/Kyiv','onboarded'=>true,'sleep_at'=>'23:00','wake_at'=>'07:00']);
    }

    private function task(string $status, string $title, string $due='2026-11-01 20:50:00', int $uid=11): void
    {
        $id=DB::table('commitments')->insertGetId(['profile_id'=>$uid,'title'=>$title,'mandatory'=>true,'schedule'=>'{}']);
        DB::table('occurrences')->insert(['profile_id'=>$uid,'commitment_id'=>$id,'status'=>$status,'due_at'=>$due]);
    }

    public function test_before_sleep_summary_is_once_per_local_day_and_owner_only(): void
    {
        $this->task('done','Готово'); $this->task('review','На перевірці');
        $this->task('pending','Лист <важливий>'); $this->task('missed','Не встиг');
        $this->task('pending','Завтра','2026-11-02 10:00:00');
        $this->task('pending','Чужа задача','2026-11-01 20:50:00',22);
        $service=app(EveningSummary::class); $service->tick();
        $this->assertDatabaseCount('bot_outbox',0);
        $this->travel(1)->minutes(); $service->tick(); $service->tick();
        $this->assertDatabaseCount('bot_outbox',1);
        $payload=json_decode(DB::table('bot_outbox')->first()->payload,true);
        $this->assertStringContainsString('Виконано: 1',$payload['text']);
        $this->assertStringContainsString('На перевірці: 1',$payload['text']);
        $this->assertStringContainsString('Ще залишилося: 1',$payload['text']);
        $this->assertStringContainsString('Лист &lt;важливий&gt;',$payload['text']);
        $this->assertStringNotContainsString('Чужа задача',$payload['text']);
        $this->assertStringNotContainsString('Завтра',$payload['text']);
    }

    public function test_midnight_bedtime_summarizes_the_previous_evening_date(): void
    {
        DB::table('bot_profiles')->where('id',11)->update(['sleep_at'=>'00:15']);
        $this->task('done','Вчорашня задача');
        $this->travelTo(Time::parse('2026-11-01 22:02:00','UTC')); // 00:02 on November 2 in Kyiv.
        app(EveningSummary::class)->tick();
        $payload=json_decode(DB::table('bot_outbox')->first()->payload,true);
        $this->assertStringContainsString('01.11',$payload['text']);
        $this->assertStringContainsString('Виконано: 1',$payload['text']);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'evening_last_date'=>'2026-11-01']);
    }

    public function test_disabled_unconfigured_and_sleeping_profiles_are_skipped(): void
    {
        $this->travel(1)->minutes();
        DB::table('bot_profiles')->where('id',11)->update(['evening_enabled'=>false]);
        app(EveningSummary::class)->tick(); $this->assertDatabaseCount('bot_outbox',0);
        DB::table('bot_profiles')->where('id',11)->update(['evening_enabled'=>true,'sleep_at'=>null]);
        app(EveningSummary::class)->tick(); $this->assertDatabaseCount('bot_outbox',0);
        DB::table('bot_profiles')->where('id',11)->update(['sleep_at'=>'23:00']);
        $this->travel(31)->minutes();
        app(EveningSummary::class)->tick(); $this->assertDatabaseCount('bot_outbox',0);
    }

    public function test_failed_delivery_is_discarded_at_bedtime(): void
    {
        $this->travel(1)->minutes(); app(EveningSummary::class)->tick();
        DB::table('bot_settings')->insert(['key'=>'token','value'=>Crypt::encryptString('fake-token')]);
        Http::fake(['*'=>Http::response(['ok'=>false,'error_code'=>500],500)]);
        app(Telegram::class)->flush();
        $this->assertSame(1,DB::table('bot_outbox')->first()->attempts);
        $this->travel(31)->minutes();
        Http::fake(['*'=>Http::response(['ok'=>true,'result'=>[]])]);
        app(Telegram::class)->flush();
        $this->assertDatabaseCount('bot_outbox',0);
    }

    public function test_valid_delivery_strips_internal_marker_and_can_send_next_evening(): void
    {
        $this->travel(1)->minutes(); app(EveningSummary::class)->tick();
        DB::table('bot_settings')->insert(['key'=>'token','value'=>Crypt::encryptString('fake-token')]);
        Http::fake(['*'=>Http::response(['ok'=>true,'result'=>[]])]);
        app(Telegram::class)->flush();
        Http::assertSent(fn ($r) => !isset($r['_evening_before']) && str_contains($r['text'],'Сьогодні без запланованих задач'));
        $this->assertNotNull(DB::table('bot_outbox')->first()->sent_at);
        $this->travel(1)->days(); app(EveningSummary::class)->tick();
        $this->assertDatabaseCount('bot_outbox',2);
    }

    public function test_settings_can_disable_and_enable_summary_and_disabled_queue_is_not_sent(): void
    {
        $this->travel(1)->minutes(); app(EveningSummary::class)->tick();
        app(\App\Services\Bot::class)->update(['update_id'=>500,'callback_query'=>['id'=>'500','from'=>['id'=>11],'data'=>'evening:off','message'=>['message_id'=>1,'chat'=>['id'=>11,'type'=>'private']]]]);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'evening_enabled'=>false]);
        DB::table('bot_outbox')->where('method','!=','sendMessage')->delete();
        // Remove navigation setup; only the queued summary should remain.
        DB::table('bot_outbox')->where('payload','not like','%_evening_before%')->delete();
        Http::fake(); app(Telegram::class)->flush();
        Http::assertNothingSent();
        $this->assertDatabaseCount('bot_outbox',0);
        app(\App\Services\Bot::class)->update(['update_id'=>501,'callback_query'=>['id'=>'501','from'=>['id'=>11],'data'=>'evening:on','message'=>['message_id'=>1,'chat'=>['id'=>11,'type'=>'private']]]]);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'evening_enabled'=>true]);
    }
}
