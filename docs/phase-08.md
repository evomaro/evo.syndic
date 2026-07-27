# EvoSyndic Phase 08 — Release Hardening and Pilot Readiness

> **Status:** Approved Phase 08 specification (G0 approved). This document defines release-hardening work and completion gates. Approval starts Phase 08 planning and G1 review only; it does not record implementation, authorize deployment, certify legal compliance, or approve a pilot.

## Purpose

Phase 08 converts the implemented Phase 01–07 system into a controlled, reviewable release candidate for a limited pilot. It is a hardening, verification, packaging, and operational-readiness phase. It must preserve the tenant, authorization, accounting, audit, document-integrity, governance, and compliance controls already established.

Phase 08 begins after G0 approval. G1 establishes the Phase 01–07 source boundary described below. Pilot deployment requires G1–G8 to pass; passing technical gates does not substitute for legal, accounting-professional, privacy, operational, or business approval.

## Baseline

The release candidate is expected to include the implemented Phase 01–07 capabilities:

- Phase 01: tenant-aware organizations and residences, property structure, contacts, ownership and occupancy history, allocation keys, imports, onboarding, roles, activity, localization, and dashboards.
- Phase 02: operational charges, fund calls, payments, allocations, receipts, owner statements, recurring generation, exercise controls, and collection audits.
- Phase 03: suppliers, commitments, invoices, settlements, credit notes, budgets, contracts, resident documents and announcements, notification preferences, and the resident portal.
- Phase 04: corrective and preventive maintenance, equipment, requests, assignments, quotations, work orders, attachments, SLA events, notifications, and invoice handoff.
- Phase 05: governance foundations, mandates, assemblies, agenda, convocations, attendance, proxies, quorum, ballots, resolutions, minutes, delivery, and execution.
- Phase 06A–06E: accounting configuration and journals, automated source posting, reporting and reconciliation, closing and carry-forward controls, and compliance obligations, evidence, reminders, calendars, and exports.
- Phase 07: governance rule provenance, eligibility snapshots, voting-share sources, quorum confirmation, secret-ballot aggregation, challenges, minutes approval, finalization, audits, exports, and delivery hardening.

This baseline is descriptive only. The approved Phase 01–07 merge manifest is authoritative.

## Scope

### 1. Source and release-boundary review

- Reconcile every staged, unstaged, and untracked repository path against an approved Phase 01–07 change manifest.
- Divide inherited changes into dependency-complete review units without losing user work.
- Review database migrations in execution order and verify that code, schema, routes, permissions, translations, tests, jobs, commands, and assets land together.
- Remove generated, local-only, temporary, secret-bearing, or unverifiable artifacts from the proposed release boundary without deleting them from the working tree.
- Produce an immutable release-candidate identifier from an approved commit only after separate authorization.

### 2. Release validation

- Execute the full Phase 01–07 automated suite under PHP 8.3 using explicitly named disposable SQLite and MySQL databases.
- Rehearse migrations from both an empty database and a representative Phase 01–07 predecessor schema.
- Repeat MySQL-only locking tests required to demonstrate concurrency guarantees.
- Exercise critical French and Arabic/RTL browser journeys using synthetic pilot data.
- Verify queues, scheduler jobs, notifications with non-delivering transports, exports, PDFs, private documents, public verification endpoints, and read-only audit commands.
- Validate frontend types, formatting, production assets, PHP formatting and syntax, dependency metadata, translations, and whitespace.

### 3. Security and privacy hardening

