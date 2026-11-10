import { describe, expect, it, vi } from 'vitest';
import { confirmAction, promptAction } from './Feedback';

const mocks = vi.hoisted(() => ({ fire: vi.fn(), visit: vi.fn() }));
vi.mock('sweetalert2', () => ({ default: { fire: mocks.fire, showValidationMessage: vi.fn() } }));
vi.mock('@inertiajs/react', () => ({ router: { visit: mocks.visit } }));

describe('workflow confirmation dialogs', () => {
  it('sends a confirmed action through Inertia without bypassing the backend', async () => {
    mocks.fire.mockResolvedValueOnce({ isConfirmed: true });
    await confirmAction({ title: 'Setujui?', url: '/expenses/1/decision', data: { decision: 'APPROVED' } });
    expect(mocks.visit).toHaveBeenCalledWith('/expenses/1/decision', expect.objectContaining({ method: 'post', data: { decision: 'APPROVED' } }));
  });

  it('collects a required workflow date', async () => {
    mocks.fire.mockResolvedValueOnce({ isConfirmed: true, value: { value: '2026-10-10' } });
    await promptAction({ title: 'Bayar?', label: 'Tanggal', input: 'date', url: '/expenses/1/pay', field: 'paid_date' });
    expect(mocks.visit).toHaveBeenCalledWith('/expenses/1/pay', expect.objectContaining({ data: { paid_date: '2026-10-10' } }));
  });

  it('uses a caller-provided default workflow date', async () => {
    mocks.fire.mockResolvedValueOnce({ isConfirmed: false });
    await promptAction({ title: 'Bayar?', label: 'Tanggal', input: 'date', defaultValue: '2026-09-29', url: '/revenues/1/pay', field: 'paid_date' });
    expect(mocks.fire).toHaveBeenCalledWith(expect.objectContaining({ inputValue: '2026-09-29' }));
  });

  it('shows a free-text reason field when the workflow requires one', async () => {
    mocks.fire.mockResolvedValueOnce({ isConfirmed: false });
    await promptAction({ title: 'Tutup kolam?', label: 'Tanggal penutupan', input: 'date', reason: true, url: '/lele/groups/1/close', field: 'closed_at' });
    expect(mocks.fire).toHaveBeenCalledWith(expect.objectContaining({ html: expect.stringContaining('swal-reason') }));
  });
});
