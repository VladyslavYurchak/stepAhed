<?php

namespace Tests\Feature;

use App\Services\Accountability;
use App\Services\Bot;
use App\Services\Tasks;
use Carbon\CarbonImmutable as Time;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); Time::setTestNow(Time::parse('2026-10-29 12:00:00','UTC')); $this->travelTo(Time::now());
        DB::table('bot_profiles')->insert(['id'=>11,'name'=>'Тест','timezone'=>'Europe/Kyiv','onboarded'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('bot_settings')->insert(['key'=>'navigation_keyboard_11','value'=>'1']);
        DB::table('bot_settings')->insert([['key'=>'moderation_chat','value'=>'-100'],['key'=>'admin_id','value'=>'99']]);
    }
    protected function tearDown(): void { Time::setTestNow(); parent::tearDown(); }

    private function task(string $title='30 віджимань'): object
    {
        $id=DB::table('commitments')->insertGetId(['profile_id'=>11,'title'=>$title,'mandatory'=>true,'photo_required'=>false,'schedule'=>json_encode(['mode'=>'daily','time'=>'21:00','timezone'=>'Europe/Kyiv']),'created_at'=>now(),'updated_at'=>now()]);
        return DB::table('commitments')->find($id);
    }
    private function occurrence(object $task, Time $due, string $status='pending', int $penalty=0): object
    {
        $id=DB::table('occurrences')->insertGetId(['profile_id'=>11,'commitment_id'=>$task->id,'due_at'=>$due->toDateTimeString(),'status'=>$status,'penalty_amount'=>$penalty,'created_at'=>now(),'updated_at'=>now()]);
        return app(Tasks::class)->occurrence($id,11);
    }
    private function transaction(callable $f): mixed { return DB::transaction($f); }

    public function test_reminders_start_at_two_hours_and_are_not_repeated(): void
    {
        $task=$this->task();
        $yes=$this->occurrence($task,Time::now()->addHours(2));
        $no=$this->occurrence($task,Time::now()->addHours(2)->addMinute());
        app(Tasks::class)->tick(); app(Tasks::class)->tick();
        $this->assertNotNull(DB::table('occurrences')->find($yes->id)->reminded_at);
        $this->assertNull(DB::table('occurrences')->find($no->id)->reminded_at);
        $messages=DB::table('bot_outbox')->where('method','sendMessage')->get()->filter(fn($m)=>str_contains($m->payload,'Нагадування:'));
        $this->assertCount(1,$messages);
    }

    public function test_single_edit_keeps_history_and_other_repetitions(): void
    {
        $task=$this->task(); $old=$this->occurrence($task,Time::now()->addHours(4));
        $other=$this->occurrence($task,Time::now()->addDay());
        $new=$this->transaction(fn()=>app(Tasks::class)->reviseSingle(11,$old->id,'20 віджимань'));
        $this->assertSame('20 віджимань',$new->title);
        $this->assertSame($old->due_at,$new->due_at);
        $this->assertDatabaseHas('occurrences',['id'=>$old->id,'status'=>'superseded']);
        $this->assertSame('30 віджимань',app(Tasks::class)->occurrence($other->id,11)->title);
        $this->assertSame('30 віджимань',DB::table('commitments')->find($task->id)->title);
    }

    public function test_single_edit_rechecks_three_hour_boundary(): void
    {
        $o=$this->occurrence($this->task(),Time::now()->addHours(3));
        $this->expectException(\DomainException::class);
        $this->transaction(fn()=>app(Tasks::class)->reviseSingle(11,$o->id,'10 віджимань'));
    }

    public function test_series_edit_keeps_locked_and_completed_occurrences(): void
    {
        $task=$this->task();
        $locked=$this->occurrence($task,Time::now()->addHours(2));
        $done=$this->occurrence($task,Time::now()->subDay(),'done');
        $future=$this->occurrence($task,Time::now()->addDay());
        $schedule=['mode'=>'weekly','weekdays'=>[1,5],'day_times'=>[1=>'08:00',5=>'20:00'],'time'=>'08:00','timezone'=>'Europe/Kyiv'];
        $next=$this->transaction(fn()=>app(Tasks::class)->reviseSeries(11,$task->id,hash('sha256',$task->title.$task->schedule),'20 віджимань',$schedule));
        $this->assertDatabaseHas('occurrences',['id'=>$locked->id,'status'=>'pending']);
        $this->assertDatabaseHas('occurrences',['id'=>$done->id,'status'=>'done']);
        $this->assertDatabaseHas('occurrences',['id'=>$future->id,'status'=>'superseded']);
        $this->assertSame('30 віджимань',app(Tasks::class)->occurrence($locked->id,11)->title);
        $this->assertDatabaseHas('occurrences',['commitment_id'=>$next->id,'due_at'=>'2026-10-30 18:00:00']);
        $n=DB::table('occurrences')->count(); app(Tasks::class)->generate($next);
        $this->assertSame($n,DB::table('occurrences')->count());
        $this->assertDatabaseHas('commitments',['id'=>$task->id,'active'=>false]);
    }

    public function test_stale_series_edit_is_rejected(): void
    {
        $task=$this->task();
        $this->expectException(\DomainException::class);
        $this->transaction(fn()=>app(Tasks::class)->reviseSeries(11,$task->id,'stale','20 віджимань',json_decode($task->schedule,true)));
    }

    public function test_automatic_pause_only_covers_future_and_suppresses_fines(): void
    {
        $task=$this->task();
        $past=$this->occurrence($task,Time::now()->subHours(2),'missed',20);
        $future=$this->occurrence($task,Time::now()->addHour());
        $pause=$this->transaction(fn()=>app(Accountability::class)->requestPause(11,3,'Захворів'));
        $this->assertSame('approved',$pause->status); $this->assertTrue((bool)$pause->automatic);
        $this->assertDatabaseHas('occurrences',['id'=>$future->id,'status'=>'paused','penalty_amount'=>0]);
        $this->assertDatabaseHas('occurrences',['id'=>$past->id,'status'=>'missed','penalty_amount'=>20]);
        app(Tasks::class)->tick();
        $this->assertDatabaseHas('occurrences',['commitment_id'=>$task->id,'due_at'=>'2026-10-29 19:00:00','status'=>'paused']);
    }

    public function test_ending_pause_does_not_reset_thirty_day_limit(): void
    {
        $first=$this->transaction(fn()=>app(Accountability::class)->requestPause(11,1,'Захворів'));
        $this->transaction(fn()=>app(Accountability::class)->resume(11));
        $second=$this->transaction(fn()=>app(Accountability::class)->requestPause(11,1,'Знову хвороба'));
        $this->assertSame('pending',$second->status);
        $this->assertDatabaseHas('bot_pauses',['id'=>$first->id,'status'=>'ended']);
    }

    public function test_repeated_pause_waits_for_moderator_and_can_be_rejected(): void
    {
        $this->transaction(fn()=>app(Accountability::class)->requestPause(11,1,'Хвороба'));
        $task=$this->task(); $future=$this->occurrence($task,Time::now()->addDays(2));
        $second=$this->transaction(fn()=>app(Accountability::class)->requestPause(11,3,'Продовження'));
        $this->assertSame('pending',$second->status);
        $this->assertDatabaseHas('occurrences',['id'=>$future->id,'status'=>'pause_review']);
        $this->transaction(fn()=>app(Accountability::class)->moderatePause($second->id,false));
        $this->assertDatabaseHas('occurrences',['id'=>$future->id,'status'=>'pending','pause_id'=>null]);
    }

    public function test_ten_successful_days_reset_escalation_only_once(): void
    {
        $task=$this->task(); DB::table('bot_profiles')->where('id',11)->update(['violations'=>7]);
        for($i=10;$i>=1;$i--) $this->occurrence($task,Time::now()->subDays($i),'done');
        $this->transaction(fn()=>app(Accountability::class)->settleDays(11));
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'violations'=>0,'success_streak'=>0,'streak_cursor'=>'2026-10-28']);
        $n=DB::table('bot_outbox')->count();
        $this->transaction(fn()=>app(Accountability::class)->settleDays(11));
        $this->assertSame($n,DB::table('bot_outbox')->count());
    }

    public function test_reset_preserves_later_violations_and_existing_penalty_amounts(): void
    {
        $task=$this->task(); DB::table('bot_profiles')->where('id',11)->update(['violations'=>8]);
        $old=$this->occurrence($task,Time::now()->subDays(12),'missed',25);
        for($i=10;$i>=1;$i--) $this->occurrence($task,Time::now()->subDays($i),'done');
        $later=$this->occurrence($task,Time::now()->subHour(),'missed',27);
        $this->transaction(fn()=>app(Accountability::class)->settleDays(11));
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'violations'=>1]);
        $this->assertDatabaseHas('occurrences',['id'=>$old->id,'penalty_amount'=>25]);
        $this->assertDatabaseHas('occurrences',['id'=>$later->id,'penalty_amount'=>27]);
    }

    public function test_empty_and_pause_days_do_not_add_to_streak_and_failure_breaks_it(): void
    {
        $task=$this->task();
        $this->occurrence($task,Time::now()->subDays(6),'done');
        $this->occurrence($task,Time::now()->subDays(4),'paused');
        $this->occurrence($task,Time::now()->subDays(3),'done');
        $this->transaction(fn()=>app(Accountability::class)->settleDays(11));
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'success_streak'=>2]);
        $this->occurrence($task,Time::now()->subDays(2),'missed',20);
        $this->occurrence($task,Time::now()->subDay(),'done');
        $this->transaction(fn()=>app(Accountability::class)->settleDays(11));
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'success_streak'=>1]);
    }

    public function test_undecided_photo_blocks_finalizing_success_streak(): void
    {
        $task=$this->task();
        for($i=10;$i>=1;$i--) $this->occurrence($task,Time::now()->subDays($i),$i===5?'review':'done');
        $this->transaction(fn()=>app(Accountability::class)->settleDays(11));
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'success_streak'=>5,'streak_cursor'=>'2026-10-23']);
        DB::table('occurrences')->where('status','review')->update(['status'=>'done']);
        $this->transaction(fn()=>app(Accountability::class)->settleDays(11));
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'success_streak'=>0,'streak_cursor'=>'2026-10-28']);
    }

    public function test_paused_future_slots_are_replaced_when_editing_series(): void
    {
        $task=$this->task(); $old=$this->occurrence($task,Time::now()->addDay(),'paused');
        $this->transaction(fn()=>app(Accountability::class)->requestPause(11,3,'Хвороба'));
        $schedule=json_decode($task->schedule,true); $schedule['time']='20:00';
        $next=$this->transaction(fn()=>app(Tasks::class)->reviseSeries(11,$task->id,hash('sha256',$task->title.$task->schedule),'15 віджимань',$schedule));
        $this->assertDatabaseHas('occurrences',['id'=>$old->id,'status'=>'superseded']);
        $this->assertDatabaseHas('occurrences',['commitment_id'=>$next->id,'due_at'=>'2026-10-30 18:00:00','status'=>'paused']);
    }

    public function test_only_bound_admin_can_approve_pause_and_decision_is_idempotent(): void
    {
        $this->transaction(fn()=>app(Accountability::class)->requestPause(11,1,'Хвороба'));
        $second=$this->transaction(fn()=>app(Accountability::class)->requestPause(11,2,'Продовження'));
        $update=fn($uid,$id)=>['update_id'=>$id,'callback_query'=>['id'=>(string)$id,'from'=>['id'=>$uid],'data'=>'pauseapprove:'.$second->id,'message'=>['message_id'=>1,'chat'=>['id'=>-100,'type'=>'supergroup']]]];
        app(Bot::class)->update($update(88,301));
        $this->assertDatabaseHas('bot_pauses',['id'=>$second->id,'status'=>'pending']);
        app(Bot::class)->update($update(99,302));
        $this->assertDatabaseHas('bot_pauses',['id'=>$second->id,'status'=>'approved']);
        $n=DB::table('bot_outbox')->where('method','sendMessage')->count();
        app(Bot::class)->update($update(99,303));
        // A repeated click can acknowledge in the admin group, but must not notify the user twice.
        $this->assertSame($n+1,DB::table('bot_outbox')->where('method','sendMessage')->count());
    }

    public function test_pardoning_old_penalty_after_reset_does_not_remove_new_escalation(): void
    {
        $o=$this->occurrence($this->task(),Time::now()->subDays(12),'missed',25);
        DB::table('bot_profiles')->where('id',11)->update(['violations'=>2]);
        DB::table('bot_settings')->insert(['key'=>'penalty_reset_through_11','value'=>Time::now()->subDay()->toDateTimeString()]);
        app(Bot::class)->update(['update_id'=>401,'callback_query'=>['id'=>'401','from'=>['id'=>99],'data'=>'pardon:'.$o->id,'message'=>['message_id'=>1,'chat'=>['id'=>-100,'type'=>'supergroup']]]]);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'violations'=>2]);
        $this->assertDatabaseHas('occurrences',['id'=>$o->id,'status'=>'excused','penalty_amount'=>0]);
    }
}
