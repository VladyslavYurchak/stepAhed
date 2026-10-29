<?php

namespace App\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;

class Funds
{
    public function __construct(private Telegram $tg) {}

    public function isAdmin(int $uid): bool
    {
        return (string)$uid === $this->tg->setting('admin_id');
    }

    private function state(int $uid, ?array $s): void
    {
        DB::table('bot_profiles')->where('id',$uid)->update(['dialog'=>$s ? json_encode($s,JSON_UNESCAPED_UNICODE) : null]);
    }

    private function show(int|string $chat, string $text, array $buttons=[], ?int $messageId=null): void
    {
        if ($messageId) $this->tg->edit($chat,$messageId,$text,$buttons);
        else $this->tg->send($chat,$text,$buttons);
    }

    public function validateField(string $field,string $value,bool $proposal=false): bool
    {
        $limits=['title'=>[3,100],'recipient'=>[3,140],'purpose'=>[5,400],'url'=>[10,250],'source'=>[1,350]];
        if (!isset($limits[$field])) return false;
        [$min,$max]=$limits[$field]; if (mb_strlen($value)<$min || mb_strlen($value)>$max) return false;
        if ($field==='url') return (bool)preg_match('~^https://send\.monobank\.ua/jar/[A-Za-z0-9_-]{5,100}/?$~D',$value);
        if ($field==='source') {
            if ($value==='-' && !$proposal) return true;
            $parts=parse_url($value);
            return filter_var($value,FILTER_VALIDATE_URL) && ($parts['scheme'] ?? '')==='https' && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
        }
        return !str_contains($value,"\n") && !str_contains($value,"\r");
    }

    private function fields(): array
    {
        return ['title'=>'Назва збору (3–100 символів)','recipient'=>'Отримувач / організатор (3–140 символів)',
            'purpose'=>'Мета збору (5–400 символів)','url'=>'Посилання на банку: https://send.monobank.ua/jar/…',
            'source'=>'Публічне джерело збору: посилання https://… Для власного адміністративного запису можна написати «-».'];
    }

    private function summary(object|array $fund): string
    {
        $f=(array)$fund;
        return "{$f['title']}\nОтримувач: {$f['recipient']}\nМета: {$f['purpose']}\nБанка: {$f['url']}".
            (!empty($f['source']) && $f['source']!=='-' ? "\nДжерело: {$f['source']}" : '');
    }

    private function fingerprint(object $fund): string
    {
        return hash('sha256',json_encode(array_intersect_key((array)$fund,array_flip(['title','recipient','purpose','url','source','status','sort_order']))));
    }

    public function message(object $p,array $m): bool
    {
        $s=json_decode($p->dialog ?? 'null',true);
        if (!in_array($s['step'] ?? '',['fund_wizard','fund_edit','donation_photo'],true)) return false;
        if (($s['admin'] ?? false) && !$this->isAdmin($p->id)) {
            $this->state($p->id,null); $this->tg->send($p->id,'Недостатньо прав для адміністрування.'); return true;
        }
        if ($s['step']==='donation_photo') { $this->uploadReceipt($p,$m,(int)$s['donation_id']); return true; }
        $value=trim($m['text'] ?? ''); $field=$s['field'];
        if (!$this->validateField($field,$value,!($s['admin'] ?? false))) {
            $this->tg->send($p->id,'Перевір значення. '.$this->fields()[$field].($field==='source' && !($s['admin'] ?? false) ? ' Для пропозиції джерело обов’язкове.' : '')); return true;
        }
        $s['data'][$field]=$value;
        if ($s['step']==='fund_edit') {
            $s['step']='fund_edit_confirm'; $this->state($p->id,$s);
            $this->tg->send($p->id,$this->summary($s['data'])."\nЗміниться запис збору. Старі квитанції збережуть попередні реквізити.",[[['Зберегти','a:editsave:'.$s['id']],['Вийти','home']]]); return true;
        }
        $keys=array_keys($this->fields()); $next=array_search($field,$keys,true)+1;
        if (isset($keys[$next])) {
            $s['field']=$keys[$next]; $this->state($p->id,$s); $this->tg->send($p->id,$this->fields()[$s['field']]);
        } else {
            $s['step']='fund_confirm'; $this->state($p->id,$s);
            $this->tg->send($p->id,$this->summary($s['data']).($s['admin'] ? "\nПісля публікації збір стане доступний користувачам." : "\nЗбір стане доступним лише після ручної модерації."),
                [[[$s['admin']?'Опублікувати':'Надіслати на модерацію',$s['admin']?'a:create':'fundsubmit']],[['Вийти','home']]]);
        }
        return true;
    }

