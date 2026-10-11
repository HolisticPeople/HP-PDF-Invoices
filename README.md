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
