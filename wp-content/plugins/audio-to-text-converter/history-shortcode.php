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

    // Enqueue background summary generation for items missing summary (non-blocking)
    if (is_array($history) && !empty($history)) {
        foreach ($history as $item) {
            $ts = (int)($item['timestamp'] ?? 0);
            $meta = $item['meta'] ?? [];
            $hasTranscript = !empty($meta['transcript']);
            $hasSummary = !empty($meta['summary']);
            if ($ts > 0 && $hasTranscript && !$hasSummary) {
                if (!wp_next_scheduled('attc_generate_summary_for_history', [$user_id, $ts])) {
                    wp_schedule_single_event(time() + 1, 'attc_generate_summary_for_history', [$user_id, $ts]);
                }
            }
        }
    }
    $display_name = wp_get_current_user()->display_name;
    $logout_url = wp_logout_url(get_permalink());

    ob_start();
    ?>
    <style>
        .attc-history-wrap { max-width: 900px; margin: 2rem auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
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
        .attc-transcript-content { background: #f9f9f9; border: 1px solid #eee; padding: 10px; border-radius: 4px; max-height: 150px; overflow-y: auto; font-size: 0.95em; line-height: 1.5; }
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
        <div class="attc-retention-note" role="note" aria-label="Lưu ý lưu trữ" style="margin:12px 0 4px; padding:12px 14px; background:linear-gradient(135deg,#fff7ed 0%, #fffbeb 60%); border:1px solid #fde68a; border-radius:8px; color:#7c2d12; display:flex; gap:10px; align-items:flex-start;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" style="flex:0 0 auto; margin-top:2px;">
                <circle cx="12" cy="12" r="10" fill="#f59e0b" opacity="0.15"/>
                <path d="M12 7.5a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8Zm0 10a.75.75 0 0 0 .75-.75v-6a.75.75 0 0 0-1.5 0v6c0 .414.336.75.75.75Z" fill="#b45309"/>
            </svg>
            <div>
                <div style="font-weight:700; margin-bottom:2px;">Lưu ý thời gian lưu trữ</div>
                <?php $retention_days = (int) get_option('attc_history_retention_days', 14); if ($retention_days <= 0) { $retention_days = 14; } ?>
                <div style="line-height:1.6; color:#7c2d12;">Danh sách lịch sử chuyển đổi sẽ được <strong>lên lịch xóa sau <?php echo esc_html($retention_days); ?> ngày</strong> kể từ thời điểm file ghi âm được tạo ra.</div>
            </div>
        </div>
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
                        <th width="30%">Chi tiết</th>
                        <th width="25%">Tóm tắt điểm chính</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $item): ?>
                    <tr>
                        <td>
                            <?php 
                                $created_ts = (int)($item['timestamp'] ?? 0);
                                $retention_days = (int) get_option('attc_history_retention_days', 14);
                                if ($retention_days <= 0) { $retention_days = 14; }
                                $expire_at = $created_ts + $retention_days * DAY_IN_SECONDS;
                                $now_ts = time();
                                $seconds_left = $expire_at - $now_ts;
                                $days_left = (int) ceil($seconds_left / DAY_IN_SECONDS);
                                echo wp_date('d/m/Y H:i', $created_ts);
                                if ($seconds_left > 0) {
                                    echo '<div class="attc-expiry-note" style="font-size:12px;color:#64748b;">Còn ' . esc_html($days_left) . ' ngày sẽ bị xóa</div>';
                                } else {
                                    echo '<div class="attc-expiry-note" style="font-size:12px;color:#9ca3af;">Đã đến hạn xóa</div>';
                                }
                            ?>
                        </td>
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
                                    echo '<span style="font-style: italic;;">File ' . round($duration / 60, 2) . ' phút.</span>';
                                    if (!empty($meta['transcript'])) {
                                        $download_nonce = wp_create_nonce('attc_download_' . $item['timestamp']);
                                        $download_url = add_query_arg([
                                            'action' => 'attc_download_transcript',
                                            'timestamp' => $item['timestamp'],
                                            'nonce' => $download_nonce,
                                        ], home_url());
                                        $download_pdf_url = add_query_arg([
                                            'action' => 'attc_download_transcript_pdf',
                                            'timestamp' => $item['timestamp'],
                                            'nonce' => $download_nonce,
                                        ], home_url());
                                        echo '<br>';
                                        echo '<span style="position: relative;top: -10px;font-style: italic;color: blue;text-decoration: underline;margin-right:5px">Tải kết quả</span>';
                                        echo '<a href="' . esc_url($download_url) . '" class="attc-download-link" aria-label="Tải DOCX" title="Tải DOCX">'
                                            . '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
                                            . '<rect x="3" y="3" width="14" height="18" rx="2" ry="2" fill="#1E3A8A"/>'
                                            . '<rect x="7" y="3" width="14" height="18" rx="2" ry="2" fill="#2563EB"/>'
                                            . '<text x="14" y="16" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="9" fill="#ffffff" font-weight="700">W</text>'
                                            . '</svg>'
                                        . '</a>';
                                        echo ' ';
                                        echo '<a href="' . esc_url($download_pdf_url) . '" class="attc-download-link" aria-label="Tải PDF" title="Tải PDF">'
                                            . '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
                                            . '<rect x="3" y="3" width="18" height="18" rx="2" ry="2" fill="#DC2626"/>'
                                            . '<text x="12" y="16" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="8" fill="#ffffff" font-weight="700">PDF</text>'
                                            . '</svg>'
                                        . '</a>';
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
                                        $download_pdf_url = add_query_arg([
                                            'action' => 'attc_download_transcript_pdf',
                                            'timestamp' => $item['timestamp'],
                                            'nonce' => $download_nonce,
                                        ], home_url());
                                        echo '<br>';
                                        echo '<span style="position: relative;top: -10px;font-style: italic;color: blue;text-decoration: underline;margin-right:5px">Tải kết quả</span>';
                                        echo '<a href="' . esc_url($download_url) . '" class="attc-download-link" aria-label="Tải DOCX" title="Tải DOCX" style="display:inline-flex;align-items:center;justify-content:center;border-radius:10px;">'
                                            . '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
                                            . '<rect x="2.5" y="2" width="13.5" height="18" rx="2" ry="2" fill="#1E3A8A"/>'
                                            . '<rect x="6.5" y="2" width="15.5" height="18" rx="2" ry="2" fill="#2563EB"/>'
                                            . '<text x="14" y="15.5" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="8.8" fill="#ffffff" font-weight="700">W</text>'
                                            . '</svg>'
                                        . '</a>';
                                        echo ' ';
                                        echo '<a href="' . esc_url($download_pdf_url) . '" class="attc-download-link" aria-label="Tải PDF" title="Tải PDF" style="display:inline-flex;align-items:center;justify-content:center;border-radius:10px;">'
                                            . '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
                                            . '<rect x="3" y="3" width="18" height="18" rx="2" ry="2" fill="#DC2626"/>'
                                            . '<text x="12" y="15.5" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="7.8" fill="#ffffff" font-weight="700">PDF</text>'
                                            . '</svg>'
                                        . '</a>';
                                        echo '<div class="attc-transcript-content">' . nl2br(esc_html($meta['transcript'])) . '</div>';
                                    }
                                }
                            ?>
                        </td>
                        <td>
                            <?php 
                                $meta = $item['meta'] ?? [];
                                $summary = $meta['summary'] ?? '';
                                $hasTranscript = !empty($meta['transcript']);
                                if (!empty($summary)) {
                                    $download_nonce = wp_create_nonce('attc_download_' . $item['timestamp']);
                                    $download_url = add_query_arg([
                                        'action' => 'attc_download_summary',
                                        'timestamp' => $item['timestamp'],
                                        'nonce' => $download_nonce,
                                    ], home_url());
                                    echo '<a href="' . esc_url($download_url) . '" class="attc-download-link">Tải tóm tắt (.docx)</a>';
                                    echo '<div class="attc-transcript-content">' . nl2br(esc_html($summary)) . '</div>';
                                } elseif ($hasTranscript) {
                                    echo '<div class="attc-transcript-content" style="font-style:italic;color:#475569;">Đang tạo tóm tắt...</div>';
                                } else {
                                    // No transcript available -> leave summary cell empty
                                    echo '';
                                }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <script>
    (function(){
        function hasPendingSummary(){
            var nodes = document.querySelectorAll('.attc-transcript-content');
            for (var i=0;i<nodes.length;i++){
                var t = (nodes[i].textContent||'').trim();
                if (t === 'Đang tạo tóm tắt...') return true;
            }
            return false;
        }
        var tries = 0;
        var maxTries = 120; // ~20 phút nếu mỗi 10s
        function tick(){
            if (!hasPendingSummary() || tries++ > maxTries) { return; }
            setTimeout(function(){
                if (hasPendingSummary()) { window.location.reload(); }
            }, 10000);
        }
        if (hasPendingSummary()) { tick(); }
        // Also observe DOM mutations to re-arm after reload
        document.addEventListener('visibilitychange', function(){ if (!document.hidden && hasPendingSummary()) { tick(); }});
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('attc_history', 'attc_history_shortcode');

// Tiêm tự động shortcode vào trang lịch sử nếu trang chưa có shortcode
function attc_inject_history_shortcode($content) {
    if (is_page('lich-su-chuyen-doi') && in_the_loop() && is_main_query()) {
        if (stripos($content, '[attc_history]') === false) {
            // Ensure the shortcode is added if not present
            $content = '[attc_history]' . $content;
        }
    }
    return $content;
}
add_filter('the_content', 'attc_inject_history_shortcode', 5);
