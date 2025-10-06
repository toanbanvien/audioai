<?php

if (!defined('ABSPATH')) {
    exit; // Chặn truy cập trực tiếp
}

// ===== History Page Shortcode =====

function attc_history_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>Vui lòng <a href="/dang-nhap">đăng nhập</a> để xem lịch sử.</p>';
    }

    $user_id = get_current_user_id();
    $history = get_user_meta($user_id, 'attc_wallet_history', true);
    $display_name = wp_get_current_user()->display_name;
    $logout_url = wp_logout_url(get_permalink());

    ob_start();
    ?>
    <style>
        .attc-history-wrap { max-width: 800px; margin: 2rem auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .attc-userbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; background: #f0f4f8; border: 1px solid #e3e8ee; padding: 12px 16px; border-radius: 8px; }
        .attc-userbar .attc-username { margin: 0; font-weight: 600; color: #2c3e50;font-size:20px; }
        .attc-userbar .attc-logout-btn { background: #e74c3c; color: #fff !important; text-decoration: none; padding:0.6rem 1rem; border-radius: 6px; font-weight: 600; transition: background-color .2s; }
        .attc-userbar .attc-logout-btn:hover { background: #ff6b61; }
        .attc-history-table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
        .attc-history-table th, .attc-history-table td { padding: 12px 15px; border: 1px solid #e1e1e1; text-align: left; }
        .attc-history-table thead { background-color: #f5f5f5; }
        .attc-history-table th { font-weight: 600; }
        .attc-history-table tbody tr:nth-child(even) { background-color: #fafafa; }
        .attc-type-credit { color: #27ae60; font-weight: bold; }
        .attc-type-debit { color: #c0392b; font-weight: bold; }
        .attc-download-link { font-size: 0.9em; }
        .attc-transcript-content { background: #f9f9f9; border: 1px solid #eee; padding: 10px; margin-top: 10px; border-radius: 4px; max-height: 150px; overflow-y: auto; font-size: 0.95em; line-height: 1.5; }
        .attc-no-history { margin-top: 1.5rem; padding: 1.5rem; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: .25rem; text-align: center; color: #6c757d; }
        .upgrade-btn { background-color: #3498db; color: white; }
        .attc-userbar a { text-decoration: none; padding: 0.6rem 1rem; border-radius: 5px; font-weight: 500; transition: background-color 0.2s; }
        .attc-userbar .upgrade-btn { background-color: #3498db; color: white; }
        .attc-userbar .history-btn { background-color: #95a5a6; color: white; }
        .attc-userbar .logout-btn { background-color: #e74c3c; color: white; margin-left: 0.5rem; }
        #attc-back-to-plan { color: white; padding: 0.6rem 1rem; border-radius: 5px; font-weight: 500; transition: background-color 0.2s;background-color: #2271b1; margin-bottom: 1rem;}
    </style>

  
    <div class="attc-history-wrap">
    <a href="/chuyen-doi-giong-noi"><button id="attc-back-to-plan">← Quay lại chuyển đổi giọng nói</button></a>

        <div class="attc-userbar">
            <p class="attc-username"><strong><?php echo esc_html($display_name); ?></strong></p>
            <div>
            <a href="/nang-cap" class="upgrade-btn">Nâng cấp</a>
            <a class="attc-logout-btn" href="<?php echo esc_url($logout_url); ?>">Đăng xuất</a>
            </div>
          
        </div>
        <h2>Lịch sử giao dịch</h2>
        <?php if (!is_array($history) || empty($history)): ?>
            <div class="attc-no-history">
                <p>Chưa có lịch sử chuyển đổi.</p>
            </div>
        <?php else: 
            $history = array_reverse($history);
        ?>
            <table class="attc-history-table">
                <thead>
                    <tr>
                        <th width="15%">Ngày</th>
                        <th width="15%">Loại</th>
                        <th width="15%">Số tiền</th>
                        <th width="40%">Chi tiết</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $item): ?>
                    <tr>
                        <td><?php echo wp_date('d/m/Y H:i', $item['timestamp']); ?></td>
                        <td>
                            <?php 
                                $type = $item['type'] ?? 'N/A';
                                $meta = $item['meta'] ?? [];
                                $reason = $meta['reason'] ?? '';
                                if ($type === 'credit') {
                                    echo '<span class="attc-type-credit">Nạp tiền</span>';
                                } elseif ($type === 'conversion_free' || $reason === 'free_tier') {
                                    echo '<span class="attc-type-debit">Miễn phí</span>';
                                } elseif ($type === 'debit') {
                                    echo '<span class="attc-type-debit">Chuyển đổi</span>';
                                } else {
                                    echo esc_html($type);
                                }
                            ?>
                        </td>
                        <td>
                            <?php 
                                $amount = (int)($item['amount'] ?? 0);
                                if ($type === 'conversion_free' || ($meta['reason'] ?? '') === 'free_tier') {
                                    echo '0đ';
                                } else {
                                    $prefix = ($type === 'credit') ? '+' : '-';
                                    echo $prefix . ' ' . number_format($amount) . 'đ';
                                }
                            ?>
                        </td>
                        <td>
                            <?php 
                                $meta = $item['meta'] ?? [];
                                $reason = $meta['reason'] ?? '';
                                if ($reason === 'webhook_bank') {
                                    echo 'Nạp tiền qua chuyển khoản ngân hàng.';
                                } elseif ($reason === 'audio_conversion') {
                                    $duration = (int)($meta['duration'] ?? 0);
                                    echo 'File ' . round($duration / 60, 2) . ' phút.';
                                    if (!empty($meta['transcript'])) {
                                        $download_nonce = wp_create_nonce('attc_download_' . $item['timestamp']);
                                        $download_url = add_query_arg([
                                            'action' => 'attc_download_transcript',
                                            'timestamp' => $item['timestamp'],
                                            'nonce' => $download_nonce,
                                        ], home_url());
                                        echo '<br><a href="' . esc_url($download_url) . '" class="attc-download-link">Tải về (.docx)</a>';
                                        echo '<div class="attc-transcript-content">' . nl2br(esc_html($meta['transcript'])) . '</div>';
                                    }
                                } elseif ($reason === 'free_tier' || $type === 'conversion_free') {
                                    $duration = (int)($meta['duration'] ?? 0);
                                    echo 'Miễn phí - File ' . round($duration / 60, 2) . ' phút.';
                                    if (!empty($meta['transcript'])) {
                                        $download_nonce = wp_create_nonce('attc_download_' . $item['timestamp']);
                                        $download_url = add_query_arg([
                                            'action' => 'attc_download_transcript',
                                            'timestamp' => $item['timestamp'],
                                            'nonce' => $download_nonce,
                                        ], home_url());
                                        echo '<br><a href="' . esc_url($download_url) . '" class="attc-download-link">Tải về (.docx)</a>';
                                        echo '<div class="attc-transcript-content">' . nl2br(esc_html($meta['transcript'])) . '</div>';
                                    }
                                }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('attc_history', 'attc_history_shortcode');

// Tiêm tự động shortcode vào trang lịch sử nếu trang chưa có shortcode
function attc_inject_history_shortcode($content) {
    if (is_page('lich-su-chuyen-doi') && in_the_loop() && is_main_query()) {
        if (stripos($content, '[attc_history]') === false) {
            return '[attc_history]';
        }
    }
    return $content;
}
add_filter('the_content', 'attc_inject_history_shortcode', 5);
