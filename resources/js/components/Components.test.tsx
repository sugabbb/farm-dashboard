import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { ColumnDef } from '@tanstack/react-table';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DataTable } from './DataTable';
import { AuditTimeline } from './AuditTimeline';
import { ExportRangeModal } from './ExportRangeModal';
import { DateField, FormDrawer, LocalizedNumberInput, MultiSelect, parseLocalizedNumber, ProofUpload, ProofViewer, SearchableSelect } from './FormControls';
import { StatusBadge } from './StatusBadge';
import { PaymentHistory } from './PaymentControls';
import { dailyBlankEggs, dailyBlankFeed, dailyBlankRow, dailyFeedForEdit } from '../pages/DailyEntry';
import { MetricCard } from './MetricCard';
import { GrowthCard, type Growth } from '../pages/LeleBiomass';
import { operationalFeedForEdit } from '../pages/Expenses';

const inertia = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
  router: inertia,
  Head: () => null,
  Link: ({ children, ...props }: React.ComponentProps<'a'>) => <a {...props}>{children}</a>,
  usePage: () => ({ props: { auth: { user: null }, flash: {}, errors: {}, today: '2026-10-10' } }),
}));

let mobileViewport = false;
const mobileViewportListeners = new Set<() => void>();
const mobileMediaQuery = {
  get matches() { return mobileViewport; },
  media: '(max-width: 991.98px)',
  onchange: null,
  addListener: () => undefined,
  removeListener: () => undefined,
  addEventListener: (_event: string, listener: () => void) => { mobileViewportListeners.add(listener); },
  removeEventListener: (_event: string, listener: () => void) => { mobileViewportListeners.delete(listener); },
  dispatchEvent: () => false,
} as unknown as MediaQueryList;
Object.defineProperty(window, 'matchMedia', { writable: true, value: () => mobileMediaQuery });

