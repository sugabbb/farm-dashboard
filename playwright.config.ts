import { defineConfig } from '@playwright/test';

const browserEnvironment = {
  APP_ENV: 'testing',
  APP_DEBUG: 'true',
  APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
  APP_URL: 'http://127.0.0.1:8010',
  BCRYPT_ROUNDS: '4',
  CACHE_STORE: 'array',
  DB_URL: '',
  DB_CONNECTION: 'pgsql',
  DB_DATABASE: process.env.BROWSER_DB_DATABASE ?? 'pondok_berkah_test',
  QUEUE_CONNECTION: 'sync',
  SESSION_DRIVER: 'database',
  SUPERADMIN_PASSWORD: 'SuperadminFarm2026!',
  ADMIN_PASSWORD: 'AdminFarm2026!',
};

export default defineConfig({
  testDir: './tests/browser',
  globalSetup: './tests/browser/global-setup.mjs',
  fullyParallel: false,
  workers: 1,
  timeout: 90_000,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: browserEnvironment.APP_URL,
    channel: 'chrome',
    headless: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'php artisan serve --host=127.0.0.1 --port=8010',
    url: browserEnvironment.APP_URL,
    reuseExistingServer: false,
    timeout: 45_000,
    env: browserEnvironment,
  },
});
