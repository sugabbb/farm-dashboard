<?php
namespace App\Http\Middleware;
use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash,RateLimiter};
use Symfony\Component\HttpFoundation\Response;
class AuthenticateAbkApi {
 public function handle(Request $request,Closure $next): Response {
  $provided=(string)$request->header('X-PBB-API-Key'); $keys=array_filter([(string)config('abk_api.key'),(string)config('abk_api.previous_key')]);
  if(!$provided || !collect($keys)->contains(fn($key)=>hash_equals($key,$provided))) return response()->json(['message'=>'Kredensial API tidak valid.'],401);
  [$username,$pin]=[$request->getUser(),$request->getPassword()]; $rateKey='abk-api:'.strtolower((string)$username).'|'.$request->ip();
  if(RateLimiter::tooManyAttempts($rateKey,5)) return response()->json(['message'=>'Terlalu banyak percobaan. Coba kembali nanti.'],429);
  $employee=$username?Employee::whereRaw('LOWER(api_username)=?',[strtolower($username)])->first():null;
  if(!$employee || !$employee->hasApiAccess() || ($employee->api_locked_until && $employee->api_locked_until->isFuture()) || !Hash::check((string)$pin,$employee->api_pin_hash)) {
   RateLimiter::hit($rateKey,900); if($employee){$attempts=$employee->api_failed_attempts+1;$employee->update(['api_failed_attempts'=>$attempts,'api_locked_until'=>$attempts>=5?now()->addMinutes(15):null]);}
   return response()->json(['message'=>'Kredensial API tidak valid.'],401);
  }
  RateLimiter::clear($rateKey); if($employee->api_failed_attempts)$employee->update(['api_failed_attempts'=>0,'api_locked_until'=>null]);
  $request->attributes->set('abk_employee',$employee); return $next($request);
 }
}
