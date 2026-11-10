import { router } from '@inertiajs/react';
import { ColumnDef } from '@tanstack/react-table';
import { Download } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';
import { Button, Card, Form } from 'react-bootstrap';
import { AppShell, PageHeader } from '../components/AppShell';
import { ResponsiveTable } from '../components/DataTable';
import { date, idr } from '../utils';

type Row={key:string;label:string;total:string;paid:string;unpaid:string};
type Section={key:string;label:string;rows:Row[]};
type Props={month:string;from:string;to:string;sections:Section[]};

export default function ExportReport({month,from,to,sections}:Props){
 const[selected,setSelected]=useState(month); const max=new Date().toLocaleDateString('en-CA',{timeZone:'Asia/Jakarta'}).slice(0,7);
 useEffect(()=>setSelected(month),[month]);
 const submit=(event:FormEvent)=>{event.preventDefault();router.get('/export-report',{month:selected},{preserveState:true,replace:true});};
 const columns:ColumnDef<Row,unknown>[]=[
  {accessorKey:'label',header:'Kategori',cell:({row})=><strong>{row.original.label}</strong>,meta:{mobile:{role:'heading'}}},
  {accessorKey:'total',header:'Total',cell:({row})=>idr(row.original.total),meta:{mobile:{role:'featured'}}},
  {accessorKey:'paid',header:'Paid',cell:({row})=>idr(row.original.paid)},
  {accessorKey:'unpaid',header:'Unpaid',cell:({row})=>idr(row.original.unpaid)},
 ];
 return <AppShell title="Export Report"><PageHeader eyebrow="Keuangan" title="Export Report" description={`Ringkasan ${date(from)}–${date(to)} berdasarkan aturan dua ledger.`} actions={<Button as="a" href={`/export-report/download?month=${month}`}><Download/> Export Excel</Button>}/>
  <Form className="filter-card" onSubmit={submit}><Form.Group><Form.Label>Bulan</Form.Label><Form.Control type="month" value={selected} max={max} onChange={e=>setSelected(e.target.value)}/></Form.Group><Button type="submit">Terapkan</Button></Form>
  {sections.map(section=><Card className="mb-4" key={section.key}><Card.Header>{section.label}</Card.Header><Card.Body className="p-0"><ResponsiveTable data={section.rows} columns={columns} rowId={row=>row.key} emptyTitle={`Belum ada data ${section.label}`}/></Card.Body></Card>)}
 </AppShell>;
}
