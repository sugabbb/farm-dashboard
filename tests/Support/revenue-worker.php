<?php
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script,$userId,$barrier]=$argv;
Auth::login(User::findOrFail((int)$userId));
$limit=microtime(true)+10;
while(!is_file($barrier) && microtime(true)<$limit) usleep(10000);
try {
    app(FinanceService::class)->saveRevenue(['revenue_type'=>'EGG_SALE','transaction_date'=>'2026-10-09','details'=>[['egg_category'=>'UTUH','weight_kg'=>'4','quantity_pieces'=>40,'price_per_kg_idr'=>25000]]],Auth::user());
    echo 'OK';
} catch(Throwable $e) { echo 'REJECTED'; }
