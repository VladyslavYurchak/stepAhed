<?php

namespace Tests\Feature;

use App\Services\Bot;
use App\Services\Telegram;
use App\Services\UserData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([11,22] as $uid) {
            DB::table('bot_profiles')->insert(['id'=>$uid,'name'=>'User '.$uid,'timezone'=>'Europe/Kyiv','onboarded'=>true]);
            DB::table('bot_settings')->insert(['key'=>'navigation_keyboard_'.$uid,'value'=>'1']);
            $task = DB::table('commitments')->insertGetId(['profile_id'=>$uid,'title'=>'Private task '.$uid,'mandatory'=>true,'schedule'=>'{}']);
            $occurrence = DB::table('occurrences')->insertGetId(['commitment_id'=>$task,'profile_id'=>$uid,'due_at'=>now()->addDay()]);
            $fund = DB::table('charity_funds')->insertGetId(['title'=>'Public fund','recipient'=>'Org','purpose'=>'Help','url'=>'https://example.org','status'=>'approved','proposed_by'=>$uid]);
            $donation = DB::table('donations')->insertGetId(['occurrence_id'=>$occurrence,'profile_id'=>$uid,'fund_id'=>$fund,'fund_snapshot'=>'{}','expected_amount'=>20]);
            DB::table('donation_receipts')->insert(['donation_id'=>$donation,'profile_id'=>$uid,'fund_snapshot'=>'{}','expected_amount'=>20,'file_id'=>'photo-'.$uid,'file_unique_id'=>'unique-'.$uid]);
            DB::table('bot_pauses')->insert(['profile_id'=>$uid,'starts_at'=>now(),'ends_at'=>now()->addDay(),'status'=>'pending','reason'=>'Private reason '.$uid]);
            app(Telegram::class)->send($uid,'Own message');
            app(Telegram::class)->send(-100,'Moderation message',[],null,$uid);
        }
        DB::table('bot_settings')->insert(['key'=>'admin_id','value'=>'11']);
    }

    private function button(string $data, int $id, int $uid=11): void
    {
        app(Bot::class)->update(['update_id'=>$id,'callback_query'=>['id'=>(string)$id,'from'=>['id'=>$uid],
            'data'=>$data,'message'=>['message_id'=>50,'chat'=>['id'=>$uid,'type'=>'private']]]]);
    }

    public function test_export_contains_only_owner_records_and_no_service_settings(): void
    {
        $export = app(UserData::class)->export(11);
        $data = json_decode($export,true);
        $this->assertSame(11,$data['profile']['id']);
        foreach (['commitments','occurrences','bot_pauses','donations','donation_receipts'] as $table) {
            $this->assertCount(1,$data[$table]);
            $this->assertSame(11,$data[$table][0]['profile_id']);
        }
        $this->assertStringNotContainsString('Private task 22',$export);
        $this->assertArrayNotHasKey('bot_settings',$data);
        $this->assertArrayNotHasKey('dialog',$data['profile']);
        $this->button('dataexport',101);
        $payload = json_decode(DB::table('bot_outbox')->where('method','sendDocument')->value('payload'),true);
        $this->assertSame(11,$payload['chat_id']);
        $this->assertSame(11,json_decode($payload['document_json'],true)['profile']['id']);
    }

    public function test_delete_removes_owned_data_and_preserves_other_accounts_shared_funds_and_admin_binding(): void
    {
        app(UserData::class)->delete(11);
        $this->assertDatabaseMissing('bot_profiles',['id'=>11]);
        $this->assertDatabaseHas('bot_profiles',['id'=>22]);
        foreach (['commitments','occurrences','bot_pauses','donations','donation_receipts'] as $table) {
            $this->assertSame(0,DB::table($table)->where('profile_id',11)->count());
            $this->assertSame(1,DB::table($table)->where('profile_id',22)->count());
        }
        $this->assertSame(0,DB::table('bot_outbox')->where('data_owner_id',11)->count());
        $this->assertSame(2,DB::table('bot_outbox')->where('data_owner_id',22)->count());
        $this->assertSame(2,DB::table('charity_funds')->count());
        $this->assertSame(1,DB::table('charity_funds')->whereNull('proposed_by')->count());
        $this->assertSame('11',DB::table('bot_settings')->where('key','admin_id')->value('value'));
        $this->assertDatabaseMissing('bot_settings',['key'=>'navigation_keyboard_11']);
    }

    public function test_deletion_requires_current_confirmation_and_old_buttons_do_not_recreate_profile(): void
    {
        $this->button('dataeraseconfirm:fake',201);
        $this->assertDatabaseHas('bot_profiles',['id'=>11]);
        $this->button('dataerase',202);
        $s=json_decode(DB::table('bot_profiles')->where('id',11)->value('dialog'),true);
        $this->button('dataeraseconfirm:'.$s['token'],203,22);
        $this->assertDatabaseHas('bot_profiles',['id'=>22]);
        $this->button('privacy',204);
        $this->button('dataeraseconfirm:'.$s['token'],205);
        $this->assertDatabaseHas('bot_profiles',['id'=>11]);
        $this->button('dataerase',206);
        $s=json_decode(DB::table('bot_profiles')->where('id',11)->value('dialog'),true);
        $this->button('dataeraseconfirm:'.$s['token'],207);
        $this->assertDatabaseMissing('bot_profiles',['id'=>11]);
        $this->button('dataeraseconfirm:'.$s['token'],208);
        $this->assertDatabaseMissing('bot_profiles',['id'=>11]);
    }

    public function test_expired_confirmation_and_busy_delivery_do_not_delete_data(): void
    {
        $this->button('dataerase',301);
        $s=json_decode(DB::table('bot_profiles')->where('id',11)->value('dialog'),true);
        $this->travel(11)->minutes();
        $this->button('dataeraseconfirm:'.$s['token'],302);
        $this->assertDatabaseHas('bot_profiles',['id'=>11]);
        $lock=Cache::lock('telegram-outbox',120);
        $lock->get();
        try {
            app(UserData::class)->delete(11);
            $this->fail('Busy delivery must block deletion.');
        } catch (\DomainException) {
            $this->assertDatabaseHas('bot_profiles',['id'=>11]);
        } finally { $lock->release(); }
    }

    public function test_json_export_is_sent_as_document_attachment(): void
    {
        Http::fake(['api.telegram.org/*'=>Http::response(['ok'=>true,'result'=>['message_id'=>1]])]);
        app(Telegram::class)->call('sendDocument',['chat_id'=>11,'document_json'=>'{"profile":11}'],'fake-token');
        Http::assertSent(fn ($request) => str_contains($request->body(),'krok-vpered-data.json')
            && str_contains($request->body(),'{"profile":11}') && !str_contains($request->body(),'name="document_json"'));
    }
}
