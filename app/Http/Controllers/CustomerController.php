<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Audit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerController extends Controller {
    public function index(Request $request) {
        $q=Customer::query()->withCount('revenues');
        if($request->filled('q')) $q->where(fn($query)=>$query->where('name','ilike','%'.$request->q.'%')->orWhere('phone','ilike','%'.$request->q.'%'));
        if(in_array($request->customer_type,['WARUNG','AGEN'])) $q->where('customer_type',$request->customer_type);
        [$sort,$direction,$column]=$this->sorting($request,['name'=>'name','phone'=>'phone','customer_type'=>'customer_type','revenues_count'=>'revenues_count'],'name');
        return Inertia::render('Customers',[
            'customers'=>$q->orderBy($column,$direction)->orderBy('id')->paginate(30)->withQueryString(),
            'filters'=>$request->only(['q','customer_type']),'sort'=>$sort,'direction'=>$direction,
        ]);
    }

    public function store(Request $request) {
        $customer=Customer::create($this->validated($request));
        Audit::record($customer,'customer_created');
        return back()->with('success','Pelanggan ditambahkan.');
    }

    public function update(Request $request,Customer $customer) {
        $before=$customer->toArray(); $customer->update($this->validated($request));
        Audit::record($customer,'customer_updated',$before);
        return back()->with('success','Pelanggan diperbarui.');
    }

    private function validated(Request $request): array {
        return $request->validate([
            'name'=>'required|string|max:255','phone'=>'required|string|max:50',
            'customer_type'=>'required|in:WARUNG,AGEN','notes'=>'nullable|string|max:3000',
        ]);
    }
}
