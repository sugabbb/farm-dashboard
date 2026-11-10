<?php

namespace App\Services;

use App\Models\{OperationalCashflow, Revenue};
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

final class MonthlyReport
{
    public function __construct(private FinancePayments $payments) {}
    public const EXPENSE_LABELS = [
        'FEED_AYAM' => 'Pakan Ayam', 'FEED_LELE' => 'Pakan Lele', 'SALARY' => 'Gaji ABK',
        'UTILITY' => 'Utilitas', 'HEALTH' => 'Kesehatan', 'OTHER' => 'Lain-lain',
        'LELE_SEED_PURCHASE' => 'Pembelian Benih Lele', 'PULLET_PURCHASE' => 'Pembelian Pullet',
        'BANK_ADMIN_FEE' => 'Biaya Admin Bank',
    ];
    public const REVENUE_LABELS = [
        'EGG_SALE' => 'Penjualan Telur', 'FISH_SALE' => 'Penjualan Lele', 'OTHER' => 'Pemasukan Lain-lain',
    ];

    public function range(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01', config('app.timezone'))->startOfMonth();
        $today = today();
        $end = $start->isSameMonth($today) ? $today : $start->copy()->endOfMonth();
        return [$start->toDateString(), $end->toDateString()];
    }

    public function build(string $month): array
    {
        [$from, $to] = $this->range($month);
        $revenueBase = fn () => Revenue::query()->whereIn('revenue_type', array_keys(self::REVENUE_LABELS))->whereBetween('transaction_date', [$from, $to]);
        $expenseBase = fn () => OperationalCashflow::query()->where('direction', 'OUTFLOW')->where('approval_status', 'APPROVED')->whereBetween('transaction_date', [$from, $to]);

        $cashRows = [
            $this->cashMovementRow('topups', 'Top Up dari Rekening Utama', OperationalCashflow::query()->where('cashflow_type', 'MAIN_ACCOUNT_TOPUP'), $from, $to),
            ...$this->manualTransferRows($from, $to),
            $this->cashMovementRow('direct_transfers', 'Direct Transfer ke Rekening Utama', Revenue::query()->where('payment_method', 'DIRECT_TRANSFER')->where('approval_status', '<>', 'REJECTED'), $from, $to),
        ];

        $revenueRows = [$this->row('revenue_total', 'Total Revenues', $revenueBase())];
        foreach (self::REVENUE_LABELS as $type => $label) $revenueRows[] = $this->row(strtolower($type), $label, $revenueBase()->where('revenue_type', $type));
        $revenueRows[]=$this->row('revenue_cash_method','Pembayaran Cash',$revenueBase()->where('payment_method','CASH'));
        $revenueRows[]=$this->row('revenue_direct_transfer_method','Pembayaran Direct Transfer',$revenueBase()->where('payment_method','DIRECT_TRANSFER'));

        $expenseRows = [
            $this->row('expense_total', 'Total Operational Approved', $expenseBase()),
            $this->row('livestock_purchases', 'Pembelian Benih Lele & Pullet', $expenseBase()->whereIn('cashflow_type', ['LELE_SEED_PURCHASE', 'PULLET_PURCHASE'])),
        ];
        foreach (self::EXPENSE_LABELS as $type => $label) $expenseRows[] = $this->row(strtolower($type), $label, $expenseBase()->where('cashflow_type', $type));

        return [
            'month' => $month, 'from' => $from, 'to' => $to,
            'sections' => [
                ['key' => 'cash', 'label' => 'Pergerakan Kas', 'rows' => $cashRows],
                ['key' => 'revenue', 'label' => 'Revenues', 'rows' => $revenueRows],
                ['key' => 'expenses', 'label' => 'Operational Expenses', 'rows' => $expenseRows],
            ],
        ];
    }

