import { execFileSync } from 'node:child_process';

export default function globalSetup() {
  const database = process.env.BROWSER_DB_DATABASE ?? 'pondok_berkah_test';
  if (!database.endsWith('_test')) throw new Error('Browser tests only run against a database ending in _test.');

  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--force'], {
    cwd: process.cwd(),
    stdio: 'inherit',
    env: {
      ...process.env,
      APP_ENV: 'testing',
      APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
      APP_URL: 'http://127.0.0.1:8010',
      BCRYPT_ROUNDS: '4',
      CACHE_STORE: 'array',
      DB_URL: '',
      DB_CONNECTION: 'pgsql',
      DB_DATABASE: database,
      QUEUE_CONNECTION: 'sync',
      SESSION_DRIVER: 'database',
      SUPERADMIN_PASSWORD: 'SuperadminFarm2026!',
      ADMIN_PASSWORD: 'AdminFarm2026!',
    },
  });
}
