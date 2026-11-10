import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AppShell } from './AppShell';

const mocks = vi.hoisted(() => ({
  page: {
    url: '/ayam/groups',
    props: {
      auth: { user: { id: 2, name: 'Admin', email: 'admin@example.test', role: 'ADMIN', is_superadmin: false, must_change_password: false } },
      flash: {},
      errors: {},
      today: '2026-10-11',
    },
  },
  post: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, ...props }: React.ComponentProps<'a'>) => <a {...props}>{children}</a>,
  router: { post: mocks.post },
  usePage: () => mocks.page,
}));

describe('grouped application navigation', () => {
  beforeEach(() => {
    mocks.page.url = '/ayam/groups';
    mocks.page.props.auth.user.role = 'ADMIN';
    mocks.page.props.auth.user.is_superadmin = false;
    document.documentElement.dataset.theme = 'light';
  });

  it('derives the selected group from the route and switches groups without navigating', () => {
    render(<AppShell title="Grup Ayam"><p>Halaman farm</p></AppShell>);
    expect(screen.getByRole('button', { name: 'Menu Ayam' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('link', { name: /Grup Ayam/ })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: /Telur/ })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Pakan Ayam/ })).toBeInTheDocument();
    expect(screen.queryByPlaceholderText(/Cari menu/i)).not.toBeInTheDocument();
    expect(screen.getByText('Pondok Berkah Bersama')).toBeInTheDocument();

    const finance = screen.getByRole('button', { name: 'Menu Keuangan' });
    finance.focus();
    fireEvent.click(finance);

    expect(finance).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('link', { name: /Operational Cashflow/ })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Pelanggan/ })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Report' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Export Report' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Grup Ayam/ })).not.toBeInTheDocument();
    expect(mocks.page.url).toBe('/ayam/groups');
  });

  it('resynchronizes the selected group after route changes', async () => {
    const view = render(<AppShell title="Farm"><p>Halaman farm</p></AppShell>);
    mocks.page.url = '/revenues?payment_status=PAID';
    view.rerender(<AppShell title="Revenues"><p>Halaman farm</p></AppShell>);

    await waitFor(() => expect(screen.getByRole('button', { name: 'Menu Keuangan' })).toHaveAttribute('aria-pressed', 'true'));
    expect(screen.getByRole('link', { name: /Revenues/ })).toHaveAttribute('aria-current', 'page');
  });

  it('keeps Settings hidden from Admin and visible to Superadmin', () => {
    const view = render(<AppShell title="Farm"><p>Halaman farm</p></AppShell>);
    expect(screen.queryByRole('button', { name: 'Menu Pengaturan' })).not.toBeInTheDocument();

    mocks.page.props.auth.user.role = 'SUPERADMIN';
    mocks.page.props.auth.user.is_superadmin = true;
    view.rerender(<AppShell title="Farm"><p>Halaman farm</p></AppShell>);
    expect(screen.getByRole('button', { name: 'Menu Pengaturan' })).toBeInTheDocument();
  });
});
