import { expect, Locator, Page, test } from '@playwright/test';

const adminPassword = 'AdminFarm2026!';
const superadminPassword = 'SuperadminFarm2026!';
const changedAdminPassword = 'AdminBrowser2026!';
const changedSuperadminPassword = 'SuperBrowser2026!';

function recordContainer(text: Locator) {
  return text.locator('xpath=ancestor::*[self::tr or self::article][1]');
}

async function loginAndChangePassword(page: Page, email: string, currentPassword: string, newPassword: string) {
  await page.goto('/login');
  await page.locator('.login-form input[type="email"]').fill(email);
  await page.locator('.login-form input[type="password"]').fill(currentPassword);
  await page.getByRole('button', { name: 'Masuk' }).click();
  await expect(page).toHaveURL(/\/password$/);
  const passwords = page.locator('main input[type="password"]');
  await passwords.nth(0).fill(currentPassword);
  await passwords.nth(1).fill(newPassword);
  await passwords.nth(2).fill(newPassword);
  await page.getByRole('button', { name: /Simpan Kata Sandi/ }).click();
  await expect(page).toHaveURL(/\/dashboard$/);
}

async function logout(page: Page) {
  await page.locator('.profile-toggle').click();
  await page.getByText('Keluar', { exact: true }).click();
  await expect(page).toHaveURL(/\/login$/);
}

