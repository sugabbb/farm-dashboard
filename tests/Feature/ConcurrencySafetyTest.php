<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\FinanceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrencySafetyTest extends TestCase {
    use DatabaseMigrations;

    public function test_concurrent_sales_can_make_egg_stock_negative(): void {
        $this->seed(DatabaseSeeder::class); $user=User::where('role','ADMIN')->firstOrFail();
        DB::table('inventory_opening_balances')->insert(['item_kind'=>'EGG','egg_category'=>'UTUH','quantity_kg'=>5,'quantity_pieces'=>50,'effective_date'=>'2026-10-01','created_by'=>$user->id,'created_at'=>now(),'updated_at'=>now()]);
        $barrier=sys_get_temp_dir().'/pbb-concurrency-'.uniqid();
        $connection=config('database.connections.pgsql');
        $env=['APP_ENV'=>'testing','APP_KEY'=>(string)config('app.key'),'DB_CONNECTION'=>'pgsql','DB_DATABASE'=>(string)config('database.connections.pgsql.database'),'DB_HOST'=>(string)$connection['host'],'DB_PORT'=>(string)$connection['port'],'DB_USERNAME'=>(string)$connection['username'],'DB_PASSWORD'=>(string)$connection['password']];
        $command=[PHP_BINARY,base_path('tests/Support/revenue-worker.php'),(string)$user->id,$barrier];
        $first=new Process($command,base_path(),$env); $second=new Process($command,base_path(),$env);
        $first->start(); $second->start(); file_put_contents($barrier,'go');
        $first->wait(); $second->wait(); @unlink($barrier);
        $results=[$first->getOutput(),$second->getOutput()]; sort($results);
        $this->assertSame(['OK','OK'],$results);
        $this->assertDatabaseCount('revenues',2); $this->assertDatabaseCount('revenue_egg_details',2);
        $this->assertSame(['kg'=>'-3.000','pieces'=>-30],app(\App\Services\Balances::class)->egg('UTUH','2026-10-09'));
    }

    public function test_concurrent_transfer_approvals_use_the_latest_revenue_balance(): void {
        $this->seed(DatabaseSeeder::class);
        $admin=User::where('role','ADMIN')->firstOrFail(); $superadmin=User::where('role','SUPERADMIN')->firstOrFail();
        DB::table('financial_opening_balances')->insert(['account'=>'REVENUE','amount_idr'=>100000,'effective_date'=>today()->toDateString(),'created_by'=>$superadmin->id,'created_at'=>now(),'updated_at'=>now()]);
        $finance=app(FinanceService::class); $transfers=[];
        foreach([1,2] as $number) {
            $transfer=$finance->saveRevenue(['revenue_type'=>'MAIN_ACCOUNT_TRANSFER','transaction_date'=>today()->toDateString(),'amount_idr'=>75000,'details'=>['notes'=>'Concurrent '.$number]],$admin);
            $finance->submitRevenueTransfer($transfer,$admin); $transfers[]=$transfer;
        }
        $barrier=sys_get_temp_dir().'/pbb-transfer-concurrency-'.uniqid();
        $connection=config('database.connections.pgsql');
        $env=['APP_ENV'=>'testing','APP_KEY'=>(string)config('app.key'),'DB_CONNECTION'=>'pgsql','DB_DATABASE'=>(string)config('database.connections.pgsql.database'),'DB_HOST'=>(string)$connection['host'],'DB_PORT'=>(string)$connection['port'],'DB_USERNAME'=>(string)$connection['username'],'DB_PASSWORD'=>(string)$connection['password']];
        $workers=collect($transfers)->map(fn($transfer)=>new Process([PHP_BINARY,base_path('tests/Support/transfer-approval-worker.php'),(string)$superadmin->id,(string)$transfer->id,$barrier],base_path(),$env));
        $workers->each->start(); file_put_contents($barrier,'go'); $workers->each->wait(); @unlink($barrier);
        $results=$workers->map->getOutput()->sort()->values()->all();
        $this->assertSame(['OK','WARNING'],$results);
        $this->assertSame(1,DB::table('revenues')->where('revenue_type','MAIN_ACCOUNT_TRANSFER')->where('approval_status','APPROVED')->where('payment_status','PAID')->count());
        $this->assertSame(1,DB::table('revenues')->where('revenue_type','MAIN_ACCOUNT_TRANSFER')->where('approval_status','SUBMITTED')->where('payment_status','UNPAID')->count());
    }
}