- Review authentication, session security, password reset, invitation, tenant resolution, residence restrictions, and role/permission boundaries.
- Re-run cross-organization and cross-residence authorization matrices for financial, supplier, maintenance, governance, accounting, compliance, export, attachment, and portal endpoints.
- Verify CSRF protection, rate limiting, signed or hashed tokens, non-disclosing error responses, secure cookie and proxy configuration, production HTTPS enforcement, and security headers.
- Verify that private storage cannot be served directly and that every private download rechecks current authorization, scope, version, and checksum.
- Review upload extension, MIME, size, filename, formula-injection, archive, and malicious-content handling. Any malware-scanning requirement remains an unresolved deployment decision.
- Confirm secrets are absent from tracked files, logs, exports, exceptions, queue payloads, client bundles, and release artifacts.
- Run dependency vulnerability review for Composer and npm. Findings require documented disposition; severity must not be silently waived.
- Verify audit events are append-only at application boundaries and contain no authentication tokens, plaintext invitation tokens, or unnecessary personal data.
- Complete a privacy data-flow, retention, export, correction, deletion/restriction, and backup-retention review before real pilot data is admitted.

### 4. Performance and capacity

- Record a reproducible baseline using production-like PHP, database, cache, queue, storage, and web-server configuration.
- Exercise representative residence sizes and high-volume cases for lots, contacts, ownership periods, fund-call lines, payments, supplier invoices, maintenance requests, assemblies, journal lines, compliance obligations, exports, and notifications.
- Measure database query count, slow queries, response time, memory, queue latency, export duration and size, PDF duration, scheduler duration, and concurrent write behavior.
- Confirm pagination and bounded generation on all potentially large registers and calendars.
- Explain and approve every performance exception before pilot.

Provisional pilot budgets, subject to approval after representative pilot volumes are approved:

| Workload | Proposed acceptance budget |
|---|---:|
| Common authenticated page, p95 server response | ≤ 750 ms |
| Ordinary transactional write, p95 | ≤ 1.5 s |
| Filtered register/report screen, p95 | ≤ 3 s |
| Approved maximum-size export or PDF | ≤ 30 s or asynchronous |
| Queue wait under normal pilot load, p95 | ≤ 60 s |
| Scheduler cycle | Completes before its next scheduled interval |
| Unexplained application 5xx during acceptance run | 0 |

These budgets are not current performance claims and cannot become completion criteria until pilot volumes are approved. The pilot owner must approve representative data volumes and concurrency before approving the final thresholds.

### 5. Monitoring and operational readiness

- Centralize structured application, web-server, PHP, queue, scheduler, database, and security-event logs with environment, release, organization-safe correlation, and request identifiers.
- Define dashboards and alerts for HTTP availability and error rate, latency, queue depth and age, failed jobs, scheduler heartbeat, database connections and storage, slow queries, disk capacity, private-file failures, mail delivery state, and backup outcomes.
- Exclude secrets and unnecessary personal or legally sensitive data from logs and telemetry.
- Run and retain results from collection, expense, accounting, source-posting, accounting-report, closing, carry-forward, governance, and compliance audits where applicable.
- Provide runbooks for incident declaration, tenant isolation incidents, financial-integrity findings, document checksum failures, queue backlog, scheduler failure, failed migration, failed deployment, backup restore, notification suppression, and pilot suspension.
- Assign named primary and backup responders, escalation channels, coverage hours, and severity targets before pilot.

## Exclusions

Phase 08 does not include:

- New product modules or material feature development.
- Reinterpretation or silent expansion of Phase 01–07 business rules.
- Phase 09 planning.
- Activation of unverified accounting, compliance, governance, voting, quorum, deadline, or notification rules.
- Legal, tax, accounting, privacy, cybersecurity, or professional certification by the engineering team.
- Production data correction, migration, import, seeding, or inspection without a separately approved data-change plan.
- Use, mutation, restoration, or claimed validation of the repository's default database.
- Unapproved destructive cleanup of the inherited worktree.
- Sending real email, SMS, push notifications, or external submissions during rehearsal.
- Committing, merging, pushing, deploying, or opening a pilot merely because this specification is approved.

## Phase 01–07 merge-boundary procedure

The current worktree contains inherited staged, unstaged, and untracked changes spanning multiple phases. It must not be reviewed or merged as one opaque change.

### Step 1: Freeze and inventory

Without modifying the worktree, capture:

