<?php
namespace App\Services;
use App\Models\{AbkSubmission,DailyRecord,Employee,Revenue,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
final class AbkSubmissionService {
 public function create(string $kind,array $payload,Employee $employee,?string $idempotency=null,?string $canonicalType=null,?int $canonicalId=null): AbkSubmission {
  $species=$kind==='DAILY_AYAM'?'AYAM':($kind==='DAILY_LELE'?'LELE':'AYAM'); $this->authorizeScope($employee,$species,$kind);
  if($idempotency && ($existing=AbkSubmission::where('employee_id',$employee->id)->where('idempotency_key',$idempotency)->first())) return $existing;
  $business=$kind==='EGG_SALE'?($canonicalId?'revenue:'.$canonicalId:'egg:new:'.($idempotency??Str::uuid())):strtolower($species).':'.$payload['group_id'].':'.$payload['record_date'];
  if($kind!=='EGG_SALE' && !$canonicalId) $canonicalId=DailyRecord::where('group_id',$payload['group_id'])->where('record_date',$payload['record_date'])->value('id');
  if($canonicalId && !$canonicalType) $canonicalType=$kind==='EGG_SALE'?'revenues':'daily_records';
  return DB::transaction(function() use($kind,$payload,$employee,$idempotency,$canonicalType,$canonicalId,$species,$business) {
   if(AbkSubmission::where('kind',$kind)->where('business_key',$business)->where('status','DRAFT')->exists()) throw ValidationException::withMessages(['submission'=>'Sudah ada draft untuk data ini.']);
   $canonical=$canonicalType==='revenues'?Revenue::find($canonicalId):($canonicalType==='daily_records'?DailyRecord::find($canonicalId):null);
   $revision=AbkSubmission::where('kind',$kind)->where('business_key',$business)->max('revision')+1;
   $submission=AbkSubmission::create(['kind'=>$kind,'species'=>$species,'employee_id'=>$employee->id,'business_key'=>$business,'canonical_type'=>$canonicalType,'canonical_id'=>$canonicalId,'revision'=>$revision,'base_updated_at'=>$canonical?->updated_at,'payload'=>$payload,'status'=>'DRAFT','idempotency_key'=>$idempotency]);
   Audit::record($submission,'abk_draft_created',null,null,null,null,$employee->id); return $submission;
  });
 }
 public function update(AbkSubmission $submission,array $payload,Employee $employee): AbkSubmission {
  $this->authorizeScope($employee,$submission->species,$submission->kind); if(!in_array($submission->status,['DRAFT','REJECTED'])) abort(409,'Hanya draft atau data ditolak yang dapat diedit.');
  $before=$submission->toArray(); $submission->update(['payload'=>$payload,'status'=>'DRAFT','rejection_reason'=>null,'reviewed_by'=>null,'reviewed_at'=>null]); Audit::record($submission,'abk_draft_updated',$before,null,null,null,$employee->id); return $submission->fresh();
 }
 public function approve(AbkSubmission $source,User $actor,bool $acknowledgeNegative=false): AbkSubmission {
  return DB::transaction(function() use($source,$actor,$acknowledgeNegative) {
   $submission=AbkSubmission::lockForUpdate()->findOrFail($source->id); if($submission->status!=='DRAFT') abort(409,'Submission bukan draft.'); $payload=$submission->payload;
   if($submission->canonical_id) { $table=$submission->canonical_type; $current=DB::table($table)->where('id',$submission->canonical_id)->lockForUpdate()->first(); if(!$current || ($submission->base_updated_at && !\Carbon\Carbon::parse($current->updated_at)->equalTo($submission->base_updated_at))) abort(409,'Data utama berubah setelah draft dibuat.'); }
   if(str_starts_with($submission->kind,'DAILY_')) {
    $species=$submission->species; $service=app(FarmOperations::class);
    if($submission->canonical_id) { $record=DailyRecord::findOrFail($submission->canonical_id); $service->updateDaily($species,$record,$payload,$actor); }
    else { $service->saveDaily($species,[$payload],$actor); $submission->canonical_id=DailyRecord::where('group_id',$payload['group_id'])->where('record_date',$payload['record_date'])->value('id'); $submission->canonical_type='daily_records'; }
   } else {
    if($submission->proof_path)$payload+=['proof_path'=>$submission->proof_path,'proof_url'=>$submission->proof_url]; $finance=app(FinanceService::class); $existing=$submission->canonical_id?Revenue::findOrFail($submission->canonical_id):null; $correction=$existing && ($existing->payment_status==='PAID' || $existing->payments()->whereNull('voided_at')->exists());
    $revenue=$finance->saveRevenue($payload,$actor,$existing,$correction,$correction?'Revisi ABK disetujui.':null,$acknowledgeNegative,true);
    if($correction && ($payload['payment_method']??null)==='DIRECT_TRANSFER') {
     $beforeTransferReview=$revenue->toArray();
     $revenue->update(['approval_status'=>'SUBMITTED','payment_status'=>'UNPAID','submitted_by'=>$actor->id,'submitted_at'=>now(),'approved_by'=>null,'approved_at'=>null,'paid_by'=>null,'paid_at'=>null]);
     Audit::record($revenue,'abk_direct_transfer_revision_submitted',$beforeTransferReview,'Persetujuan transfer kedua oleh Superadmin tetap diperlukan.',null,$actor->id);
    }
    if(($payload['payment_method']??null)==='CASH' && ($payload['payment_status']??'UNPAID')==='PAID' && $revenue->payment_status==='UNPAID') $finance->payRevenue($revenue,$actor,$payload['paid_date']);
    $submission->canonical_id=$revenue->id; $submission->canonical_type='revenues';
   }
   $before=$submission->toArray(); $submission->status='APPROVED';$submission->reviewed_by=$actor->id;$submission->reviewed_at=now();$submission->save();Audit::record($submission,'abk_submission_approved',$before,null,null,$actor->id); return $submission;
  },3);
 }
 public function reject(AbkSubmission $source,User $actor,string $reason): AbkSubmission { $submission=AbkSubmission::lockForUpdate()->findOrFail($source->id);if($submission->status!=='DRAFT')abort(409);$before=$submission->toArray();$submission->update(['status'=>'REJECTED','rejection_reason'=>$reason,'reviewed_by'=>$actor->id,'reviewed_at'=>now()]);Audit::record($submission,'abk_submission_rejected',$before,$reason,null,$actor->id);return $submission; }
 private function authorizeScope(Employee $employee,string $species,string $kind): void { if(!$employee->canAccessSpecies($species) || ($kind==='EGG_SALE'&&!in_array($employee->abk_type,['AYAM','BOTH']))) abort(403,'Akses ABK tidak sesuai jenis data.'); }
}
