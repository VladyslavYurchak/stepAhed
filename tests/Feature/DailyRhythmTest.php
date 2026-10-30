<?php

namespace Tests\Feature;

use App\Services\Bot;
use App\Services\DailyRhythm;
use App\Services\Tasks;
use App\Services\Telegram;
use App\Services\UserData;
use Carbon\CarbonImmutable as Time;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DailyRhythmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Time::parse('2026-10-30 04:59:00','UTC'));
        DB::table('bot_profiles')->insert(['id'=>11,'name'=>'Владислав','timezone'=>'Europe/Kyiv','onboarded'=>true,
            'sleep_at'=>'23:00','wake_at'=>'07:00','morning_mode'=>'wake','morning_at'=>'07:00']);
        DB::table('bot_settings')->insert(['key'=>'navigation_keyboard_11','value'=>'1']);
    }

    private function p(): object { return DB::table('bot_profiles')->find(11); }

    public function test_manual_wake_hides_today_and_returns_at_local_midnight(): void
    {
        $menu=function () {
            return json_decode(DB::table('bot_outbox')->where('method','sendMessage')->orderByDesc('id')->first()->payload,true);
        };
        $this->button('home',900);
        $this->assertStringContainsString('awake',json_encode($menu()['reply_markup']));
        $this->button('awake',901);
        $this->button('home',902);
        $this->assertStringNotContainsString('awake',json_encode($menu()['reply_markup']));
        $this->travelTo(Time::parse('2026-10-30 22:01:00','UTC'));
        $this->button('home',903);
        $this->assertStringContainsString('awake',json_encode($menu()['reply_markup']));
    }

    public function test_manual_wake_removes_only_its_button_from_clicked_message(): void
    {
        app(Bot::class)->update(['update_id'=>910,'callback_query'=>['id'=>'910','from'=>['id'=>11],
            'data'=>'awake','message'=>['message_id'=>20,'chat'=>['id'=>11,'type'=>'private'],
                'reply_markup'=>['inline_keyboard'=>[[['text'=>'☀️ Я прокинувся','callback_data'=>'awake']],[['text'=>'Меню','callback_data'=>'home']]]]]]]);
        $row=DB::table('bot_outbox')->where('method','editMessageReplyMarkup')->first();
        $this->assertNotNull($row);
        $payload=json_decode($row->payload,true);
        $this->assertSame(20,$payload['message_id']);
        $this->assertSame([[['text'=>'Меню','callback_data'=>'home']]],$payload['reply_markup']['inline_keyboard']);
    }

    private function button(string $data, int $id): void
    {
        app(Bot::class)->update(['update_id'=>$id,'callback_query'=>['id'=>(string)$id,'from'=>['id'=>11],
            'data'=>$data,'message'=>['message_id'=>1,'chat'=>['id'=>11,'type'=>'private']]]]);
    }

    private function text(string $text, int $id): void
    {
        app(Bot::class)->update(['update_id'=>$id,'message'=>['message_id'=>$id,'from'=>['id'=>11],
            'chat'=>['id'=>11,'type'=>'private'],'text'=>$text]]);
    }

    private function task(string $due, string $title='Написати лист'): int
    {
        $id=DB::table('commitments')->insertGetId(['profile_id'=>11,'title'=>$title,'mandatory'=>true,
            'schedule'=>json_encode(['mode'=>'once','date'=>'2026-10-30','time'=>'06:00','timezone'=>'Europe/Kyiv'])]);
        return DB::table('occurrences')->insertGetId(['commitment_id'=>$id,'profile_id'=>11,'due_at'=>$due]);
    }

    public function test_sleep_boundaries_use_local_time_for_night_and_day_sleep(): void
    {
        $r=app(DailyRhythm::class); $p=$this->p();
        $this->assertTrue($r->quiet($p,Time::parse('2026-10-29 21:00:00','UTC')));
        $this->assertTrue($r->quiet($p));
        $this->assertFalse($r->quiet($p,Time::parse('2026-10-30 05:00:00','UTC')));
        $p->sleep_at='09:00'; $p->wake_at='17:00';
        $this->assertTrue($r->quiet($p,Time::parse('2026-10-30 08:00:00','UTC')));
        $this->assertFalse($r->quiet($p,Time::parse('2026-10-30 16:00:00','UTC')));
    }

    public function test_morning_runs_once_at_local_wake_time_and_excludes_done_and_tomorrow(): void
    {
        $first=$this->task('2026-10-30 08:00:00');
        $done=$this->task('2026-10-30 09:00:00','Вже виконано');
        DB::table('occurrences')->where('id',$done)->update(['status'=>'done']);
        $this->task('2026-10-30 22:00:00','Завтра');
        $r=app(DailyRhythm::class); $r->tick();
        $this->assertDatabaseCount('bot_outbox',0);
        $this->travel(1)->minutes(); $r->tick(); $r->tick();
        $this->assertDatabaseCount('bot_outbox',1);
        $payload=json_decode(DB::table('bot_outbox')->first()->payload,true);
        $this->assertStringContainsString('Доброго раночку, Владиславе', $payload['text']);
        $this->assertStringContainsString('10:00 — Написати лист', $payload['text']);
        $this->assertStringNotContainsString('Вже виконано', $payload['text']);
        $this->assertStringNotContainsString('Завтра', $payload['text']);
        $this->assertSame('HTML',$payload['parse_mode']);
    }

    public function test_sleep_notifications_merge_and_deadlines_still_produce_one_penalty(): void
    {
        DB::table('bot_profiles')->where('id',11)->update(['morning_mode'=>'off']);
        $id=$this->task('2026-10-30 04:00:00');
        app(Tasks::class)->tick(); app(Tasks::class)->tick();
        $this->assertDatabaseCount('bot_outbox',0);
        $this->assertDatabaseCount('bot_deferred_notifications',1);
        $this->assertDatabaseHas('occurrences',['id'=>$id,'status'=>'missed','penalty_amount'=>20]);
        $this->assertSame(1,$this->p()->violations);
        $this->travel(1)->minutes();
        app(DailyRhythm::class)->tick(); app(DailyRhythm::class)->tick();
        $this->assertDatabaseCount('bot_outbox',1);
        $this->assertDatabaseCount('bot_deferred_notifications',0);
        $payload=json_decode(DB::table('bot_outbox')->first()->payload,true);
        $this->assertStringContainsString('Не підтверджено вчасно',$payload['text']);
        $this->assertStringNotContainsString('done:',json_encode($payload));
    }

    public function test_old_queued_reminder_is_not_sent_when_sleep_starts(): void
    {
        $this->travelTo(Time::parse('2026-10-29 20:59:00','UTC'));
        $id=$this->task('2026-10-30 04:00:00');
        app(DailyRhythm::class)->notify(11,$id,'Reminder');
        $this->assertDatabaseCount('bot_outbox',1);
        $this->travel(1)->minutes();
        app(Telegram::class)->flush();
        $this->assertDatabaseCount('bot_deferred_notifications',1);
        $this->assertDatabaseCount('bot_outbox',0);
    }

    public function test_setup_accepts_manual_times_and_rejects_morning_during_sleep(): void
    {
        $this->button('rhythm',101);
        $this->text('23:15',102); $this->text('07:10',103);
        $s=json_decode($this->p()->dialog,true);
        $this->button('rhythmmode:'.$s['nonce'].':custom',104);
        $this->text('06:00',105);
        $this->assertSame('rhythm_morning',json_decode($this->p()->dialog,true)['step']);
        $this->text('08:15',106);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'sleep_at'=>'23:15','wake_at'=>'07:10','morning_mode'=>'custom','morning_at'=>'08:15','dialog'=>null]);
        $this->button('rhythmmode:'.$s['nonce'].':off',107);
        $this->assertSame('custom',$this->p()->morning_mode);
    }

    public function test_manual_wake_overrides_quiet_hours_and_deletion_removes_deferred_rows(): void
    {
        $id=$this->task('2026-10-30 08:00:00');
        app(DailyRhythm::class)->notify(11,$id,'Reminder');
        $this->assertDatabaseCount('bot_deferred_notifications',1);
        $this->button('awake',201);
        $this->assertFalse(app(DailyRhythm::class)->quiet($this->p()));
        $this->assertDatabaseCount('bot_deferred_notifications',0);
        $this->assertSame('2026-10-30',$this->p()->morning_last_date);
        app(DailyRhythm::class)->defer(11,$id);
        app(UserData::class)->delete(11);
        $this->assertDatabaseCount('bot_deferred_notifications',0);
    }

    public function test_new_user_is_asked_about_sleep_after_timezone_selection(): void
    {
        DB::table('bot_profiles')->where('id',11)->update(['onboarded'=>false,'dialog'=>json_encode(['step'=>'timezone'])]);
        $this->button('tzpick:kyiv',301);
        $this->assertSame('rhythm_sleep',json_decode($this->p()->dialog,true)['step']);
    }

    public function test_retrying_morning_waits_during_sleep_and_never_sends_yesterdays_greeting(): void
    {
        $this->travel(1)->minutes();
        app(DailyRhythm::class)->tick();
        $this->assertDatabaseCount('bot_outbox',1);
        $this->travelTo(Time::parse('2026-10-30 21:00:00','UTC'));
        Http::fake();
        app(Telegram::class)->flush();
        Http::assertNothingSent();
        $this->assertDatabaseCount('bot_outbox',1);
        $this->travelTo(Time::parse('2026-10-31 05:00:00','UTC'));
        app(Telegram::class)->flush();
        Http::assertNothingSent();
        $this->assertDatabaseCount('bot_outbox',0);
        app(DailyRhythm::class)->tick();
        $this->assertDatabaseCount('bot_outbox',1);
        $this->assertSame('2026-10-31',$this->p()->morning_last_date);
    }

    public function test_new_deadline_during_sleep_shows_warning_before_confirmation(): void
    {
        DB::table('bot_profiles')->where('id',11)->update(['dialog'=>json_encode([
            'step'=>'time','mode'=>'once','date'=>'2026-10-31','title'=>'Тест сну','mandatory'=>true,'photo'=>false,
        ])]);
        $this->text('06:15',401);
        $payload=json_decode(DB::table('bot_outbox')->where('method','sendMessage')->orderByDesc('id')->value('payload'),true);
        $this->assertStringContainsString('Дедлайн потрапляє на твої години сну',$payload['text']);
        $this->assertSame('confirm',json_decode($this->p()->dialog,true)['step']);
    }
}