```bash
git status --porcelain=v2 --branch
git diff --name-status
git diff --cached --name-status
git ls-files --others --exclude-standard
git diff --check
```

Record the current branch and base commit, and hash the inventory itself. Do not stage, restore, reset, clean, or reformat during inventory.

### Step 2: Establish provenance

Assign each path and each overlapping hunk to one of these review groups:

1. Phase 01–02 prerequisite repairs.
2. Phase 03 expenses and portal.
3. Phase 04 maintenance.
4. Phase 05 governance foundation.
5. Phase 06A accounting foundation.
6. Phase 06B automation.
7. Phase 06C reports and reconciliation.
8. Phase 06D closing and carry-forward.
9. Phase 06E compliance.
10. Phase 07 governance hardening.
11. Shared integration: routes, permissions, middleware, layouts, translations, scheduler, seeders, and common services.
12. Excluded artifacts: local environment files, databases, generated builds, temporary evidence, credentials, logs, caches, and unrelated user work.

For every path, record owner, purpose, originating phase, dependencies, tests, migration dependency, security impact, and whether it is staged, unstaged, or untracked. Files touched by multiple phases must be split by reviewed hunks or assigned to the earliest dependency-complete integration unit with an explicit rationale.

### Step 3: Construct review units outside the inherited worktree

After explicit authorization, construct candidate patch sets in a separate temporary clone or worktree rooted at the recorded base commit. Do not use destructive Git commands in the inherited worktree.

Each unit must be independently reviewable and must include all required schema, model, service, authorization, route, UI, translation, command/job, and test changes. A unit must never contain:

- A migration without the code that safely supports it.
- A route without its authorization and tenant controls.
- A write workflow without focused tests and audit behavior.
- A UI action whose backend permission is absent.
- Generated or local configuration presented as source.

Proposed cumulative review order:

1. Phase 01–02 prerequisite repairs.
2. Phase 03.
3. Phase 04.
4. Phase 05.
5. Phase 06A, then 06B, 06C, 06D, and 06E.
6. Phase 07.
7. Shared release integration and documentation.

The exact split remains subject to dependency review; ordering must not be changed merely to obtain smaller diffs.

### Step 4: Validate each cumulative boundary

Run focused tests for the unit, then the full cumulative suite. Review migration ordering, tenant isolation, authorization, accounting integrity, audit coverage, translations, and generated assets at every boundary. Record commands, runtime versions, database identifiers, assertions, failures, skips, and reviewer decisions.

### Step 5: Approve the final boundary

The Phase 01–07 merge boundary is safe only when:

- Every included path and hunk appears in the approved manifest.
- Every excluded path has a recorded reason and remains preserved.
- No untracked production source is accidentally omitted.
- The candidate applies cleanly to the approved base.
- All cumulative migrations and validations pass.
- Security-sensitive and accounting-sensitive changes receive designated review.
- The candidate diff is free of credentials, local databases, test evidence, build residue, and unrelated changes.
- The candidate review worktree is clean after the approved commits are constructed.
- Required technical reviewers approve the boundary.

This procedure defines a future review workflow; it does not authorize Git mutation now.

## Database migration rehearsal

All rehearsal databases must be disposable and explicitly named. The default database and any database whose provenance is uncertain are prohibited.

Required rehearsals:

1. **Fresh installation:** apply all migrations to an empty disposable MySQL database.
2. **Upgrade path:** restore an approved, sanitized or synthetic Phase 01–07 predecessor fixture to a disposable database and apply pending migrations.
3. **Rollback mechanics:** test only migrations documented as reversible. A destructive or data-transforming migration requires a forward-fix plan instead of an unsafe rollback claim.
4. **Backup restoration:** restore a rehearsal backup to a second disposable database and verify schema, row-count controls, checksums where applicable, and application smoke tests.
5. **Interrupted deployment:** simulate an interruption at an approved migration boundary and prove the documented recovery path.
6. **Concurrency:** run required MySQL locking matrices with independent processes and connections.
7. **Volume:** rehearse migrations at approved pilot-scale data volumes while measuring locks and duration.

