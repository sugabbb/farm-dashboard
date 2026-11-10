<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

final class FinanceQuery
{
    public function operational(Builder $query, array $filters): Builder
    {
        if(($filters['payment_status']??null)==='OUTSTANDING') $query->whereIn('payment_status',['UNPAID','PARTIALLY_PAID']);
        elseif(($filters['payment_status']??null)==='WITH_PAYMENTS') $query->whereIn('payment_status',['PARTIALLY_PAID','PAID']);
        foreach (['cashflow_type', 'approval_status'] as $field) {
            if (filled($filters[$field] ?? null)) $query->where($field, $filters[$field]);
        }
        if(filled($filters['payment_status']??null) && !in_array($filters['payment_status'],['OUTSTANDING','WITH_PAYMENTS'])) $query->where('payment_status',$filters['payment_status']);
        if (filled($filters['from'] ?? null)) $query->where('transaction_date', '>=', $filters['from']);
        if (filled($filters['to'] ?? null)) $query->where('transaction_date', '<=', $filters['to']);
        return $query;
    }

    public function revenues(Builder $query, array $filters): Builder
    {
        if(($filters['summary_filter']??null)==='CASH_LEDGER') $query->whereIn('payment_status',['PARTIALLY_PAID','PAID'])->where(function($ledger){$ledger->where(fn($cash)=>$cash->whereIn('revenue_type',FinanceMetrics::OPERATING_REVENUE_TYPES)->where('payment_method','CASH'))->orWhere('revenue_type','MAIN_ACCOUNT_TRANSFER');});
        elseif(($filters['summary_filter']??null)==='PENDING_TRANSFERS') $query->where('revenue_type','MAIN_ACCOUNT_TRANSFER')->whereIn('approval_status',['DRAFT','SUBMITTED']);
        elseif(($filters['summary_filter']??null)==='PENDING_DIRECT') $query->where('payment_method','DIRECT_TRANSFER')->where('approval_status','SUBMITTED');
        elseif(($filters['summary_filter']??null)==='OPERATING_UNPAID') $query->whereIn('revenue_type',FinanceMetrics::OPERATING_REVENUE_TYPES)->whereIn('payment_status',['UNPAID','PARTIALLY_PAID']);
        foreach (['revenue_type', 'payment_method', 'payment_status', 'approval_status', 'customer_id'] as $field) {
            if (filled($filters[$field] ?? null)) $query->where($field, $filters[$field]);
        }
        if (filled($filters['from'] ?? null)) $query->where('transaction_date', '>=', $filters['from']);
        if (filled($filters['to'] ?? null)) $query->where('transaction_date', '<=', $filters['to']);
        return $query;
    }
}
