<?php

namespace Tests\Feature;

use App\Services\Bot;
use App\Services\CalendarLab;
use App\Services\UserData;
use Carbon\CarbonImmutable as Time;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalendarLabTest extends TestCase
{
    use RefreshDatabase;
    private array $remote=[];
    private int $revision=0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Time::parse('2026-10-30 10:00:00','UTC'));
        config(['calendar_lab.enabled'=>true,'calendar_lab.client_id'=>'test-client','calendar_lab.client_secret'=>'test-secret',
            'calendar_lab.redirect_uri'=>'https://example.org/calendar-lab/callback','app.url'=>'https://example.org']);
        foreach ([11,22] as $uid) {
            DB::table('bot_profiles')->insert(['id'=>$uid,'name'=>'Тест','timezone'=>'Europe/Kyiv','onboarded'=>true]);
            DB::table('bot_settings')->insert(['key'=>'navigation_keyboard_'.$uid,'value'=>'1']);
        }
        DB::table('bot_settings')->insert(['key'=>'admin_id','value'=>'11']);
        Http::preventStrayRequests();
    }

    private function connection(): void
    {
        DB::table('calendar_lab_connections')->insert(['profile_id'=>11,'access_token'=>Crypt::encryptString('test-access'),
            'refresh_token'=>Crypt::encryptString('test-refresh'),'expires_at'=>now()->addHour(),'calendar_id'=>'isolated-test-calendar','status'=>'active']);
    }

    private function fakeGoogle(): void
    {
        Http::fake(function ($r) {
            if ($r->url()==='https://oauth2.googleapis.com/token') return Http::response(['access_token'=>'refreshed-access','expires_in'=>3600]);
            $path=parse_url($r->url(),PHP_URL_PATH);
            if ($r->method()==='DELETE' && $r->body()!=='') return Http::response(['error'=>'DELETE must have no body'],400);
            if ($path==='/calendar/v3/calendars/isolated-test-calendar' && $r->method()==='DELETE') return Http::response('',204);
            if ($path==='/calendar/v3/calendars/isolated-test-calendar/events') {
                if ($r->method()==='GET') return Http::response(['items'=>array_values($this->remote)]);
                if ($r->method()==='POST') {
                    $event=$r->data();
                    if (isset($this->remote[$event['id']])) return Http::response([],409);
                    $event['etag']='"'.(++$this->revision).'"'; $event['status']='confirmed';
                    $this->remote[$event['id']]=$event;
                    return Http::response($event);
                }
            }
            $prefix='/calendar/v3/calendars/isolated-test-calendar/events/';
            if (str_starts_with($path,$prefix)) {
                $id=rawurldecode(substr($path,strlen($prefix)));
                if (!isset($this->remote[$id])) return Http::response([],404);
                if ($r->method()==='GET') return Http::response($this->remote[$id]);
                if ($r->method()==='PATCH') {
                    $this->remote[$id]=array_replace_recursive($this->remote[$id],$r->data());
                    $this->remote[$id]['etag']='"'.(++$this->revision).'"';
                    return Http::response($this->remote[$id]);
                }
                if ($r->method()==='DELETE') {
                    $this->remote[$id]['status']='cancelled'; $this->remote[$id]['etag']='"'.(++$this->revision).'"';
                    return Http::response('',204);
                }
            }
            throw new \RuntimeException('Unexpected destination in test.');
        });
    }

    private function task(): int
    {
        return DB::table('calendar_lab_tasks')->insertGetId(['profile_id'=>11,'title'=>'Лише тест','due_at'=>'2026-10-31 16:00:00','created_at'=>now(),'updated_at'=>now()]);
    }

    private function button(string $data, int $update, int $uid=11): void
    {
        app(Bot::class)->update(['update_id'=>$update,'callback_query'=>['id'=>(string)$update,'from'=>['id'=>$uid],
            'data'=>$data,'message'=>['message_id'=>1,'chat'=>['id'=>$uid,'type'=>'private']]]]);
    }

    public function test_lab_is_admin_only_and_creates_no_changes_without_configuration(): void
    {
        $this->button('lab',1,22); $this->button('lab:connect',2,22);
        $this->assertDatabaseCount('calendar_lab_links',0);
        $this->assertDatabaseCount('calendar_lab_connections',0);
        $this->assertSame(0,DB::table('bot_outbox')->where('method','sendMessage')->count());
        config(['calendar_lab.client_id'=>null]);
        $this->button('lab',3);
        $payload=json_decode(DB::table('bot_outbox')->where('method','editMessageText')->value('payload'),true);
        $this->assertStringContainsString('ще не активоване',$payload['text']);
        Http::assertNothingSent();
    }

    public function test_oauth_link_is_one_use_and_callback_is_bound_to_browser_state(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>'secret-access','refresh_token'=>'secret-refresh','expires_in'=>3600,'scope'=>CalendarLab::SCOPE]),
            'https://www.googleapis.com/calendar/v3/calendars'=>Http::response(['id'=>'new-test-calendar']),
        ]);
        $link=app(CalendarLab::class)->connectLink(11); $path=parse_url($link,PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('Основні плани',false);
        $r=$this->post($path);
        $r->assertRedirect(); $r->assertSessionHas('calendar_lab_oauth');
        $saved=session('calendar_lab_oauth');
        parse_str(parse_url($r->headers->get('Location'),PHP_URL_QUERY),$query);
        $this->assertSame(CalendarLab::SCOPE,$query['scope']);
        $this->assertSame('S256',$query['code_challenge_method']);
        $this->assertSame('offline',$query['access_type']);
        $this->post($path)->assertNotFound();
        $this->get('/calendar-lab/callback?state=wrong&code=unused')->assertStatus(419);
        $this->get('/calendar-lab/callback?state='.$saved['state'].'&code=test-code')->assertOk()->assertSee('Підключено');
        $c=DB::table('calendar_lab_connections')->where('profile_id',11)->first();
        $this->assertSame('active',$c->status);
        $this->assertNotSame('secret-access',$c->access_token);
        $this->assertSame('secret-refresh',Crypt::decryptString($c->refresh_token));
        $this->get('/calendar-lab/callback?state='.$saved['state'].'&code=test-code')->assertStatus(419);
        $this->assertStringNotContainsString('secret-refresh',app(UserData::class)->export(11));
    }

    public function test_google_created_events_import_only_into_sandbox_and_do_not_spam_chat(): void
    {
        $this->connection(); $this->fakeGoogle();
        $this->remote['from-google']=['id'=>'from-google','etag'=>'"external1"','summary'=>'Подія з iPhone','status'=>'confirmed',
            'start'=>['dateTime'=>'2026-10-31T10:30:00+02:00'],'end'=>['dateTime'=>'2026-10-31T11:30:00+02:00']];
        $this->remote['all-day']=['id'=>'all-day','etag'=>'"a"','summary'=>'Без години','start'=>['date'=>'2026-10-31']];
        app(CalendarLab::class)->sync(11); app(CalendarLab::class)->sync(11);
        $this->assertDatabaseCount('calendar_lab_tasks',1);
        $this->assertDatabaseHas('calendar_lab_tasks',['profile_id'=>11,'title'=>'Подія з iPhone','due_at'=>'2026-10-31 08:30:00','google_duration_seconds'=>3600]);
        $this->assertDatabaseCount('occurrences',0);
        $this->assertDatabaseCount('commitments',0);
        $this->assertDatabaseCount('bot_outbox',0);
        $this->assertDatabaseHas('bot_profiles',['id'=>11,'violations'=>0]);
    }

    public function test_bot_create_complete_and_google_move_sync_without_duplicates(): void
    {
        $this->connection(); $this->fakeGoogle(); $id=$this->task();
        $lab=app(CalendarLab::class); $lab->sync(11); $lab->sync(11);
        $this->assertCount(1,$this->remote);
        $task=DB::table('calendar_lab_tasks')->find($id);
        $this->assertSame('Лише тест',$this->remote[$task->google_event_id]['summary']);
        DB::table('calendar_lab_tasks')->where('id',$id)->update(['status'=>'done','updated_at'=>now()]);
        $lab->sync(11);
        $this->assertSame('✅ Виконано: Лише тест',$this->remote[$task->google_event_id]['summary']);
        $this->remote[$task->google_event_id]['start']['dateTime']='2026-11-01T12:15:00+02:00';
        $this->remote[$task->google_event_id]['end']['dateTime']='2026-11-01T12:45:00+02:00';
        $this->remote[$task->google_event_id]['etag']='"google-moved"';
        $lab->sync(11);
        $this->assertDatabaseHas('calendar_lab_tasks',['id'=>$id,'due_at'=>'2026-11-01 10:15:00','status'=>'done','title'=>'Лише тест','google_duration_seconds'=>1800]);
        $this->assertCount(1,$this->remote);
    }

    public function test_cancel_from_google_and_cleanup_never_touch_main_tasks(): void
    {
        $this->connection(); $this->fakeGoogle(); $id=$this->task();
        $real=DB::table('commitments')->insertGetId(['profile_id'=>11,'title'=>'Справжня задача','mandatory'=>true,'schedule'=>'{}']);
        DB::table('occurrences')->insert(['commitment_id'=>$real,'profile_id'=>11,'due_at'=>now()->addDay()]);
        $lab=app(CalendarLab::class); $lab->sync(11);
        $event=DB::table('calendar_lab_tasks')->find($id)->google_event_id;
        $this->remote[$event]['status']='cancelled'; $this->remote[$event]['etag']='"cancelled"';
        $lab->sync(11);
        $this->assertDatabaseHas('calendar_lab_tasks',['id'=>$id,'status'=>'cancelled']);
        DB::table('calendar_lab_connections')->where('profile_id',11)->update(['status'=>'deleting']);
        $lab->sync(11);
        $this->assertDatabaseCount('calendar_lab_tasks',0);
        $this->assertDatabaseCount('calendar_lab_connections',0);
        $this->assertDatabaseCount('occurrences',1);
        $this->assertDatabaseHas('occurrences',['commitment_id'=>$real,'status'=>'pending']);
        Http::assertSent(fn ($r) => $r->method()==='DELETE' && str_ends_with($r->url(),'/calendars/isolated-test-calendar'));
    }

    public function test_bot_cancellation_and_calendar_cleanup_send_empty_delete_bodies(): void
    {
        $this->connection(); $this->fakeGoogle(); $id=$this->task();
        $lab=app(CalendarLab::class); $lab->sync(11);
        $event=DB::table('calendar_lab_tasks')->find($id)->google_event_id;
        DB::table('calendar_lab_tasks')->where('id',$id)->update(['status'=>'cancelled']);
        $lab->sync(11);
        $this->assertSame('cancelled',$this->remote[$event]['status']);
        Http::assertSent(fn ($r) => $r->method()==='DELETE' && str_ends_with($r->url(),'/events/'.$event) && $r->body()==='' && $r->hasHeader('If-Match'));
        DB::table('calendar_lab_connections')->where('profile_id',11)->update(['status'=>'deleting']);
        $lab->sync(11);
        $this->assertDatabaseCount('calendar_lab_connections',0);
        Http::assertSent(fn ($r) => $r->method()==='DELETE' && str_ends_with($r->url(),'/calendars/isolated-test-calendar') && $r->body()==='');
    }

    public function test_refresh_token_and_owner_checks_are_enforced(): void
    {
        $this->connection(); $this->fakeGoogle();
        DB::table('calendar_lab_connections')->where('profile_id',11)->update(['expires_at'=>now()->subMinute()]);
        app(CalendarLab::class)->sync(22);
        Http::assertNothingSent();
        app(CalendarLab::class)->sync(11);
        $c=DB::table('calendar_lab_connections')->where('profile_id',11)->first();
        $this->assertSame('refreshed-access',Crypt::decryptString($c->access_token));
        $this->assertSame('test-refresh',Crypt::decryptString($c->refresh_token));
    }

    public function test_cleanup_requires_current_confirmation_and_account_deletion_clears_tokens(): void
    {
        $this->connection(); $this->task();
        $this->button('lab:remove:fake',201);
        $this->assertDatabaseHas('calendar_lab_connections',['profile_id'=>11,'status'=>'active']);
        $this->button('lab:removeask',202);
        $s=json_decode(DB::table('bot_profiles')->find(11)->dialog,true);
        $this->button('lab:remove:'.$s['token'],203,22);
        $this->assertDatabaseHas('calendar_lab_connections',['profile_id'=>11,'status'=>'active']);
        $this->button('lab',204);
        $this->button('lab:remove:'.$s['token'],205);
        $this->assertDatabaseHas('calendar_lab_connections',['profile_id'=>11,'status'=>'active']);
        app(UserData::class)->delete(11);
        $this->assertDatabaseCount('calendar_lab_connections',0);
        $this->assertDatabaseCount('calendar_lab_tasks',0);
        $this->assertDatabaseHas('bot_profiles',['id'=>22]);
        Http::assertNothingSent();
    }

    public function test_lost_insert_response_is_adopted_without_duplicate_tasks_or_events(): void
    {
        $this->connection(); $this->fakeGoogle(); $id=$this->task();
        $eventId='krok'.hash('sha256','isolated-test-calendar|'.$id);
        $this->remote[$eventId]=['id'=>$eventId,'etag'=>'"lost-response"','summary'=>'Стара назва','status'=>'confirmed',
            'start'=>['dateTime'=>'2026-10-31T16:00:00Z'],'end'=>['dateTime'=>'2026-10-31T16:01:00Z'],
            'extendedProperties'=>['private'=>['krok_lab_task'=>(string)$id]]];
        app(CalendarLab::class)->sync(11);
        $this->assertDatabaseCount('calendar_lab_tasks',1);
        $this->assertCount(1,$this->remote);
        $this->assertSame('Лише тест',$this->remote[$eventId]['summary']);
        $this->assertDatabaseHas('calendar_lab_tasks',['id'=>$id,'google_event_id'=>$eventId]);
    }

    public function test_simultaneous_edits_keep_explicit_bot_changes_in_the_test_only(): void
    {
        $this->connection(); $this->fakeGoogle(); $id=$this->task();
        $lab=app(CalendarLab::class); $lab->sync(11);
        $event=DB::table('calendar_lab_tasks')->find($id)->google_event_id;
        $this->remote[$event]['summary']='Назва з Google'; $this->remote[$event]['etag']='"changed-google"';
        DB::table('calendar_lab_tasks')->where('id',$id)->update(['title'=>'Назва з бота']);
        $lab->sync(11);
        $this->assertSame('Назва з бота',$this->remote[$event]['summary']);
        $this->assertDatabaseHas('calendar_lab_tasks',['id'=>$id,'title'=>'Назва з бота']);
        $this->assertDatabaseCount('occurrences',0);
    }

    public function test_feature_switch_hides_test_and_disables_old_connection_links(): void
    {
        $link=app(CalendarLab::class)->connectLink(11);
        config(['calendar_lab.enabled'=>false]);
        $this->button('home',601);
        $payload=DB::table('bot_outbox')->where('method','sendMessage')->orderByDesc('id')->value('payload');
        $this->assertStringNotContainsString('Тест Google Calendar',json_encode(json_decode($payload),JSON_UNESCAPED_UNICODE));
        $this->get(parse_url($link,PHP_URL_PATH))->assertNotFound();
        $this->button('lab:connect',602);
        Http::assertNothingSent();
    }
}
