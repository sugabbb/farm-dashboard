<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Hash, RateLimiter};
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
class AuthController extends Controller {
    public function login() { return Inertia::render('Auth/Login'); }
    public function authenticate(Request $r) {
        $v=$r->validate(['email'=>'required|email','password'=>'required|string']);
        $key=strtolower($v['email']).'|'.$r->ip();
        if(RateLimiter::tooManyAttempts($key,5)) return back()->withErrors(['email'=>'Terlalu banyak percobaan. Coba lagi dalam satu menit.']);
        if(!Auth::attempt($v+['is_active'=>true],$r->boolean('remember'))) { RateLimiter::hit($key,60); return back()->withErrors(['email'=>'Email atau kata sandi tidak sesuai.'])->onlyInput('email'); }
        RateLimiter::clear($key); $r->session()->regenerate(); Audit::record($r->user(),'login'); return redirect()->intended('/dashboard');
    }
    public function logout(Request $r) { Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken(); return redirect()->route('login'); }
    public function password() { return Inertia::render('Auth/Password'); }
    public function updatePassword(Request $r) {
        $v=$r->validate(['current_password'=>'required|current_password','password'=>['required','confirmed',Password::min(12)->letters()->numbers()]]);
        $r->user()->update(['password'=>$v['password'],'must_change_password'=>false]); Auth::logoutOtherDevices($v['password']);
        \Illuminate\Support\Facades\DB::table('sessions')->where('user_id',$r->user()->id)->where('id','!=',$r->session()->getId())->delete();
        $r->session()->regenerate(); Audit::record($r->user(),'password_changed'); return redirect('/')->with('success','Kata sandi diperbarui.');
    }
}
