import { router } from '@inertiajs/react';
import Swal from 'sweetalert2';

type Method = 'post' | 'patch' | 'delete';

const jakartaToday = () => Object.fromEntries(
  new Intl.DateTimeFormat('en', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' })
    .formatToParts(new Date()).map(part => [part.type, part.value]),
) as Record<string, string>;

export async function confirmAction({ title, text, confirmText, url, method = 'post', data = {}, onError }: { title: string; text?: string; confirmText?: string; url: string; method?: Method; data?: Record<string, unknown>; onError?:(errors:Record<string,string>)=>void }) {
  const dark = document.documentElement.dataset.theme === 'dark';
  const result = await Swal.fire({ title, text, icon: 'warning', showCancelButton: true, confirmButtonText: confirmText ?? 'Ya, lanjutkan', cancelButtonText: 'Batal', reverseButtons: true, background: dark ? '#1e2746' : '#fff', color: dark ? '#f2f4fb' : '#181c32', confirmButtonColor: '#3a7afe' });
  if (result.isConfirmed) router.visit(url, { method, data: data as never, preserveScroll: true, onError: errors=>onError?.(errors as Record<string,string>) });
}

export async function promptAction({ title, label, input = 'text', defaultValue, url, field, method = 'post', min, reason, extraData = {} }: { title: string; label: string; input?: 'text' | 'date'; defaultValue?: string; url: string; field: string; method?: Method; min?: string; reason?: boolean; extraData?: Record<string, unknown> }) {
  const dark = document.documentElement.dataset.theme === 'dark';
  const html = `${reason ? '<textarea id="swal-reason" class="swal2-textarea" placeholder="Alasan wajib"></textarea>' : ''}`;
  const parts = jakartaToday();
  const result = await Swal.fire({ title, input, inputLabel: label, inputValue: defaultValue ?? (input === 'date' ? `${parts.year}-${parts.month}-${parts.day}` : ''), inputAttributes: min ? { min } : {}, html, showCancelButton: true, confirmButtonText: 'Simpan', cancelButtonText: 'Batal', background: dark ? '#1e2746' : '#fff', color: dark ? '#f2f4fb' : '#181c32', confirmButtonColor: '#3a7afe', preConfirm: value => { const reasonValue = (document.querySelector('#swal-reason') as HTMLTextAreaElement | null)?.value; if (!value || (reason && !reasonValue)) { Swal.showValidationMessage('Semua data wajib diisi.'); return false; } return { value, reason: reasonValue }; } });
  if (result.isConfirmed && result.value) router.visit(url, { method, data: { ...extraData, [field]: result.value.value, ...(reason ? { reason: result.value.reason } : {}) } as never, preserveScroll: true });
}
