# EvoSyndic Phase 02 - Charges, collection and payments

## Scope and architecture

Phase 02 adds an operational financial sub-ledger to the Phase 01 organization/residence tenant boundary. Every financial aggregate is scoped by both `organization_id` and `residence_id`; route actions verify the active tenant and explicit permission. Monetary values are integer centimes (`*_cents`) and are converted at input/output boundaries by `App\Support\Money`. No financial balance is user-editable.

The main flow is:

`FinancialExercise -> FundCall -> FundCallLine -> LotCharge -> PaymentAllocation <- Payment -> FinancialAccountMovement`

Receipts are immutable `FinancialDocument` records backed by private storage. Recurring templates create fund-call drafts through idempotent `ScheduleGeneration` records. These operational movements are not the complete Moroccan double-entry engine required by Decree 2.23.700; Phase 03 expenses and the later accounting phase must reference them as source records.

## Tables and relationships

- `financial_exercises`: non-overlapping residence periods with draft/open/closed state and controlled reopening metadata.
- `financial_accounts`: bank/cash accounts, opening position, active/default state. Current balance is opening balance plus immutable movements.
- `charge_categories`: ordinary/exceptional classification and default distribution settings.
- `document_sequences`: locked counters scoped by residence, document kind and year.
- `fund_calls`, `fund_call_lines`, `lot_charges`: draft definition, immutable validation snapshot and lot debt.
- `fund_call_schedules`, `schedule_generations`: recurring JSON template and retry-safe generation occurrence.
- `payments`, `payment_allocations`: collection instrument and allocations to lot debt. Each allocation has its own effective date so a later use of advance credit does not rewrite the receipt date.
- `financial_account_movements`: immutable debit/credit operational account movements, including reversal links.
- `financial_documents`: stored PDF metadata, SHA-256 checksum, version, status and hashed/encrypted verification token.
- `contact_user`: optional authenticated coproprietaire/contact identity link.

## Formulas

All formulas operate on integer centimes.

- Account balance = opening balance + credit movements - debit movements.
- Charge outstanding = active charge amount - active payment allocations.
- Payment unallocated amount = validated payment amount - active allocations.
- Available coproprietaire credit = the unallocated amount only when the validated payment has an identified payer/contact and a reconciled movement and receipt.
- Amount called = sum of non-cancelled validated lot charges in scope.
- Amount collected = sum of non-reversed allocations to those active charges.
- Collection rate = collected / called x 100; it is zero when called is zero.
- Overdue uses the charge due date. Aging buckets are current, 1-30, 31-60, 61-90, and over 90 days.

## Fund-call lifecycle and rounding

Drafts can be edited. Validation requires an open exercise, dates inside it, valid tenant references, at least one line, and a valid distribution. The service locks the call and sequence counter, assigns `AF-YEAR-SEQUENCE`, snapshots lot/contact/distribution data, creates lot charges, records the actor, and commits atomically. Validated calls are immutable through application routes. Cancellation requires a reason and is blocked while active allocations exist.

Supported distributions are allocation-key, equal, fixed-per-lot, and exact manual amounts. Targets can be all active lots, buildings, lot types, or explicit lots. Weighted methods calculate each floor share, rank remainders descending with lot ID as stable tie-breaker, then add remaining centimes in rank order. Thus every line preserves its exact total deterministically. Fixed distribution derives the line total from fixed amount x eligible lots. Manual distribution must match the line total exactly.

## Payment allocation, credit, and reversal

Payment validation locks the payment and eligible charges. FIFO order is due date, issue date, then charge ID. It supports all eligible lots, selected lots, or explicit manual allocations; partial, multi-charge, and multi-lot payments are valid. An idempotency key prevents duplicate submissions. Validation assigns `PAY-YEAR-SEQUENCE`, writes the incoming credit movement, creates `REC-YEAR-SEQUENCE`, stores the PDF/checksum, and only then marks the payment valid in the same database transaction.

An identified overpayment remains derived advance credit on the original payment. It does not block closing, never creates a fake negative charge, and is not silently consumed. Authorized later allocation may target a charge in a later open exercise, but only in the same organization/residence and only for a lot owned by the payer on the charge issue date. The payment, incoming movement, receipt number and receipt date remain in the original exercise. The later allocation has its own effective date and appears separately in payment history and statements.

An unidentified payment is operationally unallocated, not coproprietaire credit. Automatic FIFO does not consume it. It blocks exercise closing until an authorized operator links an organization contact or the payment is reversed. Missing/duplicate movements, invalid allocations or reversal state, cross-tenant references, and missing receipts also block closing.

Reversal requires a reason. It locks the payment, timestamps rather than deletes allocations—including later cross-exercise allocations—restores charge states, creates a debit movement linked to the original credit movement, marks the receipt reversed, and reserves all document numbers permanently. If the original exercise is closed, the compensating movement belongs to the currently open exercise; the original payment is not moved. The original PDF remains archived and its public verification page changes to reversed.

## Receipts and verification

Receipt PDFs are stored on Laravel's private `local` disk under `finance/receipts/{residence}/`. They include issuer, payer/received-from identity, date/method, allocations, available credit, MAD total, and a QR verification URL. French and Arabic use DejaVu Sans; Arabic documents are RTL. `financial_documents.checksum` is SHA-256 of the exact stored bytes. Public verification accepts a 64-character random token but queries by its SHA-256 hash and exposes only document type/reference, issuer, issue date, amount, current validity, and file-integrity result.

