<?php

namespace App\Http\Controllers;

use App\Services\CalendarLab;
use Illuminate\Http\Request;

class CalendarLabController extends Controller
{
    private function page(string $state, array $extra=[], int $status=200)
    {
        return response()->view('calendar-lab',array_merge(['state'=>$state],$extra),$status)->withHeaders([
            'Cache-Control'=>'private, no-store','Referrer-Policy'=>'no-referrer','X-Robots-Tag'=>'noindex, nofollow',
            'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; form-action 'self' https://accounts.google.com; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }

    public function show(string $token, CalendarLab $lab)
    {
        abort_unless($lab->linkOwner($token),404);
        return $this->page('intro',['token'=>$token]);
    }

    public function start(string $token, Request $request, CalendarLab $lab)
    {
        $uid=$lab->consumeLink($token);
        abort_unless($uid,404);
        $request->session()->regenerate();
        $state=bin2hex(random_bytes(32)); $verifier=bin2hex(random_bytes(32));
        $request->session()->put('calendar_lab_oauth',['state'=>$state,'verifier'=>$verifier,'profile_id'=>$uid,'expires_at'=>now()->addMinutes(10)->timestamp]);
        return redirect()->away($lab->authorizeUrl($state,$verifier))->withHeaders(['Cache-Control'=>'no-store','Referrer-Policy'=>'no-referrer']);
    }

    public function callback(Request $request, CalendarLab $lab)
    {
        $saved=$request->session()->get('calendar_lab_oauth');
        if (!$saved || ($saved['expires_at'] ?? 0)<now()->timestamp || !hash_equals($saved['state'],(string)$request->query('state'))) {
            return $this->page('expired',[],419);
        }
        $request->session()->forget('calendar_lab_oauth');
        if ($request->query('error') || !$request->query('code')) return $this->page('cancelled');
        try {
            $lab->complete((int)$saved['profile_id'],(string)$request->query('code'),$saved['verifier']);
            return $this->page('success');
        } catch (\Throwable) { return $this->page('error',[],422); }
    }
}