Before execution, record:

- PHP, Composer, database server and client versions.
- Source and target schema identifiers.
- Migration list and checksums.
- Backup identifier, encryption state, retention and restore owner.
- Expected lock behavior and maintenance-window budget.
- Row-count and integrity controls.
- Abort and escalation criteria.

No production migration proceeds if rehearsal evidence is missing, the migration duration exceeds the approved window, integrity checks differ, or rollback/forward-recovery ownership is unclear.

## Deployment and rollback safety

### Release preparation

- Build once from an approved clean commit and identify the artifact by commit and checksum.
- Install locked production dependencies and compile production frontend assets in a controlled build environment.
- Verify environment variables through a redacted checklist; never copy development secrets.
- Confirm persistent private storage, backup, queue, scheduler, HTTPS, trusted proxy, mail suppression/enablement, and cache configuration.
- Run pre-deployment read-only audits and capture their results.
- Confirm migration rehearsal, capacity, monitoring, responders, and rollback decision authority.

### Deployment sequence

The approved runbook must define:

1. Pilot change freeze and stakeholder notice.
2. Verified database and private-storage backup.
3. Queue drain or controlled pause.
4. Maintenance-mode decision.
5. Release artifact installation.
6. Migration execution with captured output.
7. Cache/config optimization.
8. Queue and scheduler restart.
9. Read-only integrity audits and role-based smoke tests.
10. Monitoring observation window.
11. Explicit go/no-go decision.

### Rollback strategy

- Prefer application rollback only when the prior application is schema-compatible.
- Never reverse a data migration merely because a `down()` method exists; use the rehearsed recovery plan.
- If the new schema is backward-compatible, redeploy the prior verified artifact and repeat smoke/audit checks.
- If it is not backward-compatible, stop writes and execute the approved forward-fix or backup-restore plan.
- Preserve failed-release logs, artifacts, migration output, audit evidence, and incident timestamps.
- Define pilot data reconciliation before reopening writes.

Rollback authority, maximum decision time, acceptable pilot-data loss, restore-time objective, and restore-point objective remain approval decisions.

## Legal and professional review placeholders

The following reviews are mandatory placeholders and remain **Pending** until signed by an authorized human reviewer:

| Review | Required evidence | Reviewer | Decision/date |
|---|---|---|---|
| Moroccan copropriété governance rules | Sources, effective dates, quorum/voting/proxy/convocation/minutes interpretation and limitations | TBD | Pending |
| Accounting framework and regime | Applicable framework, account catalogue, opening, posting, reporting, closing, carry-forward and annex treatment | TBD licensed professional | Pending |
| Supplier, tax and financial documents | Invoice, credit-note, settlement, receipt, numbering, retention and export requirements | TBD | Pending |
| Compliance catalogue | Authority, source, applicability, deadlines, evidence, submission and reminder rules | TBD counsel/domain owner | Pending |
| Privacy and data protection | Legal basis, notices, processors, retention, access, correction, deletion/restriction, exports, backups and incident duties | TBD | Pending |
| Electronic delivery and verification | Email/SMS/push consent, evidentiary status, availability notices, verification pages and delivery records | TBD | Pending |
| Pilot terms and support | Participant agreement, limitations, support, suspension, data exit and incident communications | TBD business/legal owners | Pending |

Each approval must identify jurisdiction, source/version, reviewer identity and authority, date, outcome, conditions, expiry/review date, and retained evidence. Engineering tests may verify that approved configurations are enforced; they cannot create the approval.

Until approval:

- Rules and templates requiring professional review remain unverified, draft, inactive, or technically blocked.
- Exports and PDFs must not claim certification or legal sufficiency.
- Synthetic-data technical rehearsal may continue, but real-data pilot activation may not.

## Pilot acceptance criteria

### Technical

