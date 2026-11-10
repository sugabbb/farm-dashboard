<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class UserController extends Controller {
    public function store(Request $r) {
        abort_unless($r->user()->role==='SUPERADMIN',403);
        $v=$r->validate(['name'=>'required|string|max:255','email'=>'required|email|unique:users,email','role'=>'required|in:ADMIN,SUPERADMIN']);
        $password=Str::password(18); $u=User::create($v+['password'=>$password,'is_active'=>true,'must_change_password'=>true]); Audit::record($u,'user_created');
        return back()->with('success','Akun dibuat. Kata sandi sementara (tampil sekali): '.$password);
    }
    public function update(Request $r,User $user) {
        abort_unless($r->user()->role==='SUPERADMIN',403);
        $r->validate(['action'=>'required|in:reset,toggle,profile']);
        return DB::transaction(function() use($r,$user) {
            DB::select('select pg_advisory_xact_lock(721092027)'); $user=User::lockForUpdate()->findOrFail($user->id);
            if($r->action==='profile') {
                $v=$r->validate(['name'=>'required|string|max:255','email'=>['required','email',\Illuminate\Validation\Rule::unique('users')->ignore($user->id)],'role'=>'required|in:ADMIN,SUPERADMIN']);
                if($user->role==='SUPERADMIN' && $v['role']!=='SUPERADMIN') abort_if(User::where('role','SUPERADMIN')->where('is_active',true)->count()<=1,422,'Pertahankan minimal satu Superadmin aktif.');
                $user->update($v); $message='Data pengguna diperbarui.';
            }
            elseif($r->action==='reset') { $password=Str::password(18); $user->update(['password'=>$password,'must_change_password'=>true,'remember_token'=>null]); $message='Kata sandi sementara (tampil sekali): '.$password; }
            else { abort_if($user->id===$r->user()->id,422,'Tidak dapat menonaktifkan akun sendiri.'); $user->update(['is_active'=>!$user->is_active,'remember_token'=>null]); $message='Status akun diperbarui.'; }
            DB::table('sessions')->where('user_id',$user->id)->delete(); Audit::record($user,match($r->action){'reset'=>'password_reset','profile'=>'user_updated',default=>'active_changed'}); return back()->with('success',$message);
        });
    }
}
