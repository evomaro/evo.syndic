# Phase 08 G1 — Proposed Phase 01–07 merge manifest

> **Status:** G1 resolution executed in a disposable clone; approval remains blocked only by unproven PWA/brand asset ownership. The original application source, index, branch and history were not changed. G2 was not started.

## Repository base

- Branch: `main`
- Base commit and `HEAD`: `4fe9149ed9147f4f467ad712ada9b226688bf7ef`
- Base subject: `Baseline EvoSyndic Phases 01-02`
- Upstream: `origin/main` at the same commit
- Ahead/behind: `0/0`
- Merge base with upstream: `4fe9149ed9147f4f467ad712ada9b226688bf7ef`
- Merge, rebase, cherry-pick and revert state: none
- Base conclusion: the inherited Phase 01–07 worktree is based directly on the current upstream Phase 01–02 commit.

## Inventory counts

The pre-manifest snapshot contained 458 paths. This document becomes path 459 and is classified as G1 evidence outside the Phase 01–07 merge boundary.

| State | Pre-manifest | Including this manifest |
|---|---:|---:|
| Staged modification (`M.`) | 27 | 27 |
| Staged addition (`A.`) | 249 | 249 |
| Unstaged-only modification (`.M`) | 10 | 10 |
| Added in index and then modified (`AM`) | 41 | 41 |
| Modified in index and worktree (`MM`) | 7 | 7 |
| Untracked (`??`) | 124 | 125 |
| Total paths | 458 | 459 |

There are no rename records. The index has 324 paths with 22,802 added and 69 deleted text lines. The worktree delta against the index has 58 paths with 983 added and 115 deleted text lines. Untracked content is not represented in those line totals.

## Proposed review units and dependencies

| Unit | Paths | Scope | Dependencies | Disposition |
|---|---:|---|---|---|
| `D0_G0_SPEC` | 1 | Phase 08 G0 specification | Outside Phase 01–07 boundary | Approved documentation; retain separately. |
| `D1_G1_EVIDENCE` | 1 | This G1 inventory and proposed manifest | Outside Phase 01–07 boundary | Review evidence; retain separately. |
| `R01_PHASE_01_02_REPAIRS` | 3 | Phase 01–02 prerequisite repairs | Base commit | Small import/test corrections; reviewer must confirm no later-phase behavior is embedded. |
| `R02_PHASE_03` | 165 | Phase 03 expenses, portal, PWA and supporting security | R01 | Includes the tracked MySQL example, private-storage test root, source PWA/brand assets and Phase 03 tests. |
| `R03_PHASE_04` | 46 | Phase 04 maintenance | R02 | Work-order invoice handoff depends on Phase 03 suppliers and invoices. |
| `R04_PHASE_05` | 40 | Phase 05 governance foundation | R02, R03 migration order | Index generation only; paths later changed for Phase 07 are listed in X3. |
| `R04X_DOCUMENT_INTEGRITY` | 14 | Phase 02/03 financial-document integrity hardening | R02, R04 migration order | Migration timestamp is after Phase 05; feature test spans receipts and supplier vouchers. |
| `R05_PHASE_06A` | 23 | Phase 06A accounting foundation | R01, R02, R04X | Untracked final source; depends on Phase 02/03 source records and exercises. |
| `R06_PHASE_06B` | 16 | Phase 06B automated accounting | R05, R02 | Untracked final source; supplier/source hooks are separated into X2. |
| `X2_PHASE03_06B_BRIDGE` | 9 | Phase 03 supplier to Phase 06B posting bridge | R02, R06 | Nine AM paths: staged Phase 03 blob plus unstaged accounting integration. Requires hunk/blob separation. |
| `R07_PHASE_06C` | 7 | Phase 06C reporting and reconciliation | R05, R06, X2 | Report/export surface; automated-accounting test also needs a 06B/06C ownership decision. |
| `R08_PHASE_06D` | 17 | Phase 06D closing and carry-forward | R05–R07 | Closing depends on final posting and reporting invariants. |
| `R09_PHASE_06E_UNSPLIT` | 31 | Phase 06E compliance, currently unsplit | R02; ordering conflict with R10 | Contains migrations 2026_07_25_000500 and 2026_07_26_000700 around Phase 07 migration 000600. Must be split or reviewed as one non-deployable patch series. |
| `R10_PHASE_07` | 28 | Phase 07 governance hardening | R04; final migration order after Phase 06 migrations | Untracked final source; staged Phase 05 paths modified for Phase 07 are listed in X3. |
| `X3_PHASE05_07_BRIDGE` | 32 | Phase 05 to Phase 07 governance bridge | R04, R10 | Thirty-two AM paths: staged Phase 05 blob plus unstaged Phase 07 hardening. Requires hunk/blob separation. |
| `R11_SHARED_INTEGRATION` | 19 | Shared Phase 01–07 integration | All owning feature units | Base models/controllers, tenant context, routes into finance, translations, seed data and lifecycle bridges require named ownership. |
| `X4_GLOBAL_OVERLAP` | 7 | Global staged/unstaged overlap | All feature units | Seven MM paths contain different index and worktree generations: provider, activity scope, permissions, layout, i18n and routes. |

## Required dependency order

The dependency-complete target order is:

1. `R01` Phase 01–02 repairs.
2. `R02` Phase 03.
3. `R03` Phase 04.
4. `R04` Phase 05.
5. `R04X` financial-document integrity.
6. `R05` Phase 06A.
7. `R06` Phase 06B, followed by `X2`.
8. `R07` Phase 06C.
9. `R08` Phase 06D.
10. Phase 06E foundation, Phase 07, then Phase 06E concurrency hardening.
11. `X3` Phase 05/07 bridge.
12. `R11` and `X4` final shared integration.

Step 10 cannot be represented safely by the current path-level `R09` unit. The Phase 06E application/model/service/test paths must be reviewed at hunk level to separate foundation behavior from concurrency hardening, or the complete Phase 06E/07 patch series must be declared indivisible and non-deployable at intermediate commits. This decision is a G1 blocker.

## Exclusions

The following are outside the proposed Phase 01–07 application merge boundary:

- `docs/phase-08.md`: approved G0 specification, retained as Phase 08 documentation.
- `docs/phase-08-g1-merge-manifest.md`: this G1 evidence, retained as Phase 08 documentation.
- Any default or local database, including `database/database.sqlite`.
- `.env`, secrets, credentials, logs, caches, `storage/`, `tmp/`, `vendor/`, `node_modules/`, and `public/build/`.
- Disposable database files, browser profiles, generated PDFs/exports, screenshots and temporary matrix evidence.

