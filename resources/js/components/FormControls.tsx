import { format, parse } from 'date-fns';
import { id } from 'date-fns/locale';
import { CalendarDays, Eye, Upload, ZoomIn, ZoomOut } from 'lucide-react';
import { ChangeEvent, ComponentProps, ReactNode, useEffect, useId, useRef, useState } from 'react';
import { Button, Form, Modal, Offcanvas, ProgressBar } from 'react-bootstrap';
import DatePicker, { registerLocale } from 'react-datepicker';
import { createPortal } from 'react-dom';
import Select, { GroupBase, StylesConfig } from 'react-select';
import type { Option } from '../types';

registerLocale('id', id);

function CalendarPortal({ children }: { children?: ReactNode }) {
  return createPortal(children, document.body);
}

export function FieldError({ message }: { message?: string }) { return message ? <div className="invalid-feedback d-block">{message}</div> : null; }

type LocalizedNumberInputProps = Omit<ComponentProps<typeof Form.Control>, 'value' | 'onChange' | 'type' | 'inputMode'> & {
  value: string | number;
  onChange: (value: string) => void;
  scale?: number;
  allowNegative?: boolean;
};

export function formatLocalizedNumber(value: string | number, scale = 0) {
  const raw = String(value ?? '');
  if (!raw || raw === '-') return raw;
  const negative = raw.startsWith('-');
  const unsigned = negative ? raw.slice(1) : raw;
  const [integerPart = '', decimalPart] = unsigned.split('.', 2);
  const grouped = (integerPart || '0').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const decimal = scale > 0 && decimalPart !== undefined ? `.${decimalPart.slice(0, scale)}` : '';
  return `${negative ? '-' : ''}${grouped}${decimal}`;
}

export function parseLocalizedNumber(value: string, scale = 0, allowNegative = false) {
  const negative = allowNegative && value.trimStart().startsWith('-');
  const unsigned = value.replace(/-/g, '');
  const period = unsigned.indexOf('.');
  const integerSource = period >= 0 ? unsigned.slice(0, period) : unsigned;
  const integerPart = integerSource.replace(/\D/g, '');
  const decimalPart = scale > 0 && period >= 0 ? unsigned.slice(period + 1).replace(/\D/g, '').slice(0, scale) : '';
  if (!integerPart && period < 0) return negative ? '-' : '';
  const canonicalInteger = integerPart || '0';
  const canonicalDecimal = scale > 0 && period >= 0 ? `.${decimalPart}` : '';
  return `${negative ? '-' : ''}${canonicalInteger}${canonicalDecimal}`;
}

/** Displays comma grouping and period decimals while keeping form/API values locale-neutral. */
export function LocalizedNumberInput({ value, onChange, scale = 0, allowNegative = false, ...props }: LocalizedNumberInputProps) {
  return <Form.Control
    {...props}
    type="text"
    inputMode={scale > 0 ? 'decimal' : 'numeric'}
    value={formatLocalizedNumber(value, scale)}
    onChange={event => onChange(parseLocalizedNumber(event.target.value, scale, allowNegative))}
  />;
}

export function DateField({ value, onChange, label, error, min, max, disabled, required }: { value: string; onChange: (value: string) => void; label?: string; error?: string; min?: string; max?: string; disabled?: boolean; required?: boolean }) {
  const inputId = useId();
  const selected = value ? parse(value, 'yyyy-MM-dd', new Date()) : null;
  const minDate = min ? parse(min, 'yyyy-MM-dd', new Date()) : undefined;
  const maxDate = max ? parse(max, 'yyyy-MM-dd', new Date()) : undefined;
  return <Form.Group className="date-field"><>{label && <Form.Label htmlFor={inputId}>{label}</Form.Label>}</><div className="date-input-wrap"><DatePicker id={inputId} selected={selected} onChange={(date: Date | null) => onChange(date ? format(date, 'yyyy-MM-dd') : '')} dateFormat="EEE, d MMM yyyy" locale="id" minDate={minDate} maxDate={maxDate} disabled={disabled} required={required} className={`form-control ${error ? 'is-invalid' : ''}`} placeholderText="Rab, 14 Okt 2026" popperContainer={CalendarPortal}/><CalendarDays size={17}/></div><FieldError message={error}/></Form.Group>;
}

