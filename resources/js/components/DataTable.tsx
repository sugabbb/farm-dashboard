import { router } from '@inertiajs/react';
import {
  ColumnDef,
  Cell,
  flexRender,
  getCoreRowModel,
  RowData,
  SortingState,
  useReactTable,
} from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ChevronDown, ChevronsUpDown, ChevronUp } from 'lucide-react';
import { Fragment, ReactNode, useId, useSyncExternalStore } from 'react';
import { Card, Form, Pagination as BootstrapPagination, Table } from 'react-bootstrap';
import type { Pagination } from '../types';
import { integer } from '../utils';
import { EmptyState } from './AppShell';

export type MobileColumnRole = 'heading' | 'featured' | 'field' | 'actions';
export type MobileColumnWidth = 'standard' | 'full';

declare module '@tanstack/react-table' {
  interface ColumnMeta<TData extends RowData, TValue> {
    mobile?: {
      role?: MobileColumnRole;
      label?: string;
      width?: MobileColumnWidth;
      group?: string;
    };
  }
}

const mobileQuery = '(max-width: 991.98px)';
const mobileListeners = new Set<() => void>();
let mobileMedia: MediaQueryList | null = null;
let listening = false;

function mediaQuery() {
  if (typeof window === 'undefined') return null;
  if (!mobileMedia) mobileMedia = window.matchMedia(mobileQuery);
  return mobileMedia;
}

function subscribeMobile(listener: () => void) {
  mobileListeners.add(listener);
  const media = mediaQuery();
  if (media && !listening) {
    media.addEventListener('change', notifyMobileListeners);
    listening = true;
  }
  return () => {
    mobileListeners.delete(listener);
    if (media && listening && mobileListeners.size === 0) {
      media.removeEventListener('change', notifyMobileListeners);
      listening = false;
    }
  };
}

function notifyMobileListeners() {
  mobileListeners.forEach(listener => listener());
}

function useMobileTableLayout() {
  return useSyncExternalStore(subscribeMobile, () => mediaQuery()?.matches ?? false, () => false);
}

type ResponsiveTableProps<T> = {
  data: T[];
  columns: ColumnDef<T, unknown>[];
  sort?: string;
  direction?: 'asc' | 'desc';
  onSort?: (id: string, direction: 'asc' | 'desc') => void;
  emptyTitle?: string;
  renderExpanded?: (row: T) => ReactNode;
  expandedId?: string | number | null;
  rowId?: (row: T) => string | number;
};

export function ResponsiveTable<T>({
  data,
  columns,
  sort,
  direction = 'asc',
  onSort,
  emptyTitle,
  renderExpanded,
  expandedId,
  rowId,
}: ResponsiveTableProps<T>) {
  const mobile = useMobileTableLayout();
  const sortControlId = useId();
  const sorting: SortingState = sort ? [{ id: sort, desc: direction === 'desc' }] : [];
  const table = useReactTable({
    data,
    columns,
    state: { sorting },
    manualSorting: true,
    getCoreRowModel: getCoreRowModel(),
    getRowId: (row, index) => String(rowId?.(row) ?? defaultRowId(row, index)),
  });
  const leafColumns = table.getVisibleLeafColumns();
  const sortableColumns = leafColumns.filter(column => column.getCanSort());
  const selectedSort = sort && sortableColumns.some(column => column.id === sort) ? sort : sortableColumns[0]?.id;

  if (!data.length) return <div className="responsive-table-empty"><EmptyState title={emptyTitle}/></div>;

  if (!mobile) {
    return <div className="table-responsive desktop-table-wrap">
      <Table hover className="data-table mb-0">
        <thead>{table.getHeaderGroups().map(group => <tr key={group.id}>{group.headers.map(header => {
          const sortable = header.column.getCanSort() && Boolean(onSort);
          const active = sort === header.column.id;
          return <th key={header.id} colSpan={header.colSpan} aria-sort={active ? (direction === 'asc' ? 'ascending' : 'descending') : undefined}>
            {header.isPlaceholder ? null : sortable ? <button className="sort-button" onClick={() => onSort?.(header.column.id, active && direction === 'asc' ? 'desc' : 'asc')}>{flexRender(header.column.columnDef.header, header.getContext())}{active ? (direction === 'asc' ? <ChevronUp/> : <ChevronDown/>) : <ChevronsUpDown/>}</button> : flexRender(header.column.columnDef.header, header.getContext())}
          </th>;
        })}</tr>)}</thead>
        <tbody>{table.getRowModel().rows.map(row => {
          const open = renderExpanded && rowId && String(rowId(row.original)) === String(expandedId ?? '');
          return <Fragment key={row.id}><tr>{row.getVisibleCells().map(cell => <td key={cell.id}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</td>)}</tr>{open && <tr className="expanded-row"><td colSpan={leafColumns.length}>{renderExpanded(row.original)}</td></tr>}</Fragment>;
        })}</tbody>
      </Table>
    </div>;
  }

  return <div className="mobile-table-view">
    {onSort && sortableColumns.length > 0 && selectedSort && <div className="mobile-sort-controls">
      <Form.Group>
        <Form.Label htmlFor={sortControlId}>Urutkan berdasarkan</Form.Label>
        <Form.Select id={sortControlId} value={selectedSort} onChange={event => onSort(event.target.value, direction)}>
          {sortableColumns.map(column => <option key={column.id} value={column.id}>{columnLabel(column.columnDef, column.id)}</option>)}
        </Form.Select>
      </Form.Group>
      <button className="btn btn-light mobile-sort-direction" type="button" onClick={() => onSort(selectedSort, direction === 'asc' ? 'desc' : 'asc')} aria-label={direction === 'asc' ? 'Ubah urutan menjadi menurun' : 'Ubah urutan menjadi menaik'}>
        {direction === 'asc' ? <ArrowUp/> : <ArrowDown/>}<span>{direction === 'asc' ? 'Menaik' : 'Menurun'}</span>
      </button>
    </div>}
    <div className="mobile-card-list" role="list">{table.getRowModel().rows.map(row => {
      const cells = row.getVisibleCells();
      const items: Array<{ type: 'cell'; cell: typeof cells[number]; index: number } | { type: 'group'; label: string; cells: typeof cells }> = [];
      cells.forEach((cell, index) => {
        const group = cell.column.columnDef.meta?.mobile?.group;
        if (!group) items.push({ type: 'cell', cell, index });
        else {
          const existing = items.find(item => item.type === 'group' && item.label === group);
          if (existing?.type === 'group') existing.cells.push(cell);
          else items.push({ type: 'group', label: group, cells: [cell] });
        }
      });
      const actions = items.filter(item => item.type === 'cell' && mobileRole(item.cell.column.columnDef, item.index) === 'actions');
      const fields = items.filter(item => !(item.type === 'cell' && mobileRole(item.cell.column.columnDef, item.index) === 'actions'));
      const open = renderExpanded && rowId && String(rowId(row.original)) === String(expandedId ?? '');
      return <article className="mobile-data-card" role="listitem" key={row.id}>
        <div className="mobile-card-fields">{fields.map((item, itemIndex) => item.type === 'group'
          ? <section className="mobile-card-group" key={`${row.id}-${item.label}`}><h3>{item.label}</h3><div className="mobile-card-group-fields">{item.cells.map(cell => <MobileField key={cell.id} cell={cell} role="field"/>)}</div></section>
          : <MobileField key={item.cell.id} cell={item.cell} role={mobileRole(item.cell.column.columnDef, item.index)} fallbackFull={itemIndex === 0}/>)}</div>
        {actions.length > 0 && <div className="mobile-card-actions">{actions.map(item => item.type === 'cell' && <div key={item.cell.id}>{flexRender(item.cell.column.columnDef.cell, item.cell.getContext())}</div>)}</div>}
        {open && <div className="mobile-card-expanded">{renderExpanded(row.original)}</div>}
      </article>;
    })}</div>
  </div>;
}

