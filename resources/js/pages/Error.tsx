import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ShieldAlert } from 'lucide-react';
import { Card } from 'react-bootstrap';
import { AppShell } from '../components/AppShell';
import type { SharedProps } from '../types';

const content:Record<number,{title:string;detail:string}>={403:{title:'Akses ditolak',detail:'Akun Anda tidak memiliki izin untuk membuka halaman ini.'},404:{title:'Halaman tidak ditemukan',detail:'Alamat yang dibuka tidak tersedia atau sudah dipindahkan.'},419:{title:'Sesi berakhir',detail:'Muat ulang halaman dan ulangi tindakan Anda.'},500:{title:'Terjadi kesalahan',detail:'Server tidak dapat menyelesaikan permintaan saat ini.'},503:{title:'Layanan sementara tidak tersedia',detail:'Silakan coba kembali beberapa saat lagi.'}};
export default function ErrorPage({status}:{status:number}){const{auth}=usePage<SharedProps>().props;const item=content[status]??content[500];const body=<div className="d-grid place-items-center py-5"><Card style={{maxWidth:560,margin:'8vh auto'}}><Card.Body className="text-center p-5"><div className="empty-icon mx-auto mb-3"><ShieldAlert/></div><div className="eyebrow">Error {status}</div><h1 className="h3">{item.title}</h1><p className="text-muted my-3">{item.detail}</p><Link className="btn btn-primary" href={auth.user?'/dashboard':'/login'}><ArrowLeft/> Kembali</Link></Card.Body></Card></div>;return auth.user?<AppShell title={item.title}>{body}</AppShell>:<><Head title={item.title}/>{body}</>}
