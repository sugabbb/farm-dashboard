<?php
use App\Http\Controllers\Api\AbkApiController;
use Illuminate\Support\Facades\Route;
Route::prefix('v1')->middleware('abk.api')->group(function(){
 Route::get('/me',[AbkApiController::class,'me']);
 Route::get('/reference/groups',[AbkApiController::class,'groups']); Route::get('/reference/feed-types',[AbkApiController::class,'feeds']); Route::get('/reference/customers',[AbkApiController::class,'customers']);
 Route::get('/daily-reports',[AbkApiController::class,'daily']); Route::get('/daily-reports/{record}',[AbkApiController::class,'dailyShow']); Route::get('/daily-report-submissions',[AbkApiController::class,'dailySubmissions']);
 Route::post('/daily-report-submissions',[AbkApiController::class,'createDaily']); Route::post('/daily-reports/{record}/revisions',[AbkApiController::class,'reviseDaily']); Route::patch('/daily-report-submissions/{submission}',[AbkApiController::class,'updateDaily']);
 Route::get('/egg-sales',[AbkApiController::class,'eggSales']); Route::get('/egg-sales/{revenue}',[AbkApiController::class,'eggShow']); Route::get('/egg-sale-submissions',[AbkApiController::class,'eggSubmissions']);
 Route::post('/egg-sale-submissions',[AbkApiController::class,'createEgg']); Route::post('/egg-sales/{revenue}/revisions',[AbkApiController::class,'reviseEgg']); Route::patch('/egg-sale-submissions/{submission}',[AbkApiController::class,'updateEgg']);
});