function MobileField<T>({ cell, role, fallbackFull = false }: { cell: Cell<T, unknown>; role: MobileColumnRole; fallbackFull?: boolean }) {
  const meta = cell.column.columnDef.meta?.mobile;
  const full = meta?.width === 'full' || fallbackFull || role === 'heading' || role === 'featured';
  return <dl className={`mobile-card-field mobile-card-${role}${full ? ' mobile-card-field-full' : ''}`}>
    <dt>{columnLabel(cell.column.columnDef, cell.column.id)}</dt>
    <dd>{flexRender(cell.column.columnDef.cell, cell.getContext())}</dd>
  </dl>;
}

function mobileRole<T>(column: ColumnDef<T, unknown>, index: number): MobileColumnRole {
  return column.meta?.mobile?.role ?? (String((column as { id?: string }).id) === 'actions' ? 'actions' : index === 0 ? 'heading' : 'field');
}

function columnLabel<T>(column: ColumnDef<T, unknown>, fallback: string) {
  return column.meta?.mobile?.label ?? (typeof column.header === 'string' ? column.header : fallback);
}

function defaultRowId<T>(row: T, index: number) {
  if (row && typeof row === 'object') {
    const record = row as Record<string, unknown>;
    if (record.id !== undefined) return record.id as string | number;
    if (record.key !== undefined) return record.key as string | number;
  }
  return index;
}

type Props<T> = Omit<ResponsiveTableProps<T>, 'data' | 'onSort'> & { page: Pagination<T> };

export function DataTable<T>({ page, ...props }: Props<T>) {
  const applySort = (id: string, nextDirection: 'asc' | 'desc') => {
    const params = new URLSearchParams(window.location.search);
    params.set('sort', id);
    params.set('direction', nextDirection);
    params.delete('page');
    const query = params.toString();
    router.get(`${window.location.pathname}${query ? `?${query}` : ''}`, {}, { preserveState: true, preserveScroll: true, replace: true });
  };
  const visit = (url: string | null) => url && router.get(url, {}, { preserveState: true, preserveScroll: true });

  return <Card className="data-card">
    <ResponsiveTable {...props} data={page.data} onSort={applySort}/>
    {page.last_page > 1 && <div className="table-footer"><span>Menampilkan {integer(page.from ?? 0)}–{integer(page.to ?? 0)} dari {integer(page.total)}</span><BootstrapPagination size="sm" className="pagination-buttons mb-0"><BootstrapPagination.Prev disabled={!page.prev_page_url} onClick={() => visit(page.prev_page_url)} aria-label="Halaman sebelumnya"/><BootstrapPagination.Item active>{integer(page.current_page)} / {integer(page.last_page)}</BootstrapPagination.Item><BootstrapPagination.Next disabled={!page.next_page_url} onClick={() => visit(page.next_page_url)} aria-label="Halaman berikutnya"/></BootstrapPagination></div>}
  </Card>;
}