    public function button(int $uid,string $data,int $chat,?int $messageId=null): bool
    {
        if (str_starts_with($data,'a:')) {
            if (!$this->isAdmin($uid) || ($chat!==$uid && (string)$chat!==$this->tg->setting('moderation_chat'))) {
                $this->tg->send($chat,'Недостатньо прав для цієї дії.'); return true;
            }
            $this->adminButton($uid,$data,$chat,$messageId); return true;
        }
        if ($chat!==$uid) return false;
        [$action,$value]=array_pad(explode(':',$data,2),2,'');
        $s=json_decode(DB::table('bot_profiles')->where('id',$uid)->value('dialog') ?? 'null',true);
        if ($action==='funds') { $this->publicList($uid,$value,$messageId); return true; }
        if ($action==='mydonations') {
            $buttons=[];
            foreach (DB::table('donations')->where('profile_id',$uid)->orderByDesc('id')->limit(15)->get() as $d) {
                $status=['awaiting'=>'Очікує квитанцію','pending'=>'На перевірці','approved'=>'✅ Підтверджено','rejected'=>'Відхилено','void'=>'Скасовано'][$d->status];
                $buttons[]=[['#'.$d->id.' · '.$d->expected_amount.' грн · '.$status,'donationview:'.$d->id]];
            }
            $buttons[]=[['До зборів','funds'],['Повернутися в меню','home']];
            $this->show($uid,'Мої донати · останні 15 записів',$buttons,$messageId); return true;
        }
        if ($action==='donationview') {
            $d=DB::table('donations')->where('id',(int)$value)->where('profile_id',$uid)->first();
            if ($d) $this->donationCard($uid,$d,$messageId); return true;
        }
        if ($action==='proposefund') {
            $pending=DB::table('charity_funds')->where('proposed_by',$uid)->where('status','pending')->count();
            if ($pending>=3) { $this->tg->send($uid,'У тебе вже є три пропозиції, які очікують перевірки. Дочекайся рішення.'); return true; }
            $this->state($uid,['step'=>'fund_wizard','field'=>'title','data'=>[],'admin'=>false]);
            $this->tg->send($uid,'Запропонуй публічний благодійний збір. Особисті банки для переказів самому собі не допускаються. Потрібне публічне джерело. '.$this->fields()['title']); return true;
        }
        if ($action==='fundsubmit' && ($s['step'] ?? '')==='fund_confirm' && !($s['admin'] ?? false)) {
            $id=$this->createFund($uid,$s['data'],false); $this->state($uid,null);
            $this->tg->send($uid,'Збір надіслано на модерацію. До схвалення він недоступний для вибору.',[[['Збори','funds']]]);
            if ($group=$this->tg->setting('moderation_chat')) $this->tg->send($group,'Запропоновано збір #'.$id."\n".$this->summary($s['data']),[[['Переглянути','a:view:'.$id]]]);
            return true;
        }
        if ($action==='fundview') {
            $fund=DB::table('charity_funds')->where('id',(int)$value)->where('status','active')->first();
            if (!$fund) { $this->tg->send($uid,'Цей збір зараз недоступний. Обери інший.'); return true; }
            $this->show($uid,$this->summary($fund),[[['← До зборів','funds']]],$messageId); return true;
        }
        if ($action==='donate') { $this->donationMenu($uid,$value,$messageId); return true; }
        if ($action==='fundpick') {
            [$fundId,$occId]=array_pad(explode(',',$value,2),2,'0');
            try { $donation=$this->selectFund($uid,(int)$occId,(int)$fundId); $this->donationCard($uid,$donation,$messageId); }
            catch (\DomainException $e) { $this->tg->send($uid,$e->getMessage()); }
            return true;
        }
        if ($action==='receipt') {
            $d=DB::table('donations')->where('id',(int)$value)->where('profile_id',$uid)->first();
            if (!$d || !in_array($d->status,['awaiting','rejected'],true) || !$this->validOccurrence($uid,$d->occurrence_id)) { $this->tg->send($uid,'Надсилання квитанції зараз недоступне або вона вже очікує перевірки.'); return true; }
            $this->state($uid,['step'=>'donation_photo','donation_id'=>$d->id]);
            $this->tg->send($uid,'Надішли скріншот донату звичайним фото. На ньому мають бути видимі сума, дата та отримувач. Перевірка ручна; скріншот не є банківським підтвердженням надходження.'); return true;
        }
        return false;
    }

