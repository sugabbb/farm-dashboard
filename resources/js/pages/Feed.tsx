import { router, useForm, usePage } from '@inertiajs/react';
import { ColumnDef } from '@tanstack/react-table';
import { CalendarClock, Edit3, Plus, Wheat } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Button, Card, Form } from 'react-bootstrap';
import { AppShell, EmptyState, PageHeader } from '../components/AppShell';
import { DataTable } from '../components/DataTable';
import { DateField, FieldError, FormDrawer, LocalizedNumberInput, SearchableSelect } from '../components/FormControls';
import type { Pagination, SharedProps } from '../types';
import { date, dateTime, integer, kg, query } from '../utils';

type FeedType={id:number;name:string;species:string;is_active:boolean};
type Adjustment={id:number;feed_type_id:number;delta_kg:string;effective_date:string;reason:string};
type Movement={key:string;movement_date:string;recorded_at:string;feed_id:number;feed_name:string;movement_type:'OPENING'|'RECEIPT'|'ADJUSTMENT'|'USAGE';reference:string;description:string;quantity_in:string;quantity_out:string;quantity_delta:string;balance_after:string;source_type:string;source_id:number;adjustment:Adjustment|null};
type FeedEstimate={status:'ESTIMATED'|'OUT_OF_STOCK'|'NO_USAGE'|'ZERO_USAGE';usage_date:string|null;daily_usage_kg:string|null;days_remaining:number|null;estimated_out_of_stock_date:string|null};
type Props={species:'AYAM'|'LELE';date:string;types:FeedType[];stock:Record<string,string>;estimates:Record<string,FeedEstimate>;movements:Pagination<Movement>;filters:{feed_type_id?:string};sort:string;direction:'asc'|'desc'};
type AdjustmentForm={feed_type_id:number|string;delta_kg:string;effective_date:string;reason:string};
const movementLabels:Record<Movement['movement_type'],string>={OPENING:'Saldo Awal',RECEIPT:'Penerimaan',ADJUSTMENT:'Penyesuaian',USAGE:'Pemakaian'};
const movementTones:Record<Movement['movement_type'],string>={OPENING:'neutral',RECEIPT:'success',ADJUSTMENT:'warning',USAGE:'danger'};

