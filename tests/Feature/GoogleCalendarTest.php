<?php

namespace Tests\Feature;

use App\Services\GoogleCalendar;
use App\Services\Bot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_tasks_completion_and_cancellation_are_sent_without_importing_google_changes(): void
    {
        config(['calendar_lab.enabled'=>true,'calendar_lab.main_enabled'=>true,'calendar_lab.client_id'=>'id','calendar_lab.client_secret'=>'secret']);
        DB::table('bot_profiles')->insert(['id'=>11,'name'=>'Owner','timezone'=>'Europe/Kyiv']);
        DB::table('bot_settings')->insert(['key'=>'admin_id','value'=>'11']);
        DB::table('calendar_lab_connections')->insert(['profile_id'=>11,'sync_mode'=>'main','status'=>'active','calendar_id'=>'main-calendar','access_token'=>Crypt::encryptString('token'),'refresh_token'=>Crypt::encryptString('refresh'),'expires_at'=>now()->addHour()]);
        $c=DB::table('commitments')->insertGetId(['profile_id'=>11,'title'=>'Справжня задача','mandatory'=>true,'schedule'=>'{}']);
        $id=DB::table('occurrences')->insertGetId(['profile_id'=>11,'commitment_id'=>$c,'due_at'=>now()->addDay(),'status'=>'pending']);
        $other=DB::table('commitments')->insertGetId(['profile_id'=>22,'title'=>'Чужа задача','mandatory'=>false,'schedule'=>'{}']);
        DB::table('occurrences')->insert(['profile_id'=>22,'commitment_id'=>$other,'due_at'=>now()->addDay()]);
        $events=[];
        Http::preventStrayRequests();
        Http::fake(function ($r) use (&$events) {
            $event=basename(parse_url($r->url(),PHP_URL_PATH));
            if ($r->method()==='POST') { $events[$r['id']]=$r->data(); return Http::response($r->data()+['etag'=>'"v1"']); }
            if ($r->method()==='GET') return isset($events[$event]) ? Http::response($events[$event]+['etag'=>'"v2"']) : Http::response([],404);
            if ($r->method()==='PATCH') { $events[$event]=$r->data(); return Http::response($r->data()+['etag'=>'"v3"']); }
            if ($r->method()==='DELETE') { $this->assertSame('',$r->body()); unset($events[$event]); return Http::response('',204); }
            throw new \RuntimeException('Unexpected request');
        });
        $service=app(GoogleCalendar::class); $service->run();
        $this->assertCount(1,$events);
        $this->assertSame('Дедлайн: Справжня задача',array_values($events)[0]['summary']);
        DB::table('occurrences')->where('id',$id)->update(['status'=>'done']); $service->run();
        $this->assertSame('✅ Виконано: Справжня задача',array_values($events)[0]['summary']);
        $events[array_key_first($events)]['summary']='Зміна лише у Google'; $service->run();
        $this->assertDatabaseHas('occurrences',['id'=>$id,'status'=>'done']);
        $this->assertDatabaseHas('commitments',['id'=>$c,'title'=>'Справжня задача']);
        $deletedId=array_key_first($events); unset($events[$deletedId]);
        DB::table('occurrences')->where('id',$id)->update(['status'=>'rejected']); $service->run();
        $this->assertCount(1,$events);
        $this->assertNotSame($deletedId,array_key_first($events));
        DB::table('occurrences')->where('id',$id)->update(['status'=>'cancelled']); $service->run();
        $this->assertCount(0,$events);
        app(\App\Services\UserData::class)->delete(11);
        $this->assertDatabaseCount('google_calendar_events',0);
    }

    public function test_calendar_menu_hides_legacy_link_controls(): void
    {
        DB::table('bot_profiles')->insert(['id'=>11,'name'=>'Owner','onboarded'=>true,'timezone'=>'Europe/Kyiv']);
        app(Bot::class)->update(['update_id'=>991,'callback_query'=>['id'=>'991','from'=>['id'=>11],'data'=>'calendars','message'=>['message_id'=>1,'chat'=>['id'=>11,'type'=>'private']]]]);
        $payload=DB::table('bot_outbox')->where('method','editMessageText')->value('payload');
        $this->assertStringContainsString('googlecalendar',$payload);
        $this->assertStringNotContainsString('calendarrotate',$payload);
        $this->assertStringNotContainsString('calendaroff',$payload);
        $this->assertStringNotContainsString('calendarlink',$payload);
    }
}
