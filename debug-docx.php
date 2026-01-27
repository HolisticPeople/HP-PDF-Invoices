<?php
/**
 * Debug DOCX generation - test each section
 */
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../../wp-load.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;

$order_id = isset($_GET['order']) ? absint($_GET['order']) : 129174;
$order = wc_get_order($order_id);

if (!$order) {
    die("Order not found");
}

// Test each section
$sections_to_test = ['header', 'title', 'addresses', 'products', 'totals', 'notes'];
$test_section = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : 'all';

function sanitizeText($text) {
    if (empty($text)) return '';
    $text = (string) $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(array("\xC2\xA0", '&nbsp;'), ' ', $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

try {
    $phpWord = new PhpWord();
    $phpWord->setDefaultFontName('Arial');
    $phpWord->setDefaultFontSize(10);
    $section = $phpWord->addSection();

    // Test header
    if ($test_section === 'all' || $test_section === 'header') {
        $section->addText('HEADER TEST');
        $shop_name = get_bloginfo('name');
        $section->addText(sanitizeText($shop_name), array('bold' => true));
    }

    // Test title  
    if ($test_section === 'all' || $test_section === 'title') {
        $section->addTextBreak();
        $section->addText('INVOICE', array('bold' => true, 'size' => 18));
    }

    // Test addresses
    if ($test_section === 'all' || $test_section === 'addresses') {
        $section->addTextBreak();
        $section->addText('ADDRESSES TEST');
        
        $billing = $order->get_formatted_billing_address();
        $billing_lines = explode('<br/>', $billing);
        foreach ($billing_lines as $line) {
            $section->addText(sanitizeText($line));
        }
        
        $section->addText('Email: ' . sanitizeText($order->get_billing_email()));
        $section->addText('Phone: ' . sanitizeText($order->get_billing_phone()));
    }

    // Test products
    if ($test_section === 'all' || $test_section === 'products') {
        $section->addTextBreak();
        $section->addText('PRODUCTS TEST');
        
        $table = $section->addTable(array('borderSize' => 6, 'borderColor' => 'CCCCCC'));
        $table->addRow();
        $table->addCell(4000)->addText('Product', array('bold' => true));
        $table->addCell(1500)->addText('SKU', array('bold' => true));
        $table->addCell(1000)->addText('Qty', array('bold' => true));
        $table->addCell(1500)->addText('Price', array('bold' => true));
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $table->addRow();
            $table->addCell(4000)->addText(sanitizeText($item->get_name()));
            $table->addCell(1500)->addText($product ? sanitizeText($product->get_sku()) : '');
            $table->addCell(1000)->addText($item->get_quantity());
            $table->addCell(1500)->addText(sanitizeText(wc_price($item->get_total() / $item->get_quantity())));
        }
    }

    // Test totals - THIS IS LIKELY THE PROBLEM AREA
    if ($test_section === 'all' || $test_section === 'totals') {
        $section->addTextBreak();
        $section->addText('TOTALS TEST');
        
        // Raw subtotal
        $subtotal_display = $order->get_subtotal_to_display();
        $section->addText('Raw subtotal HTML: ' . substr($subtotal_display, 0, 100));
        $section->addText('Sanitized subtotal: ' . sanitizeText($subtotal_display));
        
        // Raw total
        $total_display = $order->get_formatted_order_total();
        $section->addText('Raw total HTML: ' . substr($total_display, 0, 100));
        $section->addText('Sanitized total: ' . sanitizeText($total_display));
        
        // Shipping
        $shipping_display = $order->get_shipping_to_display();
        $section->addText('Shipping: ' . sanitizeText($shipping_display));
    }

    // Test notes
    if ($test_section === 'all' || $test_section === 'notes') {
        $section->addTextBreak();
        $section->addText('NOTES TEST');
        $notes = $order->get_customer_note();
        if ($notes) {
            $section->addText(sanitizeText($notes));
        } else {
            $section->addText('No customer notes');
        }
    }

    // Save
    $temp_file = sys_get_temp_dir() . '/debug-docx-' . time() . '.docx';
    $writer = IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($temp_file);

    if (file_exists($temp_file)) {
        $content = file_get_contents($temp_file);
        $size = strlen($content);
        unlink($temp_file);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="debug-' . $test_section . '.docx"');
        header('Content-Length: ' . $size);
        echo $content;
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString();
}