export default function Feed(props:Props){
  const {auth,today}=usePage<SharedProps>().props;
  const [showCreate,setShowCreate]=useState(false);
  const [editing,setEditing]=useState<Adjustment|null>(null);
  const [filters,setFilters]=useState({date:props.date,feed_type_id:props.filters.feed_type_id??''});
  useEffect(()=>setFilters({date:props.date,feed_type_id:props.filters.feed_type_id??''}),[props.date,props.filters.feed_type_id]);
  const create=useForm<AdjustmentForm&{item_kind:string}>({item_kind:'FEED',feed_type_id:props.types[0]?.id??'',delta_kg:'',effective_date:today,reason:''});
  const edit=useForm<AdjustmentForm>({feed_type_id:'',delta_kg:'',effective_date:today,reason:''});
  const submitCreate=(event:FormEvent)=>{event.preventDefault();create.post('/inventory/adjustments',{preserveScroll:true,onSuccess:()=>{setShowCreate(false);create.reset('delta_kg','reason');}});};
  const openEdit=(adjustment:Adjustment)=>{setEditing(adjustment);edit.clearErrors();edit.setData({feed_type_id:adjustment.feed_type_id,delta_kg:adjustment.delta_kg,effective_date:adjustment.effective_date,reason:adjustment.reason});};
  const submitEdit=(event:FormEvent)=>{event.preventDefault();if(!editing)return;edit.patch(`/inventory/adjustments/${editing.id}`,{preserveScroll:true,onSuccess:()=>setEditing(null)});};
  const selectType=(id:number)=>{const next={...filters,feed_type_id:String(id)};setFilters(next);router.get(`/${props.species.toLowerCase()}/feed`,query(next),{preserveState:true,replace:true});};
  const columns=useMemo<ColumnDef<Movement,unknown>[]>(()=>[
    {accessorKey:'movement_date',header:'Tanggal',cell:({row})=><div><strong>{date(row.original.movement_date)}</strong><small className="d-block text-muted">Dicatat {dateTime(row.original.recorded_at)}</small></div>,meta:{mobile:{role:'heading'}}},
    {accessorKey:'feed_name',header:'Pakan'},
    {accessorKey:'movement_type',header:'Tipe',cell:({row})=><span className={`status-badge status-${movementTones[row.original.movement_type]}`}><i/>{movementLabels[row.original.movement_type]}</span>},
    {id:'description',header:'Deskripsi / Referensi',enableSorting:false,cell:({row})=><div>{row.original.description}<small className="d-block text-muted">{row.original.reference}</small></div>,meta:{mobile:{width:'full'}}},
    {accessorKey:'quantity_in',header:'Masuk',cell:({row})=>Number(row.original.quantity_in)!==0?<span className="text-success">+{kg(row.original.quantity_in)}</span>:'—'},
    {accessorKey:'quantity_out',header:'Keluar',cell:({row})=>Number(row.original.quantity_out)!==0?<span className="text-danger">−{kg(row.original.quantity_out)}</span>:'—'},
    {accessorKey:'quantity_delta',header:'Perubahan',cell:({row})=><strong className={Number(row.original.quantity_delta)>=0?'text-success':'text-danger'}>{Number(row.original.quantity_delta)>0?'+':''}{kg(row.original.quantity_delta)}</strong>},
    {accessorKey:'balance_after',header:'Sisa Stock',cell:({row})=><strong className={Number(row.original.balance_after)<0?'text-danger':undefined}>{kg(row.original.balance_after)}</strong>,meta:{mobile:{role:'featured'}}},
    {id:'actions',header:'Aksi',enableSorting:false,cell:({row})=>auth.user?.is_superadmin&&row.original.adjustment?<Button size="sm" variant="light" onClick={()=>openEdit(row.original.adjustment!)}><Edit3 size={15}/> Edit</Button>:'—',meta:{mobile:{role:'actions'}}},
  ],[auth.user?.is_superadmin]);
  const estimateValue=(estimate:FeedEstimate)=>estimate.status==='ESTIMATED'?date(estimate.estimated_out_of_stock_date):estimate.status==='OUT_OF_STOCK'?'Sudah habis':estimate.status==='ZERO_USAGE'?'Belum dapat dihitung':'Belum ada data pemakaian';
  const estimateNote=(estimate:FeedEstimate)=>{
    const usage=estimate.usage_date!==null?`Pemakaian ${kg(estimate.daily_usage_kg??0)} pada ${date(estimate.usage_date)}`:'Belum ada tanggal pemakaian';
    return estimate.status==='ESTIMATED'?`${integer(estimate.days_remaining)} hari tersisa · ${usage}`:estimate.status==='OUT_OF_STOCK'?`per ${date(props.date)} · ${usage}`:usage;
  };
  return <AppShell title={`Pakan ${props.species}`}>
    <PageHeader eyebrow={props.species} title="Pakan & Stok" description="Stok per jenis pakan, penerimaan, penyesuaian, dan pemakaian harian." actions={auth.user?.is_superadmin&&<Button onClick={()=>setShowCreate(true)}><Plus/> Penyesuaian</Button>}/>
    <Form className="filter-card" onSubmit={event=>{event.preventDefault();router.get(`/${props.species.toLowerCase()}/feed`,query(filters),{preserveState:true,replace:true});}}><DateField label="Saldo per tanggal" value={filters.date} onChange={value=>setFilters({...filters,date:value})}/><SearchableSelect label="Jenis pakan" value={filters.feed_type_id||null} options={[{value:'',label:'Semua jenis'},...props.types.map(item=>({value:item.id,label:item.name}))]} onChange={value=>setFilters({...filters,feed_type_id:String(value??'')})}/><Button type="submit" variant="outline-primary">Terapkan</Button></Form>
    <div className="metric-grid">{props.types.length?props.types.map(item=><Card role="button" tabIndex={0} aria-pressed={String(item.id)===filters.feed_type_id} onClick={()=>selectType(item.id)} onKeyDown={event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();selectType(item.id);}}} className={`metric-card metric-card-clickable ${String(item.id)===filters.feed_type_id?'metric-card-active':''}`} key={item.id}><Card.Body><div className="metric-icon"><Wheat/></div><div className="metric-label">{item.name}</div><div className={`metric-value ${Number(props.stock[item.id])<0?'text-danger':''}`}>{kg(props.stock[item.id])}</div><div className="metric-note">per {date(props.date)}</div></Card.Body></Card>):<Card><EmptyState title={`Belum ada jenis pakan ${props.species}`}/></Card>}</div>
    {props.types.length>0&&<><PageHeader title="Estimasi Stok Habis"/><div className="metric-grid">{props.types.map(item=>{const estimate=props.estimates[item.id];return <Card role="button" tabIndex={0} aria-pressed={String(item.id)===filters.feed_type_id} onClick={()=>selectType(item.id)} onKeyDown={event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();selectType(item.id);}}} className={`metric-card metric-card-clickable ${String(item.id)===filters.feed_type_id?'metric-card-active':''}`} key={`estimate-${item.id}`}><Card.Body><div className="metric-icon"><CalendarClock/></div><div className="metric-label">{item.name}</div><div className={`metric-value ${estimate.status==='OUT_OF_STOCK'?'text-danger':''}`}>{estimateValue(estimate)}</div><div className="metric-note">{estimateNote(estimate)}</div></Card.Body></Card>;})}</div></>}
    <PageHeader title="Stock Movement"/><DataTable columns={columns} page={props.movements} sort={props.sort} direction={props.direction} emptyTitle="Belum ada movement stok pakan"/>
    <FormDrawer show={showCreate} onHide={()=>setShowCreate(false)} title="Penyesuaian Stok Pakan"><Form onSubmit={submitCreate}><SearchableSelect label="Jenis pakan" value={create.data.feed_type_id} options={props.types.map(item=>({value:item.id,label:item.name}))} onChange={value=>create.setData('feed_type_id',value??'')} error={create.errors.feed_type_id}/><Form.Group className="my-3"><Form.Label>Perubahan kg (+ / −)</Form.Label><LocalizedNumberInput scale={3} allowNegative value={create.data.delta_kg} onChange={value=>create.setData('delta_kg',value)}/><FieldError message={create.errors.delta_kg}/></Form.Group><DateField label="Tanggal efektif" value={create.data.effective_date} onChange={value=>create.setData('effective_date',value)} error={create.errors.effective_date}/><Form.Group className="my-3"><Form.Label>Alasan</Form.Label><Form.Control as="textarea" value={create.data.reason} onChange={event=>create.setData('reason',event.target.value)}/><FieldError message={create.errors.reason}/></Form.Group><Button type="submit" disabled={create.processing}>Catat Penyesuaian</Button></Form></FormDrawer>
    <FormDrawer show={Boolean(editing)} onHide={()=>setEditing(null)} title="Edit Penyesuaian Stok Pakan"><Form onSubmit={submitEdit}><SearchableSelect label="Jenis pakan" value={edit.data.feed_type_id} options={props.types.map(item=>({value:item.id,label:`${item.name}${item.is_active?'':' · nonaktif'}`}))} onChange={value=>edit.setData('feed_type_id',value??'')} error={edit.errors.feed_type_id}/><Form.Group className="my-3"><Form.Label>Perubahan kg (+ / −)</Form.Label><LocalizedNumberInput scale={3} allowNegative value={edit.data.delta_kg} onChange={value=>edit.setData('delta_kg',value)}/><FieldError message={edit.errors.delta_kg}/></Form.Group><DateField label="Tanggal efektif" value={edit.data.effective_date} onChange={value=>edit.setData('effective_date',value)} error={edit.errors.effective_date}/><Form.Group className="my-3"><Form.Label>Alasan</Form.Label><Form.Control as="textarea" value={edit.data.reason} onChange={event=>edit.setData('reason',event.target.value)}/><FieldError message={edit.errors.reason}/></Form.Group><Button type="submit" disabled={edit.processing}>Simpan Perubahan</Button></Form></FormDrawer>
  </AppShell>;
}
