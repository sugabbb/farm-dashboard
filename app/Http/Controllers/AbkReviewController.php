<?php
namespace App\Http\Controllers;
use App\Models\AbkSubmission;
use App\Services\AbkSubmissionService;
use Illuminate\Http\Request;
class AbkReviewController extends Controller {
 public function approve(Request $request,AbkSubmission $submission,AbkSubmissionService $service){$v=$request->validate(['acknowledge_negative_balance'=>'nullable|boolean']);$service->approve($submission,$request->user(),(bool)($v['acknowledge_negative_balance']??false));return back()->with('success','Draft ABK disetujui dan diproses.');}
 public function reject(Request $request,AbkSubmission $submission,AbkSubmissionService $service){$v=$request->validate(['reason'=>'required|string|max:3000']);$service->reject($submission,$request->user(),$v['reason']);return back()->with('success','Draft ABK ditolak.');}
}
