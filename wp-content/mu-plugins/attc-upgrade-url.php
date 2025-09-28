<?php
/**
 * Mu-plugin: Force ATT Converter upgrade URL to the upgrade page permalink.
 */

if (!defined('ABSPATH')) { exit; }

add_filter('attc_upgrade_url', function($url, $user_id){
    // Ưu tiên lấy permalink theo slug trang đã tạo bởi plugin chính
    $page = get_page_by_path('nang-cap');
    if ($page) {
        $p = get_permalink($page);
        if (!empty($p)) return $p;
    }
    // Fallback cứng
    return home_url('/nang-cap/');
}, 10, 2);
