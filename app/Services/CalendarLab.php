<?php

namespace App\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CalendarLab
{
    public const SCOPE='https://www.googleapis.com/auth/calendar.app.created';

    public function configured(): bool
    {
        return (bool)(config('calendar_lab.enabled') && config('calendar_lab.client_id') && config('calendar_lab.client_secret'));
    }

    public function allowed(int $uid): bool
    {
        return (bool)config('calendar_lab.enabled') && app(Funds::class)->isAdmin($uid);
    }

    public function connectLink(int $uid): string
    {
        if (!$this->allowed($uid) || !$this->configured()) throw new \DomainException('Google-підключення ще готується.');
        $token=bin2hex(random_bytes(32));
        DB::table('calendar_lab_links')->where('profile_id',$uid)->delete();
        DB::table('calendar_lab_links')->insert(['hash'=>hash('sha256',$token),'profile_id'=>$uid,'expires_at'=>now()->addMinutes(10)]);
        return rtrim(config('app.url'),'/').'/calendar-lab/connect/'.$token;
    }

    public function linkOwner(string $token): ?int
    {
        if (!$this->configured() || !preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
        $uid=DB::table('calendar_lab_links')->where('hash',hash('sha256',$token))->where('expires_at','>',now())->value('profile_id');
        return $uid && $this->allowed((int)$uid) && DB::table('bot_profiles')->where('id',$uid)->exists() ? (int)$uid : null;
    }

    public function consumeLink(string $token): ?int
    {
        return DB::transaction(function () use ($token) {
            $uid=$this->linkOwner($token);
            if (!$uid) return null;
            return DB::table('calendar_lab_links')->where('hash',hash('sha256',$token))->delete()===1 ? $uid : null;
        });
    }

    public function authorizeUrl(string $state, string $verifier): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'=>config('calendar_lab.client_id'),'redirect_uri'=>config('calendar_lab.redirect_uri'),
            'response_type'=>'code','scope'=>self::SCOPE,'state'=>$state,'access_type'=>'offline',
            'prompt'=>'consent select_account','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),
            'code_challenge_method'=>'S256',
        ],'', '&', PHP_QUERY_RFC3986);
    }

    public function complete(int $uid, string $code, string $verifier): void
    {
        if (!$this->allowed($uid) || !$this->configured()) throw new \RuntimeException('Connection unavailable.');
        if (DB::table('calendar_lab_connections')->where('profile_id',$uid)->whereNotNull('calendar_id')->exists()) {
            throw new \RuntimeException('Test calendar is already connected.');
        }
        try {
            $response=Http::asForm()->connectTimeout(4)->timeout(12)->post('https://oauth2.googleapis.com/token',[
                'client_id'=>config('calendar_lab.client_id'),'client_secret'=>config('calendar_lab.client_secret'),
                'code'=>$code,'code_verifier'=>$verifier,'grant_type'=>'authorization_code','redirect_uri'=>config('calendar_lab.redirect_uri'),
            ]);
            $body=$response->json();
            $scopes=explode(' ',$body['scope'] ?? '');
            if (!$response->successful() || !is_array($body) || empty($body['access_token']) || empty($body['refresh_token']) || !in_array(self::SCOPE,$scopes,true)) {
                throw new \RuntimeException('Consent incomplete.');
            }
            DB::table('calendar_lab_connections')->updateOrInsert(['profile_id'=>$uid],[
                'access_token'=>Crypt::encryptString($body['access_token']),'refresh_token'=>Crypt::encryptString($body['refresh_token']),
                'expires_at'=>now()->addSeconds((int)($body['expires_in'] ?? 3600)),'status'=>'connecting','error'=>null,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            // Calendar creation has no idempotency key. Do not blindly retry it after an uncertain network response.
            $calendar=$this->api($uid,'POST','/calendars',[
                'summary'=>'Крок вперед · ТЕСТ 🧪','timeZone'=>DB::table('bot_profiles')->where('id',$uid)->value('timezone'),
                'description'=>'Окремий тест синхронізації. Основні задачі бота не змінюються.',
            ]);
            if (!$calendar->successful() || !is_string($calendar->json('id')) || $calendar->json('id')==='primary') throw new \RuntimeException('Calendar creation failed.');
            DB::table('calendar_lab_connections')->where('profile_id',$uid)->update(['calendar_id'=>$calendar->json('id'),'status'=>'active']);
        } catch (\Throwable) {
            DB::table('calendar_lab_connections')->where('profile_id',$uid)->update(['status'=>'error','error'=>'connect_failed']);
            throw new \RuntimeException('Google connection did not finish.');
        }
    }

    private function api(int $uid, string $method, string $path, array $data=[], ?string $etag=null)
    {
        $c=DB::table('calendar_lab_connections')->where('profile_id',$uid)->first();
        if (!$c || !$c->access_token) throw new \RuntimeException('Not connected.');
        if (!$c->expires_at || Time::parse($c->expires_at,'UTC')->lessThanOrEqualTo(Time::now()->addMinute())) {
            $r=Http::asForm()->connectTimeout(4)->timeout(12)->post('https://oauth2.googleapis.com/token',[
                'client_id'=>config('calendar_lab.client_id'),'client_secret'=>config('calendar_lab.client_secret'),
                'grant_type'=>'refresh_token','refresh_token'=>Crypt::decryptString($c->refresh_token),
            ]);
            if (!$r->successful() || !is_string($r->json('access_token'))) throw new \RuntimeException('Authorization expired.');
            $changes=['access_token'=>Crypt::encryptString($r->json('access_token')),'expires_at'=>now()->addSeconds((int)$r->json('expires_in',3600))];
            if ($r->json('refresh_token')) $changes['refresh_token']=Crypt::encryptString($r->json('refresh_token'));
            DB::table('calendar_lab_connections')->where('profile_id',$uid)->update($changes);
            $c->access_token=$changes['access_token'];
        }
        $request=Http::withToken(Crypt::decryptString($c->access_token))->acceptJson()->connectTimeout(4)->timeout(12);
        if ($etag) $request=$request->withHeaders(['If-Match'=>$etag]);
        $url='https://www.googleapis.com/calendar/v3'.$path;
        return $method==='GET' ? $request->get($url,$data) : $request->send($method,$url,['json'=>$data]);
    }

    public function fingerprint(object $task): string
    {
        return hash('sha256',json_encode([$task->title,$task->due_at,$task->status]));
    }

    private function eventBody(object $task): array
    {
        $due=Time::parse($task->due_at,'UTC');
        return ['summary'=>($task->status==='done' ? '✅ Виконано: ' : '').$task->title,
            'description'=>'Тестова задача. Зміни не впливають на основні плани. Час події — дедлайн.',
            'start'=>['dateTime'=>$due->toIso8601String()],'end'=>['dateTime'=>$due->addSeconds($task->google_duration_seconds)->toIso8601String()],
            'transparency'=>'transparent','reminders'=>['useDefault'=>false],
            'extendedProperties'=>['private'=>['krok_lab_task'=>(string)$task->id]],
        ];
    }

    public function run(): void
    {
        if (!$this->configured()) return;
        DB::table('calendar_lab_links')->where('expires_at','<=',now())->delete();
        foreach (DB::table('calendar_lab_connections')->whereIn('status',['active','deleting'])->pluck('profile_id') as $uid) {
            if (!$this->allowed($uid)) continue;
            Cache::lock('calendar-lab-'.$uid,120)->get(function () use ($uid) {
                try { $this->sync((int)$uid); }
                catch (\Throwable) {
                    DB::table('calendar_lab_connections')->where('profile_id',$uid)->update(['error'=>'sync_failed']);
                }
            });
        }
    }

    public function sync(int $uid): void
    {
        if (!$this->allowed($uid)) return;
        $c=DB::table('calendar_lab_connections')->where('profile_id',$uid)->first();
        if (!$c || !$c->calendar_id || $c->calendar_id==='primary') return;
        $base='/calendars/'.rawurlencode($c->calendar_id);
        if ($c->status==='deleting') {
            $r=$this->api($uid,'DELETE',$base);
            if (!$r->successful() && !in_array($r->status(),[404,410],true)) throw new \RuntimeException('Cleanup failed.');
            $this->forget($uid);
            app(Telegram::class)->send($uid,'Тестовий календар і тестові записи видалені. Основні задачі залишилися без змін.');
            return;
        }
        if ($c->status!=='active') return;
        $events=[]; $page=null;
        // A bounded full pull is sufficient for the single-admin pilot and avoids missed changes after cursor expiry.
        for ($i=0;$i<10;$i++) {
            $query=['singleEvents'=>'true','showDeleted'=>'true','maxResults'=>250,
                'timeMin'=>Time::now()->subDays(30)->toIso8601String(),'timeMax'=>Time::now()->addDays(90)->toIso8601String()];
            if ($page) $query['pageToken']=$page;
            $r=$this->api($uid,'GET',$base.'/events',$query);
            if (!$r->successful()) throw new \RuntimeException('Calendar read failed.');
            $events=array_merge($events,$r->json('items',[])); $page=$r->json('nextPageToken');
            if (!$page) break;
        }
        if ($page) throw new \RuntimeException('Test calendar is too large.');
        foreach ($events as $event) $this->pull($uid,$event);
        $started=microtime(true); $writes=0;
        foreach (DB::table('calendar_lab_tasks')->where('profile_id',$uid)->orderBy('id')->get() as $task) {
            if ($writes>=15 || microtime(true)-$started>20) break;
            $fingerprint=$this->fingerprint($task);
            if ($task->synced_fingerprint===$fingerprint) continue;
            $eventId=$task->google_event_id;
            if (!$eventId && $task->status==='cancelled') {
                DB::table('calendar_lab_tasks')->where('id',$task->id)->update(['synced_fingerprint'=>$fingerprint]); continue;
            }
            if ($eventId && $task->status==='cancelled') {
                $r=$this->api($uid,'DELETE',$base.'/events/'.rawurlencode($eventId),[],$task->google_etag);
                if (!$r->successful() && !in_array($r->status(),[404,410],true)) throw new \RuntimeException('Event delete failed.');
            } else {
                $body=$this->eventBody($task);
                if (!$eventId) {
                    $eventId='krok'.hash('sha256',$c->calendar_id.'|'.$task->id);
                    $body['id']=$eventId;
                    $r=$this->api($uid,'POST',$base.'/events',$body);
                    if ($r->status()===409) {
                        $r=$this->api($uid,'GET',$base.'/events/'.rawurlencode($eventId));
                        if ($r->json('extendedProperties.private.krok_lab_task')!==(string)$task->id) throw new \RuntimeException('Event ID conflict.');
                        $r=$this->api($uid,'PATCH',$base.'/events/'.rawurlencode($eventId),$this->eventBody($task),$r->json('etag'));
                    }
                } else $r=$this->api($uid,'PATCH',$base.'/events/'.rawurlencode($eventId),$body,$task->google_etag);
                if ($r->status()===412) continue; // Concurrent Google edit: re-read on the next pass.
                if (!$r->successful()) throw new \RuntimeException('Event write failed.');
            }
            DB::table('calendar_lab_tasks')->where('id',$task->id)->update([
                'google_event_id'=>$eventId,'google_etag'=>$r->json('etag'),'synced_fingerprint'=>$fingerprint,
            ]);
            $writes++;
        }
        DB::table('calendar_lab_connections')->where('profile_id',$uid)->update(['last_synced_at'=>now(),'error'=>null]);
    }

    private function pull(int $uid, array $event): void
    {
        if (!is_string($event['id'] ?? null)) return;
        $task=DB::table('calendar_lab_tasks')->where('profile_id',$uid)->where('google_event_id',$event['id'])->first();
        if (!$task && ctype_digit((string)($event['extendedProperties']['private']['krok_lab_task'] ?? ''))) {
            $candidate=DB::table('calendar_lab_tasks')->where('profile_id',$uid)->where('id',(int)$event['extendedProperties']['private']['krok_lab_task'])->whereNull('google_event_id')->first();
            $calendarId=DB::table('calendar_lab_connections')->where('profile_id',$uid)->value('calendar_id');
            if ($candidate && $event['id']==='krok'.hash('sha256',$calendarId.'|'.$candidate->id)) {
                DB::table('calendar_lab_tasks')->where('id',$candidate->id)->update(['google_event_id'=>$event['id']]);
                $task=$candidate; $task->google_event_id=$event['id'];
            }
        }
        $cancelled=($event['status'] ?? '')==='cancelled';
        if (!$task && $cancelled) return;
        if ($task && $task->google_etag===($event['etag'] ?? null)) return;
        if ($task && !$cancelled && $task->synced_fingerprint!==$this->fingerprint($task)) {
            // Explicit edits made in the bot win a simultaneous conflict in this isolated pilot.
            DB::table('calendar_lab_tasks')->where('id',$task->id)->update(['google_etag'=>$event['etag'] ?? null]); return;
        }
        $values=['status'=>$cancelled ? 'cancelled' : ($task->status ?? 'pending'),'updated_at'=>now(),'google_etag'=>$event['etag'] ?? null];
        if (!$cancelled) {
            if (empty($event['start']['dateTime'])) return; // All-day events are left unchanged in Google.
            try { $due=Time::parse($event['start']['dateTime'])->utc(); } catch (\Throwable) { return; }
            $title=mb_substr(trim(preg_replace('/\s+/u',' ',$event['summary'] ?? 'Без назви')),0,180);
            if ($task && str_starts_with($title,'✅ Виконано: ')) $title=mb_substr($title,12);
            $values+=['title'=>$title ?: 'Без назви','due_at'=>$due->toDateTimeString()];
            try {
                $end=Time::parse($event['end']['dateTime'] ?? $event['start']['dateTime'])->utc();
                $values['google_duration_seconds']=max(60,min(604800,$end->timestamp-$due->timestamp));
            } catch (\Throwable) { $values['google_duration_seconds']=60; }
        }
        if ($task) {
            $next=(object)array_merge((array)$task,$values);
            $values['synced_fingerprint']=$this->fingerprint($next);
            DB::table('calendar_lab_tasks')->where('id',$task->id)->where('title',$task->title)->where('due_at',$task->due_at)->where('status',$task->status)->update($values);
        } else {
            $values+=['profile_id'=>$uid,'google_event_id'=>$event['id'],'created_at'=>now()];
            $values['synced_fingerprint']=$this->fingerprint((object)$values);
            DB::table('calendar_lab_tasks')->insertOrIgnore($values);
        }
    }

    public function forget(int $uid): void
    {
        DB::transaction(function () use ($uid) {
            DB::table('calendar_lab_tasks')->where('profile_id',$uid)->delete();
            DB::table('calendar_lab_connections')->where('profile_id',$uid)->delete();
            DB::table('calendar_lab_links')->where('profile_id',$uid)->delete();
        });
    }
}
