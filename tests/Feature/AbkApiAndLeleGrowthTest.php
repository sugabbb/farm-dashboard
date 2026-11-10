<?php

namespace Tests\Feature;

use App\Models\{AbkSubmission,Customer,Employee,FeedType,LivestockGroup,Revenue,User};
use App\Services\{FinanceService,LeleGrowthService,LeleSamplingService};
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Hash};
use Tests\TestCase;

class AbkApiAndLeleGrowthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $superadmin;
    private Employee $abk;
    private array $apiHeaders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        User::query()->update(['must_change_password' => false]);
        $this->admin = User::where('role', 'ADMIN')->firstOrFail();
        $this->superadmin = User::where('role', 'SUPERADMIN')->firstOrFail();
        config(['abk_api.key' => 'test-service-key']);
        $this->abk = Employee::create([
            'name' => 'ABK API', 'job_title' => 'ABK Ayam', 'abk_type' => 'AYAM',
            'api_username' => 'abk.ayam', 'api_pin_hash' => Hash::make('123456'),
            'joined_at' => '2026-01-01', 'status' => 'ACTIVE',
        ]);
        $this->apiHeaders = [
            'X-PBB-API-Key' => 'test-service-key',
            'Authorization' => 'Basic '.base64_encode('abk.ayam:123456'),
            'Accept' => 'application/json',
        ];
    }

    public function test_abk_daily_report_is_staged_approved_and_revisions_are_staged_again(): void
    {
        $group = LivestockGroup::create([
            'species' => 'AYAM', 'code' => 'API-AYAM', 'name' => 'API Ayam', 'location' => 'Kandang API',
            'start_date' => '2026-10-01', 'initial_population' => 100, 'status' => 'ACTIVE',
        ]);
        $payload = [
            'species' => 'AYAM', 'record_date' => '2026-10-20', 'group_id' => $group->id,
            'mortality' => 1, 'feeds' => [['feed_type_id'=>FeedType::where('species','AYAM')->firstOrFail()->id,'morning_kg'=>0,'noon_kg'=>0]], 'eggs' => [
                'UTUH' => ['weight_kg' => 0, 'quantity_pieces' => 0],
                'PECAH' => ['weight_kg' => 0, 'quantity_pieces' => 0],
                'REJECT' => ['weight_kg' => 0, 'quantity_pieces' => 0],
            ],
        ];

        $missingFeeds=$payload; unset($missingFeeds['feeds']);
        $this->withHeaders($this->apiHeaders)->postJson('/api/v1/daily-report-submissions',$missingFeeds)->assertUnprocessable()->assertJsonValidationErrors('feeds');
        $this->withHeaders($this->apiHeaders)->postJson('/api/v1/daily-report-submissions',array_merge($payload,['feeds'=>[]]))->assertUnprocessable()->assertJsonValidationErrors('feeds');

        $created = $this->withHeaders($this->apiHeaders + ['Idempotency-Key' => 'daily-1'])
            ->postJson('/api/v1/daily-report-submissions', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $submission = AbkSubmission::findOrFail($created->json('data.id'));
        $this->assertDatabaseMissing('daily_records', ['group_id' => $group->id, 'record_date' => '2026-10-20']);

        $this->actingAs($this->admin)->post("/abk-submissions/{$submission->id}/approve")->assertSessionHasNoErrors();
        $recordId = DB::table('daily_records')->where('group_id', $group->id)->value('id');
        $this->assertNotNull($recordId);

        $revision = $this->withHeaders($this->apiHeaders + ['Idempotency-Key' => 'daily-2'])
            ->postJson("/api/v1/daily-reports/{$recordId}/revisions", array_merge($payload, ['mortality' => 3]))
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $this->assertDatabaseHas('daily_records', ['id' => $recordId, 'mortality' => 1]);
        $this->actingAs($this->superadmin)->post('/abk-submissions/'.$revision->json('data.id').'/approve')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('daily_records', ['id' => $recordId, 'mortality' => 3]);
    }

    public function test_abk_scope_and_static_service_key_are_both_enforced(): void
    {
        $this->withHeaders(array_merge($this->apiHeaders, ['X-PBB-API-Key' => 'wrong']))->getJson('/api/v1/me')->assertUnauthorized();
        $this->withHeaders($this->apiHeaders)->getJson('/api/v1/reference/groups?species=LELE')->assertForbidden();
        $this->withHeaders($this->apiHeaders)->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.capabilities.daily_ayam', true)
            ->assertJsonPath('data.capabilities.daily_lele', false);
    }

    public function test_public_swagger_and_openapi_document_are_available(): void
    {
        $this->get('/docs/api')->assertOk();
        $this->getJson('/docs/api.json')->assertOk()->assertJsonStructure(['openapi', 'paths']);
    }

    public function test_direct_transfer_egg_sale_keeps_the_second_superadmin_approval(): void
    {
        $customer = Customer::create(['name' => 'Warung API', 'phone' => '08123', 'customer_type' => 'WARUNG']);
        DB::table('inventory_opening_balances')->insert([
            'item_kind' => 'EGG', 'egg_category' => 'UTUH', 'quantity_kg' => 10, 'quantity_pieces' => 100,
            'effective_date' => '2026-10-01', 'created_by' => $this->superadmin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->withHeaders($this->apiHeaders)->postJson('/api/v1/egg-sale-submissions', [
            'transaction_date' => '2026-10-20', 'customer_id' => $customer->id,
            'payment_method' => 'DIRECT_TRANSFER', 'direct_transfer_date' => '2026-10-21',
            'details' => [['egg_category' => 'UTUH', 'weight_kg' => 1, 'quantity_pieces' => 10, 'price_per_kg_idr' => 25000]],
        ])->assertCreated();

        $this->actingAs($this->admin)->post('/abk-submissions/'.$response->json('data.id').'/approve')->assertSessionHasNoErrors();
        $revenue = Revenue::firstOrFail();
        $this->assertSame('SUBMITTED', $revenue->approval_status);
        $this->assertSame('UNPAID', $revenue->payment_status);
        app(FinanceService::class)->decideRevenueTransfer($revenue, $this->superadmin, 'APPROVED');
        $this->assertSame('PAID', $revenue->fresh()->payment_status);

        $revision = $this->withHeaders($this->apiHeaders)->postJson("/api/v1/egg-sales/{$revenue->id}/revisions", [
            'transaction_date' => '2026-10-20', 'customer_id' => $customer->id,
            'payment_method' => 'DIRECT_TRANSFER', 'direct_transfer_date' => '2026-10-22',
            'details' => [['egg_category' => 'UTUH', 'weight_kg' => 1, 'quantity_pieces' => 10, 'price_per_kg_idr' => 26000]],
        ])->assertCreated();
        $this->actingAs($this->admin)->post('/abk-submissions/'.$revision->json('data.id').'/approve')->assertSessionHasNoErrors();
        $this->assertSame('SUBMITTED', $revenue->fresh()->approval_status);
        $this->assertSame('UNPAID', $revenue->fresh()->payment_status);
        app(FinanceService::class)->decideRevenueTransfer($revenue->fresh(), $this->superadmin, 'APPROVED');
        $this->assertSame('PAID', $revenue->fresh()->payment_status);
    }

    public function test_growth_points_are_persisted_from_latest_sampling_pair_and_closed_at_is_harvest_end(): void
    {
        Carbon::setTestNow('2026-10-30 12:00:00');
        $group = LivestockGroup::create([
            'species' => 'LELE', 'code' => 'LELE-GROWTH', 'name' => 'Lele Growth', 'location' => 'Kolam 1',
            'start_date' => '2026-10-01', 'initial_population' => 1000, 'initial_average_weight_g' => 20,
            'planned_adg_g_per_day' => 1.3, 'status' => 'ACTIVE',
        ]);
        $sampling = app(LeleSamplingService::class);
        foreach ([['2026-10-10', '0.300'], ['2026-10-20', '0.400']] as [$date, $weight]) {
            $sampling->save(['group_id' => $group->id, 'sampling_date' => $date, 'measurements' => [
                ['sequence' => 1, 'total_weight_kg' => $weight],
                ['sequence' => 2, 'total_weight_kg' => $weight],
                ['sequence' => 3, 'total_weight_kg' => $weight],
            ]], $this->admin);
        }
        $profile = $group->fresh()->growthProfile()->with('points')->firstOrFail();
        $this->assertSame('1.000000', (string) $profile->calculated_adg_g_per_day);
        $this->assertDatabaseHas('lele_growth_points', ['profile_id' => $profile->id, 'point_date' => '2026-10-20', 'actual_weight_g' => '40.000']);

        $group->update(['status' => 'CLOSED', 'closed_at' => '2026-10-25', 'closure_reason' => 'Panen']);
        $closed = app(LeleGrowthService::class)->rebuild($group->fresh());
        $this->assertSame('2026-10-25', $closed->chart_end_date->toDateString());
        $this->assertSame('2026-10-25', $closed->points()->max('point_date'));
        $this->assertContains('HARVEST', $closed->points()->whereDate('point_date', '2026-10-25')->firstOrFail()->point_kinds);
        Carbon::setTestNow();
    }
}
