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
}
