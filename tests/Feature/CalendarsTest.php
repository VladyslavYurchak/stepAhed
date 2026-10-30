<?php

namespace Tests\Feature;

use App\Services\Bot;
use App\Services\Calendars;
use App\Services\UserData;
use Carbon\CarbonImmutable as Time;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CalendarsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Time::parse('2026-10-30 08:00:00','UTC'));
        config(['app.url'=>'https://stepahead.example']);
        foreach ([11,22] as $uid) {
            DB::table('bot_profiles')->insert(['id'=>$uid,'name'=>'User '.$uid,'timezone'=>'Europe/Kyiv','onboarded'=>true]);
            DB::table('bot_settings')->insert(['key'=>'navigation_keyboard_'.$uid,'value'=>'1']);
        }
    }

    private function token(string $url): string { return basename(parse_url($url,PHP_URL_PATH),'.ics'); }

    public function test_iphone_page_opens_subscription_and_stops_after_revocation(): void
    {
        $service=app(Calendars::class); $token=$this->token($service->link(11));
        $path='/calendar/apple/'.$token;
        $this->get($path)->assertOk()->assertHeader('Referrer-Policy','no-referrer')
            ->assertHeader('X-Robots-Tag','noindex, nofollow')
            ->assertSee('webcal://stepahead.example/calendar/feed/'.$token.'.ics?v=2',false)
            ->assertSee('Відкрити календар iPhone')->assertDontSee('User 11');
        $service->revoke(11);
        $this->get($path)->assertNotFound();
        $this->get('/calendar/apple/'.str_repeat('a',64))->assertNotFound();
    }

    public function test_iphone_button_keeps_existing_subscription_token(): void
    {
        $url=app(Calendars::class)->link(11);
        $this->button('calendarapple',800);
        $payload=json_decode(DB::table('bot_outbox')->where('method','sendMessage')->orderByDesc('id')->first()->payload,true);
        $this->assertArrayHasKey('inline_keyboard',$payload['reply_markup']);
        $this->assertSame('https://stepahead.example/calendar/apple/'.$this->token($url),$payload['reply_markup']['inline_keyboard'][0][0]['url']);
        $this->assertSame($url,app(Calendars::class)->link(11));
        app(Calendars::class)->revoke(11);
        $this->assertSame(0,DB::table('bot_outbox')->where('method','sendMessage')->count());
    }

    private function task(int $uid, string $title='Моя задача', string $status='pending'): int
    {
        $id=DB::table('commitments')->insertGetId(['profile_id'=>$uid,'title'=>$title,'mandatory'=>true,'schedule'=>'{}','created_at'=>now()]);
        return DB::table('occurrences')->insertGetId(['profile_id'=>$uid,'commitment_id'=>$id,'due_at'=>'2026-10-30 16:30:00',
            'status'=>$status,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function button(string $data, int $id, int $uid=11): void
    {
        app(Bot::class)->update(['update_id'=>$id,'callback_query'=>['id'=>(string)$id,'from'=>['id'=>$uid],
            'data'=>$data,'message'=>['message_id'=>1,'chat'=>['id'=>$uid,'type'=>'private']]]]);
    }

    public function test_private_subscription_contains_only_owner_events_and_no_photos_or_penalties(): void
    {
        $this->task(11,'Написати лист'); $this->task(22,'Чужа секретна задача');
        $service=app(Calendars::class);
        $url=$service->link(11); $token=$this->token($url);
        $this->assertSame($url,$service->link(11));
        $response=$this->get(parse_url($url,PHP_URL_PATH));
        $response->assertOk()->assertHeader('Content-Type','text/calendar; charset=utf-8');
        $response->assertHeader('X-Robots-Tag','noindex, nofollow');
        $response->assertSee('DTSTART:20261030T163000Z',false);
        $response->assertSee('DTEND:20261030T163100Z',false);
        $response->assertSee('Написати лист',false);
        $response->assertDontSee('CLASS:PRIVATE',false);
        $response->assertDontSee('Чужа секретна задача',false);
        $this->assertStringNotContainsString($token,DB::table('bot_calendar_subscriptions')->where('profile_id',11)->value('token_encrypted'));
        $export=app(UserData::class)->export(11);
        $this->assertStringNotContainsString($token,$export);
        $this->assertStringNotContainsString('token_encrypted',$export);
    }

    public function test_changed_completed_and_cancelled_events_keep_uid_and_update_feed(): void
    {
        $id=$this->task(11);
        $service=app(Calendars::class); $token=$this->token($service->link(11));
        $feed=$service->feed($token); preg_match('/UID:([^\r]+)(?:\r\n [^\r]+)?/',$feed,$uid);
        $this->travel(1)->minutes();
        DB::table('occurrences')->where('id',$id)->update(['due_at'=>'2026-10-30 18:00:00','status'=>'done','updated_at'=>now()]);
        $updated=$service->feed($token);
        $this->assertStringContainsString($uid[0],$updated);
        $this->assertStringContainsString('DTSTART:20261030T180000Z',$updated);
        $this->assertStringContainsString('Виконано:',str_replace("\r\n ",'',$updated));
        DB::table('occurrences')->where('id',$id)->update(['status'=>'cancelled']);
        $this->assertStringContainsString('STATUS:CANCELLED',$service->feed($token));
    }

    public function test_rotation_revocation_and_account_deletion_close_old_urls(): void
    {
        $service=app(Calendars::class);
        $first=$service->link(11); $second=$service->link(11,true);
        $this->get(parse_url($first,PHP_URL_PATH))->assertNotFound();
        $this->get(parse_url($second,PHP_URL_PATH))->assertOk();
        $service->revoke(11);
        $this->get(parse_url($second,PHP_URL_PATH))->assertNotFound();
        $third=$service->link(11);
        app(UserData::class)->delete(11);
        $this->get(parse_url($third,PHP_URL_PATH))->assertNotFound();
        $this->assertDatabaseHas('bot_profiles',['id'=>22]);
    }

    public function test_ics_text_is_escaped_utf8_folded_and_cannot_inject_another_event(): void
    {
        $this->task(11,str_repeat('ї',80).",;\\\r\nBEGIN:VEVENT\nATTENDEE:intruder@example.org");
        $service=app(Calendars::class); $feed=$service->feed($this->token($service->link(11)));
        $this->assertSame(1,substr_count($feed,"\r\nBEGIN:VEVENT\r\n"));
        foreach (explode("\r\n",$feed) as $line) {
            $this->assertLessThanOrEqual(75,strlen($line));
            $this->assertTrue(mb_check_encoding($line,'UTF-8'));
        }
        $unfolded=str_replace("\r\n ",'',$feed);
        $this->assertStringContainsString('\\,\\;\\\\\\nBEGIN:VEVENT\\nATTENDEE:',$unfolded);
    }

    public function test_old_rotation_confirmation_cannot_change_another_or_current_subscription(): void
    {
        $service=app(Calendars::class); $url=$service->link(11); $other=$service->link(22);
        $this->button('calendarrotateask',101);
        $this->button('calendarrotate:old-nonce',102,22);
        $this->assertSame($other,$service->link(22));
        $this->button('calendars',103);
        $this->button('calendarrotate:old-nonce',104);
        $this->assertSame($url,$service->link(11));
        $this->button('calendarrotateask',105);
        $this->button('calendarrotate:old-nonce',106);
        $this->button('calendaroff',107);
        $this->assertSame($url,$service->link(11));
    }
}
