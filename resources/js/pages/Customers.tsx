import { router, useForm } from '@inertiajs/react';
import { ColumnDef } from '@tanstack/react-table';
import { Edit3, Plus } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';
import { Button, Form } from 'react-bootstrap';
import { AppShell, PageHeader } from '../components/AppShell';
import { DataTable } from '../components/DataTable';
import { FieldError, FormDrawer } from '../components/FormControls';
import type { Pagination } from '../types';
import { integer, query } from '../utils';

type Customer={id:number;name:string;phone:string;customer_type:'WARUNG'|'AGEN';notes:string|null;revenues_count:number};
type Props={customers:Pagination<Customer>;filters:Record<string,string|undefined>;sort:string;direction:'asc'|'desc'};
type CustomerForm={name:string;phone:string;customer_type:'WARUNG'|'AGEN';notes:string};

export default function Customers(props:Props){
 const[filters,setFilters]=useState({q:props.filters.q??'',customer_type:props.filters.customer_type??''});const[selected,setSelected]=useState<Customer|null>(null);const[show,setShow]=useState(false);
 const form=useForm<CustomerForm>({name:'',phone:'',customer_type:'WARUNG',notes:''});
 const open=(customer?:Customer)=>{setSelected(customer??null);form.setData(customer?{name:customer.name,phone:customer.phone,customer_type:customer.customer_type,notes:customer.notes??''}:{name:'',phone:'',customer_type:'WARUNG',notes:''});form.clearErrors();setShow(true);};
 const submit=(event:FormEvent)=>{event.preventDefault();selected?form.patch(`/customers/${selected.id}`,{preserveScroll:true,onSuccess:()=>setShow(false)}):form.post('/customers',{preserveScroll:true,onSuccess:()=>setShow(false)});};
 const columns=useMemo<ColumnDef<Customer,unknown>[]>(()=>[
  {accessorKey:'name',header:'Nama',cell:({row})=><div><strong>{row.original.name}</strong>{row.original.notes&&<small className="d-block">{row.original.notes}</small>}</div>,meta:{mobile:{role:'heading'}}},
  {accessorKey:'phone',header:'Telepon'},{accessorKey:'customer_type',header:'Tipe'},
  {accessorKey:'revenues_count',header:'Transaksi',cell:({row})=>integer(row.original.revenues_count)},
  {id:'actions',header:'Aksi',enableSorting:false,cell:({row})=><Button size="sm" variant="light" onClick={()=>open(row.original)}><Edit3/> Edit</Button>,meta:{mobile:{role:'actions'}}},
 ],[]);
 return <AppShell title="Pelanggan"><PageHeader eyebrow="Keuangan" title="Pelanggan" description="Data pelanggan untuk Penjualan Telur dan Penjualan Lele." actions={<Button onClick={()=>open()}><Plus/> Pelanggan</Button>}/>
 <Form className="filter-card" onSubmit={e=>{e.preventDefault();router.get('/customers',query(filters),{preserveState:true,replace:true});}}><Form.Group><Form.Label>Cari</Form.Label><Form.Control value={filters.q} onChange={e=>setFilters({...filters,q:e.target.value})} placeholder="Nama atau telepon"/></Form.Group><Form.Group><Form.Label>Tipe</Form.Label><Form.Select value={filters.customer_type} onChange={e=>setFilters({...filters,customer_type:e.target.value})}><option value="">Semua</option><option value="WARUNG">Warung</option><option value="AGEN">Agen</option></Form.Select></Form.Group><Button type="submit" variant="outline-primary">Terapkan</Button></Form>
 <DataTable columns={columns} page={props.customers} sort={props.sort} direction={props.direction} emptyTitle="Belum ada pelanggan" rowId={row=>row.id}/>
 <FormDrawer show={show} onHide={()=>setShow(false)} title={selected?'Edit Pelanggan':'Tambah Pelanggan'}><Form onSubmit={submit}><Form.Group className="mb-3"><Form.Label>Nama</Form.Label><Form.Control value={form.data.name} onChange={e=>form.setData('name',e.target.value)}/><FieldError message={form.errors.name}/></Form.Group><Form.Group className="mb-3"><Form.Label>Telepon</Form.Label><Form.Control value={form.data.phone} onChange={e=>form.setData('phone',e.target.value)}/><FieldError message={form.errors.phone}/></Form.Group><Form.Group className="mb-3"><Form.Label>Tipe</Form.Label><Form.Select value={form.data.customer_type} onChange={e=>form.setData('customer_type',e.target.value as CustomerForm['customer_type'])}><option value="WARUNG">Warung</option><option value="AGEN">Agen</option></Form.Select></Form.Group><Form.Group className="mb-4"><Form.Label>Catatan</Form.Label><Form.Control as="textarea" value={form.data.notes} onChange={e=>form.setData('notes',e.target.value)}/><FieldError message={form.errors.notes}/></Form.Group><Button type="submit" disabled={form.processing}>Simpan Pelanggan</Button></Form></FormDrawer></AppShell>;
}