No default database, local database, generated build directory, log, cache, temporary test evidence or secret-bearing `.env` path appears in the 458-path snapshot. `.env.mysql.testing.example` is included in `R02`: its staged change contains only the disposable private-storage root and no credential value.

The source PWA/brand binaries under `public/icons/` and `public/images/` are included in `R02`, but their design/IP provenance requires explicit review before G1 approval.

## Conflicts and blockers at proposal time

1. **Forty-eight overlapping paths:** 41 `AM` and 7 `MM` paths contain distinct index and working-tree generations. The index cannot be treated as the final implementation.
2. **Phase 03/06B bridge:** nine supplier paths require separation of the staged Phase 03 implementation from unstaged accounting-posting integration.
3. **Phase 05/07 bridge:** thirty-two governance paths require separation of staged Phase 05 implementation from unstaged Phase 07 hardening.
4. **Global overlap:** seven provider/config/layout/i18n/route paths combine multiple phases and need hunk ownership.
5. **Phase 06E migration order:** `000500`, Phase 07 `000600`, and Phase 06E hardening `000700` require an approved split or an explicitly indivisible patch series.
6. **Untracked production source:** excluding the G0 specification, 123 untracked source/test/migration paths are not represented in the index.
7. **Shared integration ownership:** nineteen paths remain in `R11`; each needs a named owning unit or explicit final-integration rationale.
8. **Binary provenance:** six staged PNG assets and one SVG source asset need provenance/licensing confirmation.
9. **No candidate reconstruction:** the requested prohibition on staging/committing means no separate clean candidate has yet proved that the units apply independently.
10. **G2 deliberately not started:** no tests, migrations, Artisan commands or runtime validation were executed as part of G1.

Legal/professional approvals and default-database provenance remain separate from this source-boundary decision.

## G1 approval assessment

**Blocked / not ready for approval.**

The source-boundary, hunk, migration-order and shared-ownership blockers have been resolved in the disposable candidate described below. G1 approval remains blocked because repository history and embedded metadata do not prove ownership or licensing for the six PNG and one SVG assets.

## G1 resolution execution

The disposable clone used base `4fe9149ed9147f4f467ad712ada9b226688bf7ef`. It excluded `database/database.sqlite` from sparse checkout. Temporary commits exist only on the clone-local `codex/phase08-g1-reconstruction` branch.

### Reconciliation

- The original index generation contains 324 paths. Candidate boundary `ebe47f6dc65790737d6ce74b35af5492e7a3926a` matches all 324 by Git mode and blob ID.
- The original worktree delta contains 58 paths and 193 diff hunks.
- The 48 mixed-generation paths contain 159 hunks: 32 supplier-bridge hunks, 99 governance-bridge hunks and 28 global-integration hunks.
- The remaining ten worktree-only paths contain 34 hunks: two assigned to Phase 06A, 28 to Phase 06B and four to final integration.
- Before this report update, all 459 candidate paths matched the original worktree byte-for-byte and mode-for-mode.
- The candidate tree has exactly the same 459 changed path names as the original inventory and no additional application path.
- The reconstructed application boundary is `734809a310d6cfa91c1d1e97543de7d2329c536c`; the later report-only commit does not change application source.

### Boundary corrections

The initial filename classifier was corrected during reconstruction:

- `resources/js/Pages/Accounting/Reports.vue` belongs to Phase 06C.
- `resources/js/Pages/Accounting/Closing.vue` belongs to Phase 06D.
- `tests/Feature/PhaseSixAutomatedAccountingTest.php` lands with Phase 06C because its final version includes reporting and reconciliation evidence.
- `app/Exports/GovernanceRegisterExport.php` belongs to Phase 07.

### Shared-path ownership

| Owner | Paths | Rationale |
|---|---|---|
| Phase 01–02 repair | `ChargeCategoryController.php`, `OccupancyController.php`, `StructureController.php` | Formatting-only prerequisite corrections |
| Phase 03 | base `Controller.php`, `HandleInertiaRequests.php`, `Contact.php`, `Lot.php`, `bootstrap/app.php`, `DemoSeeder.php` | Policy authorization, portal privacy/history behavior, ownership helpers and Phase 03 fixtures |
| Phase 06A | `FinancialExercise.php` | Accounting-period relation and accounting lock cast |
| Phase 06B | `FundCallController.php`, `PaymentController.php`, `FundCallWorkflow.php`, `PaymentWorkflow.php`, finance fund-call page, finance payment page | Automated source posting, reversal and posting-status integration |
| Final Phase 06E/07 integration | `ContextController.php`, `MembershipAuthorization.php`, `lang/ar.json` | Tenant-switch navigation, combined accounting/compliance/governance permission catalogues and final Arabic disclaimers |

The seven global `MM` paths are owned by the final integration boundary: `AppServiceProvider.php`, `ActivityScope.php`, `config/evosyndic.php`, `AuthenticatedLayout.vue`, `resources/js/i18n.ts`, `routes/console.php` and `routes/web.php`.

### Migration order

The final application boundary lands these three files atomically in lexical order:

1. `2026_07_25_000500_create_phase_six_e_compliance.php`
2. `2026_07_26_000600_harden_phase_seven_governance.php`
3. `2026_07_26_000700_harden_phase_six_compliance_concurrency.php`

The same atomic commit includes Phase 06E/07 models, services, controllers, routes, permissions, jobs, UI, exports, tests and the 32 Phase 05/07 bridge paths. Earlier commits are explicitly marked review-only and must not be deployed independently.

### Asset provenance

| Asset | SHA-256 | Metadata and use | Provenance |
|---|---|---|---|
| `public/icons/apple-touch-icon.png` | `34ab19c43532fb960fc7446724f3a2a81e87c0b6d03ae948893d59e09c3b0fbc` | 180×180 RGB, sRGB, 96 dpi; manifest, service worker and Apple touch link | Blocked |
| `public/icons/icon-192.png` | `29af5b7cc7b05b00e5b3f704da906c35a6aa1e7217f3331623617674ea0f3ffa` | 192×192 RGB, 96 dpi; manifest and service worker | Blocked |
| `public/icons/icon-512.png` | `c08093ddd890f74e6e27e521c3e31fd1e31305baebe6e4f8215986cad525fc8a` | 512×512 RGB, 96 dpi; manifest and service worker | Blocked |
| `public/icons/icon-maskable-512.png` | `c08093ddd890f74e6e27e521c3e31fd1e31305baebe6e4f8215986cad525fc8a` | Byte-identical to `icon-512.png`; maskable manifest entry and service worker | Blocked |
| `public/images/evosyndic-logo.png` | `29ac5cfe7aeccfb7030ea62e8e7c9cb5605f5edf341ec76b74ec1c18342f8dc6` | 640×158 RGBA, sRGB, 72 dpi; authenticated layout | Blocked |
| `public/images/evosyndic-symbol.png` | `ffad2ee2f7445f9de73c0af7780b882f40e283231c34ec52f7a19001ca0ad6d0` | 128×128 RGBA, 96 dpi; authenticated layout | Blocked |
| `public/icons/icon.svg` | `04ec7266bec5499994f27426f0555d93e188193a0f979406e94abacc5fcbf75c` | Simple 512 viewBox using slate, teal and white geometric paths; currently no runtime reference | Blocked |

