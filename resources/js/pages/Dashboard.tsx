import { Link, router } from '@inertiajs/react';
import { ColumnDef } from '@tanstack/react-table';
import { Banknote, CircleDollarSign, Contact, Egg, Fish, Scale, Wallet } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { Button, Card, Form } from 'react-bootstrap';
import { Bar, BarChart, CartesianGrid, Cell, Legend, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { AppShell, EmptyState, PageHeader } from '../components/AppShell';
import { ChickenIcon } from '../components/ChickenIcon';
import { DateField } from '../components/FormControls';
import { MetricCard } from '../components/MetricCard';
import { ResponsiveTable } from '../components/DataTable';
import { date, eggCategoryLabel, idr, integer, kg, numeric, percentage, titleCase } from '../utils';

type Range = { from:string; to:string };
type Biomass = { group_id:number; group_name:string; group_code:string; location:string; population:number; sampling_date:string|null; average_weight_g:string|null; estimated_biomass_kg:string|null };
type Props = {
  today:string; summaryRange:Range; harvestRange:Range; financeRange:Range;
  current:{operational_cash:string;estimated_operational_cash:string;revenue_cash:string;approved_unpaid_expenses:string;waiting_expenses:string;unpaid_revenue:string};
  performance:{all_revenue:string;all_expenses:string;period_revenue:string;period_expenses:string};
  latestHarvest:{date:string|null;population:number;utuh_pieces:number;total_pieces:number;utuh_percentage:number|null;total_percentage:number|null};
  eggStock:Record<'UTUH'|'PECAH'|'REJECT',{kg:string;pieces:number}>; biomass:Biomass[];
  financeSeries:{key:string;label:string;revenue:string;expenses:string}[];
  harvestSeries:{key:string;label:string;utuh:number;pecah:number;reject:number;total:number}[];
  expenseSeries:(Record<string,string>&{key:string;label:string})[]; expenseTypes:Record<string,string>;
  breakdown:{cashflow_type:string;total:string}[]; recent:{kind:string;direction:'INFLOW'|'OUTFLOW';date:string;type:string;amount:string}[];
};
const colors=['#3a7afe','#10c995','#ff9800','#ff5c75','#00b7e8','#8b5cf6','#64748b','#e11d48','#14b8a6'];

export default function Dashboard(props:Props){
  const [summaryRange,setSummaryRange]=useState(props.summaryRange);
  const [harvestRange,setHarvestRange]=useState(props.harvestRange);
  const [financeRange,setFinanceRange]=useState(props.financeRange);
  const applyRanges=(event:FormEvent)=>{event.preventDefault();router.get('/dashboard',{
    summary_from:summaryRange.from,summary_to:summaryRange.to,
    harvest_from:harvestRange.from,harvest_to:harvestRange.to,
    finance_from:financeRange.from,finance_to:financeRange.to,
  },{preserveState:true,preserveScroll:true,replace:true});};
  const finance=props.financeSeries.map(row=>({...row,revenue:Number(row.revenue),expenses:Number(row.expenses)}));
  const expenses=props.expenseSeries.map(row=>Object.fromEntries(Object.entries(row).map(([key,value])=>[key,key==='key'||key==='label'?value:Number(value)])));
  const breakdown=props.breakdown.map(row=>({name:props.expenseTypes[row.cashflow_type]??titleCase(row.cashflow_type),value:Number(row.total)}));
  const quick=[
    ['/ayam/daily-entry','Laporan Harian Ayam',ChickenIcon],['/lele/daily-entry','Laporan Harian Lele',Fish],['/lele/biomass','Biomasa Lele',Scale],
    ['/customers','Pelanggan',Contact],['/operational-cashflow?add=1','Operational Cashflow',Banknote],['/revenues?add=1','Revenue',CircleDollarSign],
  ] as const;
  const recentColumns:ColumnDef<Props['recent'][number],unknown>[]=[
    {accessorKey:'date',header:'Tanggal',cell:({row})=>date(row.original.date),meta:{mobile:{role:'heading'}}},
    {accessorKey:'kind',header:'Ledger'},
    {accessorKey:'type',header:'Kategori',cell:({row})=>props.expenseTypes[row.original.type]??titleCase(row.original.type),meta:{mobile:{width:'full'}}},
    {accessorKey:'amount',header:'Jumlah',cell:({row})=><strong className={row.original.direction==='INFLOW'?'text-success':'text-danger'}>{row.original.direction==='INFLOW'?'+':'−'} {idr(row.original.amount)}</strong>,meta:{mobile:{role:'featured'}}},
  ];

  return <AppShell title="Dashboard">
    <PageHeader eyebrow="Ringkasan Farm" title="Dashboard" description={`Saldo dan kondisi farm per ${date(props.today)}`}/>
    <Card className="mb-4"><Card.Header>Akses Cepat</Card.Header><Card.Body><div className="quick-access-grid">{quick.map(([href,label,Icon])=><Link key={href} className="btn btn-outline-primary" href={href}><Icon size={22}/>{label}</Link>)}</div></Card.Body></Card>

    <div className="metric-grid metric-grid-4 dashboard-current-row">
      <MetricCard tone="success" icon={<Wallet/>} label="Operational Cash on Hand" value={idr(props.current.operational_cash)} note={<>Saldo per Hari ini · Est. setelah semua operational paid <strong className="operational-estimate">{idr(props.current.estimated_operational_cash)}</strong></>}/>
      <MetricCard tone="primary" icon={<Wallet/>} label="Revenue Cash on Hand" value={idr(props.current.revenue_cash)} note={`Saldo per ${date(props.today)}`}/>
      <MetricCard icon={<Banknote/>} label="Approved Belum Dibayar" value={idr(props.current.approved_unpaid_expenses)} note="Semua pengeluaran operasional approved dan unpaid"/>
      <MetricCard icon={<Banknote/>} label="Menunggu Approval" value={idr(props.current.waiting_expenses)} note="Semua pengeluaran operasional submitted"/>
    </div>
    <div className="metric-grid metric-grid-3">
      <MetricCard icon={<CircleDollarSign/>} label="Revenue Belum Dibayar" value={idr(props.current.unpaid_revenue)} note="Semua operating revenue unpaid"/>
      <MetricCard label="Revenue Operasional" value={idr(props.performance.all_revenue)} note="Paid dan unpaid sepanjang waktu"/>
      <MetricCard label="Expenses Approved" value={idr(props.performance.all_expenses)} note="Paid dan unpaid sepanjang waktu"/>
    </div>
    <div className="metric-grid metric-grid-5">
      <MetricCard icon={<Egg/>} label="Panen UTUH Terakhir" value={percentage(props.latestHarvest.utuh_percentage)} note={props.latestHarvest.date?`${integer(props.latestHarvest.utuh_pieces)} butir ÷ ${integer(props.latestHarvest.population)} populasi · ${date(props.latestHarvest.date)}`:'Belum ada panen'}/>
      <MetricCard icon={<Egg/>} label="Total Panen Terakhir" value={percentage(props.latestHarvest.total_percentage)} note={props.latestHarvest.date?`${integer(props.latestHarvest.total_pieces)} butir ÷ ${integer(props.latestHarvest.population)} populasi · ${date(props.latestHarvest.date)}`:'Belum ada panen'}/>
      {(['UTUH','PECAH','REJECT'] as const).map(category=><MetricCard key={category} icon={<Egg/>} label={`Stok ${eggCategoryLabel(category)}`} value={kg(props.eggStock[category].kg)} note={<><strong className="text-body">{integer(props.eggStock[category].pieces)} butir</strong> · per {date(props.today)}</>}/>) }
    </div>

    <Card className="mb-4 dashboard-biomass-card"><Card.Header>Estimasi Biomasa Lele</Card.Header><Card.Body>{props.biomass.length?props.biomass.map(row=><div className="dashboard-biomass-row" key={row.group_id}><div><strong>{row.group_name} · {row.group_code}</strong><small>{row.location} · Populasi {integer(row.population)} ekor</small></div><div className="dashboard-biomass-value">{row.estimated_biomass_kg?<><strong>{kg(row.estimated_biomass_kg)}</strong><small>{numeric(row.average_weight_g,{maximumFractionDigits:2})} g/ekor · sampling {date(row.sampling_date!)}</small></>:<><strong>Belum sampling</strong><small>Estimasi biomasa belum tersedia</small></>}</div></div>):<EmptyState title="Belum ada grup Lele aktif"/>}</Card.Body></Card>

    <Card className="mb-4"><Card.Header className="dashboard-card-header"><span>Revenue & Expenses · Periode Terpilih</span><RangeForm range={summaryRange} setRange={setSummaryRange} today={props.today} onSubmit={applyRanges}/></Card.Header><Card.Body><div className="dashboard-period-card-grid"><div className="dashboard-period-metrics"><div><span>Revenue Operasional</span><strong>{idr(props.performance.period_revenue)}</strong></div><div><span>Expenses Approved</span><strong>{idr(props.performance.period_expenses)}</strong></div><small>{date(props.summaryRange.from)}–{date(props.summaryRange.to)}</small></div><div className="dashboard-breakdown"><h3>Breakdown Pengeluaran Operasional</h3>{breakdown.length?<><div className="chart-wrap chart-wrap-compact"><ResponsiveContainer><PieChart><Pie data={breakdown} dataKey="value" nameKey="name" innerRadius={55} outerRadius={85} paddingAngle={2}>{breakdown.map((_,index)=><Cell key={index} fill={colors[index%colors.length]}/>)}</Pie><Tooltip formatter={value=>idr(Number(value))}/><Legend/></PieChart></ResponsiveContainer></div>{breakdown.map(row=><div className="summary-line" key={row.name}><span>{row.name}</span><strong>{idr(row.value)}</strong></div>)}</>:<EmptyState title="Belum ada biaya disetujui"/>}</div></div></Card.Body></Card>

    <ChartCard title="Operating Revenue vs Approved Expenses · Periode Terpilih" range={financeRange} setRange={setFinanceRange} today={props.today} onSubmit={applyRanges}><ResponsiveContainer><BarChart data={finance}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)"/><XAxis dataKey="label" stroke="var(--muted)"/><YAxis stroke="var(--muted)" tickFormatter={value=>`${integer(Math.round(value/1_000_000))}jt`}/><Tooltip formatter={value=>idr(Number(value))}/><Legend/><Bar dataKey="revenue" name="Revenue" fill="#3a7afe"/><Bar dataKey="expenses" name="Approved Expenses" fill="#ff9800"/></BarChart></ResponsiveContainer></ChartCard>
    <ChartCard title="Panen Telur Harian · Periode Terpilih" range={harvestRange} setRange={setHarvestRange} today={props.today} onSubmit={applyRanges}><ResponsiveContainer><LineChart data={props.harvestSeries}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)"/><XAxis dataKey="label" stroke="var(--muted)"/><YAxis stroke="var(--muted)" tickFormatter={value=>integer(value)}/><Tooltip formatter={value=>`${integer(Number(value))} butir`}/><Legend/><Line type="monotone" dataKey="utuh" name="UTUH" stroke="#10c995" strokeWidth={2}/><Line type="monotone" dataKey="pecah" name="PECAH" stroke="#ff9800" strokeWidth={2}/><Line type="monotone" dataKey="reject" name="RIJEK" stroke="#ff5c75" strokeWidth={2}/><Line type="monotone" dataKey="total" name="Total" stroke="#3a7afe" strokeWidth={3}/></LineChart></ResponsiveContainer></ChartCard>
    <ChartCard title="Pengeluaran per Jenis · Rolling 12 Bulan"><ResponsiveContainer><LineChart data={expenses}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)"/><XAxis dataKey="label" stroke="var(--muted)"/><YAxis stroke="var(--muted)" tickFormatter={value=>`${integer(Math.round(value/1_000_000))}jt`}/><Tooltip formatter={value=>idr(Number(value))}/><Legend/>{Object.entries(props.expenseTypes).map(([key,label],index)=><Line key={key} type="monotone" dataKey={key} name={label} stroke={colors[index%colors.length]} strokeWidth={2}/>)}</LineChart></ResponsiveContainer></ChartCard>
    <Card><Card.Header>Transaksi Terbaru</Card.Header><Card.Body className="p-0"><ResponsiveTable data={props.recent} columns={recentColumns} emptyTitle="Belum ada transaksi"/></Card.Body></Card>
  </AppShell>;
}

function RangeForm({range,setRange,today,onSubmit}:{range:Range;setRange:(range:Range)=>void;today:string;onSubmit:(event:FormEvent)=>void}){
  return <Form className="dashboard-card-filter" onSubmit={onSubmit}><DateField label="Dari" value={range.from} onChange={from=>setRange({...range,from})} max={today} required/><DateField label="Sampai" value={range.to} onChange={to=>setRange({...range,to})} min={range.from} max={today} required/><Button size="sm" type="submit">Terapkan</Button></Form>;
}
function ChartCard({title,children,range,setRange,today,onSubmit}:{title:string;children:React.ReactNode;range?:Range;setRange?:(range:Range)=>void;today?:string;onSubmit?:(event:FormEvent)=>void}){
  return <Card className="mb-4"><Card.Header className="dashboard-card-header"><span>{title}</span>{range&&setRange&&today&&onSubmit&&<RangeForm range={range} setRange={setRange} today={today} onSubmit={onSubmit}/>}</Card.Header><Card.Body><div className="chart-wrap">{children}</div></Card.Body></Card>;
}
