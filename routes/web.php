<?php

use App\Http\Controllers\{AbkReviewController,AuthController,CustomerController,DashboardController,DailyEntryController,EmployeeController,FinanceExportController,GroupController,InventoryController,LeleBiomassController,OperationalCashflowController,ReportController,RevenueController,SettingsController,UserController};
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function() {
    Route::get('/login',[AuthController::class,'login'])->name('login');
    Route::post('/login',[AuthController::class,'authenticate'])->name('login.submit');
});

Route::middleware(['auth','active'])->group(function() {
    Route::post('/logout',[AuthController::class,'logout'])->name('logout');
    Route::get('/password',[AuthController::class,'password'])->name('password.edit');
    Route::post('/password',[AuthController::class,'updatePassword'])->name('password.update');
    Route::redirect('/','/dashboard');
    Route::get('/dashboard',[DashboardController::class,'index'])->name('dashboard');

    foreach(['ayam'=>'AYAM','lele'=>'LELE'] as $slug=>$species) {
        Route::get("/$slug/groups",[GroupController::class,'index'])->defaults('species',$species)->name("$slug.groups");
        Route::post("/$slug/groups",[GroupController::class,'store'])->defaults('species',$species)->name("$slug.groups.store");
        if($slug==='lele') Route::get("/$slug/groups/{group}",[GroupController::class,'show'])->name('lele.groups.show');
        Route::patch("/$slug/groups/{group}",[GroupController::class,'update'])->defaults('species',$species)->name("$slug.groups.update");
        Route::post("/$slug/groups/{group}/close",[GroupController::class,'close'])->defaults('species',$species)->name("$slug.groups.close");
        Route::post("/$slug/groups/{group}/adjust",[GroupController::class,'adjust'])->defaults('species',$species)->name("$slug.groups.adjust");
        Route::get("/$slug/feed",[InventoryController::class,'feed'])->defaults('species',$species)->name("$slug.feed");
        Route::get("/$slug/daily-entry",[DailyEntryController::class,'index'])->defaults('species',$species)->name("$slug.daily");
        Route::post("/$slug/daily-entry",[DailyEntryController::class,'store'])->defaults('species',$species)->name("$slug.daily.store");
        Route::patch("/$slug/daily-entry/{record}",[DailyEntryController::class,'update'])->defaults('species',$species)->name("$slug.daily.update");
    }
    Route::get('/ayam/eggs',[InventoryController::class,'eggs'])->name('ayam.eggs');
    Route::get('/lele/biomass',[LeleBiomassController::class,'index'])->name('lele.biomass');
    Route::post('/lele/biomass',[LeleBiomassController::class,'store'])->name('lele.biomass.store');
    Route::patch('/lele/biomass/{sampling}',[LeleBiomassController::class,'update'])->name('lele.biomass.update');
    Route::post('/inventory/adjustments',[InventoryController::class,'adjust'])->name('inventory.adjust');
    Route::patch('/inventory/adjustments/{adjustment}',[InventoryController::class,'updateAdjustment'])->name('inventory.adjustments.update');

    Route::get('/employees',[EmployeeController::class,'index'])->name('employees.index');
    Route::post('/employees',[EmployeeController::class,'store'])->name('employees.store');
    Route::patch('/employees/{employee}',[EmployeeController::class,'update'])->name('employees.update');
    Route::post('/abk-submissions/{submission}/approve',[AbkReviewController::class,'approve'])->name('abk-submissions.approve');
    Route::post('/abk-submissions/{submission}/reject',[AbkReviewController::class,'reject'])->name('abk-submissions.reject');

    Route::get('/expenses',fn()=>redirect('/operational-cashflow'.(request()->getQueryString()?'?'.request()->getQueryString():'')));
    Route::get('/revenue',fn()=>redirect('/revenues'.(request()->getQueryString()?'?'.request()->getQueryString():'')));

    Route::get('/operational-cashflow',[OperationalCashflowController::class,'index'])->name('operational-cashflow.index');
    Route::get('/operational-cashflow/export',[FinanceExportController::class,'operational'])->name('operational-cashflow.export');
    Route::post('/operational-cashflow',[OperationalCashflowController::class,'store'])->name('operational-cashflow.store');
    Route::patch('/operational-cashflow/{cashflow}',[OperationalCashflowController::class,'update'])->name('operational-cashflow.update');
    Route::post('/operational-cashflow/{cashflow}/submit',[OperationalCashflowController::class,'submit'])->name('operational-cashflow.submit');
    Route::post('/operational-cashflow/{cashflow}/decision',[OperationalCashflowController::class,'decision'])->name('operational-cashflow.decision');
    Route::post('/operational-cashflow/{cashflow}/pay',[OperationalCashflowController::class,'pay'])->name('operational-cashflow.pay');
    Route::patch('/operational-cashflow/{cashflow}/payments/{payment}/void',[OperationalCashflowController::class,'voidPayment'])->name('operational-cashflow.payments.void');
    Route::post('/operational-cashflow/feed-lines/{line}/receive',[OperationalCashflowController::class,'receive'])->name('operational-cashflow.receive');
    Route::patch('/operational-cashflow/{cashflow}/correction',[OperationalCashflowController::class,'correct'])->name('operational-cashflow.correct');

    Route::get('/revenues',[RevenueController::class,'index'])->name('revenues.index');
    Route::get('/revenues/export',[FinanceExportController::class,'revenues'])->name('revenues.export');
    Route::post('/revenues',[RevenueController::class,'store'])->name('revenues.store');
    Route::patch('/revenues/{revenue}',[RevenueController::class,'update'])->name('revenues.update');
    Route::post('/revenues/{revenue}/submit',[RevenueController::class,'submit'])->name('revenues.submit');
    Route::post('/revenues/{revenue}/decision',[RevenueController::class,'decision'])->name('revenues.decision');
    Route::post('/revenues/{revenue}/pay',[RevenueController::class,'pay'])->name('revenues.pay');
    Route::patch('/revenues/{revenue}/payments/{payment}/void',[RevenueController::class,'voidPayment'])->name('revenues.payments.void');
    Route::patch('/revenues/{revenue}/correction',[RevenueController::class,'correct'])->name('revenues.correct');

    Route::get('/reports',[ReportController::class,'index'])->name('reports.index');
    Route::get('/reports/export',[FinanceExportController::class,'report'])->name('reports.export');
    Route::get('/export-report',[ReportController::class,'exportIndex'])->name('export-report.index');
    Route::get('/export-report/download',[FinanceExportController::class,'report'])->name('export-report.download');

    Route::get('/customers',[CustomerController::class,'index'])->name('customers.index');
    Route::post('/customers',[CustomerController::class,'store'])->name('customers.store');
    Route::patch('/customers/{customer}',[CustomerController::class,'update'])->name('customers.update');

    Route::get('/settings',[SettingsController::class,'index'])->name('settings.index');
    Route::post('/settings/feed-types',[SettingsController::class,'feedType'])->name('settings.feed-types');
    Route::patch('/settings/feed-types/{feedType}',[SettingsController::class,'feedTypeUpdate'])->name('settings.feed-types.update');
    Route::post('/settings/lele-adg',[SettingsController::class,'leleAdg'])->name('settings.lele-adg');
    Route::post('/settings/opening/cash',[SettingsController::class,'cashOpening'])->name('settings.cash');
    Route::post('/settings/opening/inventory',[SettingsController::class,'inventoryOpening'])->name('settings.inventory');
    Route::post('/users',[UserController::class,'store'])->name('users.store');
    Route::post('/users/{user}',[UserController::class,'update'])->name('users.update');
});
