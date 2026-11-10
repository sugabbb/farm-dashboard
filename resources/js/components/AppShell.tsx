import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
  ChevronDown, CircleDollarSign, Contact, Download, Egg, FileBarChart, Fish, Focus, Fullscreen, Gauge,
  KeyRound, LogOut, Menu, Moon, Scale, Search, Settings, Sun, UserRound, Users, Wheat,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { ComponentType, PropsWithChildren, useEffect, useMemo, useRef, useState } from 'react';
import { Button, Card, Dropdown, Form, Offcanvas, OverlayTrigger, Tooltip } from 'react-bootstrap';
import { ToastContainer, toast } from 'react-toastify';
import type { SharedProps } from '../types';
import { date } from '../utils';
import { ChickenIcon } from './ChickenIcon';
import { FieldError, LocalizedNumberInput } from './FormControls';

type MenuIcon = LucideIcon | ComponentType<{ size?: number; className?: string }>;
type NavItem = { label: string; href: string; icon: MenuIcon };
type NavGroup = { key: string; label: string; icon: MenuIcon; superOnly?: boolean; items: NavItem[] };

export const navigationGroups: NavGroup[] = [
  { key: 'dashboard', label: 'Dashboard', icon: Gauge, items: [{ label: 'Dashboard', href: '/dashboard', icon: Gauge }] },
  { key: 'ayam', label: 'Ayam', icon: ChickenIcon, items: [
    { label: 'Grup Ayam', href: '/ayam/groups', icon: ChickenIcon },
    { label: 'Pakan Ayam', href: '/ayam/feed', icon: Wheat },
    { label: 'Telur', href: '/ayam/eggs', icon: Egg },
    { label: 'Laporan Harian Ayam', href: '/ayam/daily-entry', icon: Focus },
  ] },
  { key: 'lele', label: 'Lele', icon: Fish, items: [
    { label: 'Grup Lele', href: '/lele/groups', icon: Fish },
    { label: 'Pakan Lele', href: '/lele/feed', icon: Wheat },
    { label: 'Laporan Harian Lele', href: '/lele/daily-entry', icon: Focus },
    { label: 'Biomasa Lele', href: '/lele/biomass', icon: Scale },
  ] },
  { key: 'finance', label: 'Keuangan', icon: CircleDollarSign, items: [
    { label: 'Operational Cashflow', href: '/operational-cashflow', icon: CircleDollarSign },
    { label: 'Revenues', href: '/revenues', icon: CircleDollarSign },
    { label: 'Pelanggan', href: '/customers', icon: Contact },
    { label: 'Report', href: '/reports', icon: FileBarChart },
    { label: 'Export Report', href: '/export-report', icon: Download },
  ] },
  { key: 'employees', label: 'Karyawan', icon: Users, items: [{ label: 'ABK / Karyawan', href: '/employees', icon: Users }] },
  { key: 'settings', label: 'Pengaturan', icon: Settings, superOnly: true, items: [{ label: 'Pengaturan', href: '/settings', icon: Settings }] },
];

const pathFromUrl = (url: string) => url.split('?')[0].split('#')[0] || '/dashboard';
const groupForPath = (path: string) => navigationGroups.find(group => group.items.some(item => path === item.href || (item.href !== '/dashboard' && path.startsWith(item.href))))?.key ?? 'dashboard';

function GroupRail({ groups, selected, onSelect, mobile = false }: { groups: NavGroup[]; selected: string; onSelect: (key: string) => void; mobile?: boolean }) {
  return <nav className={mobile ? 'mobile-group-tabs' : 'rail-menu'} aria-label="Grup navigasi">
    {groups.map(group => {
      const Icon = group.icon;
      const button = <button
        type="button"
        className={selected === group.key ? 'active' : ''}
        aria-label={`Menu ${group.label}`}
        aria-pressed={selected === group.key}
        onClick={() => onSelect(group.key)}
      ><Icon size={22}/>{mobile && <span>{group.label}</span>}</button>;
      return mobile ? <span key={group.key}>{button}</span> : <OverlayTrigger key={group.key} placement="right" overlay={<Tooltip>{group.label}</Tooltip>}>{button}</OverlayTrigger>;
    })}
  </nav>;
}