test.describe.serial('farm dashboard browser workflows', () => {
  test('Admin uses the responsive shell and creates finance transactions', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Selamat datang' })).toBeVisible();
    await expect(page.locator('.login-visual')).toBeHidden();

    await loginAndChangePassword(page, 'admin@pondokberkah.test', adminPassword, changedAdminPassword);
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Operational Cashflow', exact: true })).toBeVisible();
    await expect(page.getByText('Revenue & Expenses · Periode Terpilih', { exact: true })).toBeVisible();
    await expect(page.getByText('Panen Telur Harian · Periode Terpilih', { exact: true })).toBeVisible();
    await page.evaluate(async () => { await document.fonts.ready; });
    await expect.poll(() => page.evaluate(() => getComputedStyle(document.body).fontFamily)).toContain('Roboto');
    await expect.poll(() => page.evaluate(() => document.fonts.check('400 14px Roboto'))).toBe(true);
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await page.getByRole('button', { name: 'Buka navigasi' }).click();
    const mobileNavigation = page.getByRole('dialog');
    await expect(mobileNavigation).toBeVisible();
    await expect(mobileNavigation.getByRole('button', { name: 'Menu Pengaturan' })).toHaveCount(0);
    const ayamGroup = mobileNavigation.getByRole('button', { name: 'Menu Ayam' });
    await ayamGroup.focus();
    await ayamGroup.press('Enter');
    await expect(ayamGroup).toHaveAttribute('aria-pressed', 'true');
    await expect(page).toHaveURL(/\/dashboard$/);
    await mobileNavigation.getByRole('link', { name: /Laporan Harian Ayam/ }).click();
    await expect(page).toHaveURL(/\/ayam\/daily-entry$/);
    await expect(mobileNavigation).toBeHidden();
    await page.goBack();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.getByRole('button', { name: 'Buka navigasi' }).click();
    await expect(page.getByRole('dialog').getByRole('button', { name: 'Menu Dashboard' })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('dialog').getByRole('button', { name: 'Close' }).click();

    await page.setViewportSize({ width: 768, height: 900 });
    await page.getByRole('button', { name: 'Gunakan tema gelap' }).click();
    await expect.poll(() => page.evaluate(() => document.documentElement.dataset.theme)).toBe('dark');
    await expect.poll(() => page.evaluate(() => localStorage.getItem('pbb-theme'))).toBe('dark');

    await page.goto('/settings');
    await expect(page.getByRole('heading', { name: 'Akses ditolak' })).toBeVisible();

    await page.goto('/operational-cashflow');
    await page.getByRole('button', { name: 'Buka navigasi' }).click();
    await expect(page.getByRole('dialog').getByRole('button', { name: 'Menu Keuangan' })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('dialog').getByRole('button', { name: 'Close' }).click();
    await page.getByRole('button', { name: 'Export Excel', exact: true }).click();
    const operationalExport = page.getByRole('dialog');
    await expect(operationalExport.getByText('Export Operational Cashflow')).toBeVisible();
    const operationalDownload = operationalExport.getByRole('button', { name: /Download Excel/ });
    await expect(operationalDownload).toHaveAttribute('href', /\/operational-cashflow\/export\?from=\d{4}-\d{2}-01&to=\d{4}-\d{2}-\d{2}/);
    await expect(operationalDownload).not.toHaveAttribute('href', /cashflow_type|approval_status|payment_status/);
    await operationalExport.getByRole('button', { name: 'Close' }).click();
    await page.getByRole('button', { name: 'Pengeluaran', exact: true }).click();
    const expenseDrawer = page.locator('.form-drawer');
    await expect(expenseDrawer.getByText('Tambah Pengeluaran')).toBeVisible();
    await expenseDrawer.locator('select').first().selectOption('OTHER');
    await expenseDrawer.locator('input[inputmode="numeric"]').first().fill('125,000');
    await expenseDrawer.locator('textarea').first().fill('Peralatan browser test');
    await expenseDrawer.getByRole('button', { name: 'Simpan & Ajukan' }).click();
    await expect(expenseDrawer).toBeHidden();
    await expect(page.getByText('Peralatan browser test')).toBeVisible();
    await expect(page.locator('.status-badge').getByText('SUBMITTED', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Top Up', exact: true }).click();
    const topupDrawer = page.locator('.form-drawer');
    await topupDrawer.locator('input[inputmode="numeric"]').first().fill('250,000');
    await topupDrawer.locator('textarea').first().fill('Top Up browser test');
    await topupDrawer.getByRole('button', { name: 'Simpan Top Up & Tandai Paid' }).click();
    await expect(topupDrawer).toBeHidden();
    for (const width of [320, 390, 575, 576, 768, 991]) {
      await page.setViewportSize({ width, height: 900 });
      await expect(page.locator('.mobile-data-card').first()).toBeVisible();
      await expect(page.locator('table.data-table')).toHaveCount(0);
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    }
    await page.setViewportSize({ width: 992, height: 900 });
    await expect(page.locator('table.data-table')).toBeVisible();
    await expect(page.locator('.mobile-data-card')).toHaveCount(0);
    await page.setViewportSize({ width: 768, height: 900 });
    const topupRow = recordContainer(page.getByText('Top Up browser test', { exact: true }));
    await expect(topupRow.locator('.status-badge').getByText('PAID', { exact: true })).toBeVisible();
    await topupRow.getByRole('button', { name: /Aksi Operational Cashflow/ }).click();
    await page.getByText('Lihat rincian', { exact: true }).click();
    await expect(page.getByText('Audit Log', { exact: true })).toBeVisible();
    await expect(page.getByText('Operational Cashflow ditandai Paid', { exact: true })).toBeVisible();

    await page.goto('/revenues');
    await page.getByRole('button', { name: 'Export Excel', exact: true }).click();
    const revenueExport = page.getByRole('dialog');
    await expect(revenueExport.getByText('Export Revenues')).toBeVisible();
    const revenueDownload = revenueExport.getByRole('button', { name: /Download Excel/ });
    await expect(revenueDownload).toHaveAttribute('href', /\/revenues\/export\?from=\d{4}-\d{2}-01&to=\d{4}-\d{2}-\d{2}/);
    await expect(revenueDownload).not.toHaveAttribute('href', /revenue_type|approval_status|payment_status|customer_id/);
    await revenueExport.getByRole('button', { name: 'Close' }).click();
    await page.getByRole('button', { name: 'Revenue', exact: true }).click();
    const revenueDrawer = page.locator('.form-drawer');
    await revenueDrawer.locator('select').first().selectOption('OTHER');
    await revenueDrawer.locator('input[inputmode="numeric"]').first().fill('500,000');
    await revenueDrawer.locator('textarea').first().fill('Pemasukan browser test');
    await revenueDrawer.getByRole('button', { name: 'Simpan Revenue' }).click();
    await expect(revenueDrawer).toBeHidden();
    await page.getByRole('button', { name: /Aksi Revenues/ }).first().click();
    await page.getByText('Catat Pembayaran', { exact: true }).click();
    await page.getByRole('button', { name: 'Simpan Pembayaran', exact: true }).click();

    await page.getByRole('button', { name: 'Transfer ke Rekening Utama', exact: true }).click();
    const transferDrawer = page.locator('.form-drawer');
    await transferDrawer.locator('input[inputmode="numeric"]').first().fill('100,000');
    await transferDrawer.locator('textarea').first().fill('Setoran browser test');
    await transferDrawer.getByRole('button', { name: 'Simpan & Ajukan' }).click();
    await expect(transferDrawer).toBeHidden();
    await expect(page.getByText('Setoran browser test', { exact: true })).toBeVisible();
    await expect(page.locator('.status-badge').getByText('PAID', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Revenue', exact: true }).click();
    await revenueDrawer.locator('select').first().selectOption('FISH_SALE');
    await revenueDrawer.getByRole('button', { name: /Tambah pelanggan baru/ }).click();
    await revenueDrawer.locator('.customer-picker input[type="text"]').nth(0).fill('Agen Browser');
    await revenueDrawer.locator('.customer-picker input[type="text"]').nth(1).fill('0812999000');
    await revenueDrawer.locator('input[inputmode="decimal"]').nth(0).fill('12.5');
    await revenueDrawer.locator('input[inputmode="decimal"]').nth(1).fill('22,000');
    await revenueDrawer.getByRole('button', { name: 'Simpan Revenue' }).click();
    await expect(revenueDrawer).toBeHidden();
    await expect(page.getByText('Agen Browser').first()).toBeVisible();
    await page.getByRole('button', { name: /Aksi Revenues/ }).first().click();
    await page.getByText('Catat Pembayaran', { exact: true }).click();
    await page.getByRole('button', { name: 'Simpan Pembayaran', exact: true }).click();

    await page.getByRole('button', { name: 'Revenue', exact: true }).click();
    await revenueDrawer.locator('input[inputmode="numeric"]').first().fill('10');
    await revenueDrawer.locator('input[inputmode="decimal"]').nth(0).fill('1.5');
    await revenueDrawer.locator('input[inputmode="decimal"]').nth(1).fill('20,000');
    await revenueDrawer.getByRole('button', { name: 'Simpan Revenue' }).click();
    await expect(revenueDrawer).toBeVisible();
    await expect(revenueDrawer.locator('input[inputmode="decimal"]').nth(0)).toHaveValue('1.5');
    const eggCustomer = revenueDrawer.getByRole('combobox', { name: 'Pelanggan' });
    await eggCustomer.press('ArrowDown'); await eggCustomer.press('Enter');
    await revenueDrawer.getByRole('button', { name: 'Simpan Revenue' }).click();
    await expect(revenueDrawer).toBeHidden();

    await page.goto('/reports');
    await expect(page.locator('.report-chart-card')).toHaveCount(4);
    const selectedReportTypes=page.locator('.farm-select__multi-value__remove');
    while(await selectedReportTypes.count()) await selectedReportTypes.first().click();
    await page.getByRole('button', { name: 'Terapkan', exact: true }).click();
    await expect(page.getByText('Pilih minimal satu jenis Revenue atau Operational Cashflow untuk menampilkan chart.')).toBeVisible();

    await page.goto('/lele/groups');
    await page.getByRole('button', { name: /Tambah Grup/ }).click();
    const groupDrawer = page.locator('.form-drawer');
    await groupDrawer.locator('input[type="text"]').nth(0).fill('Lele Browser');
    await groupDrawer.locator('input[type="text"]').nth(1).fill('Kolam Browser');
    await groupDrawer.locator('input[inputmode="numeric"]').fill('1,000');
    await groupDrawer.getByRole('button', { name: 'Simpan Grup' }).click();
    await expect(groupDrawer).toBeHidden();

    await page.goto('/lele/biomass');
    await page.getByRole('button', { name: 'Sampling', exact: true }).click();
    const samplingDrawer = page.locator('.form-drawer');
    const groupSelect = samplingDrawer.getByRole('combobox', { name: 'Grup Lele' });
    await groupSelect.press('ArrowDown'); await groupSelect.press('Enter');
    const weights = samplingDrawer.locator('input[inputmode="decimal"]');
    await weights.nth(0).fill('1.20'); await weights.nth(1).fill('1.35'); await weights.nth(2).fill('1.25');
    await samplingDrawer.getByRole('button', { name: 'Simpan Sampling' }).click();
    await expect(samplingDrawer).toBeHidden();
    await expect(page.getByText(/126.67 g\/ekor/).first()).toBeVisible();
    await expect(page.getByText(/Est Panen Terkini/).first()).toHaveClass(/status-success/);
    await expect(page.getByText(/Target Panen Min/).first()).toHaveClass(/status-primary/);

    await logout(page);
  });

  test('Superadmin approves, pays, and audits corrections at desktop width', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await loginAndChangePassword(page, 'superadmin@pondokberkah.test', superadminPassword, changedSuperadminPassword);
    await page.evaluate(async () => { await document.fonts.ready; });
    await expect(page.getByRole('button', { name: 'Menu Pengaturan' })).toBeVisible();

    const railBox = await page.locator('.icon-rail').boundingBox();
    const panelBox = await page.locator('.context-panel').boundingBox();
    const headerBox = await page.locator('.top-header').boundingBox();
    expect(railBox?.width).toBe(80);
    expect(panelBox?.width).toBe(255);
    expect(panelBox?.x).toBe(80);
    expect(headerBox?.height).toBe(80);
    expect(headerBox?.x).toBe(335);
    await expect(page.locator('.page-actions .btn-primary').first()).toHaveCSS('font-size', '14px');
    await expect(page.locator('.page-actions .btn-primary').first()).toHaveCSS('font-weight', '500');
    await expect(page.locator('.page-actions .btn-primary').first()).toHaveCSS('padding-left', '20px');
    await expect(page.locator('.filter-card .form-select').first()).toHaveCSS('height', '40px');
    await expect(page.locator('.metric-card .card-body').first()).toHaveCSS('padding-top', '30px');

    const leleGroup = page.getByRole('button', { name: 'Menu Lele' });
    await leleGroup.focus();
    await leleGroup.press('Enter');
    await expect(leleGroup).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('link', { name: /Laporan Harian Lele/ })).toBeVisible();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.getByRole('button', { name: 'Menu Dashboard' }).click();

    await page.setViewportSize({ width: 991, height: 900 });
    await expect(page.locator('.context-panel')).toBeHidden();
    await expect(page.getByRole('button', { name: 'Buka navigasi' })).toBeVisible();
    await page.setViewportSize({ width: 992, height: 900 });
    await expect(page.locator('.context-panel')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Buka navigasi' })).toBeHidden();
    await page.setViewportSize({ width: 1440, height: 1000 });

    await page.goto('/operational-cashflow');
    await expect(page.getByRole('button', { name: 'Menu Keuangan' })).toHaveAttribute('aria-pressed', 'true');
    const submittedExpenseRow = recordContainer(page.getByText('Peralatan browser test', { exact: true }));
    await submittedExpenseRow.getByRole('button', { name: /Aksi Operational Cashflow/ }).click();
    await page.getByText('Approve', { exact: true }).click();
    await page.getByRole('button', { name: 'Ya, lanjutkan' }).click();
    await expect(page.locator('.status-badge').getByText('APPROVED', { exact: true })).toBeVisible();
    await submittedExpenseRow.getByRole('button', { name: /Aksi Operational Cashflow/ }).click();
    await page.getByText('Catat Pembayaran', { exact: true }).click();
    const partialPayment=page.getByRole('dialog');
    await partialPayment.getByLabel('Partial').check();
    await partialPayment.locator('input[inputmode="numeric"]').fill('50,000');
    await partialPayment.getByRole('button', { name: 'Simpan Pembayaran', exact: true }).click();
    await expect(submittedExpenseRow.locator('.status-badge').getByText('PARTIALLY PAID', { exact: true })).toBeVisible();
    await submittedExpenseRow.getByRole('button', { name: /Aksi Operational Cashflow/ }).click();
    await page.getByText('Catat Pembayaran', { exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Simpan Pembayaran', exact: true }).click();
    await expect(page.locator('.status-badge').getByText('PAID', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: /Aksi Operational Cashflow/ }).first().click();
    await page.getByText('Koreksi diaudit', { exact: true }).click();
    const expenseDrawer = page.locator('.form-drawer');
    await expenseDrawer.locator('input[inputmode="numeric"]').first().fill('130,000');
    await expenseDrawer.locator('textarea').last().fill('Koreksi nominal browser test');
    await expenseDrawer.getByRole('button', { name: 'Simpan Perubahan' }).click();
    await expect(expenseDrawer).toBeHidden();
    await expect(page.getByText('Rp 130,000')).toBeVisible();

    await page.goto('/revenues');
    const transferRow = recordContainer(page.getByText('Setoran browser test', { exact: true }));
    await transferRow.getByRole('button', { name: /Aksi Revenues/ }).click();
    await page.getByText('Approve & Paid', { exact: true }).click();
    await page.getByRole('button', { name: 'Setujui & Paid', exact: true }).click();
    await expect(transferRow.locator('.status-badge').getByText('APPROVED', { exact: true })).toBeVisible();
    await expect(transferRow.locator('.status-badge').getByText('PAID', { exact: true })).toBeVisible();
    await transferRow.getByRole('button', { name: /Aksi Revenues/ }).click();
    await page.getByText('Lihat rincian', { exact: true }).click();
    await expect(page.getByText('Transfer disetujui', { exact: true })).toBeVisible();
    await expect(page.getByText('Revenue ditandai Paid', { exact: true })).toBeVisible();
    const otherRevenueRow = recordContainer(page.getByText('Pemasukan Lain-lain', { exact: true }).first());
    await otherRevenueRow.getByRole('button', { name: /Aksi Revenues/ }).click();
    await page.getByText('Koreksi diaudit', { exact: true }).click();
    const revenueDrawer = page.locator('.form-drawer');
    await revenueDrawer.locator('input[inputmode="numeric"]').first().fill('550,000');
    await revenueDrawer.locator('textarea').last().fill('Koreksi pemasukan browser test');
    await revenueDrawer.getByRole('button', { name: 'Simpan Perubahan' }).click();
    await expect(revenueDrawer).toBeHidden();
    await expect(page.getByText('Rp 550,000')).toBeVisible();
  });
});
