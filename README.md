# HP Invoices

## 3.0.6

Refund memos use Core's public customer payment label contract for gateway
refund descriptions and show the actual refund reason without the recognized
EAO bookkeeping envelope. Original refund records and amounts remain intact.
Invoice PDF, DOCX and XLSX titles consume WooCommerce's view getter, including
Core's shared normalization; stored gateway IDs/titles remain audit data.

Invoice statements use the public Core Collection V2 cash receipts and
outstanding amount. Saved Wallet selections appear separately as pending,
including the cash due after future application; they do not appear as redeemed
points or applied credit. Invalid/unavailable collection evidence shows payment
review instead of inventing a Paid/Due conclusion. No settlement is written.

Verify with PHP8.5:

```powershell
php tests/admin-zen-ownership.php
php tests/refund-customer-display.php C:/path/to/HP-Core-Infrastructure
php tests/invoice-payment-statement.php C:/path/to/HP-Core-Infrastructure
php tests/invoice-payment-statement.php C:/path/to/HP-Core-Infrastructure absent-owner
```

The cross-owner checks require the actual matching Core source and verify both
absent/older Core fallback and the public read-only label integration.
Production PDF/page appearance and mobile UI need separate visual evidence.

Production verification12 October2026:3.0.6 at
`12c1c8d457e30ada493d9f103c9ea04c0d7ae421`, workflow38209350158, matched all12
tracked runtime files. All pages of actual paid/pending invoices and a store-credit
refund memo were inspected; authenticated invoice and memo links downloaded.
Paid collection showed zero due, customer payment wording was normalized, and
refund bookkeeping stayed out of the displayed reason. Records were unchanged.

Legacy pending points with a count but no saved monetary valuation correctly
show `Value requires review` and conditional cash `Requires payment review`.
Do not substitute a hardcoded conversion or the editor's current preview for a
saved quote. `tests/invoice-payment-statement.php` includes this negative case.
Known limitation assigned to the PDF owner: the aggregate review flag also hides
the independently verified balance before selections. A successor should keep
that known balance visible while limiting review to the missing valuation and
dependent cash figure, with a regression for valid collection plus count-only
points. This proposed improvement is not implemented by3.0.6; no rollback is
needed for the fail-closed display. The dedicated development-guide gap and
preexisting PHP8.5 DOCX Unicode sanitizer warning remain PDF-owner follow-ups.
Human operational/device acceptance is separate. Canonical production receipt:
HP-Codex-Skills `skills/hp-go-live/references/customer-payment-display-20261012-production.md`.
