<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FeedStockLedger
{
    private const SOURCE_ORDER = ['OPENING' => 1, 'RECEIPT' => 2, 'ADJUSTMENT' => 3, 'USAGE' => 4];

    public function paginate(string $species, string $date, ?int $feedTypeId, string $sort, string $direction, int $perPage = 40): LengthAwarePaginator
    {
        $movements = $this->movements($species, $date, $feedTypeId);
        $movements = $movements->sort(function (array $left, array $right) use ($sort, $direction) {
            $comparison = $this->compareField($left, $right, $sort);
            if ($comparison === 0) {
                $comparison = $this->compareChronologically($left, $right);
                if ($sort === 'movement_date' && $direction === 'desc') $comparison *= -1;
            } elseif ($direction === 'desc') $comparison *= -1;
            return $comparison;
        })->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        return (new LengthAwarePaginator(
            $movements->slice(($page - 1) * $perPage, $perPage)->values(),
            $movements->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        ))->withQueryString();
    }

    /** @return Collection<int,array<string,mixed>> */
    public function movements(string $species, string $date, ?int $feedTypeId = null): Collection
    {
        $feedIds = DB::table('feed_types')->where('species', $species)
            ->when($feedTypeId, fn ($query) => $query->where('id', $feedTypeId))
            ->pluck('id');
        if ($feedIds->isEmpty()) return collect();

        $rows = collect();
        foreach (DB::table('inventory_opening_balances as opening')
            ->join('feed_types as feed', 'feed.id', '=', 'opening.feed_type_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'opening.created_by')
            ->where('opening.item_kind', 'FEED')->whereIn('opening.feed_type_id', $feedIds)
            ->where('opening.effective_date', '<=', $date)->where('opening.quantity_kg', '<>', 0)
            ->get(['opening.id', 'opening.feed_type_id', 'feed.name as feed_name', 'opening.effective_date as movement_date', 'opening.quantity_kg as quantity_delta', 'opening.created_at as recorded_at', 'creator.name as creator_name']) as $row) {
            $rows->push($this->row('OPENING', $row, "Saldo awal inventori oleh ".($row->creator_name ?: '—'), "Opening #{$row->id}"));
        }

        foreach (DB::table('operational_cashflow_feed_details as detail')
            ->join('operational_cashflows as cashflow', 'cashflow.id', '=', 'detail.operational_cashflow_id')
            ->join('feed_types as feed', 'feed.id', '=', 'detail.feed_type_id')
            ->leftJoin('users as receiver', 'receiver.id', '=', 'detail.received_by')
            ->whereIn('detail.feed_type_id', $feedIds)->where('cashflow.approval_status', 'APPROVED')
            ->whereNotNull('detail.received_at')->whereDate('detail.received_at', '<=', $date)->where('detail.quantity_kg', '<>', 0)
            ->get(['detail.id', 'detail.feed_type_id', 'feed.name as feed_name', 'detail.quantity_kg as quantity_delta', 'detail.received_at', 'detail.updated_at as recorded_at', 'detail.operational_cashflow_id', 'receiver.name as receiver_name']) as $row) {
            $row->movement_date = substr((string) $row->received_at, 0, 10);
            $rows->push($this->row('RECEIPT', $row, "Penerimaan oleh ".($row->receiver_name ?: '—'), "Operational Cashflow #{$row->operational_cashflow_id}"));
        }

        foreach (DB::table('inventory_adjustments as adjustment')
            ->join('feed_types as feed', 'feed.id', '=', 'adjustment.feed_type_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'adjustment.created_by')
            ->where('adjustment.item_kind', 'FEED')->whereIn('adjustment.feed_type_id', $feedIds)
            ->where('adjustment.effective_date', '<=', $date)->where('adjustment.delta_kg', '<>', 0)
            ->get(['adjustment.id', 'adjustment.feed_type_id', 'feed.name as feed_name', 'adjustment.effective_date as movement_date', 'adjustment.delta_kg as quantity_delta', 'adjustment.reason', 'adjustment.created_at as recorded_at', 'creator.name as creator_name']) as $row) {
            $movement=$this->row('ADJUSTMENT', $row, $row->reason.' · '.($row->creator_name ?: '—'), "Adjustment #{$row->id}");
            $movement['adjustment']=['id'=>(int)$row->id,'feed_type_id'=>(int)$row->feed_type_id,'delta_kg'=>(string)$row->quantity_delta,'effective_date'=>substr((string)$row->movement_date,0,10),'reason'=>$row->reason];
            $rows->push($movement);
        }

        foreach (DB::table('feed_usages as usage')
            ->join('daily_records as record', 'record.id', '=', 'usage.daily_record_id')
            ->join('livestock_groups as livestock_group', 'livestock_group.id', '=', 'record.group_id')
            ->join('feed_types as feed', 'feed.id', '=', 'usage.feed_type_id')
            ->where('livestock_group.species', $species)->whereIn('usage.feed_type_id', $feedIds)
            ->where('record.record_date', '<=', $date)->where('usage.quantity_kg', '<>', 0)
            ->get(['usage.id', 'usage.feed_type_id', 'feed.name as feed_name', 'record.record_date as movement_date', 'record.created_at as recorded_at', 'record.id as daily_record_id', 'livestock_group.name as group_name', 'usage.morning_kg', 'usage.noon_kg', 'usage.evening_kg', 'usage.quantity_kg']) as $row) {
            $row->quantity_delta = Decimal::sub(0, (string) $row->quantity_kg);
            $schedule = "Pagi {$row->morning_kg} kg · Siang {$row->noon_kg} kg".($species === 'LELE' ? " · Sore {$row->evening_kg} kg" : '');
            $rows->push($this->row('USAGE', $row, "{$row->group_name} · {$schedule}", "Laporan Harian #{$row->daily_record_id}"));
        }

        $balances = [];
        return $rows->sort(fn (array $left, array $right) => $this->compareChronologically($left, $right))->values()
            ->map(function (array $movement) use (&$balances) {
                $feedId = $movement['feed_id'];
                $balances[$feedId] = Decimal::add($balances[$feedId] ?? '0', $movement['quantity_delta']);
                $movement['balance_after'] = $balances[$feedId];
                return $movement;
            });
    }

    private function row(string $type, object $row, string $description, string $reference): array
    {
        $delta = Decimal::add((string) $row->quantity_delta, '0');
        return [
            'key' => strtolower($type).'-'.$row->id,
            'movement_date' => substr((string) $row->movement_date, 0, 10),
            'recorded_at' => Carbon::parse($row->recorded_at)->toIso8601String(),
            'feed_id' => (int) $row->feed_type_id,
            'feed_name' => $row->feed_name,
            'movement_type' => $type,
            'reference' => $reference,
            'description' => $description,
            'quantity_in' => Decimal::compare($delta, '0') > 0 ? $delta : '0.000',
            'quantity_out' => Decimal::compare($delta, '0') < 0 ? Decimal::sub('0', $delta) : '0.000',
            'quantity_delta' => $delta,
            'balance_after' => '0.000',
            'source_type' => $type,
            'source_id' => (int) $row->id,
            'adjustment' => null,
        ];
    }

    private function compareChronologically(array $left, array $right): int
    {
        return strcmp($left['movement_date'], $right['movement_date'])
            ?: strcmp($left['recorded_at'], $right['recorded_at'])
            ?: (self::SOURCE_ORDER[$left['source_type']] <=> self::SOURCE_ORDER[$right['source_type']])
            ?: ($left['source_id'] <=> $right['source_id']);
    }

    private function compareField(array $left, array $right, string $field): int
    {
        if (in_array($field, ['quantity_in', 'quantity_out', 'quantity_delta', 'balance_after'], true)) {
            return Decimal::compare($left[$field], $right[$field]);
        }
        return strcmp((string) $left[$field], (string) $right[$field]);
    }
}