describe('shared farm interface components', () => {
  beforeEach(() => { inertia.get.mockReset(); mobileViewport = false; window.history.replaceState({}, '', '/employees?q=abk'); });

  it('uses a server-side sort URL and renders pagination totals', () => {
    type Row = { name: string; status: string };
    const columns: ColumnDef<Row, unknown>[] = [{ accessorKey: 'name', header: 'Nama' }, { accessorKey: 'status', header: 'Status' }];
    render(<DataTable columns={columns} page={{ data: [{ name: 'Siti', status: 'ACTIVE' }], current_page: 1, last_page: 2, per_page: 30, total: 31, from: 1, to: 30, first_page_url: '/employees?page=1', last_page_url: '/employees?page=2', prev_page_url: null, next_page_url: '/employees?page=2' }} sort="name" direction="asc" expandedId="Siti" rowId={row=>row.name} renderExpanded={row=><div>Rincian {row.name}</div>}/>);
    expect(screen.getByText('Siti')).toBeInTheDocument();
    expect(screen.getByText('Rincian Siti')).toBeInTheDocument();
    expect(screen.getByText('Menampilkan 1–30 dari 31')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Nama/i }));
    expect(inertia.get).toHaveBeenCalledWith('/employees?q=abk&sort=name&direction=desc', {}, expect.objectContaining({ replace: true }));
  });

  it('renders cards with grouped fields and mobile sorting, then switches back to the desktop table', async () => {
    type Row = { id: number; name: string; status: string; kg: string; pieces: number };
    const onEdit = vi.fn();
    const columns: ColumnDef<Row, unknown>[] = [
      { accessorKey: 'name', header: 'Nama', meta: { mobile: { role: 'heading' } } },
      { accessorKey: 'status', header: 'Status' },
      { accessorKey: 'kg', header: 'Kg', enableSorting: false, meta: { mobile: { group: 'Telur Utuh', label: 'Kg' } } },
      { accessorKey: 'pieces', header: 'Butir', enableSorting: false, meta: { mobile: { group: 'Telur Utuh', label: 'Butir' } } },
      { id: 'actions', header: 'Aksi', enableSorting: false, cell: ({ row }) => <button onClick={() => onEdit(row.original)}>Edit {row.original.name}</button>, meta: { mobile: { role: 'actions' } } },
    ];
    mobileViewport = true;
    render(<DataTable columns={columns} page={{ data: [{ id: 3, name: 'Panen Pagi', status: 'ACTIVE', kg: '2.500', pieces: 42 }], current_page: 1, last_page: 1, per_page: 30, total: 1, from: 1, to: 1, first_page_url: '/employees?page=1', last_page_url: '/employees?page=1', prev_page_url: null, next_page_url: null }} sort="name" direction="asc" rowId={row => row.id} expandedId={3} renderExpanded={() => <div>Rincian audit</div>}/>);

    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(screen.getByRole('listitem')).toHaveTextContent('Panen Pagi');
    expect(screen.getByRole('heading', { name: 'Telur Utuh' })).toBeInTheDocument();
    expect(screen.getByText('Rincian audit')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Edit Panen Pagi' }));
    expect(onEdit).toHaveBeenCalledTimes(1);

    fireEvent.change(screen.getByLabelText('Urutkan berdasarkan'), { target: { value: 'status' } });
    expect(inertia.get).toHaveBeenLastCalledWith('/employees?q=abk&sort=status&direction=asc', {}, expect.objectContaining({ replace: true }));
    fireEvent.click(screen.getByRole('button', { name: 'Ubah urutan menjadi menurun' }));
    expect(inertia.get).toHaveBeenLastCalledWith('/employees?q=abk&sort=name&direction=desc', {}, expect.objectContaining({ replace: true }));

    act(() => { mobileViewport = false; mobileViewportListeners.forEach(listener => listener()); });
    await waitFor(() => expect(screen.getByRole('table')).toBeInTheDocument());
    expect(screen.queryByRole('listitem')).not.toBeInTheDocument();
  });

  it('keeps Indonesian display dates while returning a date-only value', async () => {
    const onChange = vi.fn();
    render(<DateField label="Tanggal transaksi" value="2026-10-10" onChange={onChange}/>);
    const input = screen.getByRole('textbox', { name: 'Tanggal transaksi' });
    expect(input).toHaveValue('Sab, 10 Okt 2026');
    fireEvent.change(input, { target: { value: 'Min, 11 Okt 2026' } });
    fireEvent.blur(input);
    await waitFor(() => expect(onChange).toHaveBeenCalledWith('2026-10-11'));
  });

  it('formats comma-grouped numbers and period decimals while returning canonical numbers', () => {
    const onMoneyChange = vi.fn();
    const onDecimalChange = vi.fn();
    const { rerender } = render(<LocalizedNumberInput aria-label="Jumlah" value="1250000" onChange={onMoneyChange}/>);
    const input = screen.getByRole('textbox', { name: 'Jumlah' });
    expect(input).toHaveValue('1,250,000');
    expect(input).toHaveAttribute('inputmode', 'numeric');
    fireEvent.change(input, { target: { value: '2,500,000' } });
    expect(onMoneyChange).toHaveBeenLastCalledWith('2500000');

    rerender(<LocalizedNumberInput aria-label="Berat" scale={3} allowNegative value="-1234.500" onChange={onDecimalChange}/>);
    expect(input).toHaveValue('-1,234.500');
    expect(input).toHaveAttribute('inputmode', 'decimal');
    fireEvent.change(input, { target: { value: '2,345.75' } });
    expect(onDecimalChange).toHaveBeenLastCalledWith('2345.75');
    expect(parseLocalizedNumber('-1,234.500', 3, true)).toBe('-1234.500');
    expect(parseLocalizedNumber('1.234', 0)).toBe('1');
    expect(parseLocalizedNumber('2,345.', 3)).toBe('2345.');
  });

  it('keeps zero as a valid localized value and renders audited detail removal', () => {
    const onChange = vi.fn();
    render(<><LocalizedNumberInput aria-label="Nilai rekonsiliasi" value="1" onChange={onChange}/><AuditTimeline logs={[{
      id: 9,
      action: 'revenue_egg_detail_deleted',
      label: 'Rincian penjualan telur dihapus',
      actor_name: 'Superadmin',
      happened_at: '2026-10-16T08:00:00+07:00',
      reason: 'Kategori salah dihapus',
      subject_label: 'Telur RIJEK',
      changes: [{ field: 'quantity_pieces', label: 'Jumlah (butir)', before: '12 butir', after: '—' }],
    }]}/></>);
    fireEvent.change(screen.getByRole('textbox', { name: 'Nilai rekonsiliasi' }), { target: { value: '0' } });
    expect(onChange).toHaveBeenCalledWith('0');
    expect(screen.getByText('Rincian penjualan telur dihapus')).toBeInTheDocument();
    expect(screen.getByText('Telur RIJEK')).toBeInTheDocument();
    expect(screen.getByText('Kategori salah dihapus')).toBeInTheDocument();
  });

  it('supports keyboard selection and disabled historical options', async () => {
    const onChange = vi.fn();
    render(<SearchableSelect label="Jenis pakan" value={null} onChange={onChange} options={[{ value: 1, label: 'Layer' }, { value: 2, label: 'Grower nonaktif', isDisabled: true }]}/>);
    const select = screen.getByRole('combobox', { name: 'Jenis pakan' });
    fireEvent.keyDown(select, { key: 'ArrowDown' });
    fireEvent.keyDown(select, { key: 'Enter' });
    await waitFor(() => expect(onChange).toHaveBeenCalledWith(1));
  });

  it('opens drawers and exposes status semantics', () => {
    const onHide = vi.fn();
    render(<><StatusBadge value="APPROVED"/><FormDrawer show onHide={onHide} title="Edit grup"><p>Isi formulir</p></FormDrawer></>);
    expect(screen.getByText('APPROVED')).toHaveClass('status-success');
    expect(screen.getByRole('dialog')).toHaveTextContent('Edit grup');
    fireEvent.click(screen.getByLabelText('Close'));
    expect(onHide).toHaveBeenCalledOnce();
  });

  it('renders partial-payment history and independently filters report types', async () => {
    const onVoid=vi.fn();
    const onTypes=vi.fn();
    render(<><StatusBadge value="PARTIALLY_PAID"/><PaymentHistory payments={[{
      id:4,payment_mode:'PARTIAL',paid_date:'2026-10-09',amount_idr:'25000',proof_url:'https://storage.googleapis.com/farm/installment.png',creator_name:'Admin',created_at:'2026-10-09T08:00:00+07:00',voided_at:null,voider_name:null,void_reason:null,
    }]} onVoid={onVoid}/><MultiSelect label="Jenis Revenue" values={['OTHER']} options={[{value:'OTHER',label:'Pemasukan Lain-lain'},{value:'EGG_SALE',label:'Penjualan Telur'}]} onChange={onTypes}/></>);
    expect(screen.getByText('PARTIALLY PAID')).toHaveClass('status-warning');
    expect(screen.getByText(/Partial · Rp 25,000/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button',{name:'Batalkan'}));
    expect(onVoid).toHaveBeenCalledOnce();
    const select=screen.getByRole('combobox',{name:'Jenis Revenue'});
    fireEvent.keyDown(select,{key:'ArrowDown'});
    fireEvent.keyDown(select,{key:'ArrowDown'});
    fireEvent.keyDown(select,{key:'Enter'});
    await waitFor(()=>expect(onTypes).toHaveBeenCalledWith(['OTHER','EGG_SALE']));
  });

  it('previews an uploaded proof and offers a zoomable proof viewer', () => {
    const onChange = vi.fn();
    const { rerender } = render(<ProofUpload onChange={onChange}/>);
    const file = new File(['proof'], 'nota.png', { type: 'image/png' });
    fireEvent.change(document.querySelector('input[type="file"]') as HTMLInputElement, { target: { files: [file] } });
    expect(onChange).toHaveBeenCalledWith(file);
    expect(screen.getByAltText('Pratinjau bukti baru')).toHaveAttribute('src', 'blob:proof-preview');
    rerender(<ProofViewer url="https://storage.googleapis.com/farm/proof.png"/>);
    fireEvent.click(screen.getByRole('button', { name: /Lihat bukti/i }));
    const proofDialog = screen.getByText('Bukti transaksi').closest('[role="dialog"]');
    expect(proofDialog).toHaveTextContent('100%');
    expect(screen.getByRole('button', { name: 'Buka gambar asli' })).toHaveAttribute('href', 'https://storage.googleapis.com/farm/proof.png');
    fireEvent.click(screen.getByRole('button', { name: 'Perbesar gambar' }));
    expect(proofDialog).toHaveTextContent('125%');
  });

  it('builds a date-only export URL independently from list filters', () => {
    const { rerender } = render(<ExportRangeModal show onHide={vi.fn()} endpoint="/revenues/export" today="2026-10-14" title="Export Revenues"/>);
    expect(screen.getByRole('dialog')).toHaveTextContent('tidak mengikuti filter daftar transaksi');
    expect(screen.getByRole('button', { name: /Download Excel/i })).toHaveAttribute('href', '/revenues/export?from=2026-10-01&to=2026-10-14');
    rerender(<ExportRangeModal show onHide={vi.fn()} endpoint="/revenues/export" today="2026-10-14" title="Export Revenues" filters={{ payment_method: 'DIRECT_TRANSFER' }}/>);
    expect(screen.getByRole('dialog')).toHaveTextContent('Filter daftar yang aktif tetap diterapkan');
    expect(screen.getByRole('button', { name: /Download Excel/i })).toHaveAttribute('href', '/revenues/export?from=2026-10-01&to=2026-10-14&payment_method=DIRECT_TRANSFER');
  });

  it('removes stored-only feed fields before editing a daily Ayam record', () => {
    const stored = { id: 7, feed_type_id: 2, feed_name: 'Layer', morning_kg: '1.000', noon_kg: '2.000', evening_kg: '0.000', quantity_kg: '3.000' };
    expect(dailyFeedForEdit(stored, 'AYAM')).toEqual({ feed_type_id: 2, morning_kg: '1.000', noon_kg: '2.000' });
    expect(dailyFeedForEdit(stored, 'LELE')).toEqual({ feed_type_id: 2, morning_kg: '1.000', noon_kg: '2.000', evening_kg: '0.000' });
  });

  it('starts new daily numeric inputs empty while retaining zero as their visual placeholder', () => {
    expect(dailyBlankFeed('AYAM')).toEqual({ feed_type_id: '', morning_kg: '', noon_kg: '' });
    expect(dailyBlankFeed('LELE')).toEqual({ feed_type_id: '', morning_kg: '', noon_kg: '', evening_kg: '' });
    expect(dailyBlankEggs()).toEqual({
      UTUH: { weight_kg: '', quantity_pieces: '' },
      PECAH: { weight_kg: '', quantity_pieces: '' },
      REJECT: { weight_kg: '', quantity_pieces: '' },
    });
    expect(dailyBlankRow('2026-10-10','LELE').feeds).toEqual([{feed_type_id:'',morning_kg:'',noon_kg:'',evening_kg:''}]);
  });

  it('keeps metric cards keyboard accessible and exposes their active filter state', () => {
    const onClick=vi.fn();
    render(<MetricCard label="Menunggu Approval" value="Rp 10,000" note="Submitted" onClick={onClick} active/>);
    const card=screen.getByRole('button',{name:/Menunggu Approval/i});
    expect(card).toHaveAttribute('aria-pressed','true');
    fireEvent.keyDown(card,{key:'Enter'}); fireEvent.keyDown(card,{key:' '});
    expect(onClick).toHaveBeenCalledTimes(2);
  });

  it('uses the revised ADG terminology and explanatory note on growth cards', () => {
    const growth:Growth={status:'OK',target_weight_g:'142.857143',planned_adg_g_per_day:'1.300',calculated_adg_g_per_day:'1.500',planned_harvest_date:'2026-12-01',chart_end_date:'2026-12-01',rebuilt_at:'2026-10-10T00:00:00+07:00',points:[{date:'2026-10-10',planned:10,calculated:10,actual:10,kinds:['SAMPLING']}]};
    render(<GrowthCard title="Kolam A" growth={growth}/>);
    expect(screen.getAllByText(/ADG Ideal/).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/ADG by Bobot Sampling/).length).toBeGreaterThan(0);
    expect(screen.getByText('ADG: Average Daily Gain')).toBeInTheDocument();
    expect(screen.queryByText('ADG Rencana')).not.toBeInTheDocument();
  });

  it('removes stored-only fields when editing a pre-unit feed purchase', () => {
    const editable=operationalFeedForEdit({id:9,feed_type_id:2,feed_name:'Layer',purchase_unit:undefined as never,purchase_quantity:undefined as never,kg_per_sak:null,price_per_purchase_unit_idr:undefined as never,quantity_kg:'25.000',price_per_kg_idr:'10000.000',line_amount_idr:'250000',received_at:null,receiver_name:null});
    expect(editable).toEqual({id:9,feed_type_id:2,purchase_unit:'KG',purchase_quantity:'25.000',kg_per_sak:null,price_per_purchase_unit_idr:'10000.000',quantity_kg:'25.000',price_per_kg_idr:'10000.000'});
    expect(editable).not.toHaveProperty('line_amount_idr');
    expect(editable).not.toHaveProperty('received_at');
  });
});
