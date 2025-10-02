<?php

if (!defined('ABSPATH')) {
    exit; // Chặn truy cập trực tiếp
}

// ===== History Page Shortcode =====

function attc_history_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>Vui lòng <a href="' . wp_login_url(get_permalink()) . '">đăng nhập</a> để xem lịch sử.</p>';
    }

    $user_id = get_current_user_id();
    $history = get_user_meta($user_id, 'attc_wallet_history', true);

    if (!is_array($history) || empty($history)) {
        return '<p>Bạn chưa có giao dịch nào.</p>';
    }

    $history = array_reverse($history);

    ob_start();
    ?>
    <style>
        .attc-history-wrap { max-width: 800px; margin: 2rem auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .attc-history-table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
        .attc-history-table th, .attc-history-table td { padding: 12px 15px; border: 1px solid #e1e1e1; text-align: left; }
        .attc-history-table thead { background-color: #f5f5f5; }
        .attc-history-table th { font-weight: 600; }
        .attc-history-table tbody tr:nth-child(even) { background-color: #fafafa; }
        .attc-type-credit { color: #27ae60; font-weight: bold; }
        .attc-type-debit { color: #c0392b; font-weight: bold; }
        .attc-download-link { font-size: 0.9em; }
        .attc-transcript-content { background: #f9f9f9; border: 1px solid #eee; padding: 10px; margin-top: 10px; border-radius: 4px; max-height: 150px; overflow-y: auto; font-size: 0.95em; line-height: 1.5; }
    </style>
    <div class="attc-history-wrap">
        <h2>Lịch sử giao dịch</h2>
        <table class="attc-history-table">
            <thead>
                <tr>
                    <th>Ngày</th>
                    <th>Loại</th>
                    <th>Số tiền</th>
                    <th>Chi tiết</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $item): ?>
                <tr>
                    <td><?php echo date('d/m/Y H:i', $item['timestamp']); ?></td>
                    <td>
                        <?php 
                            $type = $item['type'] ?? 'N/A';
                            if ($type === 'credit') {
                                echo '<span class="attc-type-credit">Nạp tiền</span>';
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
                            $prefix = ($type === 'credit') ? '+' : '-';
                            echo $prefix . ' ' . number_format($amount) . 'đ';
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
                            }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('attc_history', 'attc_history_shortcode');
