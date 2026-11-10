<?php
namespace Database\Seeders;
use App\Models\{FeedType,User};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class DatabaseSeeder extends Seeder {
    public function run(): void {
        DB::transaction(function() {
            foreach(['SUPERADMIN','ADMIN'] as $role) {
                $key=strtoupper($role); $password=env($key.'_PASSWORD');
                if(!$password && !app()->environment(['local','testing'])) throw new \RuntimeException('Set '.$key.'_PASSWORD sebelum seed di production.');
                User::firstOrCreate(['email'=>env($key.'_EMAIL',strtolower($role).'@pondokberkah.test')],['name'=>ucfirst(strtolower($role)),'role'=>$role,'password'=>$password??($role==='SUPERADMIN'?'SuperadminFarm2026!':'AdminFarm2026!'),'is_active'=>true,'must_change_password'=>true]);
            }
            foreach([['AYAM','Layer'],['AYAM','Grower'],['LELE','781-2']] as [$species,$name]) FeedType::firstOrCreate(compact('species','name'),['is_active'=>true]);
        });
    }
}
