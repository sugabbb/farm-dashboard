<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FinanceAuditTimeline
{
    private const ACTION_LABELS=[
        'cashflow_created'=>'Operational Cashflow dibuat', 'cashflow_updated'=>'Operational Cashflow diperbarui',
        'cashflow_corrected'=>'Operational Cashflow dikoreksi', 'cashflow_submitted'=>'Pengeluaran diajukan',
        'cashflow_approved_edit_preserved'=>'Pengeluaran Approved diperbarui tanpa approval ulang',
        'cashflow_approval_increase_reset'=>'Kenaikan pengeluaran dikembalikan ke Draft',
        'cashflow_paid_increase_reset'=>'Kenaikan pengeluaran membatalkan Paid dan approval',
        'cashflow_paid'=>'Operational Cashflow ditandai Paid', 'approved'=>'Pengeluaran disetujui',
        'cashflow_partially_paid'=>'Pembayaran sebagian Operational dicatat','cashflow_payment_voided'=>'Pembayaran Operational dibatalkan',
        'rejected'=>'Pengeluaran ditolak', 'revenue_created'=>'Revenue dibuat', 'revenue_updated'=>'Revenue diperbarui',
        'revenue_corrected'=>'Revenue dikoreksi', 'revenue_paid'=>'Revenue ditandai Paid',
        'revenue_partially_paid'=>'Pembayaran sebagian Revenue dicatat','revenue_payment_voided'=>'Pembayaran Revenue dibatalkan',
        'transfer_submitted'=>'Transfer diajukan', 'transfer_approved'=>'Transfer disetujui',
        'transfer_rejected'=>'Transfer ditolak', 'negative_balance_override'=>'Saldo negatif dikonfirmasi',
        'direct_transfer_approved'=>'Direct Transfer disetujui', 'direct_transfer_rejected'=>'Direct Transfer ditolak',
        'automatic_payment_backfilled'=>'Pembayaran otomatis dari migrasi', 'feed_received'=>'Pakan diterima',
        'cashflow_feed_detail_deleted'=>'Rincian pakan dihapus', 'revenue_egg_detail_deleted'=>'Rincian penjualan telur dihapus',
    ];

    private const FIELD_LABELS=[
        'cashflow_type'=>'Jenis', 'revenue_type'=>'Jenis', 'direction'=>'Arah', 'transaction_date'=>'Tanggal transaksi',
        'amount_idr'=>'Jumlah', 'gross_amount_idr'=>'Jumlah bruto', 'payment_cost_idr'=>'Biaya transfer',
        'payment_method'=>'Tipe pembayaran', 'direct_transfer_date'=>'Tanggal Direct Transfer', 'approval_status'=>'Status approval', 'payment_status'=>'Status pembayaran',
        'rejection_reason'=>'Alasan penolakan', 'customer_id'=>'Pelanggan', 'proof_url'=>'Bukti',
        'submitted_at'=>'Waktu pengajuan', 'approved_at'=>'Waktu persetujuan', 'rejected_at'=>'Waktu penolakan',
        'paid_at'=>'Waktu pembayaran', 'received_at'=>'Waktu penerimaan', 'received_by'=>'Penerima',
        'payment_amount_idr'=>'Jumlah pembayaran','payment_date'=>'Tanggal pembayaran','payment_proof_url'=>'Bukti pembayaran',
        'feed_type_id'=>'Jenis pakan', 'egg_category'=>'Kategori telur', 'purchase_unit'=>'Unit pembelian',
        'purchase_quantity'=>'Jumlah pembelian','kg_per_sak'=>'Kg per sak','price_per_purchase_unit_idr'=>'Harga per unit pembelian','quantity_kg'=>'Jumlah (kg)',
        'weight_kg'=>'Berat (kg)', 'quantity_pieces'=>'Jumlah (butir)', 'price_per_kg_idr'=>'Harga per kg',
        'line_amount_idr'=>'Jumlah baris', 'notes'=>'Catatan',
    ];

    /** @return array<int,array<int,array<string,mixed>>> */
    public function operational(Collection $cashflows): array
    {
        $ids=$cashflows->pluck('id')->map(fn($id)=>(int)$id)->values();
        if($ids->isEmpty()) return [];
        $lineParents=[]; $subjects=[];
        foreach($cashflows as $cashflow) foreach($cashflow->feedDetails as $line) {
            $lineParents[(int)$line->id]=(int)$cashflow->id;
            $subjects[(int)$line->id]='Pakan '.($line->feedType?->name ?? '#'.$line->id);
        }
        return $this->load('operational_cashflows',$ids,'operational_cashflow_feed_details',$lineParents,$subjects);
    }

    /** @return array<int,array<int,array<string,mixed>>> */
    public function revenues(Collection $revenues): array
    {
        $ids=$revenues->pluck('id')->map(fn($id)=>(int)$id)->values();
        $lineParents=[]; $subjects=[];
        foreach($revenues as $revenue) foreach($revenue->eggDetails as $line) {
            $lineParents[(int)$line->id]=(int)$revenue->id;
            $subjects[(int)$line->id]='Telur '.$this->eggLabel($line->egg_category);
        }
        return $ids->isEmpty()?[]:$this->load('revenues',$ids,'revenue_egg_details',$lineParents,$subjects);
    }

    /** @return array<int,array<int,array<string,mixed>>> */
    private function load(string $entityType,Collection $ids,?string $childType,array $lineParents,array $subjects): array
    {
        $parentKey=$entityType==='revenues'?'revenue_id':'operational_cashflow_id';
        $lineIds=array_keys($lineParents);
        $events=DB::table('audit_events as audit')->leftJoin('users as actor','actor.id','=','audit.actor_id')
            ->where(function($query) use($entityType,$ids,$childType,$lineIds,$parentKey) {
                $query->where(fn($direct)=>$direct->where('audit.entity_type',$entityType)->whereIn('audit.entity_id',$ids));
                if($childType) $query->orWhere(function($child) use($childType,$lineIds,$ids,$parentKey) {
                    $child->where('audit.entity_type',$childType)->where(function($related) use($lineIds,$ids,$parentKey) {
                        if($lineIds) $related->whereIn('audit.entity_id',$lineIds);
                        $method=$lineIds?'orWhereIn':'whereIn';
                        $related->{$method}(DB::raw("(audit.before->>'{$parentKey}')::bigint"),$ids)
                            ->orWhereIn(DB::raw("(audit.after->>'{$parentKey}')::bigint"),$ids);
                    });
                });
            })
            ->orderByDesc('audit.happened_at')->orderByDesc('audit.id')
            ->get(['audit.*','actor.name as actor_name']);
        $users=DB::table('users')->pluck('name','id')->all();
        $customers=DB::table('customers')->pluck('name','id')->all();
        $feeds=DB::table('feed_types')->pluck('name','id')->all();
        $result=[];
        foreach($events as $event) {
            $before=$this->payload($event->before); $after=$this->payload($event->after);
            $parentId=$event->entity_type===$entityType?(int)$event->entity_id:($lineParents[(int)$event->entity_id]??$after[$parentKey]??$before[$parentKey]??null);
            if(!$parentId) continue;
            $subject=null;
            if($event->entity_type===$childType) {
                $subject=$subjects[(int)$event->entity_id]??null;
                if(!$subject && $childType==='operational_cashflow_feed_details') {
                    $feedId=$after['feed_type_id']??$before['feed_type_id']??null;
                    $subject='Pakan '.($feeds[(int)$feedId]??'#'.$event->entity_id);
                }
                if(!$subject && $childType==='revenue_egg_details') $subject='Telur '.$this->eggLabel((string)($after['egg_category']??$before['egg_category']??''));
            }
            $result[$parentId][]=[
                'id'=>(int)$event->id, 'action'=>$event->action,
                'label'=>self::ACTION_LABELS[$event->action]??str($event->action)->replace('_',' ')->title()->toString(),
                'actor_name'=>$event->actor_name??'System',
                'happened_at'=>Carbon::parse($event->happened_at)->toIso8601String(), 'reason'=>$event->reason,
                'subject_label'=>$subject,
                'changes'=>$this->changes($before,$after,$users,$customers,$feeds),
            ];
        }
        return $result;
    }

    private function payload(mixed $value): array
    {
        if(is_array($value)) return $value;
        if(is_object($value)) return (array)$value;
        if(!is_string($value) || $value==='') return [];
        return json_decode($value,true)?:[];
    }

    /** @return array<int,array{field:string,label:string,before:string,after:string}> */
    private function changes(array $before,array $after,array $users,array $customers,array $feeds): array
    {
        $changes=[];
        foreach(self::FIELD_LABELS as $field=>$label) {
            $old=$before[$field]??null; $new=$after[$field]??null;
            if($old===$new || ($old===null && $new===null)) continue;
            $changes[]=['field'=>$field,'label'=>$label,'before'=>$this->format($field,$old,$users,$customers,$feeds),'after'=>$this->format($field,$new,$users,$customers,$feeds)];
        }
        return $changes;
    }

    private function format(string $field,mixed $value,array $users,array $customers,array $feeds): string
    {
        if($value===null || $value==='') return '—';
        if(in_array($field,['amount_idr','gross_amount_idr','payment_cost_idr','price_per_kg_idr','price_per_purchase_unit_idr','line_amount_idr','payment_amount_idr'])) return 'Rp '.number_format((float)$value,0,'.',',');
        if(in_array($field,['quantity_kg','weight_kg','kg_per_sak'])) return rtrim(rtrim(number_format((float)$value,3,'.',','),'0'),'.').' kg';
        if($field==='purchase_quantity') return rtrim(rtrim(number_format((float)$value,3,'.',','),'0'),'.');
        if($field==='quantity_pieces') return number_format((int)$value,0,'.',',').' butir';
        if($field==='feed_type_id') return $feeds[(int)$value]??'Jenis pakan #'.$value;
        if($field==='egg_category') return $this->eggLabel((string)$value);
        if($field==='customer_id') return $customers[(int)$value]??'Pelanggan #'.$value;
        if($field==='received_by') return $users[(int)$value]??'Pengguna #'.$value;
        if(in_array($field,['proof_url','payment_proof_url'])) return 'Bukti tersedia';
        if(in_array($field,['transaction_date','direct_transfer_date','payment_date'])) return str(Carbon::parse((string)$value)->locale('id')->translatedFormat('D, j M Y'))->ucfirst()->toString();
        if(str_ends_with($field,'_at')) return str(Carbon::parse((string)$value)->timezone(config('app.timezone'))->locale('id')->translatedFormat('D, j M Y, H.i'))->ucfirst()->toString();
        $labels=[
            'FEED_AYAM'=>'Pakan Ayam','FEED_LELE'=>'Pakan Lele','SALARY'=>'Gaji ABK','UTILITY'=>'Utilitas',
            'HEALTH'=>'Kesehatan','OTHER'=>'Lain-lain','LELE_SEED_PURCHASE'=>'Pembelian Benih Lele',
            'PULLET_PURCHASE'=>'Pembelian Pullet','BANK_ADMIN_FEE'=>'Biaya Admin Bank','MAIN_ACCOUNT_TOPUP'=>'Top Up dari Rekening Utama',
            'EGG_SALE'=>'Penjualan Telur','FISH_SALE'=>'Penjualan Lele','MAIN_ACCOUNT_TRANSFER'=>'Transfer ke Rekening Utama',
            'CASH'=>'Cash','DIRECT_TRANSFER'=>'Direct Transfer','INFLOW'=>'Inflow','OUTFLOW'=>'Outflow','NOT_REQUIRED'=>'Tidak perlu approval',
        ];
        return $labels[(string)$value]??(string)$value;
    }

    private function eggLabel(string $category): string
    {
        return $category==='REJECT'?'RIJEK':$category;
    }
}
