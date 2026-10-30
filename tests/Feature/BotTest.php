<?php

namespace Tests\Feature;

use App\Services\Bot;
use App\Services\Tasks;
use Carbon\CarbonImmutable as Time;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Time::setTestNow(Time::parse('2026-10-29 12:00:00','UTC'));
        $this->travelTo(Time::now());
        DB::table('bot_profiles')->insert(['id'=>11,'name'=>'Тест','timezone'=>'Europe/Kyiv','onboarded'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('bot_settings')->insert(['key'=>'navigation_keyboard_11','value'=>'1']);
    }

    protected function tearDown(): void { Time::setTestNow(); parent::tearDown(); }

    private function makeOccurrence(array $changes=[]): object
    {
        $id=DB::table('commitments')->insertGetId(['profile_id'=>11,'title'=>'30 віджимань','mandatory'=>true,'photo_required'=>false,'schedule'=>json_encode(['mode'=>'daily','time'=>'21:00','timezone'=>'Europe/Kyiv']),'created_at'=>now(),'updated_at'=>now()]);
        $oid=DB::table('occurrences')->insertGetId(array_merge(['commitment_id'=>$id,'profile_id'=>11,'due_at'=>Time::now()->addHours(4)->toDateTimeString(),'status'=>'pending','created_at'=>now(),'updated_at'=>now()],$changes));
        return app(Tasks::class)->occurrence($oid,11);
    }

    private function button(string $data,int $update=1,int $uid=11,int $chat=11,string $type='private'): void
    {
        app(Bot::class)->update(['update_id'=>$update,'callback_query'=>['id'=>(string)$update,'from'=>['id'=>$uid,'first_name'=>'Тест'],'data'=>$data,'message'=>['message_id'=>1,'chat'=>['id'=>$chat,'type'=>$type]]]]);
    }

    public function test_time_buttons_use_local_next_hour_and_keep_manual_input(): void
    {
        Time::setTestNow(Time::parse('2026-10-30 13:20:00', 'UTC'));
        $this->draft(['step'=>'schedule']);
        $this->button('schedule:today', 801);
        $payload = json_decode(DB::table('bot_outbox')->where('method','sendMessage')->orderByDesc('id')->value('payload'), true);
        $buttons = array_merge(...$payload['reply_markup']['inline_keyboard']);
        $this->assertSame('16:00', $buttons[0]['text']);
        $this->assertSame('16:30', $buttons[1]['text']);
        $this->assertSame('23:00', end($buttons)['text']);
        $this->assertCount(15, $buttons);
        $this->button($buttons[1]['callback_data'], 802);
        $s = json_decode(DB::table('bot_profiles')->where('id',11)->value('dialog'), true);
        $this->assertSame('confirm', $s['step']);
        $this->assertSame('16:30', $s['time']);
        $this->draft(['step'=>'schedule']);
        $this->button('schedule:today', 803);
        $this->button($buttons[0]['callback_data'], 804);
        $this->assertSame('time', json_decode(DB::table('bot_profiles')->where('id',11)->value('dialog'), true)['step']);
        $this->text('16:17', 805);
        $this->assertSame('16:17', json_decode(DB::table('bot_profiles')->where('id',11)->value('dialog'), true)['time']);
    }

    public function test_late_evening_time_prompt_has_no_next_day_buttons(): void
    {
        Time::setTestNow(Time::parse('2026-10-30 21:20:00', 'UTC'));
        $this->draft(['step'=>'schedule']);
        $this->button('schedule:today', 811);
        $payload = json_decode(DB::table('bot_outbox')->where('method','sendMessage')->orderByDesc('id')->value('payload'), true);
        $this->assertArrayNotHasKey('inline_keyboard', $payload['reply_markup']);
        $this->assertStringContainsString('введи свій', $payload['text']);
    }

    public function test_per_day_time_buttons_cannot_answer_the_next_day(): void
    {
        $this->draft(['weekdays'=>[1,5]]);
        $this->button('daysdone', 821);
        $this->button('timechoice:separate', 822);
        $payload = json_decode(DB::table('bot_outbox')->where('method','sendMessage')->orderByDesc('id')->value('payload'), true);
        $choice = $payload['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->button($choice, 823);
        $this->button($choice, 824);
        $s = json_decode(DB::table('bot_profiles')->where('id',11)->value('dialog'), true);
        $this->assertSame(1, $s['time_index']);
        $this->assertCount(1, $s['day_times']);
    }

    public function test_home_shows_only_own_unfinished_tasks_for_local_today_in_time_order(): void
    {
        $this->makeOccurrence(['due_at'=>'2026-10-29 21:30:00']);
        $this->makeOccurrence(['due_at'=>'2026-10-29 20:00:00']);
        $this->makeOccurrence(['due_at'=>'2026-10-29 19:00:00','status'=>'done']);
        $this->makeOccurrence(['due_at'=>'2026-10-29 22:00:00']); // Next local day.
        $this->text('/menu', 901);
        $payload=json_decode(DB::table('bot_outbox')->where('method','sendMessage')->orderByDesc('id')->value('payload'),true);
        $this->assertStringContainsString('Твій календарик на сьогодні:', $payload['text']);
        $this->assertStringContainsString('22:00 —', $payload['text']);
        $this->assertStringContainsString('23:30 —', $payload['text']);
        $this->assertStringNotContainsString('21:00 —', $payload['text']);
        $this->assertStringNotContainsString('00:00 —', $payload['text']);
        $this->assertLessThan(strpos($payload['text'],'23:30'), strpos($payload['text'],'22:00'));
        $this->assertStringNotContainsString('Мій розклад',json_encode($payload,JSON_UNESCAPED_UNICODE));
        DB::table('occurrences')->update(['status'=>'done']);
        $this->text('/menu', 902);
        $payload=json_decode(DB::table('bot_outbox')->where('method','sendMessage')->orderByDesc('id')->value('payload'),true);
        $this->assertStringContainsString('більше немає невиконаних', $payload['text']);
        $this->assertStringContainsString('Що сьогодні зробимо', $payload['text']);
    }

    public function test_three_hour_boundary_is_strict(): void
    {
        $o=$this->makeOccurrence(); $tasks=app(Tasks::class);
        $due=Time::parse($o->due_at,'UTC');
        $this->assertTrue($tasks->changeAllowed($o,$due->subHours(3)->subSecond()));
        $this->assertFalse($tasks->changeAllowed($o,$due->subHours(3)));
        $this->assertFalse($tasks->changeAllowed($o,$due->subHours(2)));
    }

    public function test_confirmation_has_exact_thirty_minute_grace(): void
    {
        $o=$this->makeOccurrence(); $due=Time::parse($o->due_at,'UTC');
        $this->assertTrue(app(Tasks::class)->confirmationAllowed($o,$due->addMinutes(30)));
        $this->assertFalse(app(Tasks::class)->confirmationAllowed($o,$due->addMinutes(30)->addSecond()));
    }

    public function test_duplicate_telegram_update_is_processed_once(): void
    {
        $this->button('new',1); $this->button('new',1);
        $this->assertDatabaseCount('bot_updates',1);
        $this->assertDatabaseCount('bot_outbox',2); // acknowledgement + prompt
    }

    public function test_cannot_complete_another_users_task(): void
    {
        $o=$this->makeOccurrence();
        DB::table('bot_profiles')->insert(['id'=>22,'name'=>'Інший','onboarded'=>true]);
        $this->button('done:'.$o->id,1,22,22);
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'pending']);
    }

    public function test_photo_requirement_cannot_be_bypassed_with_button(): void
    {
        $o=$this->makeOccurrence(); DB::table('commitments')->where('id',$o->commitment_id)->update(['photo_required'=>true]);
        DB::table('bot_settings')->insert(['key'=>'moderation_chat','value'=>'-100']);
        $this->button('done:'.$o->id);
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'pending']);
        $this->assertSame('photo',json_decode(DB::table('bot_profiles')->where('id',11)->value('dialog'),true)['step']);
    }

    public function test_pending_review_does_not_receive_deadline_penalty(): void
    {
        $o=$this->makeOccurrence(['status'=>'review','due_at'=>Time::now()->subHours(2)->toDateTimeString()]);
        app(Tasks::class)->tick();
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'review','penalty_amount'=>0]);
    }

    public function test_penalty_is_not_duplicated_on_repeated_ticks(): void
    {
        $o=$this->makeOccurrence(['due_at'=>Time::now()->subHour()->toDateTimeString()]);
        app(Tasks::class)->tick(); app(Tasks::class)->tick();
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'missed','penalty_amount'=>20]);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'violations'=>1]);
    }

    public function test_optional_plan_has_no_penalty(): void
    {
        $o=$this->makeOccurrence(['due_at'=>Time::now()->subHour()->toDateTimeString()]);
        DB::table('commitments')->where('id',$o->commitment_id)->update(['mandatory'=>false]);
        app(Tasks::class)->tick();
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'missed','penalty_amount'=>0]);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'violations'=>0]);
    }

    public function test_cancellation_requires_three_questions_and_preserves_start_time(): void
    {
        $o=$this->makeOccurrence();
        $this->button('cancel:'.$o->id,1);
        $this->button('cancelnext:'.$o->id,2);
        $this->button('cancelnext:'.$o->id,3);
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'pending']);
        $this->button('cancelnext:'.$o->id,4);
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'cancelled','penalty_amount'=>0]);
    }

    public function test_only_bound_admin_can_moderate(): void
    {
        $o=$this->makeOccurrence(['status'=>'review']);
        DB::table('bot_settings')->insert([['key'=>'moderation_chat','value'=>'-100'],['key'=>'admin_id','value'=>'99']]);
        $this->button('approve:'.$o->id,1,88,-100,'supergroup');
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'review']);
        $this->button('approve:'.$o->id,2,99,-100,'supergroup');
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'done']);
    }

    public function test_rejection_after_grace_assigns_one_penalty(): void
    {
        $o=$this->makeOccurrence(['status'=>'review','due_at'=>Time::now()->subHour()->toDateTimeString()]);
        DB::table('bot_settings')->insert([['key'=>'moderation_chat','value'=>'-100'],['key'=>'admin_id','value'=>'99']]);
        $this->button('reason:'.$o->id.',wrong',1,99,-100,'supergroup');
        $this->button('reason:'.$o->id.',wrong',2,99,-100,'supergroup');
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'missed','penalty_amount'=>20]);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'violations'=>1]);
    }

    public function test_recurring_generation_is_idempotent_and_keeps_timezone(): void
    {
        $o=$this->makeOccurrence(); $task=DB::table('commitments')->find($o->commitment_id);
        app(Tasks::class)->generate($task); $n=DB::table('occurrences')->count();
        DB::table('bot_profiles')->where('id',11)->update(['timezone'=>'America/New_York']);
        app(Tasks::class)->generate($task);
        $this->assertSame($n,DB::table('occurrences')->count());
    }

    public function test_webhook_rejects_missing_secret(): void
    {
        config(['bot.webhook_secret'=>'expected']);
        $this->postJson('/telegram/webhook',['update_id'=>1])->assertForbidden();
        $this->assertDatabaseCount('bot_updates',0);
    }

    public function test_setup_closes_after_configuration_and_never_echoes_token(): void
    {
        config(['bot.setup_key'=>'test-key','bot.webhook_secret'=>'secret']);
        Http::fake(['*getMe'=>Http::response(['ok'=>true,'result'=>['username'=>'krokvpered_bot']]),'*setWebhook'=>Http::response(['ok'=>true,'result'=>true])]);
        $token='123456:'.str_repeat('a',35);
        $this->post('/setup/test-key',['token'=>$token])->assertOk()->assertDontSee($token)->assertSee('/bind');
        $this->assertNotSame($token,DB::table('bot_settings')->where('key','token')->value('value'));
        $this->assertSame($token,Crypt::decryptString(DB::table('bot_settings')->where('key','token')->value('value')));
        $this->get('/setup/test-key')->assertNotFound();
    }

    private function draft(array $values = []): void
    {
        DB::table('bot_profiles')->where('id',11)->update(['dialog'=>json_encode(array_merge([
            'step'=>'weekdays','title'=>'Читати 10 сторінок','mandatory'=>true,'photo'=>false,
            'mode'=>'weekly','weekdays'=>[],
        ],$values), JSON_UNESCAPED_UNICODE)]);
    }

    private function text(string $value,int $id): void
    {
        app(Bot::class)->update(['update_id'=>$id,'message'=>['message_id'=>$id,'from'=>['id'=>11,'first_name'=>'Тест'],'chat'=>['id'=>11,'type'=>'private'],'text'=>$value]]);
    }

    public function test_weekday_toggle_edits_same_message_without_sending_another(): void
    {
        $this->draft();
        $this->button('day:1',101);
        $this->button('day:5',102);
        $this->button('day:1',103);
        $this->assertSame(0,DB::table('bot_outbox')->where('method','sendMessage')->count());
        $edits=DB::table('bot_outbox')->where('method','editMessageText')->get();
        $this->assertCount(3,$edits);
        $last=json_decode($edits->last()->payload,true);
        $this->assertSame(1,$last['message_id']);
        $this->assertSame('Пн',$last['reply_markup']['inline_keyboard'][0][0]['text']);
        $this->assertSame('✓ Пт',$last['reply_markup']['inline_keyboard'][1][0]['text']);
        $s=json_decode(DB::table('bot_profiles')->where('id',11)->value('dialog'),true);
        $this->assertSame([5],$s['weekdays']);
    }

    public function test_old_picker_cannot_modify_current_selection(): void
    {
        $this->draft(['weekdays'=>[1],'picker_message_id'=>99]);
        $this->button('day:5',101);
        $s=json_decode(DB::table('bot_profiles')->where('id',11)->value('dialog'),true);
        $this->assertSame([1],$s['weekdays']);
        $this->assertSame(0,DB::table('bot_outbox')->where('method','editMessageText')->count());
    }

    public function test_per_day_times_are_saved_and_shown_before_confirmation(): void
    {
        $this->draft(['weekdays'=>[1,5]]);
        $this->button('daysdone',101);
        $this->button('timechoice:separate',102);
        $this->text('08:00',103);
        $this->text('20:00',104);
        $s=json_decode(DB::table('bot_profiles')->where('id',11)->value('dialog'),true);
        $this->assertSame('confirm',$s['step']);
        $this->assertSame(['1'=>'08:00','5'=>'20:00'],$s['day_times']);
        $preview=json_decode(DB::table('bot_outbox')->where('method','sendMessage')->orderByDesc('id')->first()->payload,true);
        $this->assertStringContainsString('Пн 08:00 · Пт 20:00',$preview['text']);
        $this->button('save',105);
        $schedule=json_decode(DB::table('commitments')->first()->schedule,true);
        $this->assertSame($s['day_times'],$schedule['day_times']);
        $task=DB::table('commitments')->first();
        $this->assertDatabaseHas('occurrences',['commitment_id'=>$task->id,'due_at'=>'2026-10-30 18:00:00']);
        $this->assertDatabaseHas('occurrences',['commitment_id'=>$task->id,'due_at'=>'2026-11-02 06:00:00']);
    }

    public function test_invalid_day_time_does_not_advance_to_next_day(): void
    {
        $this->draft(['weekdays'=>[1,5]]);
        $this->button('daysdone',101); $this->button('timechoice:separate',102);
        $this->text('25:90',103);
        $s=json_decode(DB::table('bot_profiles')->where('id',11)->value('dialog'),true);
        $this->assertSame(0,$s['time_index']);
        $this->assertSame([],$s['day_times']);
    }

    public function test_shared_time_and_existing_weekly_schedules_still_work(): void
    {
        $this->draft(['weekdays'=>[1,5]]);
        $this->button('daysdone',101); $this->button('timechoice:common',102); $this->text('19:00',103); $this->button('save',104);
        $task=DB::table('commitments')->first(); $schedule=json_decode($task->schedule,true);
        $this->assertSame('19:00',$schedule['time']);
        $this->assertArrayNotHasKey('day_times',$schedule);
        $this->assertDatabaseHas('occurrences',['commitment_id'=>$task->id,'due_at'=>'2026-10-30 17:00:00']);
    }

    public function test_week_overview_is_one_grouped_message(): void
    {
        $first = $this->makeOccurrence();
        $second = $this->makeOccurrence(['due_at'=>Time::now()->addDay()->toDateTimeString()]);
        $this->button('list:week',201);
        $this->assertSame(0,DB::table('bot_outbox')->where('method','sendMessage')->count());
        $edits=DB::table('bot_outbox')->where('method','editMessageText')->get();
        $this->assertCount(1,$edits);
        $payload=json_decode($edits->first()->payload,true);
        $this->assertStringContainsString('Четвер · 29.10',$payload['text']);
        $this->assertStringContainsString('П’ятниця · 30.10',$payload['text']);
        $this->assertSame('detail:'.$first->id.',week,0',$payload['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('detail:'.$second->id.',week,0',$payload['reply_markup']['inline_keyboard'][1][0]['callback_data']);
    }

    public function test_overview_pagination_and_details_reuse_same_message(): void
    {
        $o=$this->makeOccurrence();
        for ($i=1;$i<10;$i++) DB::table('occurrences')->insert([
            'profile_id'=>11,'commitment_id'=>$o->commitment_id,'due_at'=>Time::parse($o->due_at,'UTC')->addMinutes($i)->toDateTimeString(),'status'=>'pending',
        ]);
        $this->button('list:today,1',201);
        $payload=json_decode(DB::table('bot_outbox')->where('method','editMessageText')->orderByDesc('id')->first()->payload,true);
        $this->assertStringContainsString('Сторінка 2 із 2 · задач: 10',$payload['text']);
        $this->assertStringContainsString('9. ',$payload['text']);
        $this->assertLessThan(4096,mb_strlen($payload['text']));
        $detail=$payload['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->button($detail,202);
        $card=json_decode(DB::table('bot_outbox')->where('method','editMessageText')->orderByDesc('id')->first()->payload,true);
        $buttons=$card['reply_markup']['inline_keyboard'];
        $this->assertSame('list:today,1',end($buttons)[0]['callback_data']);
        $this->button('list:today,1',203);
        $this->assertSame(0,DB::table('bot_outbox')->where('method','sendMessage')->count());
        $this->assertSame(3,DB::table('bot_outbox')->where('method','editMessageText')->count());
    }

    public function test_empty_overview_is_single_message_and_week_is_seven_local_days(): void
    {
        $this->makeOccurrence(['due_at'=>Time::now()->addDays(7)->toDateTimeString()]);
        $this->button('list:week',201);
        $payload=json_decode(DB::table('bot_outbox')->where('method','editMessageText')->first()->payload,true);
        $this->assertStringContainsString('29.10 — 04.11',$payload['text']);
        $this->assertStringContainsString('Поки задач немає.',$payload['text']);
        $this->assertSame(0,DB::table('bot_outbox')->where('method','sendMessage')->count());
    }

    public function test_single_edit_dialog_saves_only_after_confirmation(): void
    {
        $o=$this->makeOccurrence();
        $this->button('editsingle:'.$o->id,301);
        $this->text('15 віджимань',302);
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'pending']);
        $this->button('editsave:'.$o->id,303);
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'superseded']);
        $this->assertDatabaseHas('commitments',['profile_id'=>11,'title'=>'15 віджимань']);
        $this->button('editsave:'.$o->id,304);
        $this->assertSame(1,DB::table('commitments')->where('title','15 віджимань')->count());
    }

    public function test_series_schedule_editor_uses_existing_day_picker_and_per_day_times(): void
    {
        $o=$this->makeOccurrence();
        $this->button('seriesschedule:'.$o->commitment_id,301);
        $this->button('schedule:weekly',302); $this->button('day:1',303); $this->button('day:5',304);
        $this->button('daysdone',305); $this->button('timechoice:separate',306);
        $this->text('09:00',307); $this->text('18:00',308);
        $this->button('save',309);
        $this->assertDatabaseHas('commitments',['id'=>$o->commitment_id,'active'=>false]);
        $new=DB::table('commitments')->where('active',true)->first();
        $schedule=json_decode($new->schedule,true);
        $this->assertSame([1,5],$schedule['weekdays']);
        $this->assertSame(['1'=>'09:00','5'=>'18:00'],$schedule['day_times']);
    }

    public function test_emergency_pause_dialog_requires_reason_and_confirmation(): void
    {
        $this->button('pausedays:2',301); $this->text('Хвороба',302);
        $this->assertDatabaseCount('bot_pauses',0);
        $this->button('pauseconfirm',303);
        $this->assertDatabaseHas('bot_pauses',['profile_id'=>11,'status'=>'approved','automatic'=>true,'reason'=>'Хвороба']);
        $this->button('pauseconfirm',304);
        $this->assertDatabaseCount('bot_pauses',1);
    }

    public function test_timezone_picker_shows_city_buttons_in_same_message(): void
    {
        $this->button('timezone',501);
        $this->assertSame(0,DB::table('bot_outbox')->where('method','sendMessage')->count());
        $payload=json_decode(DB::table('bot_outbox')->where('method','editMessageText')->first()->payload,true);
        $this->assertSame('tzpick:kyiv',$payload['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('tzpick:warsaw',$payload['reply_markup']['inline_keyboard'][0][1]['callback_data']);
        $this->assertStringContainsString('Київ',$payload['reply_markup']['inline_keyboard'][0][0]['text']);
    }

    public function test_city_selection_changes_timezone_without_moving_existing_deadlines(): void
    {
        $o=$this->makeOccurrence();
        $this->button('timezone',501); $this->button('tzpick:warsaw',502);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'timezone'=>'Europe/Warsaw','onboarded'=>true,'dialog'=>null]);
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'due_at'=>$o->due_at]);
        $this->assertSame(0,DB::table('bot_outbox')->where('method','sendMessage')->count());
    }

    public function test_timezone_manual_fallback_and_invalid_city_are_safe(): void
    {
        $this->button('timezone',501); $this->button('tzpick:invalid',502);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'timezone'=>'Europe/Kyiv']);
        $this->button('tzcustom',503); $this->text('America/Chicago',504);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'timezone'=>'America/Chicago']);
        $this->button('tzpick:berlin',505);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'timezone'=>'America/Chicago']);
    }

    public function test_menu_button_interrupts_any_draft_without_saving_or_cancelling_tasks(): void
    {
        $o=$this->makeOccurrence();
        foreach (['timezone','title','time','daytime','photo','pause_reason','edit_single_title','cancel'] as $i=>$step) {
            $this->draft(['step'=>$step,'id'=>$o->id]);
            $this->text('Повернутися в меню',600+$i);
            $this->assertDatabaseHas('bot_profiles',['id'=>11,'dialog'=>null]);
            $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'pending']);
            $this->assertDatabaseCount('commitments',1);
        }
    }

    public function test_persistent_menu_keyboard_is_installed_once_for_existing_user(): void
    {
        DB::table('bot_settings')->where('key','navigation_keyboard_11')->delete();
        $this->button('timezone',601); $this->button('tzpick:warsaw',602);
        $messages=DB::table('bot_outbox')->where('method','sendMessage')->get();
        $this->assertCount(1,$messages);
        $payload=json_decode($messages->first()->payload,true);
        $this->assertSame('Повернутися в меню',$payload['reply_markup']['keyboard'][0][0]['text']);
        $this->assertTrue($payload['reply_markup']['is_persistent']);
        $this->assertFalse($payload['reply_markup']['one_time_keyboard']);
    }

    public function test_group_messages_do_not_receive_personal_navigation_keyboard(): void
    {
        app(\App\Services\Telegram::class)->send(-100,'Повідомлення модератору');
        $payload=json_decode(DB::table('bot_outbox')->first()->payload,true);
        $this->assertArrayNotHasKey('reply_markup',$payload);
    }

    public function test_settings_guide_opens_and_returns_in_same_message_without_video_link(): void
    {
        $this->button('settings',701);
        $settings=json_decode(DB::table('bot_outbox')->where('method','editMessageText')->first()->payload,true);
        $this->assertStringContainsString('Дізнайся про мене більше💜',json_encode($settings,JSON_UNESCAPED_UNICODE));
        $this->button('guide',702);
        $guide=json_decode(DB::table('bot_outbox')->where('method','editMessageText')->orderByDesc('id')->first()->payload,true);
        $this->assertStringContainsString('Відеоінструкцію додамо згодом.',$guide['text']);
        $this->assertStringContainsString('30 хвилин',$guide['text']);
        $this->assertStringContainsString('2 години',$guide['text']);
        $this->assertLessThanOrEqual(4096,mb_strlen($guide['text']));
        $this->assertSame('settings',$guide['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->button('settings',703);
        $this->assertSame(0,DB::table('bot_outbox')->where('method','sendMessage')->count());
    }
}
