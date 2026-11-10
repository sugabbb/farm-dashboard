<?php

use App\Models\{Revenue,User};
use App\Services\FinanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script,$userId,$transferId,$barrier]=$argv;
Auth::login(User::findOrFail((int)$userId));
$limit=microtime(true)+10;
while(!is_file($barrier) && microtime(true)<$limit) usleep(10000);
try {
    app(FinanceService::class)->decideRevenueTransfer(Revenue::findOrFail((int)$transferId),Auth::user(),'APPROVED');
    echo 'OK';
} catch(ValidationException $exception) {
    echo isset($exception->errors()['balance_warning'])?'WARNING':'REJECTED';
} catch(Throwable $exception) {
    echo 'REJECTED';
}
