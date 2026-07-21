# Phase 01 architecture and operations

## Application shape

EvoSyndic is a Laravel 12 modular monolith using Breeze session authentication, Inertia 2, Vue 3, TypeScript, Tailwind, Sanctum, Media Library, Activity Log, and PhpSpreadsheet. Phase 01 contains no accounting, charge, payment, expense, incident, or assembly logic.

`ResolveTenantContext` resolves only active organizations in the authenticated user's memberships, repairs stale selections, enforces residence restrictions, excludes archived residences from active context, and exposes a server-side `TenantContext`. Permission middleware, the `ResidencePolicy`, tenant-scoped validation, controller guards, restrictive foreign keys, and feature tests provide defense in depth. Client-provided organization/residence IDs never establish tenant context.

## Permission architecture

Organization membership is the single authorization source. The unused Spatie Permission dependency was removed. Each `organization_user` row stores one of owner, administrator, manager, accountant, maintenance agent, or auditor; an all-residences flag; and optional explicit permissions. Users can hold different roles in different organizations.

| Permission | Owner | Admin | Manager | Accountant | Maintenance | Auditor |
|---|---:|---:|---:|---:|---:|---:|
| Manage organization/team | ✓ | ✓ | — | — | — | — |
| View residences | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Create/edit residence | ✓ | ✓ | ✓ | — | — | — |
| Property structure | ✓ | ✓ | ✓ | — | — | — |
| Contacts | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| Ownership/occupancy | ✓ | ✓ | ✓ | — | — | — |
| Allocation keys | ✓ | ✓ | ✓ | ✓ | — | — |
| Import | ✓ | ✓ | ✓ | ✓ | — | — |
| Activity | ✓ | ✓ | ✓ | ✓ | — | ✓ |

The last owner cannot be demoted. Administrators cannot assign owner or grant permissions they do not hold. Explicit permissions are intersected with the known catalogue. When `all_residences` is false, `residence_user` is authoritative.

## Domain integrity

Residences archive through status rather than deletion. Archive clears or changes the actor's active residence, retains dependent history, blocks normal writes, and is reversible only by an owner/administrator. Archive, restore, and activation are logged.

Ownership and occupancy histories use inclusive date ranges. “Active” requires `starts_on <= date` and a null or non-expired `ends_on`. Ownership percentages are decimal(7,4), constrained to `(0, 100]`, compared as scaled integers, and transfers are transactional. Transfers close only periods active at the effective date, preserve history, reject overlaps/future conflicts, and allow one primary owner. Occupancy rejects same-contact overlaps and multiple primary occupants, permits multiple non-primary occupants, never touches ownership, and derives the lot status from current occupants.

Every residence receives one default “Tantièmes généraux” key with no assumed expected total. A nullable uniqueness slot enforces one default per residence. General and selected-lot special keys expose server-calculated assigned total, difference, and missing lots. Inline edits and tab-separated spreadsheet paste validate the whole payload before a transaction; zero and four-decimal values are valid.

## Invitations and media

Invitations store only a SHA-256 token hash. The plaintext token exists only while a localized French/Arabic Laravel notification is built. Guest landing, new-user registration, existing-user login/acceptance, expiration, cancellation, resend with token/expiry rotation, email matching, and single use are enforced. Create, resend, cancel, and accept events are logged without token material.

Residence logos use Media Library's single-file `logo` collection on the public disk. JPEG, PNG, and WebP files up to 4 MB are accepted. Create/edit preview, replacement, removal, authorization, selector/overview display, and initials fallback are implemented. Run `php artisan storage:link` for local public media.

## Onboarding

Onboarding persists its organization/residence context and presents all eight steps: organization, residence, structure, contacts, ownership, tantièmes, team, and review. Status is derived from current records; only optional steps store skips. Activation locks and rechecks the residence in a transaction. At least one active lot and one default allocation key are mandatory; missing ownership requires explicit acknowledgement and incomplete allocation values require explicit deferral. Normal residence requests cannot change setup/active/archive status.

## Imports

CSV/TXT/XLSX imports are private, MIME/extension checked, and limited to 10 MB. Original name, MIME, byte size, SHA-256, mapping, status, counters, and timestamps are stored. Statuses are `uploaded`, `mapping`, `pending`, `processing`, `completed`, `completed_with_errors`, `failed`, and `rolled_back`.

Files at or below `EVOSYNDIC_IMPORT_SYNC_THRESHOLD` (default 250 data rows) run synchronously; larger files dispatch `ProcessImportBatch`. Jobs serialize batch, organization, residence, and user IDs and recheck membership, permission, tenant ownership, and archive status without session context. Each row has its own safe transaction and provenance row containing source data, action, subject, before/after snapshots, error, and processing time. Completed row numbers make retries idempotent. Invalid peers produce `completed_with_errors`, and failures are downloadable as CSV.

Rollback visits rows in reverse order. Unchanged records created solely by the import are removed only when no later ownership, occupancy, or allocation dependency exists. Unchanged pre-existing records are restored from snapshots. Modified/dependent rows remain untouched and appear as blocked in the rollback report.

Production workers:

```bash
php artisan queue:work --queue=default --tries=3 --timeout=900
```

## Localization

French is the default. Guest locale is session-backed before authentication; the user's safe `fr`/`ar` preference takes over afterward. Authentication, invitation, recovery, verification, profile, validation, status messages, navigation, and domain pages are translated. Arabic sets RTL on the document and both application shells. Internal enum/storage values remain English.

## Deployment

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan down --render="errors::503"
php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan up
php artisan queue:restart
```

Point the web server at `public/`, supervise queue workers, schedule `php artisan schedule:run` every minute, and never run demo seeders in production. Required production values include `APP_URL`, `APP_KEY`, MySQL `DB_*`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK`, `MAIL_*`, and optionally `EVOSYNDIC_IMPORT_SYNC_THRESHOLD` and S3 credentials.

## Runtime verification and Phase 02 boundary

Composer requires PHP 8.3+. The closure audit was executed on PHP 8.2.26; that is not PHP 8.3 runtime verification. CI and production must execute Composer validation and the full suite on PHP 8.3 or newer.

Phase 02 should start with a double-entry ledger and charge-period module consuming immutable ownership periods and `lot_allocation_values`. It must not rewrite Phase 01 history.
