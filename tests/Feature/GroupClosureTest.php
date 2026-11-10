<?php

namespace Tests\Feature;

use App\Models\{LivestockGroup,User};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class GroupClosureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        User::query()->update(['must_change_password'=>false]);
        $this->admin=User::where('role','ADMIN')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_lele_closure_requires_and_stores_a_free_text_reason(): void
    {
        $group=LivestockGroup::create(['species'=>'LELE','code'=>'LELE-CLOSE','name'=>'Kolam Close','location'=>'Kolam 1','start_date'=>'2026-10-01','initial_population'=>1000,'status'=>'ACTIVE']);

        $this->post("/lele/groups/{$group->id}/close",['closed_at'=>'2026-10-17'])->assertSessionHasErrors('reason');
        $this->assertSame('ACTIVE',$group->fresh()->status);

        $this->post("/lele/groups/{$group->id}/close",['closed_at'=>'2026-10-17','reason'=>'Siklus panen selesai dan kolam dikeringkan.'])->assertSessionHasNoErrors();
        $group->refresh();
        $this->assertSame('CLOSED',$group->status);
        $this->assertSame('2026-10-17',$group->closed_at->toDateString());
        $this->assertSame('Siklus panen selesai dan kolam dikeringkan.',$group->closure_reason);
        $this->assertDatabaseHas('audit_events',['entity_type'=>'livestock_groups','entity_id'=>$group->id,'action'=>'group_closed','reason'=>'Siklus panen selesai dan kolam dikeringkan.']);

        $this->get('/lele/groups?status=CLOSED')->assertInertia(fn (Assert $page) => $page
            ->where('groups.data.0.id',$group->id)
            ->where('groups.data.0.closure_reason','Siklus panen selesai dan kolam dikeringkan.'));
    }

    public function test_ayam_closure_does_not_require_a_reason(): void
    {
        $group=LivestockGroup::create(['species'=>'AYAM','code'=>'AYAM-CLOSE','name'=>'Kandang Close','location'=>'Kandang 1','start_date'=>'2026-10-01','initial_population'=>100,'status'=>'ACTIVE']);
        $this->post("/ayam/groups/{$group->id}/close",['closed_at'=>'2026-10-17'])->assertSessionHasNoErrors();
        $this->assertSame('CLOSED',$group->fresh()->status);
        $this->assertNull($group->fresh()->closure_reason);
    }

    public function test_new_lele_group_code_uses_pond_before_name_without_changing_existing_groups(): void
    {
        $existing=LivestockGroup::create(['species'=>'LELE','code'=>'LEGACY-GROUP','name'=>'Nama Lama','location'=>'Kolam Lama','start_date'=>'2026-10-01','initial_population'=>1000,'status'=>'ACTIVE']);

        $this->post('/lele/groups',[
            'name'=>'Siklus 3','location'=>'Kolam C2','start_date'=>'2026-10-21','initial_population'=>8500,'notes'=>null,
            'initial_average_weight_g'=>'10.000',
        ])->assertSessionHasNoErrors();

        $created=LivestockGroup::where('name','Siklus 3')->firstOrFail();
        $this->assertSame('KOLAM-C2-SIKLUS-3',$created->code);
        $this->assertSame('Siklus 3',$created->name);
        $this->assertSame('Kolam C2',$created->location);
        $this->assertSame('LEGACY-GROUP',$existing->fresh()->code);

        $this->get('/lele/daily-entry')->assertInertia(fn (Assert $page) => $page
            ->where('groups',fn($groups)=>collect($groups)->contains(fn($group)=>$group['id']===$created->id && $group['location']==='Kolam C2' && $group['name']==='Siklus 3')));
    }
}
