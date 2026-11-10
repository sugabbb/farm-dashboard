import { useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Alert, Button, Card, Form, Spinner } from 'react-bootstrap';
import { AppShell, PageHeader } from '../../components/AppShell';
import { FieldError } from '../../components/FormControls';
import type { SharedProps } from '../../types';

export default function Password() {
  const { auth } = usePage<SharedProps>().props;
  const form = useForm({ current_password: '', password: '', password_confirmation: '' });
  const submit = (event: FormEvent) => { event.preventDefault(); form.post('/password', { onSuccess: () => form.reset() }); };
  return <AppShell title="Ubah Kata Sandi"><PageHeader eyebrow="Keamanan" title="Ubah Kata Sandi" description="Gunakan minimal 12 karakter yang mengandung huruf dan angka."/><Card style={{ maxWidth: 650 }}><Card.Body>{auth.user?.must_change_password && <Alert variant="warning">Ganti kata sandi sementara sebelum mengakses data farm.</Alert>}<Form onSubmit={submit}>{([
    ['current_password', 'Kata sandi saat ini', 'current-password'], ['password', 'Kata sandi baru', 'new-password'], ['password_confirmation', 'Ulangi kata sandi baru', 'new-password'],
  ] as const).map(([field, label, autocomplete]) => <Form.Group className="mb-3" key={field}><Form.Label>{label}</Form.Label><Form.Control type="password" autoComplete={autocomplete} value={form.data[field]} onChange={e => form.setData(field, e.target.value)} isInvalid={Boolean(form.errors[field])}/><FieldError message={form.errors[field]}/></Form.Group>)}<Button type="submit" disabled={form.processing}>{form.processing && <Spinner size="sm"/>} Simpan Kata Sandi</Button></Form></Card.Body></Card></AppShell>;
}
