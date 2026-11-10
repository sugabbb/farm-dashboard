# Pondok Berkah Bersama — Farm Management Dashboard

Laravel 12 / PostgreSQL implementation of the Pondok Berkah Bersama farm dashboard. The application tracks Ayam, Lele, ABK, stock, population, **Operational Cashflow**, and **Revenues**. Operational and revenue cash are independent ledgers; money from a paid sale is not available for operational spending until users separately record a transfer to the external main account and a top-up into Operational Cashflow.

The interface is an Inertia + React + TypeScript application served by Laravel. It uses React Bootstrap, TanStack Table, React Select, React Datepicker, SweetAlert2, React Toastify, Recharts, Lucide icons, and locally bundled Roboto. A dedicated `/api/v1` JSON API is available for the separate ABK frontend; there is no React Router, Redux store, Node production server, or external font request.

## Requirements and local installation

- PHP 8.2–8.5 with BCMath and PostgreSQL extensions
- PostgreSQL
- Composer 2
- Node 20.19+ and pnpm 10

```sh
composer install
pnpm install --frozen-lockfile
cp .env.example .env
php artisan key:generate
```

Configure PostgreSQL and Google Cloud Storage in `.env`, then initialize a new local database:

```sh
php artisan migrate:fresh --seed
pnpm build
php artisan serve
```

During interface development, run `pnpm dev` in a separate terminal. Production serves the static Vite build from `public/build` through the Laravel application.

Existing installations use the transactional migrations in `database/migrations`. Back up PostgreSQL before deployment and deploy the schema and application in the same maintenance window. After the new growth migration, inspect existing data first and then persist the derived Lele profiles:

```sh
php artisan biomass:backfill-growth
php artisan biomass:backfill-growth --apply
```

The dry run lists existing Lele groups whose initial average weight must be supplied manually. Sampling-derived ADG and chart points are computed when records are written, not while rendering a page. The scheduler refreshes active charts daily; a closed group's `closed_at` is its harvest date and exact chart end date.

The clean schema contains 35 tables. Financial openings are keyed by `OPERATIONAL` or `REVENUE`; Operational Cashflow uses its own detail tables, while Revenues has sale and main-account-transfer details. Sessions remain in PostgreSQL so Superadmin account deactivation can invalidate active sessions. ABK submissions remain staged until dashboard approval, and Lele growth chart points are persisted for fast reads.

Operational Cashflow and Revenue proofs use Google Cloud Storage. Existing proof object paths remain valid through the financial-ledger migration. Uploads accept one optional JPG/PNG file up to 5 MB.

## Production deployment

This MVP targets one Docker application server. The PostgreSQL database is hosted and managed outside this deployment; Docker Compose and GitLab CI never create databases, run migrations, seed data, or manage database backups.

The deployment files live under `.docker/`. Before using them, replace every `REPLACE_ME` value in `.docker/.env.development`, `.docker/.env.production`, and `.gitlab-ci.yml`. The production server must already have:

- Docker Engine and the Docker Compose plugin;
- a privileged GitLab Docker runner capable of starting Docker-in-Docker for image builds;
- an external Docker network named `drml-dev`;
- the GCS service-account JSON at `/opt/pondok-berkah-dashboard/.docker/gcs-service-account.json`;
- access to the externally managed PostgreSQL endpoint; and
- a reverse proxy targeting `farm-dashboard:80` or `127.0.0.1:9000`.

Keep the working credential file at `.docker/gcs-service-account.json`; it is intentionally ignored by Git and excluded from Docker build contexts. Upload it to the same `.docker` location under `/opt/pondok-berkah-dashboard` on the production server before the first deployment:

```sh
ssh USER@SERVER 'mkdir -p /opt/pondok-berkah-dashboard/.docker && chmod 700 /opt/pondok-berkah-dashboard/.docker'
scp .docker/gcs-service-account.json USER@SERVER:/opt/pondok-berkah-dashboard/.docker/gcs-service-account.json
ssh USER@SERVER 'chmod 644 /opt/pondok-berkah-dashboard/.docker/gcs-service-account.json'
```

The pipeline does not copy this credential. It checks that the server-side file is readable and mounts it read-only inside the dashboard container at `/var/run/secrets/gcs-service-account.json`.

Run the production-parity development container with:

```sh
docker compose \
  --env-file .docker/.env.development \
  --file .docker/docker-compose.dev.yml \
  up --build --wait
```

Production images are deployed only by protected `vX.Y.Z` Git tags. Protected `main` builds publish the moving `latest` image without deploying it. Database schema compatibility must be handled separately before a release tag is created.

Configure these GitLab CI/CD variables:

| Variable | Configuration |
|---|---|
| `GCP_ARTIFACT_KEY_BASE64` | Protected, masked/hidden, variable expansion disabled |
| `SSH_PRIVATE_KEY_BASE64` | Protected, masked/hidden, production-scoped, variable expansion disabled |
| `SSH_USERNAME` | Protected and production-scoped |
| `SSH_IP` | Protected and production-scoped |

The Artifact Registry service account requires repository-level push, pull, list, and delete permissions. The deploy and cleanup jobs validate the SSH server fingerprint configured in `.gitlab-ci.yml`. Server cleanup runs `docker system prune --all --force` without `--volumes`, so named volumes are preserved.

The file cache lives under `storage/framework/cache`; do not configure a queue worker for this release. Compose starts a separate Laravel scheduler container, which runs the persisted Lele growth refresh at 00:10 Asia/Jakarta. Confirm GCS credentials and public bucket access before allowing finance users to upload proofs.

