<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class ActiveUser {
    public function handle(Request $request, Closure $next) {
        if(!$request->user()?->is_active) { Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken(); return redirect()->route('login'); }
        if($request->user()->must_change_password && !$request->routeIs('password.*','logout')) return redirect()->route('password.edit');
        return $next($request);
    }
}
