<?php

namespace Tests\Feature;

use App\Services\Bot;
use App\Services\Funds;
use Carbon\CarbonImmutable as Time;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FundsTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp(); $this->travelTo(Time::parse('2026-10-29 12:00:00','UTC'));
        foreach ([11,99] as $uid) {
            DB::table('bot_profiles')->insert(['id'=>$uid,'name'=>'Тест','timezone'=>'Europe/Kyiv','onboarded'=>true,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('bot_settings')->insert(['key'=>'navigation_keyboard_'.$uid,'value'=>'1']);
        }
        DB::table('bot_settings')->insert([['key'=>'admin_id','value'=>'99'],['key'=>'moderation_chat','value'=>'-100']]);
    }
    private function data(): array
    {
        return ['title'=>'Тестовий публічний збір','recipient'=>'Тестова організація','purpose'=>'Допомога для тестування','url'=>'https://send.monobank.ua/jar/TestJar123','source'=>'https://example.org/official-fund'];
    }
    private function fund(bool $active=true): int
    {
        return DB::transaction(fn()=>app(Funds::class)->createFund($active?99:11,$this->data(),$active));
    }
    private function occurrence(): int
    {
        $id=DB::table('commitments')->insertGetId(['profile_id'=>11,'title'=>'30 віджимань','mandatory'=>true,'photo_required'=>false,'schedule'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        return DB::table('occurrences')->insertGetId(['commitment_id'=>$id,'profile_id'=>11,'due_at'=>now()->subHour(),'status'=>'missed','penalty_amount'=>20,'created_at'=>now(),'updated_at'=>now()]);
    }
    private function button(string $data,int $update,int $uid=11,int $chat=0): void
    {
        $chat=$chat?:$uid;
        app(Bot::class)->update(['update_id'=>$update,'callback_query'=>['id'=>(string)$update,'from'=>['id'=>$uid],'data'=>$data,'message'=>['message_id'=>1,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']]]]);
    }
    private function text(string $text,int $id,int $uid=99): void
    {
        app(Bot::class)->update(['update_id'=>$id,'message'=>['message_id'=>$id,'chat'=>['id'=>$uid,'type'=>'private'],'from'=>['id'=>$uid],'text'=>$text]]);
    }
    private function upload(int $donation,int $update=501,int $uid=11,string $unique='photo-one'): void
    {
        $this->button('receipt:'.$donation,$update,$uid);
        app(Bot::class)->update(['update_id'=>$update+1,'message'=>['message_id'=>$update+1,'chat'=>['id'=>$uid,'type'=>'private'],'from'=>['id'=>$uid],'photo'=>[['file_id'=>'photo-file-id','file_unique_id'=>$unique]]]]);
    }

    public function test_only_admin_sees_and_can_use_admin_menu(): void
    {
        $this->text('/menu',101,11); $this->text('/menu',102,99);
        $messages=DB::table('bot_outbox')->where('method','sendMessage')->get()->map(fn($m)=>json_decode($m->payload,true));
        $this->assertStringNotContainsString('a:menu',json_encode($messages[0]));
        $this->assertStringContainsString('a:menu',json_encode($messages[1]));
        $this->button('a:add',103,11);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'dialog'=>null]);
        $this->assertDatabaseCount('charity_funds',0);
    }

    public function test_admin_add_wizard_publishes_only_after_confirmation(): void
    {
        $this->button('a:add',101,99);
        $i=102; foreach($this->data() as $value) $this->text($value,$i++);
        $this->assertDatabaseCount('charity_funds',0);
        $this->button('a:create',110,99); $this->button('a:create',111,99);
        $this->assertDatabaseCount('charity_funds',1);
        $this->assertDatabaseHas('charity_funds',['status'=>'active','reviewed_by'=>99]);
    }

    public function test_proposal_is_not_public_before_approval(): void
    {
        $this->button('proposefund',101);
        $i=102; foreach($this->data() as $value) $this->text($value,$i++,11);
        $this->button('fundsubmit',110);
        $fund=DB::table('charity_funds')->first();
        $this->assertSame('pending',$fund->status);
        $this->button('funds',111);
        $latest=json_decode(DB::table('bot_outbox')->where('method','editMessageText')->orderByDesc('id')->first()->payload,true);
        $this->assertStringNotContainsString($fund->title,$latest['text']);
        $this->assertStringNotContainsString('fundview:',json_encode($latest));
        $this->button('a:publish:'.$fund->id,112,99);
        $prompt=json_decode(DB::table('bot_outbox')->where('method','editMessageText')->orderByDesc('id')->first()->payload,true);
        $this->button($prompt['reply_markup']['inline_keyboard'][0][0]['callback_data'],113,99);
        $this->assertDatabaseHas('charity_funds',['id'=>$fund->id,'status'=>'active']);
    }

    public function test_fund_links_only_accept_exact_https_monobank_jar_host(): void
    {
        $service=app(Funds::class);
        foreach(['http://send.monobank.ua/jar/TestJar123','https://send.monobank.ua.evil.test/jar/TestJar123','https://evil.test/','javascript:alert(1)','https://send.monobank.ua@evil.test/jar/TestJar123'] as $url) $this->assertFalse($service->validateField('url',$url));
        $this->assertTrue($service->validateField('url',$this->data()['url']));
        $this->assertFalse($service->validateField('source','-',true));
        $this->assertFalse($service->validateField('source','https://user@example.org/official',true));
    }

    public function test_non_admin_cannot_create_or_review_even_by_forged_action(): void
    {
        $id=$this->fund(false); $this->button('a:status:'.$id.',active,anything',101,11);
        $this->assertDatabaseHas('charity_funds',['id'=>$id,'status'=>'pending']);
        $this->expectException(\DomainException::class);
        app(Funds::class)->createFund(11,$this->data(),true);
    }

    public function test_edit_does_not_change_existing_donation_snapshot(): void
    {
        $id=$this->fund(); $occ=$this->occurrence();
        $d=DB::transaction(fn()=>app(Funds::class)->selectFund(11,$occ,$id));
        $this->button('a:field:'.$id.',recipient',101,99); $this->text('Нова організація',102); $this->button('a:editsave:'.$id,103,99);
        $this->assertDatabaseHas('charity_funds',['id'=>$id,'recipient'=>'Нова організація']);
        $this->assertSame('Тестова організація',json_decode(DB::table('donations')->find($d->id)->fund_snapshot,true)['recipient']);
    }

    public function test_archived_fund_cannot_be_selected_but_history_is_preserved(): void
    {
        $id=$this->fund(); $occ=$this->occurrence();
        $d=DB::transaction(fn()=>app(Funds::class)->selectFund(11,$occ,$id));
        $this->button('a:archive:'.$id,101,99);
        $prompt=json_decode(DB::table('bot_outbox')->where('method','editMessageText')->orderByDesc('id')->first()->payload,true);
        $this->button($prompt['reply_markup']['inline_keyboard'][0][0]['callback_data'],102,99);
        $this->assertDatabaseHas('charity_funds',['id'=>$id,'status'=>'archived']);
        $this->assertDatabaseHas('donations',['id'=>$d->id,'fund_id'=>$id]);
        $this->expectException(\DomainException::class);
        DB::transaction(fn()=>app(Funds::class)->selectFund(11,$occ,$id));
    }

    public function test_stale_publication_confirmation_cannot_republish_changed_fund(): void
    {
        $id=$this->fund(false); $this->button('a:publish:'.$id,101,99);
        $prompt=json_decode(DB::table('bot_outbox')->where('method','editMessageText')->orderByDesc('id')->first()->payload,true);
        DB::table('charity_funds')->where('id',$id)->update(['recipient'=>'Інший отримувач']);
        $this->button($prompt['reply_markup']['inline_keyboard'][0][0]['callback_data'],102,99);
        $this->assertDatabaseHas('charity_funds',['id'=>$id,'status'=>'pending']);
    }

    public function test_receipt_owner_and_admin_review_are_enforced_and_idempotent(): void
    {
        $fund=$this->fund(); $occ=$this->occurrence();
        $d=DB::transaction(fn()=>app(Funds::class)->selectFund(11,$occ,$fund));
        $this->upload($d->id,101,99);
        $this->assertDatabaseCount('donation_receipts',0);
        $this->upload($d->id,103);
        $receipt=DB::table('donation_receipts')->first();
        $this->assertSame('pending',$receipt->status);
        $this->button('a:receiptapprove:'.$receipt->id,105,11);
        $this->assertDatabaseHas('donation_receipts',['id'=>$receipt->id,'status'=>'pending']);
        $this->button('a:receiptapprove:'.$receipt->id,106,99,-100);
        $this->button('a:receiptapprove:'.$receipt->id,107,99,-100);
        $this->assertDatabaseHas('donations',['id'=>$d->id,'status'=>'approved']);
        $this->assertDatabaseHas('occurrences',['id'=>$occ,'status'=>'missed','penalty_amount'=>20]);
        $this->assertDatabaseHas('donation_receipts',['id'=>$receipt->id,'reviewed_by'=>99]);
    }

    public function test_old_rejected_receipt_cannot_approve_new_submission(): void
    {
        $fund=$this->fund(); $occ=$this->occurrence();
        $d=DB::transaction(fn()=>app(Funds::class)->selectFund(11,$occ,$fund));
        $this->upload($d->id,101); $first=DB::table('donation_receipts')->first();
        $this->button('a:receiptreason:'.$first->id.',wrong',103,99,-100);
        $this->assertDatabaseHas('donations',['id'=>$d->id,'status'=>'rejected']);
        $this->upload($d->id,104,11,'photo-two');
        $this->button('a:receiptapprove:'.$first->id,106,99,-100);
        $this->assertDatabaseHas('donations',['id'=>$d->id,'status'=>'pending']);
        $this->assertSame(1,DB::table('donation_receipts')->where('status','pending')->count());
    }

    public function test_receipt_for_cancelled_penalty_is_not_approved(): void
    {
        $fund=$this->fund(); $occ=$this->occurrence();
        $d=DB::transaction(fn()=>app(Funds::class)->selectFund(11,$occ,$fund));
        $this->upload($d->id,101); $r=DB::table('donation_receipts')->first();
        DB::table('occurrences')->where('id',$occ)->update(['status'=>'excused','penalty_amount'=>0]);
        $this->button('a:receiptapprove:'.$r->id,103,99,-100);
        $this->assertDatabaseHas('donations',['id'=>$d->id,'status'=>'void']);
    }

    public function test_admin_callbacks_from_another_group_are_rejected(): void
    {
        $id=$this->fund(false);
        $this->button('a:view:'.$id,101,99,-200);
        $this->assertDatabaseHas('charity_funds',['id'=>$id,'status'=>'pending']);
        $messages=DB::table('bot_outbox')->where('method','sendMessage')->get();
        $this->assertStringNotContainsString($this->data()['recipient'],$messages->last()->payload);
    }

    public function test_navigation_cancels_admin_draft_without_publishing(): void
    {
        $this->button('a:add',101,99); $this->text('Новий збір',102);
        $this->text('Повернутися в меню',103);
        $this->button('a:create',104,99);
        $this->assertDatabaseCount('charity_funds',0);
        $this->assertDatabaseHas('bot_profiles',['id'=>99,'dialog'=>null]);
    }

    public function test_owner_only_donation_history_and_receipt_history_do_not_mutate_status(): void
    {
        $fund=$this->fund(); $occ=$this->occurrence();
        $d=DB::transaction(fn()=>app(Funds::class)->selectFund(11,$occ,$fund));
        $this->upload($d->id,101); $r=DB::table('donation_receipts')->first();
        $this->button('a:receiptapprove:'.$r->id,103,99,-100);
        $this->button('donationview:'.$d->id,104,99);
        $this->button('a:receipts:history,0',105,99);
        $payload=json_decode(DB::table('bot_outbox')->where('method','editMessageText')->orderByDesc('id')->first()->payload,true);
        $this->assertStringContainsString('Історія квитанцій: 1',$payload['text']);
        $this->assertDatabaseHas('donations',['id'=>$d->id,'status'=>'approved']);
    }

    public function test_active_fund_order_can_be_changed_without_deleting_records(): void
    {
        $first=$this->fund(); $second=$this->fund();
        $this->button('a:move:'.$second.',up',101,99);
        $ordered=DB::table('charity_funds')->orderBy('sort_order')->pluck('id')->all();
        $this->assertSame([$second,$first],$ordered);
        $this->assertDatabaseCount('charity_funds',2);
    }
}