## ABK integration API

The external frontend must call its own backend proxy. That proxy adds the static `X-PBB-API-Key`; the browser must never receive this key. Each request also uses HTTP Basic authentication with the employee's API username and six-digit PIN. Configure `ABK_API_KEY` in every environment, and optionally set `ABK_API_PREVIOUS_KEY` during key rotation.

Public interactive API documentation is served at `/docs/api`, with the OpenAPI document at `/docs/api.json`. The API includes:

- current ABK identity/capabilities and scoped group, feed-type, and customer references;
- canonical Ayam/Lele daily-report lists and detail endpoints;
- draft creation, draft editing, and post-approval revisions for daily reports;
- canonical egg-sale lists/details and the equivalent staged create/edit/revision endpoints.

ABK types are `AYAM`, `LELE`, `BOTH`, and `NO_SYSTEM_ACCESS`. Credentials and scope are managed on the employee page. Every write is staged in `abk_submissions`; Admin or Superadmin must approve it in the existing daily-report or Revenues page before it affects stock, population, or finance. Editing an approved record creates another draft. Direct-transfer egg sales remain `SUBMITTED` after ABK review and still require the normal Superadmin transfer approval.

Example proxy request:

```sh
curl --user 'abk.ayam:123456' \
  --header 'X-PBB-API-Key: replace-with-secret' \
  --header 'Accept: application/json' \
  https://dashboard.example.com/api/v1/me
```

## Initial users

Local/testing defaults are:

| Role | Email | Temporary password |
|---|---|---|
| Superadmin | `superadmin@pondokberkah.test` | `SuperadminFarm2026!` |
| Admin | `admin@pondokberkah.test` | `AdminFarm2026!` |

Production seeding requires `SUPERADMIN_PASSWORD` and `ADMIN_PASSWORD`. Email addresses can be overridden with `SUPERADMIN_EMAIL` and `ADMIN_EMAIL`. Temporary passwords must be changed on first login.

## MVP workflow

1. Superadmin configures feed types, separate Operational and Revenue opening balances, feed stock, egg stock, and user access in **Pengaturan**.
2. Admin creates active Ayam and Lele groups. Codes are generated from the group and kandang/kolam names and become immutable after creation.
3. Admin records daily mortality, shift-based feed usage (Ayam: Pagi/Siang; Lele: Pagi/Siang/Sore), egg harvest, and notes. A group/date can only be created once; corrections use Edit.
4. Superadmin records inventory or population adjustments only when a physical count differs, always with an effective date and reason.
5. **Operational Cashflow** contains operational expenses and **Top Up dari Rekening Utama**. Top-ups need no approval. Expenses follow Draft → Submitted → Approved/Rejected → Paid.
6. **Revenues** contains egg/fish/other revenue and **Transfer ke Rekening Utama**. Sales need no approval; transfers use the expense-style approval workflow. Transfers and top-ups are independent manual records because the main account is an external bridge.
7. Paid dates determine each cash balance. Transaction dates continue to determine period revenue and expense metrics. Unpaid entries never affect either cash balance.
8. If an outflow or downward correction would make its ledger negative, the server returns the projected deficit and earliest negative date. An acknowledged retry is allowed and creates a dedicated audit event.
9. An optional JPG/PNG proof can be attached to either ledger. Replacing it during an allowed edit/correction deletes the previous GCS object after the database update succeeds.
10. Admin records Lele biomass sampling separately per active group using three total-weight measurements of 10 fish each. Sampling estimates average fish weight and biomass without changing population or inventory, then persists the planned ADG, latest-sampling ADG, and actual-weight chart through projected harvest.

The application supports full payments and full receipt per feed line only. It does not include budgets, procurement, partial payments, sampling schedules, general forecasts, payroll calculation, attendance, notifications, multi-farm ledgers, reconciliation, or interactive Excel migration.

The responsive interface provides a desktop icon rail/context panel, mobile overlay navigation, light/dark themes, server-sorted paginated tables, searchable selectors, Indonesian date controls, confirmation dialogs, toast feedback, offcanvas forms, and a zoomable proof viewer. Dashboard charts are backed only by the existing v2.1 metrics and retain exact-value summaries beside them.

## Pages

Canonical finance pages are `/operational-cashflow` and `/revenues`. Legacy GET requests to `/expenses` and `/revenue` redirect with query parameters preserved; legacy write routes do not exist.

## Verification

Use a dedicated PostgreSQL database whose name ends in `_test`; `phpunit.xml` defaults to `pondok_berkah_test`.

```sh
pnpm check
php artisan view:cache
php artisan route:list --except-vendor
pnpm build
vendor/bin/phpunit
```

`pnpm check` includes PHP/Blade static parsing, TypeScript checking, and Vitest component tests. The PHPUnit suite covers the two-ledger acceptance sequence, transfer approval, top-up behavior, negative-balance acknowledgement/auditing, importer reconciliation, stock regressions, exact IDR rounding, ABK staging/authentication, persisted Lele growth, and the 35-table schema.

Browser workflows are available separately:

```sh
pnpm test:browser
```

This command builds the current frontend, launches headless Chrome, and recreates `pondok_berkah_test` before testing Admin and Superadmin workflows at 390px, 768px, and 1440px. Set `BROWSER_DB_DATABASE` only to another disposable database whose name ends in `_test`; the guard refuses any other name.