All seven assets are staged additions with no repository history. No embedded author, copyright, license, creator-tool metadata, useful extended attribute or source-design reference was found. Filesystem dates group the icons on 2026-07-21 and the logo/symbol on 2026-07-24, but timestamps and visual similarity do not prove ownership.

## Exhaustive path manifest

Status is Git porcelain relative to the base: `M.` staged modification, `A.` staged addition, `.M` unstaged modification, `AM` added then modified, `MM` modified in both, and `??` untracked.

```text
status	unit	path
??	D0_G0_SPEC	docs/phase-08.md
??	D1_G1_EVIDENCE	docs/phase-08-g1-merge-manifest.md
M.	R01_PHASE_01_02_REPAIRS	app/Services/ImportService.php
M.	R01_PHASE_01_02_REPAIRS	tests/Feature/PhaseTwoFinanceTest.php
M.	R01_PHASE_01_02_REPAIRS	tests/Feature/PhaseTwoHardeningTest.php
M.	R02_PHASE_03	.env.mysql.testing.example
A.	R02_PHASE_03	app/Http/Controllers/AnnouncementController.php
M.	R02_PHASE_03	app/Http/Controllers/Auth/AuthenticatedSessionController.php
A.	R02_PHASE_03	app/Http/Controllers/BudgetController.php
M.	R02_PHASE_03	app/Http/Controllers/DashboardController.php
A.	R02_PHASE_03	app/Http/Controllers/ExpenseCategoryController.php
A.	R02_PHASE_03	app/Http/Controllers/ExpenseCommitmentController.php
A.	R02_PHASE_03	app/Http/Controllers/ExpenseDashboardController.php
A.	R02_PHASE_03	app/Http/Controllers/NotificationController.php
A.	R02_PHASE_03	app/Http/Controllers/ResidenceDocumentController.php
A.	R02_PHASE_03	app/Http/Controllers/ResidentPortalController.php
A.	R02_PHASE_03	app/Http/Controllers/SupplierContractAttachmentController.php
A.	R02_PHASE_03	app/Http/Controllers/SupplierContractController.php
A.	R02_PHASE_03	app/Http/Controllers/SupplierController.php
A.	R02_PHASE_03	app/Http/Controllers/SupplierPayableController.php
A.	R02_PHASE_03	app/Http/Controllers/SupplierStatementController.php
A.	R02_PHASE_03	app/Http/Requests/Expenses/BudgetRequest.php
A.	R02_PHASE_03	app/Http/Requests/Expenses/BudgetRevisionRequest.php
A.	R02_PHASE_03	app/Http/Requests/Expenses/ExpenseCategoryRequest.php
A.	R02_PHASE_03	app/Http/Requests/Expenses/ExpenseCommitmentRequest.php
A.	R02_PHASE_03	app/Http/Requests/Expenses/ExpenseFormRequest.php
A.	R02_PHASE_03	app/Http/Requests/Expenses/SupplierContractRequest.php
A.	R02_PHASE_03	app/Http/Requests/Expenses/SupplierCreditNoteRequest.php
A.	R02_PHASE_03	app/Http/Requests/Expenses/SupplierInvoiceRequest.php
A.	R02_PHASE_03	app/Http/Requests/Expenses/SupplierRequest.php
A.	R02_PHASE_03	app/Http/Requests/Expenses/SupplierSettlementRequest.php
A.	R02_PHASE_03	app/Models/Budget.php
A.	R02_PHASE_03	app/Models/BudgetLine.php
A.	R02_PHASE_03	app/Models/DocumentGenerationAttempt.php
A.	R02_PHASE_03	app/Models/ExpenseCategory.php
A.	R02_PHASE_03	app/Models/ExpenseCommitment.php
M.	R02_PHASE_03	app/Models/FinancialAccountMovement.php
A.	R02_PHASE_03	app/Models/NotificationPreference.php
A.	R02_PHASE_03	app/Models/ResidenceAnnouncement.php
A.	R02_PHASE_03	app/Models/ResidenceDocument.php
A.	R02_PHASE_03	app/Models/ResidenceDocumentVersion.php
A.	R02_PHASE_03	app/Models/Supplier.php
A.	R02_PHASE_03	app/Models/SupplierContract.php
A.	R02_PHASE_03	app/Models/SupplierContractAttachment.php
A.	R02_PHASE_03	app/Models/SupplierCreditNote.php
A.	R02_PHASE_03	app/Models/SupplierCreditNoteAllocation.php
A.	R02_PHASE_03	app/Models/SupplierInvoice.php
A.	R02_PHASE_03	app/Models/SupplierInvoiceAttachment.php
A.	R02_PHASE_03	app/Models/SupplierInvoiceLine.php
A.	R02_PHASE_03	app/Models/SupplierServiceCategory.php
A.	R02_PHASE_03	app/Models/SupplierSettlement.php
A.	R02_PHASE_03	app/Models/SupplierSettlementAllocation.php
A.	R02_PHASE_03	app/Notifications/PortalNotification.php
A.	R02_PHASE_03	app/Policies/BudgetPolicy.php
A.	R02_PHASE_03	app/Policies/Concerns/AuthorizesExpenseScope.php
A.	R02_PHASE_03	app/Policies/ExpenseCommitmentPolicy.php
A.	R02_PHASE_03	app/Policies/SupplierContractPolicy.php
A.	R02_PHASE_03	app/Policies/SupplierCreditNotePolicy.php
A.	R02_PHASE_03	app/Policies/SupplierInvoicePolicy.php
A.	R02_PHASE_03	app/Policies/SupplierPolicy.php
A.	R02_PHASE_03	app/Policies/SupplierSettlementPolicy.php
A.	R02_PHASE_03	app/Queries/BudgetReportingQuery.php
A.	R02_PHASE_03	app/Queries/ExpenseDashboardQuery.php
A.	R02_PHASE_03	app/Queries/SupplierInvoiceQuery.php
A.	R02_PHASE_03	app/Queries/SupplierPayableQuery.php
A.	R02_PHASE_03	app/Queries/SupplierQuery.php
A.	R02_PHASE_03	app/Queries/SupplierSettlementQuery.php
A.	R02_PHASE_03	app/Services/AnnouncementService.php
A.	R02_PHASE_03	app/Services/BudgetDraftService.php
A.	R02_PHASE_03	app/Services/BudgetService.php
A.	R02_PHASE_03	app/Services/BudgetThresholdNotificationService.php
A.	R02_PHASE_03	app/Services/CommitmentWorkflow.php
A.	R02_PHASE_03	app/Services/ContractExpirationNotificationService.php
A.	R02_PHASE_03	app/Services/ExpenseAuditService.php
A.	R02_PHASE_03	app/Services/ExpenseResidenceAccessService.php
M.	R02_PHASE_03	app/Services/FinancialExerciseLifecycleService.php
A.	R02_PHASE_03	app/Services/ManagerNotificationService.php
A.	R02_PHASE_03	app/Services/OrganizationDocumentNumberService.php
A.	R02_PHASE_03	app/Services/OverdueSupplierInvoiceNotificationService.php
A.	R02_PHASE_03	app/Services/ResidenceDocumentService.php
A.	R02_PHASE_03	app/Services/SupplierContractAttachmentService.php
A.	R02_PHASE_03	app/Services/SupplierContractRenewalService.php
A.	R02_PHASE_03	app/Services/SupplierContractWorkflow.php
A.	R02_PHASE_03	app/Services/SupplierDuplicateService.php
A.	R02_PHASE_03	app/Services/SupplierInvoiceDraftService.php
A.	R02_PHASE_03	app/Services/SupplierPayableService.php
A.	R02_PHASE_03	app/Services/SupplierStatementService.php
A.	R02_PHASE_03	app/Services/VoucherService.php
M.	R02_PHASE_03	config/filesystems.php
A.	R02_PHASE_03	database/migrations/2026_07_21_000600_create_phase_three_expenses_and_portal.php
A.	R02_PHASE_03	database/migrations/2026_07_21_000700_harden_phase_three_expenses_and_portal.php
A.	R02_PHASE_03	database/migrations/2026_07_21_000800_close_phase_three_operational_gaps.php
A.	R02_PHASE_03	public/icons/apple-touch-icon.png
A.	R02_PHASE_03	public/icons/icon-192.png
A.	R02_PHASE_03	public/icons/icon-512.png
A.	R02_PHASE_03	public/icons/icon-maskable-512.png
A.	R02_PHASE_03	public/icons/icon.svg
A.	R02_PHASE_03	public/images/evosyndic-logo.png
A.	R02_PHASE_03	public/images/evosyndic-symbol.png
A.	R02_PHASE_03	public/manifest.webmanifest
A.	R02_PHASE_03	public/offline.html
A.	R02_PHASE_03	public/sw.js
M.	R02_PHASE_03	resources/css/app.css
M.	R02_PHASE_03	resources/js/app.ts
A.	R02_PHASE_03	resources/js/Components/Expenses/BudgetLineEditor.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/BudgetMetricGrid.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/CommitmentPicker.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/CreditAllocationEditor.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/ExpenseCategoryPicker.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/ExpenseNavigation.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/ExpenseVisibilitySelector.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/FinancialConfirmationPanel.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/FinancialStatusBadge.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/InvoiceLineEditor.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/InvoiceTotals.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/MoneyInput.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/PrivateAttachmentUploader.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/ResidenceAllocationEditor.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/SettlementAllocationPreview.vue
A.	R02_PHASE_03	resources/js/Components/Expenses/SupplierPicker.vue
A.	R02_PHASE_03	resources/js/Pages/Budgets/Form.vue
A.	R02_PHASE_03	resources/js/Pages/Budgets/Index.vue
A.	R02_PHASE_03	resources/js/Pages/Budgets/Show.vue
M.	R02_PHASE_03	resources/js/Pages/Dashboard.vue
A.	R02_PHASE_03	resources/js/Pages/ExpenseCategories/Index.vue
A.	R02_PHASE_03	resources/js/Pages/ExpenseCommitments/Form.vue
A.	R02_PHASE_03	resources/js/Pages/ExpenseCommitments/Index.vue
A.	R02_PHASE_03	resources/js/Pages/ExpenseCommitments/Show.vue
A.	R02_PHASE_03	resources/js/Pages/Expenses/Overview.vue
A.	R02_PHASE_03	resources/js/Pages/Expenses/Payables.vue
A.	R02_PHASE_03	resources/js/Pages/Expenses/SupplierStatement.vue
A.	R02_PHASE_03	resources/js/Pages/Portal/Announcements.vue
A.	R02_PHASE_03	resources/js/Pages/Portal/Documents.vue
A.	R02_PHASE_03	resources/js/Pages/Portal/Home.vue
A.	R02_PHASE_03	resources/js/Pages/Portal/Notifications.vue
A.	R02_PHASE_03	resources/js/Pages/Portal/ResidentAnnouncements.vue
A.	R02_PHASE_03	resources/js/Pages/Portal/ResidentDocuments.vue
A.	R02_PHASE_03	resources/js/Pages/SupplierContracts/Form.vue
A.	R02_PHASE_03	resources/js/Pages/SupplierContracts/Index.vue
A.	R02_PHASE_03	resources/js/Pages/SupplierContracts/Show.vue
A.	R02_PHASE_03	resources/js/Pages/SupplierCreditNotes/Form.vue
A.	R02_PHASE_03	resources/js/Pages/SupplierCreditNotes/Index.vue
A.	R02_PHASE_03	resources/js/Pages/SupplierInvoices/Form.vue
A.	R02_PHASE_03	resources/js/Pages/SupplierInvoices/Index.vue
A.	R02_PHASE_03	resources/js/Pages/SupplierPayables/Index.vue
A.	R02_PHASE_03	resources/js/Pages/Suppliers/Form.vue
A.	R02_PHASE_03	resources/js/Pages/Suppliers/Index.vue
A.	R02_PHASE_03	resources/js/Pages/Suppliers/Show.vue
A.	R02_PHASE_03	resources/js/Pages/SupplierSettlements/Form.vue
A.	R02_PHASE_03	resources/js/Pages/SupplierSettlements/Index.vue
M.	R02_PHASE_03	resources/views/app.blade.php
A.	R02_PHASE_03	resources/views/pdf/supplier-statement.blade.php
A.	R02_PHASE_03	resources/views/pdf/supplier-voucher.blade.php
M.	R02_PHASE_03	tests/Feature/Auth/AuthenticationTest.php
A.	R02_PHASE_03	tests/Feature/BudgetThresholdNotificationTest.php
A.	R02_PHASE_03	tests/Feature/Concerns/CreatesPhaseThreeContext.php
A.	R02_PHASE_03	tests/Feature/DocumentGenerationFailureTest.php
A.	R02_PHASE_03	tests/Feature/ExpenseFormRequestTest.php
A.	R02_PHASE_03	tests/Feature/ExpenseLegacyRedirectTest.php
A.	R02_PHASE_03	tests/Feature/ExpenseRouteAuthorizationTest.php
A.	R02_PHASE_03	tests/Feature/OverdueSupplierInvoiceNotificationTest.php
A.	R02_PHASE_03	tests/Feature/PhaseThreeClosureTest.php
A.	R02_PHASE_03	tests/Feature/PhaseThreeCrossResidenceIsolationTest.php
A.	R02_PHASE_03	tests/Feature/PhaseThreeExpensesTest.php
A.	R02_PHASE_03	tests/Feature/PhaseThreeMySqlConcurrencyTest.php
A.	R02_PHASE_03	tests/Feature/PhaseThreeNotificationPreferenceTest.php
A.	R02_PHASE_03	tests/Feature/PhaseThreePortalSecurityTest.php
A.	R02_PHASE_03	tests/Feature/PublicationFailureTest.php
A.	R02_PHASE_03	tests/Feature/SupplierContractAttachmentTest.php
A.	R02_PHASE_03	tests/Feature/SupplierContractRenewalTest.php
A.	R03_PHASE_04	app/Http/Controllers/MaintenanceAttachmentController.php
A.	R03_PHASE_04	app/Http/Controllers/MaintenanceController.php
A.	R03_PHASE_04	app/Http/Controllers/MaintenanceQuotationController.php
A.	R03_PHASE_04	app/Http/Controllers/MaintenanceRequestActionController.php
A.	R03_PHASE_04	app/Http/Controllers/MaintenanceWorkOrderController.php
A.	R03_PHASE_04	app/Http/Controllers/PreventiveMaintenanceController.php
A.	R03_PHASE_04	app/Http/Controllers/ResidentMaintenanceController.php
A.	R03_PHASE_04	app/Models/MaintenanceAssignment.php
A.	R03_PHASE_04	app/Models/MaintenanceAttachment.php
A.	R03_PHASE_04	app/Models/MaintenanceCategory.php
A.	R03_PHASE_04	app/Models/MaintenanceEquipment.php
A.	R03_PHASE_04	app/Models/MaintenanceQuotation.php
A.	R03_PHASE_04	app/Models/MaintenanceRequest.php
A.	R03_PHASE_04	app/Models/MaintenanceRequestTransition.php
A.	R03_PHASE_04	app/Models/MaintenanceRequestUpdate.php
A.	R03_PHASE_04	app/Models/MaintenanceSlaEvent.php
A.	R03_PHASE_04	app/Models/MaintenanceWorkOrder.php
A.	R03_PHASE_04	app/Models/PreventiveIntervention.php
A.	R03_PHASE_04	app/Models/PreventiveMaintenancePlan.php
A.	R03_PHASE_04	app/Policies/Concerns/AuthorizesMaintenanceScope.php
A.	R03_PHASE_04	app/Policies/MaintenanceRequestPolicy.php
A.	R03_PHASE_04	app/Policies/MaintenanceWorkOrderPolicy.php
A.	R03_PHASE_04	app/Services/MaintenanceAttachmentService.php
A.	R03_PHASE_04	app/Services/MaintenanceNotificationService.php
A.	R03_PHASE_04	app/Services/MaintenanceQuotationWorkflow.php
A.	R03_PHASE_04	app/Services/MaintenanceRequestWorkflow.php
A.	R03_PHASE_04	app/Services/MaintenanceSlaService.php
A.	R03_PHASE_04	app/Services/MaintenanceWorkOrderWorkflow.php
A.	R03_PHASE_04	app/Services/PreventiveMaintenanceScheduler.php
A.	R03_PHASE_04	app/Services/WorkOrderInvoiceService.php
A.	R03_PHASE_04	database/migrations/2026_07_22_000900_create_phase_four_maintenance_tables.php
A.	R03_PHASE_04	resources/js/Components/Maintenance/MaintenanceNav.vue
A.	R03_PHASE_04	resources/js/Pages/Maintenance/Categories.vue
A.	R03_PHASE_04	resources/js/Pages/Maintenance/Dashboard.vue
A.	R03_PHASE_04	resources/js/Pages/Maintenance/Equipment/Index.vue
A.	R03_PHASE_04	resources/js/Pages/Maintenance/Equipment/Show.vue
A.	R03_PHASE_04	resources/js/Pages/Maintenance/Operations.vue
A.	R03_PHASE_04	resources/js/Pages/Maintenance/Preventive/Index.vue
A.	R03_PHASE_04	resources/js/Pages/Maintenance/Requests/Form.vue
A.	R03_PHASE_04	resources/js/Pages/Maintenance/Requests/Index.vue
A.	R03_PHASE_04	resources/js/Pages/Maintenance/Requests/Show.vue
A.	R03_PHASE_04	resources/js/Pages/Maintenance/WorkOrders/Index.vue
A.	R03_PHASE_04	resources/js/Pages/Portal/Maintenance/Index.vue
A.	R03_PHASE_04	resources/js/Pages/Portal/Maintenance/Show.vue
A.	R03_PHASE_04	tests/Feature/PhaseFourMaintenanceTest.php
A.	R03_PHASE_04	tests/Feature/PhaseFourMySqlConcurrencyTest.php
??	R04_PHASE_05	app/Exports/GovernanceRegisterExport.php
A.	R04_PHASE_05	app/Http/Controllers/GovernanceMandateController.php
A.	R04_PHASE_05	app/Http/Requests/Governance/AgendaItemRequest.php
A.	R04_PHASE_05	app/Http/Requests/Governance/AssemblyRequest.php
A.	R04_PHASE_05	app/Models/AgendaQuestionSubmission.php
A.	R04_PHASE_05	app/Models/AssemblyAgendaItem.php
A.	R04_PHASE_05	app/Models/AssemblyAttendanceEvent.php
A.	R04_PHASE_05	app/Models/AssemblyAttendanceRecord.php
A.	R04_PHASE_05	app/Models/AssemblyBallot.php
A.	R04_PHASE_05	app/Models/AssemblyMinuteVersion.php
A.	R04_PHASE_05	app/Models/AssemblyProxy.php
A.	R04_PHASE_05	app/Models/AssemblyProxyEvent.php
A.	R04_PHASE_05	app/Models/AssemblyQuorumSnapshot.php
A.	R04_PHASE_05	app/Models/AssemblyTransition.php
A.	R04_PHASE_05	app/Models/BallotCorrection.php
A.	R04_PHASE_05	app/Models/Convocation.php
A.	R04_PHASE_05	app/Models/ConvocationDeliveryAttempt.php
A.	R04_PHASE_05	app/Models/ConvocationRecipient.php
A.	R04_PHASE_05	app/Models/DecisionDeliveryAttempt.php
A.	R04_PHASE_05	app/Models/DecisionNotification.php
A.	R04_PHASE_05	app/Models/ElectorateCorrection.php
A.	R04_PHASE_05	app/Models/GovernanceDocument.php
A.	R04_PHASE_05	app/Models/GovernanceDocumentVersion.php
A.	R04_PHASE_05	app/Models/GovernanceMandate.php
A.	R04_PHASE_05	app/Models/ResolutionResult.php
A.	R04_PHASE_05	app/Models/ResolutionRuleSnapshot.php
A.	R04_PHASE_05	app/Policies/Concerns/AuthorizesGovernanceScope.php
A.	R04_PHASE_05	app/Queries/GovernanceReportingQuery.php
A.	R04_PHASE_05	app/Services/AssemblyWorkflow.php
A.	R04_PHASE_05	app/Services/DecisionNotificationService.php
A.	R04_PHASE_05	app/Services/GovernanceMandateService.php
A.	R04_PHASE_05	app/Services/GovernancePortalAccessService.php
A.	R04_PHASE_05	app/Services/QuorumService.php
A.	R04_PHASE_05	app/Services/VotingRuleEngine.php
A.	R04_PHASE_05	config/governance.php
A.	R04_PHASE_05	database/migrations/2026_07_22_001000_create_phase_five_governance_core.php
A.	R04_PHASE_05	database/migrations/2026_07_22_001100_create_phase_five_governance_session_and_documents.php
A.	R04_PHASE_05	database/migrations/2026_07_22_001200_create_phase_five_minutes_notifications_and_execution.php
A.	R04_PHASE_05	resources/js/Pages/Governance/Form.vue
A.	R04_PHASE_05	resources/js/Pages/Governance/Mandates.vue
A.	R04X_DOCUMENT_INTEGRITY	app/Console/Commands/RepairFinancialDocumentChecksums.php
A.	R04X_DOCUMENT_INTEGRITY	app/Exceptions/FinancialDocumentIntegrityException.php
A.	R04X_DOCUMENT_INTEGRITY	app/Http/Controllers/FinancialDocumentController.php
M.	R04X_DOCUMENT_INTEGRITY	app/Http/Controllers/ReceiptController.php
A.	R04X_DOCUMENT_INTEGRITY	app/Models/ChecksumRepairHistory.php
M.	R04X_DOCUMENT_INTEGRITY	app/Models/FinancialDocument.php
M.	R04X_DOCUMENT_INTEGRITY	app/Services/CollectionAuditService.php
A.	R04X_DOCUMENT_INTEGRITY	app/Services/FinancialDocumentChecksumService.php
A.	R04X_DOCUMENT_INTEGRITY	app/Services/FinancialDocumentMutationGuard.php
A.	R04X_DOCUMENT_INTEGRITY	app/Services/FinancialDocumentRecoveryService.php
A.	R04X_DOCUMENT_INTEGRITY	app/Services/FinancialDocumentRenderer.php
M.	R04X_DOCUMENT_INTEGRITY	app/Services/ReceiptService.php
A.	R04X_DOCUMENT_INTEGRITY	database/migrations/2026_07_24_000100_create_checksum_repair_histories.php
A.	R04X_DOCUMENT_INTEGRITY	tests/Feature/ChecksumRepairTest.php
??	R05_PHASE_06A	app/Console/Commands/AuditAccountingIntegrity.php
??	R05_PHASE_06A	app/Http/Controllers/AccountingController.php
??	R05_PHASE_06A	app/Models/AccountingAccountTemplate.php
??	R05_PHASE_06A	app/Models/AccountingActivityEvent.php
??	R05_PHASE_06A	app/Models/AccountingBook.php
??	R05_PHASE_06A	app/Models/AccountingFramework.php
??	R05_PHASE_06A	app/Models/AccountingJournal.php
??	R05_PHASE_06A	app/Models/AccountingPeriod.php
??	R05_PHASE_06A	app/Models/AccountingRegimeAssessment.php
??	R05_PHASE_06A	app/Models/JournalEntry.php
??	R05_PHASE_06A	app/Models/JournalEntryLine.php
??	R05_PHASE_06A	app/Models/LedgerAccount.php
??	R05_PHASE_06A	app/Services/AccountingConfigurationService.php
??	R05_PHASE_06A	app/Services/AccountingIntegrityAuditService.php
??	R05_PHASE_06A	app/Services/AccountingMutationGuard.php
??	R05_PHASE_06A	app/Services/AccountingPostingService.php
??	R05_PHASE_06A	app/Services/AccountingSourceStatusService.php
??	R05_PHASE_06A	database/migrations/2026_07_25_000200_create_phase_six_a_accounting_foundation.php
??	R05_PHASE_06A	resources/js/Pages/Accounting/Closing.vue
??	R05_PHASE_06A	resources/js/Pages/Accounting/Index.vue
??	R05_PHASE_06A	resources/js/Pages/Accounting/Reports.vue
??	R05_PHASE_06A	resources/js/Pages/Accounting/Show.vue
??	R05_PHASE_06A	tests/Feature/PhaseSixAccountingTest.php
??	R06_PHASE_06B	app/Console/Commands/AuditSourcePostings.php
??	R06_PHASE_06B	app/Http/Controllers/AccountingAutomationController.php
??	R06_PHASE_06B	app/Models/AccountingAutomation.php
??	R06_PHASE_06B	app/Models/AccountingOpeningBatch.php
??	R06_PHASE_06B	app/Models/AccountingOpeningLine.php
??	R06_PHASE_06B	app/Models/AccountingPostingRule.php
??	R06_PHASE_06B	app/Models/AccountingSourceMapping.php
??	R06_PHASE_06B	app/Models/AccountingSourcePosting.php
??	R06_PHASE_06B	app/Services/AccountingAutomationService.php
??	R06_PHASE_06B	app/Services/AccountingPostingConfigurationService.php
??	R06_PHASE_06B	app/Services/AutomatedAccountingPostingService.php
??	R06_PHASE_06B	app/Services/OpeningBalanceService.php
??	R06_PHASE_06B	app/Services/SourcePostingIntegrityAuditService.php
??	R06_PHASE_06B	database/migrations/2026_07_25_000300_create_phase_six_b_automated_accounting.php
??	R06_PHASE_06B	resources/js/Components/AccountingPostingStatus.vue
??	R06_PHASE_06B	tests/Feature/PhaseSixAutomatedAccountingTest.php
AM	X2_PHASE03_06B_BRIDGE	app/Http/Controllers/SupplierCreditNoteController.php
AM	X2_PHASE03_06B_BRIDGE	app/Http/Controllers/SupplierInvoiceController.php
AM	X2_PHASE03_06B_BRIDGE	app/Http/Controllers/SupplierSettlementController.php
AM	X2_PHASE03_06B_BRIDGE	app/Services/CreditNoteWorkflow.php
AM	X2_PHASE03_06B_BRIDGE	app/Services/SupplierInvoiceWorkflow.php
AM	X2_PHASE03_06B_BRIDGE	app/Services/SupplierSettlementWorkflow.php
AM	X2_PHASE03_06B_BRIDGE	resources/js/Pages/SupplierCreditNotes/Show.vue
AM	X2_PHASE03_06B_BRIDGE	resources/js/Pages/SupplierInvoices/Show.vue
AM	X2_PHASE03_06B_BRIDGE	resources/js/Pages/SupplierSettlements/Show.vue
??	R07_PHASE_06C	app/Console/Commands/AuditAccountingReports.php
??	R07_PHASE_06C	app/Exports/AccountingReportExport.php
??	R07_PHASE_06C	app/Http/Controllers/AccountingReportController.php
??	R07_PHASE_06C	app/Services/AccountingReportIntegrityAuditService.php
??	R07_PHASE_06C	app/Services/AccountingReportService.php
??	R07_PHASE_06C	resources/views/pdf/accounting-report.blade.php
??	R07_PHASE_06C	tests/Feature/PhaseSixAccountingReportsTest.php
??	R08_PHASE_06D	app/Console/Commands/AuditAccountingCarryForwards.php
??	R08_PHASE_06D	app/Console/Commands/AuditAccountingClosingPackages.php
??	R08_PHASE_06D	app/Console/Commands/AuditAccountingClosingReadiness.php
??	R08_PHASE_06D	app/Http/Controllers/AccountingClosingController.php
??	R08_PHASE_06D	app/Models/AccountingClosingAccountClassification.php
??	R08_PHASE_06D	app/Models/AccountingClosingConfiguration.php
??	R08_PHASE_06D	app/Models/AccountingClosingPackage.php
??	R08_PHASE_06D	app/Models/AccountingClosingPeriodSnapshot.php
??	R08_PHASE_06D	app/Models/AccountingClosingTransition.php
??	R08_PHASE_06D	app/Services/AccountingCarryForwardAuditService.php
??	R08_PHASE_06D	app/Services/AccountingClosingAuditService.php
??	R08_PHASE_06D	app/Services/AccountingClosingConfigurationService.php
??	R08_PHASE_06D	app/Services/AccountingClosingReadinessService.php
??	R08_PHASE_06D	app/Services/AccountingClosingWorkflowService.php
??	R08_PHASE_06D	database/migrations/2026_07_25_000400_create_phase_six_d_accounting_closing.php
??	R08_PHASE_06D	resources/views/pdf/accounting-closing-evidence.blade.php
??	R08_PHASE_06D	tests/Feature/PhaseSixAccountingClosingTest.php
??	R09_PHASE_06E_UNSPLIT	app/Http/Controllers/ComplianceController.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceApplicabilityDecision.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceApplicabilityProfile.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceAuthority.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceDeadlineOverride.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceEscalationOccurrence.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceEvidence.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceEvidenceVersion.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceObligation.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceObligationAssignment.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceObligationTransition.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceReminderOccurrence.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceReminderPolicy.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceSource.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceSubmission.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceTemplate.php
??	R09_PHASE_06E_UNSPLIT	app/Models/ComplianceTemplateVersion.php
??	R09_PHASE_06E_UNSPLIT	app/Services/ComplianceApplicabilityService.php
??	R09_PHASE_06E_UNSPLIT	app/Services/ComplianceAuditService.php
??	R09_PHASE_06E_UNSPLIT	app/Services/ComplianceDeadlineService.php
??	R09_PHASE_06E_UNSPLIT	app/Services/ComplianceEvidenceService.php
??	R09_PHASE_06E_UNSPLIT	app/Services/ComplianceObligationWorkflow.php
??	R09_PHASE_06E_UNSPLIT	app/Services/ComplianceOccurrenceService.php
??	R09_PHASE_06E_UNSPLIT	app/Services/ComplianceReminderService.php
??	R09_PHASE_06E_UNSPLIT	app/Services/ComplianceTemplateWorkflow.php
??	R09_PHASE_06E_UNSPLIT	database/migrations/2026_07_25_000500_create_phase_six_e_compliance.php
??	R09_PHASE_06E_UNSPLIT	database/migrations/2026_07_26_000700_harden_phase_six_compliance_concurrency.php
??	R09_PHASE_06E_UNSPLIT	resources/js/Pages/Compliance/Index.vue
??	R09_PHASE_06E_UNSPLIT	resources/js/Pages/Compliance/Show.vue
??	R09_PHASE_06E_UNSPLIT	resources/views/pdf/compliance-register.blade.php
??	R09_PHASE_06E_UNSPLIT	tests/Feature/PhaseSixComplianceTest.php
??	R10_PHASE_07	app/Http/Controllers/GovernanceExportController.php
??	R10_PHASE_07	app/Http/Controllers/GovernanceRuleController.php
??	R10_PHASE_07	app/Http/Controllers/PhaseSevenGovernanceController.php
??	R10_PHASE_07	app/Jobs/DispatchConvocationAvailability.php
??	R10_PHASE_07	app/Models/AssemblyAgendaVersion.php
??	R10_PHASE_07	app/Models/AssemblyEligibilitySnapshot.php
??	R10_PHASE_07	app/Models/AssemblyMinutesApproval.php
??	R10_PHASE_07	app/Models/AssemblyParticipant.php
??	R10_PHASE_07	app/Models/AssemblyResolutionTransition.php
??	R10_PHASE_07	app/Models/AssemblySecretBallotAggregate.php
??	R10_PHASE_07	app/Models/GovernanceRule.php
??	R10_PHASE_07	app/Models/GovernanceRuleSource.php
??	R10_PHASE_07	app/Models/GovernanceVotingShareSource.php
??	R10_PHASE_07	app/Models/ResolutionExecutionEvent.php
??	R10_PHASE_07	app/Services/AgendaVersionService.php
??	R10_PHASE_07	app/Services/GovernanceDeliveryScheduler.php
??	R10_PHASE_07	app/Services/GovernanceExportService.php
??	R10_PHASE_07	app/Services/GovernanceIntegrityAuditService.php
??	R10_PHASE_07	app/Services/GovernanceRuleWorkflow.php
??	R10_PHASE_07	app/Services/PhaseSevenEligibilityService.php
??	R10_PHASE_07	app/Services/PhaseSevenGovernanceWorkflow.php
??	R10_PHASE_07	database/migrations/2026_07_26_000600_harden_phase_seven_governance.php
??	R10_PHASE_07	database/seeders/PhaseSevenQaSeeder.php
??	R10_PHASE_07	resources/js/Pages/Governance/Diagnostics.vue
??	R10_PHASE_07	resources/js/Pages/Governance/Rules.vue
??	R10_PHASE_07	resources/views/pdf/governance-register.blade.php
??	R10_PHASE_07	tests/Feature/PhaseSevenGovernanceTest.php
??	R10_PHASE_07	tests/Feature/PhaseSevenHardeningTest.php
AM	X3_PHASE05_07_BRIDGE	app/Http/Controllers/GovernanceActionController.php
AM	X3_PHASE05_07_BRIDGE	app/Http/Controllers/GovernanceController.php
AM	X3_PHASE05_07_BRIDGE	app/Http/Controllers/GovernanceDocumentController.php
AM	X3_PHASE05_07_BRIDGE	app/Http/Controllers/OwnerGovernanceController.php
AM	X3_PHASE05_07_BRIDGE	app/Models/Assembly.php
AM	X3_PHASE05_07_BRIDGE	app/Models/AssemblyElectorate.php
AM	X3_PHASE05_07_BRIDGE	app/Models/AssemblyMinutes.php
AM	X3_PHASE05_07_BRIDGE	app/Models/AssemblyResolution.php
AM	X3_PHASE05_07_BRIDGE	app/Models/GovernanceRuleVersion.php
AM	X3_PHASE05_07_BRIDGE	app/Models/ResolutionExecutionAction.php
AM	X3_PHASE05_07_BRIDGE	app/Policies/AssemblyPolicy.php
AM	X3_PHASE05_07_BRIDGE	app/Services/AgendaQuestionService.php
AM	X3_PHASE05_07_BRIDGE	app/Services/AgendaService.php
AM	X3_PHASE05_07_BRIDGE	app/Services/AttendanceProxyService.php
AM	X3_PHASE05_07_BRIDGE	app/Services/BallotService.php
AM	X3_PHASE05_07_BRIDGE	app/Services/ConvocationService.php
AM	X3_PHASE05_07_BRIDGE	app/Services/ElectorateSnapshotService.php
AM	X3_PHASE05_07_BRIDGE	app/Services/GovernanceDeadlineService.php
AM	X3_PHASE05_07_BRIDGE	app/Services/GovernanceDocumentService.php
AM	X3_PHASE05_07_BRIDGE	app/Services/GovernanceNotificationService.php
AM	X3_PHASE05_07_BRIDGE	app/Services/GovernanceRuleService.php
AM	X3_PHASE05_07_BRIDGE	app/Services/MinutesService.php
AM	X3_PHASE05_07_BRIDGE	app/Services/ResolutionExecutionService.php
AM	X3_PHASE05_07_BRIDGE	resources/js/Components/Governance/GovernanceNav.vue
AM	X3_PHASE05_07_BRIDGE	resources/js/Pages/Governance/Dashboard.vue
AM	X3_PHASE05_07_BRIDGE	resources/js/Pages/Governance/Index.vue
AM	X3_PHASE05_07_BRIDGE	resources/js/Pages/Governance/OwnerIndex.vue
AM	X3_PHASE05_07_BRIDGE	resources/js/Pages/Governance/OwnerShow.vue
AM	X3_PHASE05_07_BRIDGE	resources/js/Pages/Governance/Show.vue
AM	X3_PHASE05_07_BRIDGE	resources/views/pdf/governance-convocation.blade.php
AM	X3_PHASE05_07_BRIDGE	resources/views/pdf/governance-minutes.blade.php
AM	X3_PHASE05_07_BRIDGE	tests/Feature/PhaseFiveGovernanceTest.php
M.	R11_SHARED_INTEGRATION	app/Http/Controllers/ChargeCategoryController.php
.M	R11_SHARED_INTEGRATION	app/Http/Controllers/ContextController.php
M.	R11_SHARED_INTEGRATION	app/Http/Controllers/Controller.php
.M	R11_SHARED_INTEGRATION	app/Http/Controllers/FundCallController.php
M.	R11_SHARED_INTEGRATION	app/Http/Controllers/OccupancyController.php
.M	R11_SHARED_INTEGRATION	app/Http/Controllers/PaymentController.php
M.	R11_SHARED_INTEGRATION	app/Http/Controllers/StructureController.php
M.	R11_SHARED_INTEGRATION	app/Http/Middleware/HandleInertiaRequests.php
M.	R11_SHARED_INTEGRATION	app/Models/Contact.php
.M	R11_SHARED_INTEGRATION	app/Models/FinancialExercise.php
M.	R11_SHARED_INTEGRATION	app/Models/Lot.php
.M	R11_SHARED_INTEGRATION	app/Services/FundCallWorkflow.php
.M	R11_SHARED_INTEGRATION	app/Services/MembershipAuthorization.php
.M	R11_SHARED_INTEGRATION	app/Services/PaymentWorkflow.php
M.	R11_SHARED_INTEGRATION	bootstrap/app.php
M.	R11_SHARED_INTEGRATION	database/seeders/DemoSeeder.php
.M	R11_SHARED_INTEGRATION	lang/ar.json
.M	R11_SHARED_INTEGRATION	resources/js/Pages/Finance/FundCalls/Show.vue
.M	R11_SHARED_INTEGRATION	resources/js/Pages/Finance/Payments/Show.vue
MM	X4_GLOBAL_OVERLAP	app/Providers/AppServiceProvider.php
MM	X4_GLOBAL_OVERLAP	app/Services/ActivityScope.php
MM	X4_GLOBAL_OVERLAP	config/evosyndic.php
MM	X4_GLOBAL_OVERLAP	resources/js/i18n.ts
MM	X4_GLOBAL_OVERLAP	resources/js/Layouts/AuthenticatedLayout.vue
MM	X4_GLOBAL_OVERLAP	routes/console.php
MM	X4_GLOBAL_OVERLAP	routes/web.php
```
