# Phase 02 hardening requirement matrix

This matrix records the 2026-07-21 audit. It is limited to Phase 02.

| Risk area | Finding before hardening | Implemented evidence |
|---|---|---|
| Money | Centimes were consistent, but grouped input failed and ambiguous grouping was normalized | Strict parser, reusable positive-money rule and boundary datasets |
| Numbering/concurrency | Sequence rows were locked, but first creation could race | Insert-if-absent, locked increment and scoped unique numbers |
| Exercise lifecycle | Close checked only drafts; reopen overwrote metadata | Full readiness checks, unique open exercise, reopen history/activity and closed-write guards |
| Fund-call history | Core values were snapshotted but category, target and rounding evidence was incomplete | Per-charge immutable validation snapshot with target, recipient, category, weights and rounding |
| Cancellation | Allocated calls were blocked without locking all charges | Call/charge locks, post-lock allocation recheck, reason and open-period requirement |
| Allocation/reversal | Reversal evidence existed only on the payment | Allocation actor/date/reason/restored amount and unique original/reversal movements |
| Receipts | Failure rolled back validation, but retry was not idempotent | Renderer boundary, stable-number retry, unique subject/version and missing-file audit |
| QR | Token hashing/privacy existed without throttling | Neutral unknown response, token grammar, 30/min throttle and production HTTPS guard |
| Contact links | Pivot uniqueness existed without an authorized lifecycle | Owner/admin service, tenant checks, revocation and activity history |
| Owner visibility | Period filtering existed without inherited-debt distinction | Former/current rules, inherited debt marker and payer-private receipts |
| Statements | No opening balance/date/type/due-date and no CSV formula defense | Shared service, deterministic ordering, reconciled exports and CSV escaping |
| Aging | Last bucket was labelled `90+` | Exact 0/1/30/31/60/61/90/91-day dataset and `>90` label |
| Scheduling | Custom/month-end/failure evidence was absent | Custom intervals, clamping, template snapshots and retry state |
| Reconciliation | No read-only collection auditor | Scoped/JSON audit command with invariant and cross-tenant checks |
| MySQL evidence | Tests ran only on SQLite | Dedicated environment example and MySQL-only profile; no local MySQL execution claimed |
| Exercise-spanning credit | Every unallocated remainder blocked closing and later allocation required the original exercise to remain open | Identified advance credit closes cleanly, remains on its original payment/receipt, and can be allocated with an independent effective date to payer-owned charges in a later open exercise |
| Unidentified receipts | Anonymous unallocated funds were exposed through the same derived credit accessor | Separate operational unallocated amount, no automatic FIFO, no owner/dashboard credit, close blocker, and authorized payer-identification workflow |
| Cross-exercise reversal | Reversal required the original exercise to be open | Later allocations reverse in place; when the original exercise is closed, the compensating movement is recorded in the current open exercise |