function Navigation({ group, path, onNavigate }: { group: NavGroup; path: string; onNavigate?: () => void }) {
  return <>
    <div className="context-head"><span>{group.label}</span></div>
    <div className="context-body">
      <div className="context-brand"><span className="brand-glyph">PB</span><div><strong>Pondok Berkah Bersama</strong><small>Farm Management</small></div></div>
      <div className="nav-label">Menu {group.label}</div>
      <nav className="context-nav" aria-label={`Menu ${group.label}`}>
        {group.items.map(item => {
          const active = path === item.href || (item.href !== '/dashboard' && path.startsWith(item.href));
          const Icon = item.icon;
          return <Link key={item.href} href={item.href} className={active ? 'active' : ''} aria-current={active ? 'page' : undefined} onClick={onNavigate}>
            <Icon size={20}/><span>{item.label}</span>{active && <span className="active-dot"/>}
          </Link>;
        })}
      </nav>
      <div className="side-note">Asia/Jakarta · WIB<br/>Ayam Petelur & Lele</div>
    </div>
  </>;
}

export function AppShell({ children, title }: PropsWithChildren<{ title: string }>) {
  const page = usePage<SharedProps>();
  const { auth, flash, errors } = page.props;
  const path = pathFromUrl(page.url);
  const allowedGroups = useMemo(() => navigationGroups.filter(group => !group.superOnly || auth.user?.is_superadmin), [auth.user?.is_superadmin]);
  const routeGroup = groupForPath(path);
  const [selectedGroup, setSelectedGroup] = useState(() => routeGroup);
  const [mobileNav, setMobileNav] = useState(false);
  const mobileMenuButton = useRef<HTMLButtonElement>(null);
  const [theme, setTheme] = useState<'light' | 'dark'>(() => (document.documentElement.dataset.theme as 'light' | 'dark') || 'light');
  const selected = allowedGroups.find(group => group.key === selectedGroup) ?? allowedGroups[0];

  useEffect(() => setSelectedGroup(routeGroup), [routeGroup]);
  useEffect(() => { if (flash.success) toast.success(flash.success); }, [flash.success]);
  useEffect(() => { if (Object.keys(errors).length) toast.error('Periksa kembali data yang diisi.'); }, [errors]);

  const toggleTheme = () => {
    const next = theme === 'light' ? 'dark' : 'light';
    setTheme(next);
    document.documentElement.dataset.theme = next;
    document.documentElement.dataset.bsTheme = next;
    localStorage.setItem('pbb-theme', next);
  };
  const closeMobileNavigation = () => setMobileNav(false);

  return <div className="app-shell">
    <Head title={title.replace('Input Harian AYAM', 'Laporan Harian Ayam').replace('Input Harian LELE', 'Laporan Harian Lele')}/>
    <aside className="icon-rail" aria-label="Navigasi modul">
      <Link href="/dashboard" className="rail-logo" aria-label="Pondok Berkah Bersama Dashboard">P</Link>
      <GroupRail groups={allowedGroups} selected={selected.key} onSelect={setSelectedGroup}/>
    </aside>
    <aside className="context-panel"><Navigation group={selected} path={path}/></aside>
    <header className="top-header">
      <button ref={mobileMenuButton} className="icon-button mobile-menu" onClick={() => setMobileNav(true)} aria-label="Buka navigasi"><Menu/></button>
      <div className="header-title"><strong>{selected.label}</strong><small>{date(page.props.today)}</small></div>
      <div className="header-actions">
        <OverlayTrigger placement="bottom" overlay={<Tooltip>{theme === 'light' ? 'Tema gelap' : 'Tema terang'}</Tooltip>}><button className="icon-button" onClick={toggleTheme} aria-label={theme === 'light' ? 'Gunakan tema gelap' : 'Gunakan tema terang'}>{theme === 'light' ? <Moon/> : <Sun/>}</button></OverlayTrigger>
        <OverlayTrigger placement="bottom" overlay={<Tooltip>Layar penuh</Tooltip>}><button className="icon-button desktop-only" onClick={() => document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen()} aria-label="Layar penuh"><Fullscreen/></button></OverlayTrigger>
        <Dropdown align="end">
          <Dropdown.Toggle as="button" className="profile-toggle"><span className="avatar"><UserRound/></span><span><strong>{auth.user?.name}</strong><small>{auth.user?.role}</small></span><ChevronDown size={15}/></Dropdown.Toggle>
          <Dropdown.Menu>
            <Dropdown.Item as={Link} href="/password"><KeyRound size={16}/> Ubah kata sandi</Dropdown.Item>
            <Dropdown.Divider/>
            <Dropdown.Item onClick={() => router.post('/logout')}><LogOut size={16}/> Keluar</Dropdown.Item>
          </Dropdown.Menu>
        </Dropdown>
      </div>
    </header>
    <main className="content-area">{page.props.defaultLeleAdg!==undefined&&<DefaultLeleAdgCard value={String(page.props.defaultLeleAdg)}/>} {children}</main>
    <footer className="app-footer">Pondok Berkah Bersama · Farm Management Dashboard</footer>
    <Offcanvas show={mobileNav} onHide={closeMobileNavigation} onExited={() => mobileMenuButton.current?.focus()} className="mobile-navigation">
      <Offcanvas.Header closeButton><Offcanvas.Title>Navigasi</Offcanvas.Title></Offcanvas.Header>
      <Offcanvas.Body><GroupRail mobile groups={allowedGroups} selected={selected.key} onSelect={setSelectedGroup}/><Navigation group={selected} path={path} onNavigate={closeMobileNavigation}/></Offcanvas.Body>
    </Offcanvas>
    <ToastContainer position="top-right" theme={theme} newestOnTop limit={3}/>
  </div>;
}

