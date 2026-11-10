<?php
namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeController extends Controller {
    public function index(Request $request) {
        $q=Employee::query(); if($request->filled('q')) $q->where('name','ilike','%'.$request->q.'%'); if(in_array($request->status,['ACTIVE','INACTIVE'])) $q->where('status',$request->status);
        [$sort,$direction,$column]=$this->sorting($request,['name'=>'name','job_title'=>'job_title','joined_at'=>'joined_at','status'=>'status'],'name');
        return Inertia::render('Employees',['employees'=>$q->orderBy($column,$direction)->orderBy('id')->paginate(30)->withQueryString()->through(fn($employee)=>['id'=>$employee->id,'name'=>$employee->name,'job_title'=>$employee->job_title,'abk_type'=>$employee->abk_type,'api_username'=>$employee->api_username,'api_enabled'=>$employee->hasApiAccess(),'credentials_updated_at'=>$employee->credentials_updated_at?->toIso8601String(),'joined_at'=>$employee->joined_at->toDateString(),'status'=>$employee->status,'notes'=>$employee->notes]),'filters'=>$request->only(['q','status']),'sort'=>$sort,'direction'=>$direction]);
    }
    public function store(Request $request) { return $this->save($request,new Employee); }
    public function update(Request $request,Employee $employee) { return $this->save($request,$employee); }
    private function save(Request $request,Employee $employee) {
        $v=$request->validate(['name'=>'required|string|max:255','job_title'=>'nullable|string|max:255','abk_type'=>'required|in:AYAM,LELE,BOTH,NO_SYSTEM_ACCESS','api_username'=>['nullable','string','max:100',Rule::unique('employees')->ignore($employee->id)],'api_pin'=>'nullable|digits:6','joined_at'=>'required|date_format:Y-m-d','status'=>'required|in:ACTIVE,INACTIVE','notes'=>'nullable|string|max:3000']);
        if(filled($v['api_username']??null) && Employee::whereRaw('LOWER(api_username)=?',[strtolower($v['api_username'])])->when($employee->exists,fn($q)=>$q->whereKeyNot($employee->id))->exists()) throw \Illuminate\Validation\ValidationException::withMessages(['api_username'=>'Username API sudah digunakan.']);
        if(!$employee->exists && $v['abk_type']!=='NO_SYSTEM_ACCESS' && filled($v['api_username']??null) && !filled($v['api_pin']??null)) throw \Illuminate\Validation\ValidationException::withMessages(['api_pin'=>'PIN 6 digit wajib diisi saat mengaktifkan username API.']);
        if($v['abk_type']!=='NO_SYSTEM_ACCESS' && filled($v['api_pin']??null) && !filled($v['api_username']??null)) throw \Illuminate\Validation\ValidationException::withMessages(['api_username'=>'Username API wajib diisi saat mengatur PIN.']);
        $v['job_title']=match($v['abk_type']){'AYAM'=>'ABK Ayam','LELE'=>'ABK Lele','BOTH'=>'ABK Ayam & Lele',default=>'ABK'};
        if($v['abk_type']==='NO_SYSTEM_ACCESS') { $v['api_username']=null; $v['api_pin_hash']=null; }
        elseif(filled($v['api_pin']??null)) { $v['api_pin_hash']=Hash::make($v['api_pin']); $v['credentials_updated_at']=now(); }
        unset($v['api_pin']); if(!$employee->exists && !array_key_exists('api_pin_hash',$v)) $v['api_pin_hash']=null;
        $before=$employee->exists?$employee->toArray():null; $employee->fill($v)->save(); Audit::record($employee,$before?'employee_updated':'employee_created',$before); return back()->with('success','Data dan akses ABK disimpan.');
    }
}
