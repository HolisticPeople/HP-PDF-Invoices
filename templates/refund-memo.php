<?php
/**
 * Refund memo template.
 *
 * @var \HP_PDFI\RefundMemo $memo
 * @var \WC_Order           $order
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$logo_id = get_option( 'hp_pdfi_logo' );
if ( ! $logo_id ) {
	$wpo_settings = get_option( 'wpo_wcpdf_settings_general' );
	$logo_id      = isset( $wpo_settings['header_logo'] ) ? $wpo_settings['header_logo'] : '';
}
$logo_url = $logo_id ? wp_get_attachment_url( $logo_id ) : '';

$shop_name = get_option( 'hp_pdfi_shop_name' );
if ( ! $shop_name ) {
	$shop_name = get_bloginfo( 'name' );
}

$shop_address = get_option( 'hp_pdfi_shop_address' );
if ( ! $shop_address ) {
	$wpo_settings = get_option( 'wpo_wcpdf_settings_general' );
	if ( isset( $wpo_settings['shop_address_line_1']['default'] ) ) {
		$shop_address = $wpo_settings['shop_address_line_1']['default'];
		if ( ! empty( $wpo_settings['shop_address_line_2']['default'] ) ) {
			$shop_address .= "\n" . $wpo_settings['shop_address_line_2']['default'];
		}
		$city_line = array_filter(
			array(
				$wpo_settings['shop_address_city']['default'] ?? '',
				$wpo_settings['shop_address_state']['default'] ?? '',
				$wpo_settings['shop_address_postcode']['default'] ?? '',
			)
		);
		if ( ! empty( $city_line ) ) {
			$shop_address .= "\n" . implode( ' ', $city_line );
		}
		if ( ! empty( $wpo_settings['shop_address_country']['default'] ) ) {
			$shop_address .= "\n" . $wpo_settings['shop_address_country']['default'];
		}
	}
}
$shop_address_lines = preg_split( '/\R+/', (string) $shop_address );
$shop_address_lines = array_filter( array_map( 'trim', $shop_address_lines ) );
$shop_address_compact = implode( ', ', $shop_address_lines );

$groups       = $memo->get_refund_groups();
$summary      = $memo->get_summary();
$order_date   = $order->get_date_created() ? $memo->format_date( $order->get_date_created() ) : '';
$payment_name = $order->get_payment_method_title() ?: $order->get_payment_method();
?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
	<style>
		body {
			font-family: 'dejavu sans', sans-serif;
			font-size: 10pt;
			color: #24303f;
			margin: 0;
			padding: 0;
		}
		table {
			width: 100%;
			border-collapse: collapse;
			margin-bottom: 16px;
		}
		th,
		td {
			padding: 6px 8px;
			text-align: left;
			vertical-align: top;
		}
		.header-table td {
			padding: 0;
			vertical-align: top;
		}
		.logo-cell {
			width: 25%;
		}
		.logo {
			max-height: 72px;
			max-width: 210px;
		}
		.shop-info {
			width: 75%;
			text-align: right;
			line-height: 1.3;
			color: #4a5565;
			font-size: 9.5pt;
		}
		.shop-name {
			display: block;
			font-size: 11pt;
			font-weight: bold;
			color: #334155;
			white-space: nowrap;
		}
		.shop-address {
			display: block;
			white-space: nowrap;
		}
		.document-type-label {
			font-size: 22pt;
			margin: 16px 0 12px;
			background: #111827;
			color: #fff;
			padding: 12px 14px;
			letter-spacing: 0.03em;
		}
		.notice {
			border-left: 4px solid #2563eb;
			background: #eff6ff;
			padding: 10px 12px;
			margin-bottom: 18px;
			color: #1e3a8a;
		}
		.addresses td {
			width: 30%;
		}
		.addresses .memo-meta-cell {
			width: 40%;
		}
		.addresses h3,
		.refund-group h3,
		.summary-title {
			font-size: 11pt;
			margin: 0 0 6px;
			color: #111827;
		}
		.memo-meta {
			font-size: 9.5pt;
			line-height: 1.55;
		}
		.memo-meta-row {
			white-space: nowrap;
		}
		.memo-meta-label {
			display: inline-block;
			width: 82px;
			color: #4a5565;
			font-weight: normal;
		}
		.memo-meta-value {
			font-weight: bold;
		}
		.refund-group {
			margin-top: 14px;
			page-break-inside: avoid;
		}
		.refund-heading {
			background: #f3f4f6;
			border: 1px solid #d1d5db;
			padding: 8px 10px;
			margin: 0 0 0;
			font-size: 11pt;
		}
		.refund-details {
			border-left: 1px solid #d1d5db;
			border-right: 1px solid #d1d5db;
			margin-bottom: 0;
		}
		.refund-details th {
			width: 20%;
			color: #4a5565;
			font-weight: normal;
		}
		.lines-table {
			border: 1px solid #d1d5db;
			border-top: 0;
		}
		.lines-table th {
			background: #f9fafb;
			border-bottom: 1px solid #d1d5db;
			color: #374151;
		}
		.lines-table td {
			border-bottom: 1px solid #eef0f3;
		}
		.price-column {
			text-align: right;
			white-space: nowrap;
		}
		.quantity-column {
			text-align: center;
			white-space: nowrap;
		}
		.small-muted {
			color: #6b7280;
			font-size: 8.5pt;
		}
		.totals-table {
			width: 48%;
			float: right;
			margin-top: 8px;
			border: 1px solid #d1d5db;
		}
		.totals-table th {
			width: 62%;
			text-align: right;
			background: #f9fafb;
		}
		.totals-table td {
			width: 38%;
			text-align: right;
			font-weight: bold;
		}
		.footer-note {
			clear: both;
			margin-top: 24px;
			color: #4a5565;
			font-size: 8.5pt;
			border-top: 1px solid #d1d5db;
			padding-top: 8px;
		}
	</style>
</head>
<body>

<table class="header-table">
	<tr>
		<td class="logo-cell">
			<?php if ( $logo_url ) : ?>
				<img src="<?php echo esc_url( $logo_url ); ?>" class="logo">
			<?php else : ?>
				<h2><?php echo esc_html( $shop_name ); ?></h2>
			<?php endif; ?>
		</td>
		<td class="shop-info">
			<span class="shop-name"><?php echo esc_html( $shop_name ); ?></span>
			<span class="shop-address"><?php echo esc_html( $shop_address_compact ); ?></span>
		</td>
	</tr>
</table>

<h1 class="document-type-label"><?php esc_html_e( 'REFUND MEMO / RECEIPT', 'hp-pdf-invoices' ); ?></h1>

<div class="notice">
	<?php esc_html_e( 'This refund memo confirms a refund or credit against the original order. It is not a bill.', 'hp-pdf-invoices' ); ?>
</div>

<table class="addresses">
	<tr>
		<td>
			<h3><?php esc_html_e( 'Customer', 'hp-pdf-invoices' ); ?></h3>
			<?php echo wp_kses_post( $order->get_formatted_billing_address() ); ?><br>
			<?php echo esc_html( $order->get_billing_email() ); ?><br>
			<?php echo esc_html( $order->get_billing_phone() ); ?>
		</td>
		<td>
			<h3><?php esc_html_e( 'Ship To', 'hp-pdf-invoices' ); ?></h3>
			<?php echo wp_kses_post( $order->get_formatted_shipping_address() ); ?>
		</td>
		<td class="memo-meta-cell">
			<div class="memo-meta">
				<div class="memo-meta-row"><span class="memo-meta-label"><?php esc_html_e( 'Memo #:', 'hp-pdf-invoices' ); ?></span><span class="memo-meta-value"><?php echo esc_html( $memo->get_memo_number() ); ?></span></div>
				<div class="memo-meta-row"><span class="memo-meta-label"><?php esc_html_e( 'Memo Date:', 'hp-pdf-invoices' ); ?></span><span class="memo-meta-value"><?php echo esc_html( date_i18n( get_option( 'date_format' ) ) ); ?></span></div>
				<div class="memo-meta-row"><span class="memo-meta-label"><?php esc_html_e( 'Order #:', 'hp-pdf-invoices' ); ?></span><span class="memo-meta-value"><?php echo esc_html( $order->get_order_number() ); ?></span></div>
				<div class="memo-meta-row"><span class="memo-meta-label"><?php esc_html_e( 'Order Date:', 'hp-pdf-invoices' ); ?></span><span class="memo-meta-value"><?php echo esc_html( $order_date ); ?></span></div>
				<?php if ( $payment_name ) : ?>
					<div class="memo-meta-row"><span class="memo-meta-label"><?php esc_html_e( 'Payment:', 'hp-pdf-invoices' ); ?></span><span class="memo-meta-value"><?php echo esc_html( $payment_name ); ?></span></div>
				<?php endif; ?>
			</div>
		</td>
	</tr>
</table>

<?php foreach ( $groups as $group ) : ?>
	<?php
	$methods = array();
	if ( (float) $group['cash_amount'] > 0 ) {
		$methods[] = ! empty( $group['gateway'] )
			? sprintf( __( 'Original payment via %s', 'hp-pdf-invoices' ), $group['gateway'] )
			: __( 'Original payment method or manual refund', 'hp-pdf-invoices' );
	}
	if ( (float) $group['store_credit'] > 0 ) {
		$methods[] = __( 'HP Wallet store credit', 'hp-pdf-invoices' );
	}
	if ( (int) $group['points'] > 0 ) {
		$methods[] = __( 'HP Wallet points', 'hp-pdf-invoices' );
	}
	if ( empty( $methods ) ) {
		$methods[] = __( 'Order quantity/accounting adjustment', 'hp-pdf-invoices' );
	}
	?>
	<div class="refund-group">
		<h3 class="refund-heading"><?php echo esc_html( sprintf( __( 'Refund #%d', 'hp-pdf-invoices' ), $group['id'] ) ); ?></h3>
		<table class="refund-details">
			<tr>
				<th><?php esc_html_e( 'Refund Date', 'hp-pdf-invoices' ); ?></th>
				<td><?php echo esc_html( $group['date'] ); ?></td>
				<th><?php esc_html_e( 'Refund Method', 'hp-pdf-invoices' ); ?></th>
				<td><?php echo esc_html( implode( ', ', $methods ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Reason', 'hp-pdf-invoices' ); ?></th>
				<td><?php echo esc_html( $group['reason'] ? $group['reason'] : __( 'Not specified', 'hp-pdf-invoices' ) ); ?></td>
				<th><?php esc_html_e( 'Gateway Reference', 'hp-pdf-invoices' ); ?></th>
				<td><?php echo esc_html( $group['gateway_reference'] ? $group['gateway_reference'] : __( 'N/A', 'hp-pdf-invoices' ) ); ?></td>
			</tr>
		</table>

		<table class="lines-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Type', 'hp-pdf-invoices' ); ?></th>
					<th><?php esc_html_e( 'Refunded Item', 'hp-pdf-invoices' ); ?></th>
					<th class="quantity-column"><?php esc_html_e( 'Qty', 'hp-pdf-invoices' ); ?></th>
					<th class="price-column"><?php esc_html_e( 'Amount', 'hp-pdf-invoices' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $group['rows'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['type'] ); ?></td>
						<td>
							<strong><?php echo esc_html( $row['name'] ); ?></strong>
							<?php if ( ! empty( $row['sku'] ) ) : ?>
								<br><span class="small-muted"><?php echo esc_html( sprintf( __( 'SKU: %s', 'hp-pdf-invoices' ), $row['sku'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td class="quantity-column"><?php echo esc_html( $row['qty'] ); ?></td>
						<td class="price-column"><?php echo wp_kses_post( $memo->format_money( $row['amount'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endforeach; ?>

<table class="totals-table">
	<tr>
		<th><?php esc_html_e( 'Refunded line value:', 'hp-pdf-invoices' ); ?></th>
		<td><?php echo wp_kses_post( $memo->format_money( $summary['line_total'] ) ); ?></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Cash/payment refund:', 'hp-pdf-invoices' ); ?></th>
		<td><?php echo wp_kses_post( $memo->format_money( $summary['cash_amount'] ) ); ?></td>
	</tr>
	<?php if ( (float) $summary['store_credit'] > 0 ) : ?>
		<tr>
			<th><?php esc_html_e( 'Store credit restored:', 'hp-pdf-invoices' ); ?></th>
			<td><?php echo wp_kses_post( $memo->format_money( $summary['store_credit'] ) ); ?></td>
		</tr>
	<?php endif; ?>
	<?php if ( (int) $summary['points'] > 0 ) : ?>
		<tr>
			<th><?php esc_html_e( 'Points restored:', 'hp-pdf-invoices' ); ?></th>
			<td><?php echo esc_html( (int) $summary['points'] ); ?></td>
		</tr>
	<?php endif; ?>
	<?php if ( (int) $summary['quantity'] > 0 ) : ?>
		<tr>
			<th><?php esc_html_e( 'Quantity refunded:', 'hp-pdf-invoices' ); ?></th>
			<td><?php echo esc_html( (int) $summary['quantity'] ); ?></td>
		</tr>
	<?php endif; ?>
</table>

<div class="footer-note">
	<?php esc_html_e( 'Amounts are shown in the order currency. Keep this memo with the original order record for customer service and accounting reference.', 'hp-pdf-invoices' ); ?>
</div>

</body>
</html>
