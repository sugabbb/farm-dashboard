import { router } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import { Button, Card, Form } from 'react-bootstrap';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { AppShell, PageHeader } from '../components/AppShell';
import { MultiSelect } from '../components/FormControls';
import { date, idr } from '../utils';

type Granularity='WEEKLY'|'MONTHLY';
type Point={date:string;revenue:number;operational:number;revenue_from:string;revenue_to:string;operational_from:string;operational_to:string;partial:boolean};
type Chart={key:string;title:string;revenue_basis:'PAID_AT'|'TRANSACTION_DATE';operational_basis:'PAID_AT'|'TRANSACTION_DATE';points:Point[]};
type TypeOption={value:string;label:string};
type Props={filters:{months:1|2|3;granularity:Granularity;revenue_types:string[];operational_types:string[]};available_types:{revenues:TypeOption[];operational:TypeOption[]};range:{from:string;to:string};series:{weekly:Chart[];monthly:Chart[]}};
const shortIdr=(value:number)=>new Intl.NumberFormat('id-ID',{notation:'compact',maximumFractionDigits:1}).format(value);

export default function Report(props:Props){
 const[months,setMonths]=useState<number>(props.filters.months);const[granularity,setGranularity]=useState<Granularity>(props.filters.granularity);const[revenueTypes,setRevenueTypes]=useState(props.filters.revenue_types);const[operationalTypes,setOperationalTypes]=useState(props.filters.operational_types);
 useEffect(()=>{setMonths(props.filters.months);setGranularity(props.filters.granularity);setRevenueTypes(props.filters.revenue_types);setOperationalTypes(props.filters.operational_types);},[props.filters.months,props.filters.granularity,props.filters.revenue_types,props.filters.operational_types]);
 const submit=(event:FormEvent)=>{event.preventDefault();router.get('/reports',{months,granularity,revenue_types:revenueTypes.join(','),operational_types:operationalTypes.join(',')},{preserveState:true,replace:true});};
 const charts=granularity==='WEEKLY'?props.series.weekly:props.series.monthly;
 return <AppShell title="Report"><PageHeader eyebrow="Keuangan" title="Report" description={`Perbandingan operating revenue dan operational cashflow ${date(props.range.from)}–${date(props.range.to)}.`}/>
  <Form className="filter-card" onSubmit={submit}><Form.Group><Form.Label>Periode</Form.Label><Form.Select value={granularity} onChange={e=>setGranularity(e.target.value as Granularity)}><option value="WEEKLY">Weekly</option><option value="MONTHLY">Monthly</option></Form.Select></Form.Group><Form.Group><Form.Label>Rentang</Form.Label><Form.Select value={months} onChange={e=>setMonths(Number(e.target.value))}><option value={1}>1 bulan</option><option value={2}>2 bulan</option><option value={3}>3 bulan</option></Form.Select></Form.Group><MultiSelect label="Jenis Revenue" values={revenueTypes} options={props.available_types.revenues} onChange={setRevenueTypes}/><MultiSelect label="Jenis Operational" values={operationalTypes} options={props.available_types.operational} onChange={setOperationalTypes}/><Button type="submit" variant="outline-primary">Terapkan</Button></Form>
  {!revenueTypes.length&&!operationalTypes.length?<Card><Card.Body className="text-muted">Pilih minimal satu jenis Revenue atau Operational Cashflow untuk menampilkan chart.</Card.Body></Card>:<div className="report-chart-grid">{charts.map(chart=><Card className="report-chart-card" key={chart.key}><Card.Header><strong>{chart.title}</strong></Card.Header><Card.Body><div className="chart-wrap"><ResponsiveContainer><LineChart data={chart.points} margin={{top:12,right:18,left:10,bottom:8}}><CartesianGrid strokeDasharray="3 3"/><XAxis dataKey="date" tickFormatter={value=>date(String(value))} minTickGap={28}/><YAxis tickFormatter={value=>shortIdr(Number(value))}/><Tooltip labelFormatter={(_,payload)=>{const point=payload?.[0]?.payload as Point|undefined;return point?`${date(point.date)}${point.partial?' · Partial':''}`:'';}} formatter={(value,name)=>[idr(Number(value)),name]} contentStyle={{borderRadius:8}}/><Legend/>{revenueTypes.length>0&&<Line name="Revenues" dataKey="revenue" stroke="#078762" strokeWidth={3} dot={{r:3}}/>}{operationalTypes.length>0&&<Line name="Operational Cashflow" dataKey="operational" stroke="#f59f00" strokeWidth={3} dot={{r:3}}/>}</LineChart></ResponsiveContainer></div><div className="small text-muted mt-2">{granularity==='WEEKLY'?'Revenue Sen–Min · Operational Min–Sab sebelumnya':'Kalender bulanan'} · Titik partial ditandai pada tooltip.</div></Card.Body></Card>)}</div>}
 </AppShell>;
}