    public function createFund(int $uid,array $data,bool $admin): int
    {
        if ($admin && !$this->isAdmin($uid)) throw new \DomainException('Недостатньо прав.');
        foreach ($this->fields() as $field=>$label) if (!$this->validateField($field,$data[$field] ?? '',!$admin)) throw new \DomainException('Некоректне поле: '.$field);
        return DB::table('charity_funds')->insertGetId([
            ...array_intersect_key($data,$this->fields()), 'status'=>$admin?'active':'pending',
            'sort_order'=>(DB::table('charity_funds')->max('sort_order') ?? 0)+1,
            'proposed_by'=>$uid,'reviewed_by'=>$admin?$uid:null,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function publicList(int $uid,string $pageValue,?int $messageId): void
    {
        $query=DB::table('charity_funds')->where('status','active')->orderBy('sort_order')->orderBy('id');
        $total=(clone $query)->count(); $pages=max(1,(int)ceil($total/6)); $page=min($pages-1,max(0,(int)$pageValue));
        $text="Благодійні збори\nСхвалені адміністратором збори. Донати добровільні; вони не купують доступ до функцій бота.";
        $buttons=[];
        foreach ($query->offset($page*6)->limit(6)->get() as $fund) $buttons[]=[[$fund->title,'fundview:'.$fund->id]];
        if (!$total) $text.="\nПоки немає активних зборів. Адміністратор має додати першу банку.";
        if ($pages>1) { $nav=[]; if($page) $nav[]=['←','funds:'.($page-1)]; if($page+1<$pages) $nav[]=['→','funds:'.($page+1)]; $buttons[]=$nav; }
        $buttons[]=[['Запропонувати збір','proposefund']]; $buttons[]=[['Мої донати','mydonations']]; $buttons[]=[['Повернутися в меню','home']];
        $this->show($uid,$text,$buttons,$messageId);
    }

    private function adminButton(int $uid,string $data,int $chat,?int $messageId): void
    {
        [, $action, $value]=array_pad(explode(':',$data,3),3,'');
        $s=json_decode(DB::table('bot_profiles')->where('id',$uid)->value('dialog') ?? 'null',true);
        if ($action==='menu') {
            $this->state($uid,null);
            $pending=DB::table('charity_funds')->where('status','pending')->count();
            $receipts=DB::table('donation_receipts')->where('status','pending')->count();
            $this->show($chat,'⚙️ Адміністрування',[
                [['＋ Додати збір','a:add']],[['Активні збори','a:list:active']],[['Запропоновані збори · '.$pending,'a:list:pending']],
                [['Архів зборів','a:list:archived']],[['Квитанції на перевірку · '.$receipts,'a:receipts']],[['Історія квитанцій','a:receipts:history,0']],[['Повернутися в меню','home']],
            ],$messageId); return;
        }
        if ($action==='add') {
            if ($chat!==$uid) { $this->tg->send($chat,'Додавання доступне в особистому чаті з ботом.'); return; }
            $this->state($uid,['step'=>'fund_wizard','field'=>'title','data'=>[],'admin'=>true]);
            $this->tg->send($uid,$this->fields()['title']); return;
        }
        if ($action==='create' && ($s['step'] ?? '')==='fund_confirm' && ($s['admin'] ?? false)) {
            $id=$this->createFund($uid,$s['data'],true); $this->state($uid,null);
            $this->tg->send($chat,'Збір опубліковано ✅',[[['Відкрити','a:view:'.$id],['Адмін-меню','a:menu']]]); return;
        }
        if ($action==='list') { $this->adminList($chat,$value,$messageId); return; }
        if ($action==='receipts') { $this->receiptList($chat,$value,$messageId); return; }
        if ($action==='receiptview') { $this->receiptCard($chat,(int)$value); return; }
        if (in_array($action,['receiptapprove','receiptreject','receiptreason'],true)) {
            [$id,$code]=array_pad(explode(',',$value,2),2,'');
            if ($action==='receiptreject') {
                $this->tg->send($chat,'Причина відмови квитанції #'.(int)$id,[[['Не видно суми / дати','a:receiptreason:'.$id.',details']],[['Інший отримувач або сума','a:receiptreason:'.$id.',wrong']],[['Повторний скріншот','a:receiptreason:'.$id.',duplicate']]]); return;
            }
            $reasons=['details'=>'Не видно суми або дати','wrong'=>'Сума або отримувач не відповідає обраному збору','duplicate'=>'Повторний скріншот'];
            if ($action==='receiptreason' && !isset($reasons[$code])) return;
            $changed=$this->reviewReceipt($uid,(int)$id,$action==='receiptapprove',$reasons[$code] ?? null);
            $this->tg->send($chat,$changed?'Рішення щодо квитанції збережено.':'Квитанцію вже перевірено або порушення скасоване.'); return;
        }
        [$id,$extra,$version]=array_pad(explode(',',$value,3),3,'');
        $fund=DB::table('charity_funds')->where('id',(int)$id)->lockForUpdate()->first();
        if (!$fund) { $this->tg->send($chat,'Збір не знайдено.'); return; }
        if ($action==='view') { $this->adminCard($chat,$fund,$messageId); return; }
        if (in_array($action,['publish','archive'],true)) {
            $target=$action==='publish'?'active':'archived';
            $this->show($chat,$this->summary($fund)."\n".($target==='active'?'Опублікувати цей збір? Перевір отримувача й публічне джерело.':'Вимкнути збір для нових донатів? Історія залишиться.'),[[['Підтвердити','a:status:'.$fund->id.','.$target.','.substr($this->fingerprint($fund),0,12)]],[['Назад','a:view:'.$fund->id]]],$messageId); return;
        }
        if ($action==='status' && in_array($extra,['active','archived'],true)) {
            if (!$version || !hash_equals(substr($this->fingerprint($fund),0,12),$version)) { $this->tg->send($chat,'Запис змінився. Відкрий збір заново перед підтвердженням.'); return; }
            $old=$fund->status;
            DB::table('charity_funds')->where('id',$fund->id)->update(['status'=>$extra,'reviewed_by'=>$uid,'review_reason'=>null,'updated_at'=>now()]);
            if ($old==='pending' && $extra==='active' && $fund->proposed_by) $this->tg->send($fund->proposed_by,'Твій запропонований збір «'.$fund->title.'» схвалено.');
            $this->adminCard($chat,DB::table('charity_funds')->find($fund->id),$messageId); return;
        }
        if ($action==='reject' && $fund->status==='pending') {
            $this->show($chat,'Чому відхиляємо збір?',[[['Не підтверджено джерело','a:rejectdo:'.$id.',source']],[['Не відповідає правилам','a:rejectdo:'.$id.',rules']],[['Назад','a:view:'.$id]]],$messageId); return;
        }
        if ($action==='rejectdo' && $fund->status==='pending' && in_array($extra,['source','rules'],true)) {
            $reason=$extra==='source'?'Не вдалося підтвердити публічне джерело збору':'Збір не відповідає правилам сервісу';
            DB::table('charity_funds')->where('id',$fund->id)->update(['status'=>'rejected','review_reason'=>$reason,'reviewed_by'=>$uid,'updated_at'=>now()]);
            if ($fund->proposed_by) $this->tg->send($fund->proposed_by,'Пропозицію «'.$fund->title.'» відхилено. '.$reason);
            $this->adminCard($chat,DB::table('charity_funds')->find($fund->id),$messageId); return;
        }
        if ($action==='edit') {
            $buttons=[]; foreach ($this->fields() as $field=>$label) $buttons[]=[[$label,'a:field:'.$id.','.$field]];
            $buttons[]=[['Назад','a:view:'.$id]]; $this->show($chat,'Що змінити в «'.$fund->title.'»?',$buttons,$messageId); return;
        }
        if ($action==='field' && isset($this->fields()[$extra])) {
            if ($chat!==$uid) { $this->tg->send($chat,'Редагуй текстові поля в особистому адмін-меню бота.'); return; }
            $this->state($uid,['step'=>'fund_edit','admin'=>true,'id'=>$fund->id,'expected'=>$this->fingerprint($fund),'field'=>$extra,'data'=>array_intersect_key((array)$fund,$this->fields())]);
            $this->tg->send($uid,'Нове значення. '.$this->fields()[$extra]); return;
        }
        if ($action==='editsave' && ($s['step'] ?? '')==='fund_edit_confirm' && ($s['admin'] ?? false) && $s['id']===$fund->id) {
            if (!hash_equals($s['expected'],$this->fingerprint($fund))) { $this->state($uid,null); $this->tg->send($chat,'Запис уже змінився. Відкрий його заново.'); return; }
            DB::table('charity_funds')->where('id',$fund->id)->update([...$s['data'],'updated_at'=>now()]);
            $this->state($uid,null); $this->adminCard($chat,DB::table('charity_funds')->find($fund->id),$messageId); return;
        }
        if ($action==='move' && $fund->status==='active' && in_array($extra,['up','down'],true)) {
            $all=DB::table('charity_funds')->where('status','active')->orderBy('sort_order')->orderBy('id')->get();
            foreach ($all as $i=>$f) DB::table('charity_funds')->where('id',$f->id)->update(['sort_order'=>$i]);
            $index=$all->search(fn($f)=>$f->id===$fund->id); $other=$index+($extra==='up'?-1:1);
            if (isset($all[$other])) { DB::table('charity_funds')->where('id',$fund->id)->update(['sort_order'=>$other]); DB::table('charity_funds')->where('id',$all[$other]->id)->update(['sort_order'=>$index]); }
            $this->adminCard($chat,DB::table('charity_funds')->find($fund->id),$messageId);
        }
    }

    private function adminList(int $chat,string $value,?int $messageId): void
    {
        [$status,$page]=array_pad(explode(',',$value,2),2,'0'); if(!in_array($status,['active','pending','archived'],true)) $status='active';
        $query=DB::table('charity_funds');
        $status==='archived' ? $query->whereIn('status',['archived','rejected']) : $query->where('status',$status);
        $total=(clone $query)->count(); $pages=max(1,(int)ceil($total/6)); $page=min($pages-1,max(0,(int)$page));
        $buttons=[]; foreach($query->orderBy('sort_order')->orderBy('id')->offset($page*6)->limit(6)->get() as $fund) $buttons[]=[[$fund->title,'a:view:'.$fund->id]];
        if ($pages>1) { $nav=[]; if($page) $nav[]=['←','a:list:'.$status.','.($page-1)]; if($page+1<$pages) $nav[]=['→','a:list:'.$status.','.($page+1)]; $buttons[]=$nav; }
        $buttons[]=[['＋ Додати','a:add'],['Адмін-меню','a:menu']];
        $this->show($chat,(['active'=>'Активні збори','pending'=>'Пропозиції на модерацію','archived'=>'Архів зборів'][$status])."\nУсього: {$total}",$buttons,$messageId);
    }

    private function adminCard(int $chat,object $fund,?int $messageId): void
    {
        $buttons=[];
        if ($fund->status!=='active') $buttons[]=[['Опублікувати','a:publish:'.$fund->id]];
        else { $buttons[]=[['Вимкнути / завершити','a:archive:'.$fund->id]]; $buttons[]=[['↑ Вище','a:move:'.$fund->id.',up'],['↓ Нижче','a:move:'.$fund->id.',down']]; }
        if ($fund->status==='pending') $buttons[]=[['Відхилити з причиною','a:reject:'.$fund->id]];
        $buttons[]=[['Редагувати','a:edit:'.$fund->id]]; $buttons[]=[['Адмін-меню','a:menu']];
        $this->show($chat,'Збір #'.$fund->id.' · '.(['active'=>'активний','pending'=>'на перевірці','archived'=>'вимкнений','rejected'=>'відхилений'][$fund->status])."\nЗапропонував: ".$fund->proposed_by."\n".$this->summary($fund),$buttons,$messageId);
    }

    private function validOccurrence(int $uid,int $id): ?object
    {
        return DB::table('occurrences')->where('id',$id)->where('profile_id',$uid)->where('status','missed')->where('penalty_amount','>',0)->first();
    }

    public function selectFund(int $uid,int $occId,int $fundId): object
    {
        $o=$this->validOccurrence($uid,$occId); $fund=DB::table('charity_funds')->where('id',$fundId)->where('status','active')->first();
        if (!$o || !$fund) throw new \DomainException('Порушення або збір недоступні. Обери актуальний збір.');
        $existing=DB::table('donations')->where('occurrence_id',$occId)->lockForUpdate()->first();
        if ($existing && in_array($existing->status,['pending','approved'],true)) throw new \DomainException('Квитанцію вже надіслано або підтверджено.');
        $values=['profile_id'=>$uid,'fund_id'=>$fundId,'fund_snapshot'=>json_encode(array_intersect_key((array)$fund,$this->fields()),JSON_UNESCAPED_UNICODE),'expected_amount'=>$o->penalty_amount,'status'=>'awaiting','updated_at'=>now()];
        if ($existing) DB::table('donations')->where('id',$existing->id)->update($values);
        else { $id=DB::table('donations')->insertGetId(['occurrence_id'=>$occId,...$values,'created_at'=>now()]); }
        return DB::table('donations')->find($existing->id ?? $id);
    }

    private function donationMenu(int $uid,string $value,?int $messageId): void
    {
        [$occId,$page]=array_pad(explode(',',$value,2),2,'0'); $occId=(int)$occId;
        $o=$this->validOccurrence($uid,$occId);
        if (!$o) { $this->tg->send($uid,'Для цієї задачі немає чинного штрафу.'); return; }
        $donation=DB::table('donations')->where('occurrence_id',$occId)->first();
        if ($donation && in_array($donation->status,['pending','approved'],true)) { $this->donationCard($uid,$donation,$messageId); return; }
        $buttons=[]; $q=DB::table('charity_funds')->where('status','active'); $total=(clone $q)->count();
        $pages=max(1,(int)ceil($total/6)); $page=min($pages-1,max(0,(int)$page));
        foreach ($q->orderBy('sort_order')->orderBy('id')->offset($page*6)->limit(6)->get() as $fund) $buttons[]=[[$fund->title,'fundpick:'.$fund->id.','.$occId]];
        $text='Добровільний донат за пропуск · '.$o->penalty_amount." грн\nОбери схвалений збір. Донат не відкриває платних функцій і не змінює результат задачі.";
        if (!$buttons) $text.="\nАктивних зборів поки немає. Адміністратор має додати банку.";
        if ($pages>1) { $nav=[]; if($page) $nav[]=['←','donate:'.$occId.','.($page-1)]; if($page+1<$pages) $nav[]=['→','donate:'.$occId.','.($page+1)]; $buttons[]=$nav; }
        if ($donation) $buttons[]=[['Надіслати квитанцію попереднього переказу','receipt:'.$donation->id]];
        $buttons[]=[['Повернутися в меню','home']]; $this->show($uid,$text,$buttons,$messageId);
    }

    private function donationCard(int $uid,object $d,?int $messageId): void
    {
        $snapshot=json_decode($d->fund_snapshot,true);
        $text=$this->summary($snapshot)."\nСума: {$d->expected_amount} грн\n".(['awaiting'=>'Після переказу надішли скріншот.','pending'=>'Квитанція очікує ручної перевірки.','approved'=>'✅ Донат підтверджений адміністратором.','rejected'=>'Квитанція відхилена. Можна надіслати іншу.','void'=>'Порушення скасоване.'][$d->status]);
        $buttons=[];
        if (in_array($d->status,['awaiting','rejected'],true)) {
            $current=DB::table('charity_funds')->where('id',$d->fund_id)->where('status','active')->first();
            $active=$current && $current->url===$snapshot['url'];
            if ($active) $text.="\nВідкрий посилання на банку вище та перевір отримувача перед переказом.";
            else $text.="\n⚠️ Збір вимкнений або реквізити змінилися: не роби новий переказ за старими даними. Якщо вже переказав — надішли квитанцію; інакше обери актуальний збір.";
            $buttons[]=[['Я переказав — надіслати квитанцію','receipt:'.$d->id]];
            $buttons[]=[['Обрати інший збір','donate:'.$d->occurrence_id]];
        }
        $buttons[]=[['Повернутися в меню','home']]; $this->show($uid,$text,$buttons,$messageId);
    }

    private function uploadReceipt(object $p,array $m,int $donationId): void
    {
        $d=DB::table('donations')->where('id',$donationId)->where('profile_id',$p->id)->lockForUpdate()->first();
        if (!$d || !in_array($d->status,['awaiting','rejected'],true) || !$this->validOccurrence($p->id,$d->occurrence_id)) { $this->state($p->id,null); $this->tg->send($p->id,'Квитанція вже на перевірці, підтверджена або порушення скасоване.'); return; }
        $photos=$m['photo'] ?? []; $photo=end($photos);
        if (!$photo) { $this->tg->send($p->id,'Надішли скріншот як звичайне фото, не текст або файл.'); return; }
        if (!$this->tg->setting('moderation_chat')) { $this->tg->send($p->id,'Група модерації ще не підключена.'); return; }
        $id=DB::table('donation_receipts')->insertGetId(['donation_id'=>$d->id,'profile_id'=>$p->id,'fund_snapshot'=>$d->fund_snapshot,'expected_amount'=>$d->expected_amount,'file_id'=>$photo['file_id'],'file_unique_id'=>$photo['file_unique_id'],'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
        DB::table('donations')->where('id',$d->id)->update(['status'=>'pending','updated_at'=>now()]);
        $this->state($p->id,null); $this->receiptCard((int)$this->tg->setting('moderation_chat'),$id);
        $this->tg->send($p->id,'Квитанцію отримано. Очікує ручної перевірки адміністратором.',[[['Статус донату','donate:'.$d->occurrence_id]]]);
    }

    private function receiptList(int $chat,string $value,?int $messageId): void
    {
        [$filter,$page]=array_pad(explode(',',$value,2),2,'0'); $history=$filter==='history';
        $q=DB::table('donation_receipts'); $history ? $q->where('status','!=','pending') : $q->where('status','pending'); $total=(clone $q)->count();
        $pages=max(1,(int)ceil($total/6)); $page=min($pages-1,max(0,(int)($history?$page:$filter))); $buttons=[];
        foreach ($q->orderBy('id',$history?'desc':'asc')->offset($page*6)->limit(6)->get() as $r) $buttons[]=[['#'.$r->id.' · '.$r->expected_amount.' грн · '.$r->status,'a:receiptview:'.$r->id]];
        $prefix='a:receipts:'.($history?'history,':'');
        if ($pages>1) { $nav=[]; if($page) $nav[]=['←',$prefix.($page-1)]; if($page+1<$pages) $nav[]=['→',$prefix.($page+1)]; $buttons[]=$nav; }
        $buttons[]=[['Адмін-меню','a:menu']];
        $this->show($chat,($history?'Історія квитанцій: ':'Квитанції на перевірку: ').$total,$buttons,$messageId);
    }

    private function receiptCard(int $chat,int $id): void
    {
        $r=DB::table('donation_receipts')->find($id); if(!$r) return;
        $snapshot=json_decode($r->fund_snapshot,true);
        $d=DB::table('donations')->find($r->donation_id);
        $o=DB::table('occurrences')->join('commitments','commitments.id','=','occurrences.commitment_id')->where('occurrences.id',$d->occurrence_id)->select('occurrences.*','commitments.title')->first();
        $tz=DB::table('bot_profiles')->where('id',$r->profile_id)->value('timezone');
        $duplicate=DB::table('donation_receipts')->where('file_unique_id',$r->file_unique_id)->where('id','!=',$id)->exists();
        $caption="Квитанція #{$id}\nСтатус: {$r->status}\nКористувач: {$r->profile_id}\nПропуск #{$d->occurrence_id}: ".mb_strimwidth($o->title,0,80,'…')."\nДедлайн: ".Time::parse($o->due_at,'UTC')->setTimezone($tz)->format('d.m H:i')."\nОчікувана сума: {$r->expected_amount} грн\n{$snapshot['title']}\nОтримувач: {$snapshot['recipient']}\nБанка: {$snapshot['url']}".
            "\nПеревір суму, дату й отримувача вручну. Скріншот не підтверджує надходження коштів банком.".($duplicate?"\n⚠️ Таке фото вже надсилали.":'');
        $buttons=$r->status==='pending' ? [[['text'=>'✅ Підтвердити','callback_data'=>'a:receiptapprove:'.$id]],[['text'=>'❌ Відхилити','callback_data'=>'a:receiptreject:'.$id]]] : [];
        $this->tg->enqueue('sendPhoto',['chat_id'=>$chat,'photo'=>$r->file_id,'caption'=>$caption,'reply_markup'=>['inline_keyboard'=>$buttons]]);
    }

    public function reviewReceipt(int $admin,int $id,bool $approve,?string $reason=null): bool
    {
        if (!$this->isAdmin($admin)) throw new \DomainException('Недостатньо прав.');
        $r=DB::table('donation_receipts')->where('id',$id)->lockForUpdate()->first();
        if (!$r || $r->status!=='pending') return false;
        $d=DB::table('donations')->where('id',$r->donation_id)->lockForUpdate()->first();
        if (!$d || $d->status!=='pending') return false;
        if (!$this->validOccurrence($r->profile_id,$d->occurrence_id)) {
            DB::table('donation_receipts')->where('id',$id)->update(['status'=>'void','reviewed_by'=>$admin,'reviewed_at'=>now()]);
            DB::table('donations')->where('id',$d->id)->update(['status'=>'void']); return false;
        }
        DB::table('donation_receipts')->where('id',$id)->update(['status'=>$approve?'approved':'rejected','reason'=>$approve?null:$reason,'reviewed_by'=>$admin,'reviewed_at'=>now(),'updated_at'=>now()]);
        DB::table('donations')->where('id',$d->id)->update(['status'=>$approve?'approved':'rejected','updated_at'=>now()]);
        $this->tg->send($r->profile_id,$approve ? '✅ Адміністратор підтвердив квитанцію донату. Пропуск залишається в історії задачі.' : 'Квитанція не пройшла перевірку. Причина: '.$reason.'. Можна надіслати інший скріншот.',[[['Статус донату','donate:'.$d->occurrence_id]]]);
        return true;
    }
}