function DefaultLeleAdgCard({value}:{value:string}){const form=useForm({value});return <Card className="mb-4"><Card.Header>Default ADG Lele untuk Grup Baru</Card.Header><Card.Body><Form className="action-row" onSubmit={e=>{e.preventDefault();form.post('/settings/lele-adg',{preserveScroll:true});}}><Form.Group><Form.Label>Gram / ekor / hari</Form.Label><LocalizedNumberInput scale={3} value={form.data.value} onChange={next=>form.setData('value',next)}/><FieldError message={form.errors.value}/></Form.Group><Button type="submit" disabled={form.processing}>Simpan Default ADG</Button></Form><small className="text-muted">Perubahan hanya menjadi default grup baru.</small></Card.Body></Card>}

export function PageHeader({ eyebrow, title, description, actions }: { eyebrow?: string; title: string; description?: string; actions?: React.ReactNode }) {
  const visibleTitle = title === 'Input Harian' && eyebrow?.includes('AYAM') ? 'Laporan Harian Ayam' : title === 'Input Harian' && eyebrow?.includes('LELE') ? 'Laporan Harian Lele' : title;
  return <div className="page-header"><div>{eyebrow && <div className="eyebrow">{eyebrow}</div>}<h1>{visibleTitle}</h1>{description && <p>{description}</p>}</div>{actions && <div className="page-actions">{actions}</div>}</div>;
}

export function EmptyState({ title = 'Belum ada data', detail }: { title?: string; detail?: string }) {
  return <div className="empty-state"><div className="empty-icon"><Search/></div><strong>{title}</strong>{detail && <span>{detail}</span>}</div>;
}