## Ownership changes and portal privacy

Debt always belongs to the lot and is never reassigned when ownership changes. The billed contact is only a validation-time snapshot. Active owners can see current lot balance; transaction visibility is filtered by their ownership periods. Joint owners see the same authorized lot statement. A non-owner payer sees their payment/receipt only and does not gain lot history. Occupancy alone grants no financial access.

## Permissions

Phase 02 permissions are `view_finance`, `manage_financial_exercises`, `manage_financial_accounts`, `manage_charge_categories`, `create_fund_calls`, `validate_fund_calls`, `cancel_fund_calls`, `create_payments`, `validate_payments`, `reverse_payments`, `allocate_credit`, `view_outstanding`, `view_statements`, `export_finance`, and `view_financial_activity`.

Owners have all permissions. Administrators have operational finance access. Managers create drafts/payments and read balances but only validate when explicitly granted. Accountants have full finance/reversal/export access. Auditors are read-only with exports. Maintenance agents have no finance access. Direct routes repeat tenant and permission checks.

## Scheduling and queues

Preview due schedules without writes:

```bash
php artisan evosyndic:generate-fund-calls --dry-run
php artisan evosyndic:generate-fund-calls --residence=1 --date=2026-08-01 --dry-run
```

Generate due drafts explicitly:

```bash
php artisan evosyndic:generate-fund-calls --apply
```

Laravel schedules the apply form daily at 01:15 with overlap protection. Production cron:

```cron
* * * * * cd /var/www/evosyndic && php artisan schedule:run >> /dev/null 2>&1
```

Use a durable queue worker for future queued bulk statement/export work:

```bash
php artisan queue:work --queue=default --tries=3 --timeout=120
```

## Local setup and validation

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan test
npm run format:check
npm run lint
npm run build
composer validate --strict --no-check-publish
```

Demo credentials remain documented in Phase 01; demo data is guarded to local/testing environments. The development seed includes open 2026 exercise, bank/cash accounts, ordinary and exceptional calls, paid/partial/unpaid/overdue positions, unallocated credit, a reversed payment, joint ownership, ownership transfer, and FR/AR contacts.

## Production deployment

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan queue:restart
php artisan schedule:list
```

Ensure `storage/app/private/finance` is persistent and backed up, `APP_URL` is the public HTTPS origin used by receipt QR codes, the PHP process can write storage, and queue/scheduler supervisors are active.

## Phase 03 starting point

Begin Phase 03 supplier expenses by adding expense commitments, supplier invoices and settlements that create debit `financial_account_movements`. Reference existing fund-call categories and exercises; do not mutate Phase 02 receipts, allocations, or source movements. Reconciliation should continue to use the operational account ledger until the later full double-entry engine projects these source records into Moroccan statutory journals and annexes.

## Phase 02 hardening addendum

`credit` and `debit` are operational cash directions only: `credit` means money received into the selected account and `debit` means the compensating outflow created by reversal. They are not statutory double-entry classifications.

Closing is blocked by draft calls/payments, unidentified payments, missing receipts, over-allocation, unreconciled or duplicate payment movements, invalid reversal state, cross-tenant references, or failed recurring generation during the exercise. Legitimate identified advance credit does not block closing and stays available after closing. Reopening requires an owner/accountant, a reason, no other open exercise, appended reopen metadata, and an activity record.

Receipt safety uses transactional option A. Rendering and document creation happen before the payment becomes validated. Renderer/storage failure rolls back allocations, movement, numbering and payment state. Generation is idempotent by payment/type/version: missing or corrupt bytes are regenerated using the same receipt number and token. Production generation rejects a non-HTTPS verification URL. Public verification is rate-limited to 30 requests per minute.

Statements now share one service across screen, PDF and CSV. Date filters calculate an opening position from earlier activity. Lines include transaction date, due date, type, reference, label, debit, credit and running balance. Cancelled charges are excluded; reversed allocations retain an explanatory original/reversal pair with zero net effect. CSV formula-leading cells are escaped.

Current owners see current lot debt plus inherited debt originating before their period. Former owners see their period only and not current balance. Receipt access requires staff permission or a direct payer/contact link. Contact-user links are owner/administrator governed, tenant-scoped, revocable and logged.

Monthly, quarterly, semiannual, annual and custom intervals preserve the configured day, clamped to month end. Generations snapshot their template. Failures are recorded and retryable.

Read-only reconciliation commands:

```bash
php artisan evosyndic:audit-collections
php artisan evosyndic:audit-collections --residence=1 --json
php artisan evosyndic:audit-collections --exercise=1
php artisan evosyndic:audit-collections --payment=1
php artisan evosyndic:audit-collections --fund-call=1
```

Normal automated tests use SQLite for rollback, idempotency and unique-constraint evidence. SQLite does not prove InnoDB lock scheduling. Use `.env.mysql.testing.example` with a disposable MySQL 8 database for the MySQL-only integration profile. The production guarantees rely on InnoDB transactions, `SELECT ... FOR UPDATE`, balance rechecks after locks, locked sequence rows, and final unique constraints.