export function SearchableSelect({ value, options, onChange, label, error, placeholder = 'Pilih...', isDisabled, name }: { value: Option['value'] | null | undefined; options: Option[]; onChange: (value: Option['value'] | null) => void; label?: string; error?: string; placeholder?: string; isDisabled?: boolean; name?: string }) {
  const generatedId = useId();
  const inputId = name ?? generatedId;
  const displayOptions = options.map(option => option.label === 'REJECT' ? { ...option, label: 'RIJEK' } : option);
  const selected = displayOptions.find(option => String(option.value) === String(value ?? '')) ?? null;
  const styles: StylesConfig<Option, false, GroupBase<Option>> = {
    menuPortal: base => ({ ...base, zIndex: 2000 }),
    control: base => ({ ...base, minHeight: 40 }),
  };
  return <Form.Group><>{label && <Form.Label htmlFor={inputId}>{label}</Form.Label>}</><Select<Option, false> inputId={inputId} name={name} value={selected} options={displayOptions} onChange={option => onChange(option?.value ?? null)} placeholder={placeholder} isDisabled={isDisabled} aria-invalid={Boolean(error)} classNamePrefix="farm-select" menuPortalTarget={document.body} styles={styles}/><FieldError message={error}/></Form.Group>;
}

export function MultiSelect({ values, options, onChange, label, placeholder = 'Pilih jenis...' }: { values: string[]; options: Option[]; onChange: (values: string[]) => void; label: string; placeholder?: string }) {
  const inputId = useId();
  const selected = options.filter(option => values.includes(String(option.value)));
  const styles: StylesConfig<Option, true, GroupBase<Option>> = { menuPortal: base => ({ ...base, zIndex: 2000 }), control: base => ({ ...base, minHeight: 40 }) };
  return <Form.Group><Form.Label htmlFor={inputId}>{label}</Form.Label><Select<Option, true> inputId={inputId} isMulti closeMenuOnSelect={false} value={selected} options={options} onChange={items => onChange(items.map(item => String(item.value)))} placeholder={placeholder} noOptionsMessage={() => 'Tidak ada jenis pada rentang ini'} classNamePrefix="farm-select" menuPortalTarget={document.body} styles={styles}/></Form.Group>;
}

export function FormDrawer({ show, onHide, title, children, size = 'standard' }: { show: boolean; onHide: () => void; title: string; children: ReactNode; size?: 'standard' | 'wide' }) {
  useEffect(() => {
    if (!show) return;
    const focusTimer = window.setTimeout(() => {
      const invalid = document.querySelector<HTMLElement>('.form-drawer .is-invalid, .form-drawer [aria-invalid="true"]');
      invalid?.focus();
    }, 50);
    return () => window.clearTimeout(focusTimer);
  });
  return <Offcanvas show={show} onHide={onHide} placement="end" className={`form-drawer ${size === 'wide' ? 'form-drawer-wide' : ''}`} restoreFocus>
    <Offcanvas.Header closeButton><Offcanvas.Title>{title}</Offcanvas.Title></Offcanvas.Header><Offcanvas.Body>{children}</Offcanvas.Body>
  </Offcanvas>;
}

export function ProofUpload({ currentUrl, progress, error, onChange }: { currentUrl?: string | null; progress?: number; error?: string; onChange: (file: File | null) => void }) {
  const [preview, setPreview] = useState<string | null>(null);
  const input = useRef<HTMLInputElement>(null);
  const change = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0] ?? null;
    if (preview) URL.revokeObjectURL(preview);
    setPreview(file ? URL.createObjectURL(file) : null); onChange(file);
  };
  return <Form.Group><Form.Label>Bukti transaksi <span className="text-muted">(opsional)</span></Form.Label><button type="button" className="proof-dropzone" onClick={() => input.current?.click()}><Upload/><span><strong>Pilih JPG atau PNG</strong><small>Maksimal 5 MiB</small></span></button><input ref={input} hidden type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" onChange={change}/>{preview && <img className="proof-preview" src={preview} alt="Pratinjau bukti baru"/>}{currentUrl && !preview && <ProofViewer url={currentUrl}/>} {typeof progress === 'number' && <ProgressBar now={progress} label={`${progress}%`}/>}<FieldError message={error}/></Form.Group>;
}

export function ProofViewer({ url }: { url: string }) {
  const [show, setShow] = useState(false); const [zoom, setZoom] = useState(1);
  return <><Button variant="link" className="proof-link" onClick={() => setShow(true)}><Eye size={16}/> Lihat bukti</Button><Modal show={show} onHide={() => setShow(false)} size="xl" centered className="proof-modal"><Modal.Header closeButton><Modal.Title>Bukti transaksi</Modal.Title></Modal.Header><Modal.Body><div className="proof-toolbar"><Button variant="light" aria-label="Perkecil gambar" onClick={() => setZoom(Math.max(.5, zoom - .25))}><ZoomOut/></Button><span>{Math.round(zoom * 100)}%</span><Button variant="light" aria-label="Perbesar gambar" onClick={() => setZoom(Math.min(3, zoom + .25))}><ZoomIn/></Button><Button variant="outline-primary" href={url} target="_blank" rel="noopener">Buka gambar asli</Button></div><div className="proof-stage"><img src={url} alt="Bukti transaksi" style={{ transform: `scale(${zoom})` }}/></div></Modal.Body></Modal></>;
}

