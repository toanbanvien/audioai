<?php
// Test file để kiểm tra syntax của plugin
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Đang kiểm tra syntax của plugin...\n";

// Include file plugin để kiểm tra lỗi syntax
include_once 'wp-content/plugins/audio-to-text-converter/audio-to-text-converter.php';

echo "Syntax OK - Không có lỗi!\n";
?>
