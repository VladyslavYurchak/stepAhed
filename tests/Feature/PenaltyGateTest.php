<?php

namespace Tests\Feature;

use App\Services\Bot;
use App\Services\Funds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PenaltyGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([11,22,99] as $uid) {
            DB::table('bot_profiles')->insert(['id'=>$uid,'name'=>'Тест','timezone'=>'Europe/Kyiv','onboarded'=>true]);
            DB::table('bot_settings')->insert(['key'=>'navigation_keyboard_'.$uid,'value'=>'1']);
        }
        DB::table('bot_settings')->insert(['key'=>'admin_id','value'=>'99']);
    }

    private function occurrence(int $uid=11, string $status='missed', int $amount=20): int
    {
        $id=DB::table('commitments')->insertGetId(['profile_id'=>$uid,'title'=>'Задача','mandatory'=>true,'schedule'=>'{}']);
        return DB::table('occurrences')->insertGetId(['profile_id'=>$uid,'commitment_id'=>$id,'due_at'=>now()->addHour(),'status'=>$status,'penalty_amount'=>$amount]);
    }

    private function button(string $data, int $update, int $uid=11): void
    {
        app(Bot::class)->update(['update_id'=>$update,'callback_query'=>['id'=>(string)$update,'from'=>['id'=>$uid],
            'data'=>$data,'message'=>['message_id'=>1,'chat'=>['id'=>$uid,'type'=>'private']]]]);
    }

    public function test_unpaid_penalty_blocks_new_and_old_draft_save_but_allows_existing_task_completion(): void
    {
        $this->occurrence(); $active=$this->occurrence(11,'pending',0);
        $this->button('new',1);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'dialog'=>null]);
        $payload=json_decode(DB::table('bot_outbox')->where('method','sendMessage')->orderByDesc('id')->value('payload'),true);
        $this->assertStringContainsString('Оплатіть, будь ласка, штраф',$payload['text']);
        DB::table('bot_profiles')->where('id',11)->update(['dialog'=>json_encode(['step'=>'confirm','mode'=>'once','date'=>now()->addDay()->format('Y-m-d'),'time'=>'21:00','title'=>'Нова задача','mandatory'=>false,'photo'=>false])]);
        $this->button('save',2);
        $this->assertDatabaseCount('commitments',2);
        $this->button('done:'.$active,3);
        $this->assertDatabaseHas('occurrences',['id'=>$active,'status'=>'done']);
    }

    public function test_pending_receipt_waits_for_approval_then_new_tasks_unlock(): void
    {
        $occ=$this->occurrence();
        $fund=DB::table('charity_funds')->insertGetId(['title'=>'Збір','recipient'=>'Організація','purpose'=>'Допомога','url'=>'https://send.monobank.ua/jar/TestJar123','status'=>'active']);
        $d=app(Funds::class)->selectFund(11,$occ,$fund);
        DB::table('donations')->where('id',$d->id)->update(['status'=>'pending']);
        $r=DB::table('donation_receipts')->insertGetId(['donation_id'=>$d->id,'profile_id'=>11,'fund_snapshot'=>$d->fund_snapshot,'expected_amount'=>20,'file_id'=>'test','file_unique_id'=>'test','status'=>'pending']);
        $this->button('new',10);
        $payload=json_decode(DB::table('bot_outbox')->where('method','sendMessage')->orderByDesc('id')->value('payload'),true);
        $this->assertStringContainsString('на перевірці',$payload['text']);
        $this->assertStringContainsString('повторно',$payload['text']);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'dialog'=>null]);
        app(Funds::class)->reviewReceipt(99,$r,true);
        $this->button('new',11);
        $this->assertSame('title',json_decode(DB::table('bot_profiles')->find(11)->dialog,true)['step']);
    }

    public function test_other_users_penalties_zero_and_excused_amounts_do_not_block(): void
    {
        $this->occurrence(22); $this->occurrence(11,'missed',0); $this->occurrence(11,'excused',20);
        $this->button('new',20);
        $this->assertSame('title',json_decode(DB::table('bot_profiles')->find(11)->dialog,true)['step']);
    }

    public function test_all_penalties_must_be_approved_and_list_excludes_other_users(): void
    {
        $first=$this->occurrence(); $second=$this->occurrence(); $this->occurrence(22);
        $fund=DB::table('charity_funds')->insertGetId(['title'=>'Збір','recipient'=>'Організація','purpose'=>'Допомога','url'=>'https://send.monobank.ua/jar/TestJar123','status'=>'active']);
        $d=app(Funds::class)->selectFund(11,$first,$fund);
        DB::table('donations')->where('id',$d->id)->update(['status'=>'approved']);
        $this->button('new',30);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'dialog'=>null]);
        $this->button('unpaid',31);
        $payload=DB::table('bot_outbox')->where('method','editMessageText')->orderByDesc('id')->value('payload');
        $this->assertStringContainsString('donate:'.$second.'"',$payload);
        $this->assertStringNotContainsString('donate:'.$first.'"',$payload);
        DB::table('occurrences')->where('id',$second)->update(['status'=>'excused']);
        $this->button('new',32);
        $this->assertSame('title',json_decode(DB::table('bot_profiles')->find(11)->dialog,true)['step']);
    }
}
