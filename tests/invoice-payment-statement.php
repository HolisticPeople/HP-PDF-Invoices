<?php
declare(strict_types=1);
define('ABSPATH', __DIR__);
function __($text, $domain = '') { return $text; }
function wc_get_price_decimals() { return 2; }
function wc_price($value, $options = []) { return '$' . number_format($value, 2, '.', ','); }
function wp_json_encode($value) { return json_encode($value); }
function wp_strip_all_tags($value) { return strip_tags($value); }
function same($expected, $actual, $message) { if ($expected !== $actual) { throw new RuntimeException($message . ': ' . var_export($actual, true)); } }
class WC_Order {
    public function __construct(public array $meta = [], public string $status = 'pending', public float $total = 656.28) {}
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function get_id() { return 42; }
    public function get_status() { return $this->status; }
    public function get_currency() { return 'USD'; }
    public function get_shipping_total() { return 0; }
    public function get_total_tax() { return 0; }
    public function get_total_paid() { return 0; }
    public function get_fees() { return []; }
    public function get_items($type = '') { return []; }
}
$mode = 'valid';
$reads = 0;
if (($argv[2] ?? '') !== 'absent-owner') { function hp_core_order_collection_snapshot_v1($order) {
    $GLOBALS['reads']++;
    $base = ['contract_version'=>'2.0', 'available'=>true, 'order_id'=>42, 'currency'=>'usd', 'minor_unit'=>2, 'balance_state'=>'unpaid', 'exception_codes'=>[], 'cash_settled'=>0, 'outstanding'=>65628];
    switch ($GLOBALS['mode']) {
        case 'paid': return array_replace($base, ['cash_settled'=>55117, 'outstanding'=>0, 'balance_state'=>'paid']);
        case 'cent': return array_replace($base, ['outstanding'=>1, 'balance_state'=>'partially_paid']);
        case 'wrong_order': return array_replace($base, ['order_id'=>43]);
        case 'wrong_currency': return array_replace($base, ['currency'=>'eur']);
        case 'negative': return array_replace($base, ['outstanding'=>-1]);
        case 'malformed': return array_replace($base, ['cash_settled'=>'bad']);
        case 'unknown_state': return array_replace($base, ['balance_state'=>'banana']);
        case 'exception': return array_replace($base, ['balance_state'=>'exception']);
        case 'unavailable': return ['available'=>false];
        case 'throws': throw new RuntimeException('Owner unavailable');
        default: return $base;
    }
}
}
$core = $argv[1] ?? '';
if (!is_file($core . '/includes/Services/OrderPaymentDisplay.php')) { throw new RuntimeException('Pass actual Core source checkout.'); }
require_once $core . '/includes/PaymentMeta.php';
require_once $core . '/includes/Services/OrderPaymentDisplay.php';
require_once dirname(__DIR__) . '/includes/Invoice.php';
class InvoiceFixture extends HP_PDFI\Invoice {
    public function get_items_total_gross_amount() { return $this->order->total; }
    public function get_total_product_discount_amount() { return 0.0; }
    public function get_admin_discount_amount() { return 0.0; }
    public function get_additional_charge_amount() { return 0.0; }
}
$selection = ['_eao_current_points_discount'=>['points'=>388,'amount'=>38.80], '_eao_pending_points_to_redeem'=>388, '_eao_pending_store_credit_to_apply'=>144];
if (($argv[2] ?? '') === 'absent-owner') {
    $invoice = new InvoiceFixture(new WC_Order([], 'delivered', 632.67));
    same('Requires payment review', $invoice->get_totals()['payment_status']['value'], 'Absent owner must not infer debt');
    same('Requires payment review', array_column($invoice->get_raw_totals(), null, 'key')['balance_due']['text_value'], 'Absent owner export retains review warning');
    echo "Absent collection owner requires review in every export.\n";
    exit(0);
}
$order = new WC_Order($selection);
$before = serialize($order);
$invoice = new InvoiceFixture($order);
$rows = $invoice->get_totals();
same('$656.28', $rows['balance_due']['value'], 'Owner balance before selected tender');
same('-$38.80', $rows['points_selected']['value'], '388 selected points');
same('-$144.00', $rows['credit_selected']['value'], 'Selected credit included');
same('$473.48', $rows['cash_after_selections']['value'], 'Cash after future Wallet application');
same('Wallet selections pending', $rows['payment_status']['value'], 'Selection is not settlement');
same(false, isset($rows['points_discount']), 'No falsely redeemed points');
$numeric = array_column($invoice->get_raw_totals(), null, 'key');
same(473.48, $numeric['cash_after_selections']['raw_value'], 'Word/Excel share conditional cash amount');
same('Cash due after Wallet application', $numeric['cash_after_selections']['label'], 'Numeric exports retain the pending qualifier');
same('Wallet selections pending', $numeric['payment_status']['text_value'], 'Word/Excel retain pending status');
same(1, $reads, 'One cached read-only collection snapshot');
same($before, serialize($order), 'Export performs no order mutation');
$mode = 'paid';
$paid = new WC_Order(['_hp_wallet_points_redeemed'=>815, '_hp_wallet_points_discount'=>81.50], 'delivered', 632.67);
$rows = (new InvoiceFixture($paid))->get_totals();
same('-$551.17', $rows['payments_received']['value'], 'Both verified cash receipts displayed');
same('$0.00', $rows['balance_due']['value'], 'Paid historical order has no fabricated debt');
same('Paid in Full', $rows['payment_status']['value'], 'Paid backed by owner snapshot');
$mode = 'cent';
$rows = (new InvoiceFixture(new WC_Order()))->get_totals();
same('$0.01', $rows['balance_due']['value'], 'One minor unit remains due');
same('Due $0.01', $rows['payment_status']['value'], 'One cent cannot be marked paid');
$mode = 'valid';
$settled = new WC_Order($selection + ['_hp_wallet_points_redeemed'=>388, '_hp_wallet_points_discount'=>38.80, '_hp_wallet_credit_applied'=>144]);
$rows = (new InvoiceFixture($settled))->get_totals();
same(false, isset($rows['wallet_selected']), 'Stale intent not deducted again alongside settled tender');
foreach (['cancelled', 'failed', 'refunded', 'processing', 'completed', 'shipped', 'delivered'] as $status) {
    $rows = (new InvoiceFixture(new WC_Order($selection, $status)))->get_totals();
    same(false, isset($rows['wallet_selected']), 'No pending tender on inactive/finalized order ' . $status);
}
foreach (['wrong_order', 'wrong_currency', 'negative', 'malformed', 'unknown_state', 'exception', 'unavailable', 'throws'] as $case) {
    $mode = $case;
    $invoice = new InvoiceFixture(new WC_Order($selection));
    $rows = $invoice->get_totals();
    same('Requires payment review', $rows['balance_due']['value'], 'Invalid owner cannot fabricate debt ' . $case);
    same('Requires payment review', $rows['cash_after_selections']['value'], 'Invalid owner cannot quote future cash ' . $case);
    same('Requires payment review', $rows['payment_status']['value'], 'No false Paid or Due status ' . $case);
    $raw = array_column($invoice->get_raw_totals(), null, 'key');
    same('Requires payment review', $raw['balance_due']['text_value'], 'Word/Excel retain review balance ' . $case);
    same(false, isset($raw['balance_due']['raw_value']), 'Review is never numeric zero ' . $case);
}
$mode = 'valid';
$rows = (new InvoiceFixture(new WC_Order(['_eao_pending_points_to_redeem'=>300])))->get_totals();
same('Value requires review', $rows['points_selected']['value'], 'Count alone does not assume conversion rate');
same('Requires payment review', $rows['cash_after_selections']['value'], 'Unknown points value cannot become a cash quote');
foreach ([['points'=>388,'amount'=>[38.8]], ['points'=>[388],'amount'=>38.8], ['points'=>388.5,'amount'=>38.8], ['points'=>388,'amount'=>INF], ['points'=>388,'amount'=>-1]] as $bad) {
    $invoice = new InvoiceFixture(new WC_Order(['_eao_current_points_discount'=>$bad]));
    $raw = array_column($invoice->get_raw_totals(), null, 'key');
    same('Value requires review', $raw['points_selected']['text_value'], 'Malformed selection cannot fabricate value');
    same('Requires payment review', $raw['cash_after_selections']['text_value'], 'Malformed selection cannot fabricate cash quote');
}
$invoice = new InvoiceFixture(new WC_Order(['_eao_pending_store_credit_to_apply'=>[144]]));
same('Value requires review', $invoice->get_totals()['credit_selected']['value'], 'Malformed credit is not silently zeroed');
echo "Invoice collection receipts, pending Wallet display, replay and invalid-owner contracts passed.\n";