    public function operationalDetails(string $month)
    {
        [$from, $to] = $this->range($month);
        return OperationalCashflow::with(['feedDetails.feedType', 'creator', 'payer','payments.creator'])
            ->where(function (Builder $query) use ($from, $to) {
                $query->where(function (Builder $q) use ($from, $to) {
                    $q->where('direction', 'OUTFLOW')->where('approval_status', 'APPROVED')->whereBetween('transaction_date', [$from, $to]);
                })->orWhere(function (Builder $q) use ($from, $to) {
                    $q->where('cashflow_type', 'MAIN_ACCOUNT_TOPUP')->where(function (Builder $dates) use ($from, $to) {
                        $dates->whereBetween('paid_at', [$from.' 00:00:00', $to.' 23:59:59'])
                            ->orWhere(fn (Builder $unpaid) => $unpaid->where('payment_status', 'UNPAID')->whereBetween('transaction_date', [$from, $to]));
                    });
                });
            })->orderBy('transaction_date')->orderBy('id')->get();
    }

    public function revenueDetails(string $month)
    {
        [$from, $to] = $this->range($month);
        return Revenue::with(['eggDetails', 'fishDetail', 'customer', 'creator', 'payer','payments.creator'])
            ->where(function (Builder $query) use ($from, $to) {
                $query->where(fn (Builder $q) => $q->whereIn('revenue_type', array_keys(self::REVENUE_LABELS))->whereBetween('transaction_date', [$from, $to]))
                    ->orWhere(function (Builder $q) use ($from, $to) {
                        $q->where('revenue_type', 'MAIN_ACCOUNT_TRANSFER')->where('approval_status', '<>', 'REJECTED')->where(function (Builder $dates) use ($from, $to) {
                            $dates->whereBetween('paid_at', [$from.' 00:00:00', $to.' 23:59:59'])
                                ->orWhere(fn (Builder $unpaid) => $unpaid->where('payment_status', 'UNPAID')->whereBetween('transaction_date', [$from, $to]));
                        });
                    });
            })->orderBy('transaction_date')->orderBy('id')->get();
    }

    private function row(string $key, string $label, Builder $query): array
    {
        $rows=(clone $query)->with('payments')->get();
        $total = (string)$rows->sum('amount_idr');
        $revenue=$query->getModel() instanceof Revenue;
        $paid=$rows->reduce(fn($sum,$row)=>Decimal::add($sum,$revenue?$this->payments->revenuePaidAmount($row):$this->payments->operationalPaidAmount($row),0),'0');
        $unpaid=$rows->reduce(fn($sum,$row)=>Decimal::add($sum,$revenue?$this->payments->revenueRemaining($row):$this->payments->operationalRemaining($row),0),'0');
        return compact('key', 'label', 'total', 'paid', 'unpaid');
    }

    private function cashMovementRow(string $key, string $label, Builder $query, string $from, string $to): array
    {
        $paid = (string)(clone $query)->where('payment_status', 'PAID')->whereBetween('paid_at', [$from.' 00:00:00', $to.' 23:59:59'])->sum('amount_idr');
        $unpaid = (string)(clone $query)->where('payment_status', 'UNPAID')->whereBetween('transaction_date', [$from, $to])->sum('amount_idr');
        $total = Decimal::add($paid, $unpaid, 0);
        return compact('key', 'label', 'total', 'paid', 'unpaid');
    }

    private function manualTransferRows(string $from,string $to): array
    {
        $query=Revenue::query()->join('revenue_transfer_details as detail','detail.revenue_id','=','revenues.id')
            ->where('revenues.revenue_type','MAIN_ACCOUNT_TRANSFER')->where('revenues.approval_status','<>','REJECTED');
        $rows=[];
        foreach([
            ['transfers_gross','Transfer Kas ke Rekening Utama (Bruto)','detail.gross_amount_idr'],
            ['transfer_payment_cost','Payment Cost Transfer','detail.payment_cost_idr'],
            ['transfers_net','Net Diterima Rekening Utama','revenues.amount_idr'],
        ] as [$key,$label,$column]) {
            $paid=(string)(clone $query)->where('revenues.payment_status','PAID')->whereBetween('revenues.paid_at',[$from.' 00:00:00',$to.' 23:59:59'])->sum($column);
            $unpaid=(string)(clone $query)->where('revenues.payment_status','UNPAID')->whereBetween('revenues.transaction_date',[$from,$to])->sum($column);
            $total=Decimal::add($paid,$unpaid,0); $rows[]=compact('key','label','total','paid','unpaid');
        }
        return $rows;
    }
}
