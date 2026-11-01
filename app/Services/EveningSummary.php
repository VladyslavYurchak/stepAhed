<?php

namespace App\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;

class EveningSummary
{
    public function bedtime(object $p): ?Time
    {
        if (!$p->evening_enabled || !$p->onboarded || !$p->sleep_at || !$p->wake_at || app(DailyRhythm::class)->quiet($p)) return null;
        $local=Time::now($p->timezone);
        $sleep=$local->setTimeFromTimeString($p->sleep_at);
        if ($sleep->lessThanOrEqualTo($local)) $sleep=$sleep->addDay();
        return $local->greaterThanOrEqualTo($sleep->subMinutes(30)) ? $sleep : null;
    }

    public function tick(): void
    {
        foreach (DB::table('bot_profiles')->where('onboarded',true)->where('evening_enabled',true)->whereNotNull('sleep_at')->get() as $p) {
            $sleep=$this->bedtime($p);
            if (!$sleep) continue;
            // The date belongs to the evening window, including bedtimes just after midnight.
            $day=$sleep->subMinutes(30)->startOfDay();
            DB::transaction(function () use ($p,$sleep,$day) {
                $claimed=DB::table('bot_profiles')->where('id',$p->id)->where('evening_enabled',true)
                    ->where(fn ($q) => $q->whereNull('evening_last_date')->orWhere('evening_last_date','!=',$day->toDateString()))
                    ->update(['evening_last_date'=>$day->toDateString()]);
                if (!$claimed) return;
                $query=DB::table('occurrences')->join('commitments','commitments.id','=','occurrences.commitment_id')
                    ->where('occurrences.profile_id',$p->id)->where('commitments.profile_id',$p->id)
                    ->whereBetween('due_at',[$day->utc(),$day->addDay()->utc()->subSecond()]);
                $counts=(clone $query)->selectRaw('occurrences.status, count(*) as total')->groupBy('occurrences.status')->pluck('total','status');
                $remaining=($counts['pending'] ?? 0)+($counts['rejected'] ?? 0);
                $text='<b>Підсумок твого дня 🌙</b>' ."\n".$day->format('d.m')."\n\n✅ Виконано: ".($counts['done'] ?? 0).
                    "\n⏳ На перевірці: ".($counts['review'] ?? 0)."\n📝 Ще залишилося: ".$remaining;
                if ($counts['missed'] ?? 0) $text.="\n🍃 Не підтверджено вчасно: ".$counts['missed'];
                $buttons=[];
                if ($remaining) {
                    $text.="\n\nЩе чекають на виконання:";
                    foreach ((clone $query)->whereIn('occurrences.status',['pending','rejected'])->orderBy('due_at')->limit(5)->select('title','due_at')->get() as $o) {
                        $text.="\n".Time::parse($o->due_at,'UTC')->setTimezone($p->timezone)->format('H:i').' — '.
                            htmlspecialchars(mb_strimwidth($o->title,0,100,'…'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                    }
                    if ($remaining>5) $text.="\nІ ще ".($remaining-5).' задач.';
                    $buttons[]=[['✅ Виконати задачу','list:today']];
                }
                if (array_sum($counts->all())===0) $text.="\n\nСьогодні без запланованих задач. Нехай вечір буде спокійним 💜";
                elseif (!$remaining && !($counts['missed'] ?? 0)) $text.="\n\nНа сьогодні не залишилося задач до виконання. Можна видихнути 💜";
                else $text.="\n\nКожен маленький крок має значення. Бережи сили 💜";
                $text.="\nСпокійного відпочинку 🌙";
                $buttons[]=[['＋ Додати задачу','new'],['Меню','home']];
                $inline=array_map(fn ($row) => array_map(fn ($b) => ['text'=>$b[0],'callback_data'=>$b[1]],$row),$buttons);
                app(Telegram::class)->enqueue('sendMessage',['chat_id'=>$p->id,'text'=>$text,'parse_mode'=>'HTML',
                    'reply_markup'=>['inline_keyboard'=>$inline],'_evening_before'=>$sleep->timestamp],$p->id);
            });
        }
    }
}
