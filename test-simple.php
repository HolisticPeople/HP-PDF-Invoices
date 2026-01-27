<?php
/**
 * Simple test - generates a minimal DOCX for download
 */
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

$phpWord = new PhpWord();
$section = $phpWord->addSection();
$section->addText('Hello World - Simple Test');

$temp_file = sys_get_temp_dir() . '/test-simple-' . time() . '.docx';
$writer = IOFactory::createWriter($phpWord, 'Word2007');
$writer->save($temp_file);

$content = file_get_contents($temp_file);
unlink($temp_file);

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="test-simple.docx"');
header('Content-Length: ' . strlen($content));
echo $content;
