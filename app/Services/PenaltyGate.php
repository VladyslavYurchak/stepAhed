<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PenaltyGate
{
    private function unpaid(int $uid): Builder
    {
        return DB::table('occurrences as o')->where('o.profile_id',$uid)->where('o.status','missed')->where('o.penalty_amount','>',0)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('donations as d')->whereColumn('d.occurrence_id','o.id')->where('d.profile_id',$uid)->where('d.status','approved'));
    }

    public function hasUnpaid(int $uid): bool
    {
        return $this->unpaid($uid)->exists();
    }

    public function block(int $uid): bool
    {
        $query=$this->unpaid($uid); $count=(clone $query)->count();
        if (!$count) return false;
        $amount=(clone $query)->sum('o.penalty_amount');
        $pending=(clone $query)->whereExists(fn ($q) => $q->selectRaw('1')->from('donations as d')->whereColumn('d.occurrence_id','o.id')->where('d.profile_id',$uid)->where('d.status','pending'))->count();
        $text=$pending===$count
            ? "⏳ Квитанція на перевірці.\n\nПереказувати повторно не потрібно. Після підтвердження адміністратором знову можна буде додавати задачі."
            : "💛 Оплатіть, будь ласка, штраф.\n\nНепідтверджених штрафів: {$count} на {$amount} грн. Додавання нових задач стане доступним після підтвердження сплати адміністратором.";
        if ($pending && $pending!==$count) $text.="\nКвитанцій на перевірці: {$pending}. За них не потрібно переказувати повторно.";
        $text.="\n\nВиконання наявних задач, нагадування, налаштування й твої дані залишаються доступними.";
        app(Telegram::class)->send($uid,$text,[[[$pending===$count ? '⏳ Переглянути квитанції' : '💛 Перейти до штрафів','unpaid']],[['✅ Виконати задачу','list:today'],['Меню','home']]]);
        return true;
    }

    public function listing(int $uid, int $page, int $messageId): void
    {
        $query=$this->unpaid($uid); $count=(clone $query)->count();
        $page=min(max(0,$page),max(0,(int)ceil($count/8)-1)); $buttons=[];
        foreach ($query->join('commitments as c','c.id','=','o.commitment_id')->where('c.profile_id',$uid)->orderBy('o.id')->offset($page*8)->limit(8)->get(['o.id','o.penalty_amount','c.title']) as $o) {
            $buttons[]=[[mb_strimwidth($o->title,0,32,'…').' · '.$o->penalty_amount.' грн','donate:'.$o->id]];
        }
        $nav=[];
        if ($page>0) $nav[]=['←','unpaid:'.($page-1)];
        if (($page+1)*8<$count) $nav[]=['→','unpaid:'.($page+1)];
        if ($nav) $buttons[]=$nav;
        $buttons[]=[['Повернутися в меню','home']];
        app(Telegram::class)->edit($uid,$messageId,$count ? "💛 Твої штрафи\n\nОбери запис, щоб зробити переказ або переглянути квитанцію. На перевірці — не означає, що потрібно платити повторно." : 'Усі штрафи підтверджені або скасовані 💜 Можна додавати нові задачі.',$buttons);
    }
}
