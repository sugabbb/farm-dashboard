import { router, useForm, usePage } from '@inertiajs/react';
import { ColumnDef } from '@tanstack/react-table';
import { Egg, Plus } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Button, Card, Form } from 'react-bootstrap';
import { AppShell, EmptyState, PageHeader } from '../components/AppShell';
import { DataTable } from '../components/DataTable';
import { DateField, FieldError, FormDrawer, LocalizedNumberInput, SearchableSelect } from '../components/FormControls';
import type { Pagination, SharedProps } from '../types';
import { date, dateTime, eggCategoryLabel, integer, kg, percentage, query } from '../utils';

type Metric={kg:string;pieces:number;percentage:number|null};
type Harvest={id:number;record_date:string;group_name:string;population:number;utuh:Metric;pecah:Metric;reject:Metric;total_percentage:number|null};
type Adjustment={id:number;effective_date:string;egg_category:string;delta_kg:string;delta_pieces:number;reason:string;creator_name:string|null;created_at:string};
type Props={date:string;stock:Record<string,{kg:string;pieces:number}>;harvest:Pagination<Harvest>;adjustments:Adjustment[];filters:{egg_category?:string};sort:string;direction:'asc'|'desc'};
const categories=['UTUH','PECAH','REJECT'];

export default function Eggs(props:Props){
 const {auth,today}=usePage<SharedProps>().props; const [show,setShow]=useState(false); const [filters,setFilters]=useState({date:props.date,egg_category:props.filters.egg_category??''}); const selected=filters.egg_category;
 useEffect(()=>setFilters({date:props.date,egg_category:props.filters.egg_category??''}),[props.date,props.filters.egg_category]);
 const form=useForm({item_kind:'EGG',egg_category:'UTUH',delta_kg:'',delta_pieces:'',effective_date:today,reason:''});
 const submit=(e:FormEvent)=>{e.preventDefault();form.post('/inventory/adjustments',{preserveScroll:true,onSuccess:()=>{setShow(false);form.reset('delta_kg','delta_pieces','reason');}});};
 const columns=useMemo<ColumnDef<Harvest,unknown>[]>(()=>{
  const metrics=(key:'utuh'|'pecah'|'reject'):ColumnDef<Harvest,unknown>[]=>[
   {id:`${key}_kg`,header:'Kg',enableSorting:false,cell:({row})=>kg(row.original[key].kg),meta:{mobile:{group:eggCategoryLabel(key.toUpperCase()),label:'Kg'}}},
   {id:`${key}_pieces`,header:'Butir',enableSorting:false,cell:({row})=>integer(row.original[key].pieces),meta:{mobile:{group:eggCategoryLabel(key.toUpperCase()),label:'Butir'}}},
   {id:`${key}_percentage`,header:'%',enableSorting:false,cell:({row})=>percentage(row.original[key].percentage),meta:{mobile:{group:eggCategoryLabel(key.toUpperCase()),label:'Persentase'}}},
  ];
  const visible=(selected?[selected]:categories).map(category=>({id:`${category.toLowerCase()}_group`,header:eggCategoryLabel(category),enableSorting:false,columns:metrics(category.toLowerCase() as 'utuh'|'pecah'|'reject')}));
  return [{accessorKey:'record_date',header:'Tanggal',cell:({row})=>date(row.original.record_date),meta:{mobile:{role:'heading'}}},{accessorKey:'group_name',header:'Grup'},...visible,...(selected?[]:[{id:'total_percentage',header:'Total %',enableSorting:false,cell:({row}:{row:{original:Harvest}})=>percentage(row.original.total_percentage),meta:{mobile:{label:'Total Persentase'}}}])];
 },[selected]);
 return <AppShell title="Telur & Stok">
  <PageHeader eyebrow="Ayam" title="Telur & Stok" description="Panen per tanggal dan grup, dengan persentase terhadap populasi pada tanggal pencatatan." actions={auth.user?.is_superadmin&&<Button onClick={()=>setShow(true)}><Plus/> Penyesuaian</Button>}/>
  <Form className="filter-card" onSubmit={e=>{e.preventDefault();router.get('/ayam/eggs',query(filters),{preserveState:true,replace:true});}}><DateField label="Saldo per tanggal" value={filters.date} onChange={value=>setFilters({...filters,date:value})}/><Form.Group><Form.Label>Kategori telur</Form.Label><Form.Select value={filters.egg_category} onChange={e=>setFilters({...filters,egg_category:e.target.value})}><option value="">Semua kategori</option>{categories.map(category=><option key={category} value={category}>{eggCategoryLabel(category)}</option>)}</Form.Select></Form.Group><Button type="submit" variant="outline-primary">Terapkan</Button></Form>
  <div className="metric-grid">{categories.map(category=>{const apply=()=>{const next={...filters,egg_category:category};setFilters(next);router.get('/ayam/eggs',query(next),{preserveState:true,replace:true});};return <Card role="button" tabIndex={0} aria-pressed={selected===category} onClick={apply} onKeyDown={e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();apply();}}} className={`metric-card metric-card-clickable ${category==='UTUH'?'metric-primary':''} ${selected===category?'metric-card-active':''}`} key={category}><Card.Body><div className="metric-icon"><Egg/></div><div className="metric-label">{eggCategoryLabel(category)}</div><div className="metric-value">{kg(props.stock[category].kg)}</div><div className="metric-note">{integer(props.stock[category].pieces)} butir · per {date(props.date)}</div></Card.Body></Card>})}</div>
  <PageHeader title="Riwayat Panen"/><DataTable columns={columns} page={props.harvest} sort={props.sort} direction={props.direction} emptyTitle="Belum ada panen telur" rowId={row=>row.id}/>
  <Card className="mt-4"><Card.Header>Riwayat Penyesuaian</Card.Header><Card.Body>{props.adjustments.length?props.adjustments.map(row=><div className="summary-line" key={row.id}><div><strong>{eggCategoryLabel(row.egg_category)}</strong><div className="small text-muted">{date(row.effective_date)} · {row.creator_name} · {dateTime(row.created_at)}</div><div>{row.reason}</div></div><strong>{Number(row.delta_kg)>0?'+':''}{kg(row.delta_kg)} · {Number(row.delta_pieces)>0?'+':''}{integer(row.delta_pieces)} butir</strong></div>):<EmptyState title="Belum ada penyesuaian stok"/>}</Card.Body></Card>
  <FormDrawer show={show} onHide={()=>setShow(false)} title="Penyesuaian Stok Telur"><Form onSubmit={submit}><SearchableSelect label="Kategori" value={form.data.egg_category} options={categories.map(value=>({value,label:eggCategoryLabel(value)}))} onChange={value=>form.setData('egg_category',String(value??'UTUH'))}/><Form.Group className="my-3"><Form.Label>Perubahan kg (+ / −)</Form.Label><LocalizedNumberInput scale={3} allowNegative value={form.data.delta_kg} onChange={value=>form.setData('delta_kg',value)}/><FieldError message={form.errors.delta_kg}/></Form.Group><Form.Group className="mb-3"><Form.Label>Perubahan butir (+ / −)</Form.Label><LocalizedNumberInput allowNegative value={form.data.delta_pieces} onChange={value=>form.setData('delta_pieces',value)}/><FieldError message={form.errors.delta_pieces}/></Form.Group><DateField label="Tanggal efektif" value={form.data.effective_date} onChange={value=>form.setData('effective_date',value)} error={form.errors.effective_date}/><Form.Group className="my-3"><Form.Label>Alasan</Form.Label><Form.Control as="textarea" value={form.data.reason} onChange={e=>form.setData('reason',e.target.value)}/><FieldError message={form.errors.reason}/></Form.Group><Button type="submit" disabled={form.processing}>Catat Penyesuaian</Button></Form></FormDrawer>
 </AppShell>;
}
