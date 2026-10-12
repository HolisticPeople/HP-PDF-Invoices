<?php
declare(strict_types=1);
define('ABSPATH', __DIR__);
function absint($value): int { return abs((int) $value); }
function __($text, $domain = '') { return $text; }
require_once dirname(__DIR__) . '/includes/RefundMemo.php';
class CustomerMemo extends HP_PDFI\RefundMemo {
    public function reason(string $value, string $reference = ''): string { return $this->get_customer_refund_reason($value, $reference); }
    public function gateway(string $value): string { return $this->get_customer_gateway_label($value); }
}
function same($expected, $actual, string $message): void {
    if ($expected !== $actual) { throw new RuntimeException($message . ': ' . var_export($actual, true)); }
}
$memo = new CustomerMemo(null);
$prefix = 'Refund via Enhanced Admin Order - HolisticPeople.com - Order# 131907';
same('', $memo->reason($prefix . ' | Store credit to refund: $144.00'), 'Generated bookkeeping hidden');
same('Damaged item', $memo->reason($prefix . ' | Points to refund: 10 | Reason: Damaged item'), 'Actual reason retained');
same('Return | box damaged', $memo->reason($prefix . ' | Reason: Return | box damaged'), 'Reason separators retained');
same('Damaged item', $memo->reason($prefix . ' | Reason: Damaged item | Stripe refunds re_example (Live)', 're_example'), 'Provider suffix hidden');
same('Damaged item', $memo->reason($prefix . ' | Reason: Damaged item | Gateway reference example', 'example'), 'Gateway suffix hidden');
foreach (['Return requested | Stripe refunds have not arrived', 'Please retry | PayPal refund was not received', 'Gateway dispute | Gateway reference was incorrect'] as $reason) {
    same($reason, $memo->reason($prefix . ' | Reason: ' . $reason, 're_other'), 'Actual reason collision preserved');
}
same('Customer requested return', $memo->reason('Customer requested return'), 'Ordinary reason retained');
same('Stripe (EAO Live)', $memo->gateway('Stripe (EAO Live)'), 'Older/absent Core is fail-soft');
$core = $argv[1] ?? '';
if ($core === '' || !is_file($core . '/includes/Services/OrderPaymentDisplay.php')) {
    throw new RuntimeException('Pass the actual Core source checkout to verify the public display contract.');
}
require_once $core . '/includes/Services/OrderPaymentDisplay.php';
same('Credit card', $memo->gateway('Stripe (EAO Live)'), 'Cash refund route normalized');
same('Credit card', $memo->gateway('eao_stripe_live'), 'Gateway ID normalized');
same('PayPal', $memo->gateway('PayPal (EAO Invoice)'), 'PayPal route normalized');
same('Bank transfer', $memo->gateway('Bank transfer'), 'Other tenders retained');
same('', $memo->gateway(''), 'Store-credit-only refund has no invented cash route');
echo "Refund document customer labels, reason preservation and optional Core contracts passed.\n";