- Approved Phase 01–07 merge manifest and clean release candidate.
- PHP 8.3 platform check, full isolated suite, focused MySQL concurrency profiles, browser matrix, migrations, queues, scheduler, exports and PDFs pass with recorded evidence.
- No unresolved critical/high security vulnerability; lower findings have approved disposition.
- No known tenant-isolation, authorization, accounting-integrity, audit, private-document, or data-loss defect.
- Approved performance budgets pass at approved pilot volume.
- Backup restoration and release rollback are successfully rehearsed.

### Operational

- Monitoring, alert delivery, dashboards, log retention and scheduler heartbeat are demonstrated.
- Incident, rollback, integrity, notification-suppression and pilot-suspension runbooks are exercised.
- Named release owner, database owner, security contact, legal contact, accounting reviewer, support responders, and go/no-go authority are available.
- Pilot residences, users, roles, support hours, data volume, duration, and exit plan are approved.

### Functional

- Critical staff and resident journeys pass in French and Arabic/RTL at desktop and supported mobile widths.
- Permission and tenant-isolation matrices pass for every pilot role.
- Financial, supplier, maintenance, governance, accounting, compliance, notification, export and private-document smoke tests pass as applicable to the pilot configuration.
- Pilot seed/import procedure is reviewed, retryable, reconcilable and does not rely on demo credentials or the default database.

### Legal and business

- All legal/professional placeholders applicable to real pilot data are approved with retained evidence.
- Pilot participant terms, privacy notice, processors, support model, suspension criteria and data-exit process are approved.
- Product owner accepts documented limitations and prohibited/unverified features remain disabled.

## Validation commands

These are templates for a controlled environment. Resolve PHP 8.3 and Composer from the execution environment; do not embed workstation-specific paths in a release runbook. Record the resolved paths and versions. Never run migration or seed commands without explicit disposable database variables.

### Runtime and dependencies

```bash
PHP83_BIN="$(command -v php)"
COMPOSER_BIN="$(command -v composer)"
test -n "$PHP83_BIN" && test -n "$COMPOSER_BIN"
"$PHP83_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'
"$PHP83_BIN" --version
"$PHP83_BIN" "$COMPOSER_BIN" --version
"$PHP83_BIN" "$COMPOSER_BIN" validate --strict --no-check-publish
"$PHP83_BIN" "$COMPOSER_BIN" check-platform-reqs
"$PHP83_BIN" "$COMPOSER_BIN" audit --locked
npm audit --omit=dev
```

If the default `php` on `PATH` is not PHP 8.3, the release operator must first provide an environment whose `PATH` resolves the approved PHP 8.3 binary. The version assertion must pass before any Artisan, Composer, test, or audit command runs.

### Full isolated suite

```bash
APP_ENV=testing \
DB_CONNECTION=sqlite \
DB_DATABASE=/private/tmp/evosyndic-phase08-full.sqlite \
"$PHP83_BIN" -d memory_limit=512M vendor/bin/phpunit --colors=never
```

Focused MySQL and independent-process concurrency commands must use an explicitly created database matching:

```text
evosyndic_phase08_disposable_<purpose>
```

They must never inherit `DB_DATABASE` from `.env`.

### Disposable migration rehearsal

```bash
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=evosyndic_phase08_disposable_fresh \
"$PHP83_BIN" artisan migrate:fresh --force --no-interaction

APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=evosyndic_phase08_disposable_rollback \
"$PHP83_BIN" artisan migrate --force --no-interaction

APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=evosyndic_phase08_disposable_rollback \
"$PHP83_BIN" artisan migrate:rollback --step=1 --force --no-interaction

APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=evosyndic_phase08_disposable_rollback \
"$PHP83_BIN" artisan migrate --force --no-interaction
```

Migration rollback is evidence only for the migrations covered by the approved rehearsal plan.

### Static and frontend validation

```bash
vendor/bin/pint --test
npm run format:check
npm run lint
npm run build
find app bootstrap config database routes tests -name '*.php' -print0 | xargs -0 -n1 "$PHP83_BIN" -l
git diff --check
```

