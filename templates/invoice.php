<?php
/**
 * Invoice Template
 * 
 * @var \HP_PDFI\Invoice $invoice
 * @var \WC_Order $order
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$printer_friendly = $invoice->printer_friendly;
$show_paid_price = $invoice->show_paid_price;
$logo_id = get_option( 'hp_pdfi_logo' );
if ( ! $logo_id ) {
	$wpo_settings = get_option( 'wpo_wcpdf_settings_general' );
	$logo_id = isset( $wpo_settings['header_logo'] ) ? $wpo_settings['header_logo'] : '';
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
		if ( ! empty( $wpo_settings['shop_address_line_2']['default'] ) ) $shop_address .= "\n" . $wpo_settings['shop_address_line_2']['default'];
		$city_line = array_filter( array(
			$wpo_settings['shop_address_city']['default'] ?? '',
			$wpo_settings['shop_address_state']['default'] ?? '',
			$wpo_settings['shop_address_postcode']['default'] ?? '',
		) );
		if ( ! empty( $city_line ) ) $shop_address .= "\n" . implode( ' ', $city_line );
		if ( ! empty( $wpo_settings['shop_address_country']['default'] ) ) $shop_address .= "\n" . $wpo_settings['shop_address_country']['default'];
	}
}

$prefix = get_option( 'hp_pdfi_invoice_prefix', '' );
?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
	<style>
		body {
			font-family: 'dejavu sans', sans-serif;
			font-size: 10pt;
			color: #333;
			margin: 0;
			padding: 0;
		}
		table {
			width: 100%;
			border-collapse: collapse;
			margin-bottom: 20px;
		}
		th, td {
			padding: 8px;
			text-align: left;
			vertical-align: top;
		}
		.header-table td {
			padding: 0;
		}
		.logo {
			max-height: 80px;
			max-width: 250px;
		}
		.shop-info {
			text-align: right;
		}
		.document-type-label {
			font-size: 24pt;
			margin: 20px 0;
			<?php if ( ! $printer_friendly ) : ?>
			background: #000;
			color: #fff;
			padding: 10px;
			<?php endif; ?>
		}
		.order-data-addresses td {
			width: 33%;
		}
		.order-details th {
			<?php if ( ! $printer_friendly ) : ?>
			background: #eee;
			<?php endif; ?>
			border-bottom: 2px solid #ccc;
		}
		.order-details td {
			border-bottom: 1px solid #eee;
		}
		.price-column {
			text-align: right;
			white-space: nowrap;
		}
		.quantity-column {
			text-align: center;
		}
		.totals-table {
			width: 60%;
			float: right;
		}
		.totals-table th {
			text-align: right;
			width: 70%;
		}
		.totals-table td {
			text-align: right;
			font-weight: bold;
			width: 30%;
		}
		.discount-summary {
			margin-top: 30px;
			border-top: 1px solid #ccc;
			padding-top: 10px;
		}
		.discount-summary h3 {
			font-size: 12pt;
			margin-bottom: 5px;
		}
		.discount-line {
			font-weight: bold;
		}
		.strikethrough {
			text-decoration: line-through;
			color: #999;
			font-size: 0.9em;
		}
		.product-image {
			width: 40px;
			height: 40px;
			margin-right: 10px;
			vertical-align: middle;
		}
		<?php if ( $printer_friendly ) : ?>
		.logo {
			filter: grayscale(100%);
		}
		<?php endif; ?>
	</style>
</head>
<body>

<table class="header-table">
	<tr>
		<td>
			<?php if ( $logo_url ) : ?>
				<img src="<?php echo $logo_url; ?>" class="logo">
			<?php else : ?>
				<h2><?php echo $shop_name; ?></h2>
			<?php endif; ?>
		</td>
		<td class="shop-info">
			<strong><?php echo $shop_name; ?></strong><br>
			<?php echo nl2br( $shop_address ); ?>
		</td>
	</tr>
</table>

<h1 class="document-type-label"><?php _e( 'INVOICE', 'hp-pdf-invoices' ); ?></h1>

<table class="order-data-addresses">
	<tr>
		<td>
			<h3><?php _e( 'Billing Address', 'hp-pdf-invoices' ); ?></h3>
			<?php echo $order->get_formatted_billing_address(); ?><br>
			<?php echo $order->get_billing_email(); ?><br>
			<?php echo $order->get_billing_phone(); ?>
		</td>
		<td>
			<h3><?php _e( 'Shipping Address', 'hp-pdf-invoices' ); ?></h3>
			<?php echo $order->get_formatted_shipping_address(); ?>
		</td>
		<td>
			<table>
				<tr>
					<th><?php _e( 'Invoice Number:', 'hp-pdf-invoices' ); ?></th>
					<td><?php echo $prefix . $order->get_order_number(); ?></td>
				</tr>
				<tr>
					<th><?php _e( 'Invoice Date:', 'hp-pdf-invoices' ); ?></th>
					<td><?php echo date_i18n( get_option( 'date_format' ) ); ?></td>
				</tr>
				<tr>
					<th><?php _e( 'Order Number:', 'hp-pdf-invoices' ); ?></th>
					<td><?php echo $order->get_order_number(); ?></td>
				</tr>
				<tr>
					<th><?php _e( 'Order Date:', 'hp-pdf-invoices' ); ?></th>
					<td><?php echo date_i18n( get_option( 'date_format' ), strtotime( $order->get_date_created() ) ); ?></td>
				</tr>
				<tr>
					<th><?php _e( 'Payment Method:', 'hp-pdf-invoices' ); ?></th>
					<td><?php echo $order->get_payment_method_title(); ?></td>
				</tr>
			</table>
		</td>
	</tr>
</table>

<table class="order-details">
	<thead>
		<tr>
			<th><?php _e( 'Product', 'hp-pdf-invoices' ); ?></th>
			<th class="quantity-column"><?php _e( 'Qty', 'hp-pdf-invoices' ); ?></th>
			<th class="price-column"><?php _e( 'Price', 'hp-pdf-invoices' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $invoice->get_order_items() as $item ) : ?>
			<tr>
				<td>
					<?php if ( $item['image'] ) : ?>
						<img src="<?php echo $item['image']; ?>" class="product-image">
					<?php endif; ?>
					<strong><?php echo $item['name']; ?></strong><br>
					<small>SKU: <?php echo $item['sku']; ?></small>
				</td>
				<td class="quantity-column"><?php echo $item['quantity']; ?></td>
				<td class="price-column">
					<?php if ( ! $show_paid_price && $item['has_discount'] ) : ?>
						<span class="strikethrough"><?php echo $item['original_price']; ?></span><br>
					<?php endif; ?>
					<?php echo $item['price']; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<table class="totals-table">
	<?php foreach ( $invoice->get_totals() as $total ) : ?>
		<tr class="<?php echo isset( $total['class'] ) ? esc_attr( $total['class'] ) : ''; ?>">
			<th><?php echo $total['label']; ?></th>
			<td><?php echo $total['value']; ?></td>
		</tr>
	<?php endforeach; ?>
</table>

<div style="clear: both;"></div>

<?php if ( $order->get_customer_note() ) : ?>
	<div class="customer-notes">
		<h3><?php _e( 'Customer Notes', 'hp-pdf-invoices' ); ?></h3>
		<?php echo nl2br( $order->get_customer_note() ); ?>
	</div>
<?php endif; ?>

</body>
</html>

