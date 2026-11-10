<?php
namespace App\Console\Commands;
use App\Models\LivestockGroup;
use App\Services\LeleGrowthService;
use Illuminate\Console\Command;
class BackfillLeleGrowth extends Command {
    protected $signature='biomass:backfill-growth {--apply : Persist derived profiles and points} {--active-only : Rebuild only active groups}';
    protected $description='Inspect or rebuild persisted Lele ADG growth data from existing sampling records.';
    public function handle(LeleGrowthService $service): int {
        $query=LivestockGroup::where('species','LELE')->when($this->option('active-only'),fn($query)=>$query->where('status','ACTIVE'));
        $groups=(clone $query)->count();
        $missing=(clone $query)->whereNull('initial_average_weight_g')->pluck('code');
        $this->info("Lele groups: {$groups}; missing initial weight: {$missing->count()}");
        if($missing->isNotEmpty()) $this->line('Manual initial weight required: '.$missing->join(', '));
        if(!$this->option('apply')) { $this->warn('Dry run only. Re-run with --apply to persist derived rows.'); return self::SUCCESS; }
        $result=$service->rebuildAll(null,(bool)$this->option('active-only')); $this->info("Rebuilt {$result['rebuilt']} profiles."); return self::SUCCESS;
    }
}