### Read-only application audits

Before running any Artisan command, obtain the command catalogue from the same release candidate and verify every exact command name:

```bash
"$PHP83_BIN" artisan list --raw
```

Run only names present in that output. Applicable audit commands must use explicit disposable database variables and scoped identifiers:

```bash
"$PHP83_BIN" artisan evosyndic:audit-collections --json
"$PHP83_BIN" artisan evosyndic:audit-expenses --json
"$PHP83_BIN" artisan evosyndic:audit-accounting --json
"$PHP83_BIN" artisan evosyndic:audit-source-postings --json
"$PHP83_BIN" artisan evosyndic:audit-accounting-reports --json
"$PHP83_BIN" artisan accounting:audit-closing-readiness --json
"$PHP83_BIN" artisan accounting:audit-closing-packages --json
"$PHP83_BIN" artisan accounting:audit-carry-forwards --json
"$PHP83_BIN" artisan governance:audit-assemblies --json
"$PHP83_BIN" artisan compliance:audit-templates --json
```

Command availability is not a legal or data-integrity approval.

## Completion gates

| Gate | Requirement | Approval |
|---|---|---|
| G0 — Specification | Phase 08 scope, exclusions, pilot shape, performance budgets and owners approved | Product/technical owners |
| G1 — Merge boundary | Phase 01–07 manifest reviewed; clean dependency-complete candidate constructed | Technical reviewers |
| G2 — Build and regression | PHP 8.3, full suite, browser, concurrency, exports, PDFs and static checks pass | Technical owner |
| G3 — Database safety | Fresh/upgrade/rollback or forward-fix/backup-restore rehearsals pass | Database owner |
| G4 — Security and privacy | Threat review, tenant matrix, dependency review and privacy controls accepted | Security/privacy owners |
| G5 — Performance and operations | Budgets, monitoring, alerting, runbooks and responder exercise pass | Operations owner |
| G6 — Deployment safety | Artifact, deployment, observation and rollback rehearsal accepted | Release owner |
| G7 — Professional/legal | Applicable accounting, governance, compliance, privacy and pilot approvals recorded | Authorized reviewers |
| G8 — Pilot go/no-go | Scope, participants, limitations, support, suspension and exit accepted | Named go/no-go authority |

Phase 08 work begins when G0 is approved. Phase 08 is complete, and pilot deployment may be considered, only when G1–G8 are satisfied with retained evidence. A gate may be **Passed**, **Failed**, or **Blocked**; “not tested,” missing provenance, and pending approval are **Blocked**, not passed.

Technical readiness, merge readiness, deployment readiness, legal/professional readiness, production readiness, and pilot authorization must always be reported separately.

## Unresolved decisions requiring approval

1. Approved Phase 01–07 base commit and reviewers for the inherited-change manifest.
2. Whether review units will become separate commits, separate pull requests, or one dependency-ordered series.
3. Pilot hosting environment, region, tenancy model, domain, TLS termination, cache, queue, storage, mail, and monitoring providers.
4. Supported browsers, devices, mobile widths, languages, accessibility target, and browser-matrix ownership.
5. Pilot residences, participant count, role mix, representative data volumes, duration, support hours, and prohibited features.
6. Final performance budgets, load profile, concurrency, restore-time objective, restore-point objective, and acceptable deployment window.
7. Backup encryption, retention, restore ownership, and pilot-data reconciliation rules.
8. Malware-scanning, security-testing, penetration-testing, vulnerability-severity, and exception policies.
9. Monitoring vendor, log/metric retention, alert thresholds, responder rotation, and incident-severity targets.
10. Notification channels permitted in pilot and the approval process for leaving non-delivering mode.
11. Legal/professional reviewers and the evidence required for each placeholder.
12. Privacy controller/processor roles, data residency, retention, participant exit, and deletion/restriction procedure.
13. Release, rollback, pilot suspension, and final go/no-go decision authorities.
