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
    $history_raw = get_user_meta($user_id, 'attc_wallet_history', true);
    $history_raw = is_array($history_raw) ? $history_raw : [];

    $history = array_reverse($history_raw);
    $seen = [];
    $dedup_history = [];
    foreach ($history as $entry) {
        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        $key = $timestamp > 0 ? 'ts_' . $timestamp : 'hash_' . md5(wp_json_encode($entry));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $dedup_history[] = $entry;
    }
    $history = $dedup_history;

    // Phân trang
    $items_per_page = 10;
    $current_page = isset($_GET['trang']) ? max(1, intval($_GET['trang'])) : 1;
    $total_items = count($history);
    $total_pages = ceil($total_items / $items_per_page);
    $offset = ($current_page - 1) * $items_per_page;
    $paginated_history = array_slice($history, $offset, $items_per_page);
    $start_index = $total_items ? $offset + 1 : 0;
    $end_index = $offset + count($paginated_history);
    $display_name = wp_get_current_user()->display_name;
    $logout_url = wp_logout_url(get_permalink());
    $range_label = $total_items
        ? sprintf('Hiển thị %d–%d trên tổng số %d mục.', $start_index, $end_index, $total_items)
        : 'Không có bản ghi nào.';
    $retention_days = (int) get_option('attc_history_retention_days', 7);

    ob_start();
    ?>
    <style>
        .attc-history-wrap { max-width: 950px; margin: 2rem auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
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
        .attc-transcript-content { background: #f3f4f6; border: 1px solid #e5e7eb; padding: 10px; margin-top: 8px; border-radius: 6px; max-height: 150px; overflow-y: auto; font-size: 0.95em; line-height: 1.6; }
        .attc-summary-content { background: #f3f4f6; border: 1px solid #e5e7eb; padding: 10px; margin-top: 8px; border-radius: 6px; max-height: 150px; overflow-y: auto; font-size: 0.95em; line-height: 1.6; }
        .attc-no-history { margin-top: 1.5rem; padding: 1.5rem; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: .25rem; text-align: center; color: #6c757d; }
        .upgrade-btn { background-color: #3498db; color: white; }
        .attc-userbar a { text-decoration: none; padding: 0.6rem 1rem; border-radius: 5px; font-weight: 500; transition: background-color 0.2s; }
        .attc-userbar .upgrade-btn { background-color: #3498db; color: white; }
        .attc-userbar .history-btn { background-color: #95a5a6; color: white; }
        .attc-userbar .logout-btn { background-color: #e74c3c; color: white; margin-left: 0.5rem; }
        #attc-back-to-plan { color: white; padding: 0.6rem 1rem; border-radius: 5px; font-weight: 500; transition: background-color 0.2s;background-color: #2271b1; margin-bottom: 1rem;}
        .attc-pagination { margin-top: 2rem; text-align: center; }
        .attc-pagination a, .attc-pagination span { padding: 8px 12px; margin: 0 4px; border: 1px solid #ddd; text-decoration: none; color: #333; }
        .attc-pagination .current { background-color: #3498db; color: white; border-color: #3498db; }
        .attc-pagination .disabled { color: #aaa; pointer-events: none; }
        .attc-mini-link{color:#2563eb; font-size:.95em; font-weight:600; text-decoration:underline;}
        .attc-download-row{margin-top:4px; display:flex; align-items:center; gap:8px;}
        .attc-chip{display:inline-flex; align-items:center; justify-content:center; height:20px; padding:0 6px; border-radius:4px; font-size:12px; font-weight:700; color:#fff; line-height:1; text-decoration:none}
        .attc-chip.word{background:#2563eb}
        .attc-chip.pdf{background:#ef4444}
        .attc-muted{color:#6b7280; font-size:12px;}
        .attc-retention-box{margin:.5rem 0 1rem; background:#fff7e6; border:1px solid #f5d38b; color:#92400e; border-radius:8px; padding:10px 12px;}
        .attc-retention-head{display:flex; align-items:center; gap:8px; font-weight:700; color:#b45309; margin-bottom:4px;}
        .attc-retention-icon{display:inline-flex; width:18px; height:18px; border-radius:50%; background:#fde68a; color:#b45309; align-items:center; justify-content:center; font-size:12px; font-weight:700;}
        .attc-retention-body{color:#6b4e16; font-size:16px;}
        .attc-retention-body strong{font-weight:700; color:#8b4513;}
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
        <p><?php echo esc_html($range_label); ?></p>
        <?php if ($retention_days > 0): ?>
        <div class="attc-retention-box">
            <div class="attc-retention-head"><span class="attc-retention-icon">i</span> Lưu ý thời gian lưu trữ</div>
            <div class="attc-retention-body">Danh sách lịch sử chuyển đổi sẽ được <strong>lên lịch xóa sau <?php echo (int)$retention_days; ?> ngày</strong> kể từ thời điểm file ghi âm được tạo ra.</div>
        </div>
        <?php endif; ?>
        <?php if (empty($paginated_history)): ?>
            <div class="attc-no-history">
                <p>Chưa có lịch sử chuyển đổi.</p>
            </div>
        <?php else: 
        ?>
            <table class="attc-history-table">
                <thead>
                    <tr>
                        <th width="15%">Ngày</th>
                        <th width="15%">Loại</th>
                        <th width="12%">Số tiền</th>
                        <th width="32%">Chi tiết</th>
                        <th width="25%">Tóm tắt điểm chính</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $history_updated = false;
                    foreach ($paginated_history as $item_key => $item): 
                        $meta = $item['meta'] ?? [];
                    ?>
                    <tr>
                        <td>
                            <div><?php echo esc_html(wp_date('d/m/Y', $item['timestamp'])); ?></div>
                            <div><?php echo esc_html(wp_date('H:i', $item['timestamp'])); ?></div>
                            <?php 
                                if ($retention_days > 0) {
                                    $rea = (string)($meta['reason'] ?? '');
                                    if ($rea === 'audio_conversion' || $rea === 'free_tier') {
                                        $created_ts = (int)($meta['created_at'] ?? $item['timestamp']);
                                        $now_ts = (int) current_time('timestamp');
                                        $elapsed_days = (int) floor(max(0, $now_ts - $created_ts) / DAY_IN_SECONDS);
                                        $left = max(0, (int)$retention_days - max(0, $elapsed_days));
                                        echo '<div class="attc-muted">' . ($left > 0 ? ('Còn ' . (int)$left . ' ngày sẽ bị xóa') : 'Sẽ bị xóa trong hôm nay') . '</div>';
                                    }
                                }
                            ?>
                        </td>
                        <td>
                            <?php 
                                $type = $item['type'] ?? 'N/A';
                                if ($type === 'credit') {
                                    echo '<span class="attc-type-credit">Nạp tiền</span>';
                                } elseif ($type === 'debit') {
                                    echo '<span class="attc-type-debit">Chuyển đổi</span>';
                                } elseif ($type === 'conversion_free') {
                                    echo '<span class="attc-type-debit">Miễn phí</span>';
                                } else {
                                    echo esc_html($type);
                                }
                            ?>
                        </td>
                        <td>
                            <?php 
                                $amount = (int)($item['amount'] ?? 0);
                                if ($type === 'conversion_free') {
                                    echo '0đ';
                                } elseif ($type === 'credit') {
                                    echo '+ ' . number_format($amount) . 'đ';
                                } elseif ($type === 'debit') {
                                    echo '- ' . number_format($amount) . 'đ';
                                } else {
                                    echo number_format($amount) . 'đ';
                                }
                            ?>
                        </td>
                        <td>
                            <?php 
                                $reason = $meta['reason'] ?? '';
                                if ($reason === 'webhook_bank') {
                                    echo 'Nạp tiền qua chuyển khoản ngân hàng.';
                                } elseif ($reason === 'audio_conversion') {
                                    $duration = (int)($meta['duration'] ?? 0);
                                    echo 'File ' . round($duration / 60, 2) . ' phút.';
                                    if (!empty($meta['transcript'])) {
                                        $created_ts = (int)($meta['created_at'] ?? $item['timestamp']);
                                        $now_ts = (int) current_time('timestamp');
                                        $elapsed_days = (int) floor(max(0, $now_ts - $created_ts) / DAY_IN_SECONDS);
                                        $left = max(0, (int)$retention_days - max(0, $elapsed_days));
                                        $download_nonce = wp_create_nonce('attc_download_' . $item['timestamp']);
                                        $download_url = add_query_arg([
                                            'action' => 'attc_download_transcript',
                                            'timestamp' => $item['timestamp'],
                                            'nonce' => $download_nonce,
                                            'format' => 'doc',
                                        ], home_url());
                                        $download_pdf = add_query_arg([
                                            'action' => 'attc_download_transcript',
                                            'timestamp' => $item['timestamp'],
                                            'nonce' => $download_nonce,
                                            'format' => 'pdf',
                                        ], home_url());
                                        echo '<div class="attc-download-row">'
                                            . '<a href="' . esc_url($download_url) . '" class="attc-mini-link" download>Tải kết quả</a>'
                                            . '<a href="' . esc_url($download_url) . '" class="attc-chip word" download>W</a>'
                                            . '<a href="#" class="attc-chip pdf attc-download-pdf" data-ts="' . (int)$item['timestamp'] . '">PDF</a>'
                                        . '</div>';
                                        echo '<div class="attc-transcript-content">' . nl2br(esc_html($meta['transcript'])) . '</div>';
                                    }
                                } elseif ($type === 'conversion_free') {
                                    $duration = (int)($meta['duration'] ?? 0);
                                    echo 'Miễn phí - File ' . round($duration / 60, 2) . ' phút.';
                                    if (!empty($meta['transcript'])) {
                                        $created_ts = (int)($meta['created_at'] ?? $item['timestamp']);
                                        $now_ts = (int) current_time('timestamp');
                                        $elapsed_days = (int) floor(max(0, $now_ts - $created_ts) / DAY_IN_SECONDS);
                                        $left = max(0, (int)$retention_days - max(0, $elapsed_days));
                                        $download_nonce = wp_create_nonce('attc_download_' . $item['timestamp']);
                                        $download_url = add_query_arg([
                                            'action' => 'attc_download_transcript',
                                            'timestamp' => $item['timestamp'],
                                            'nonce' => $download_nonce,
                                            'format' => 'doc',
                                        ], home_url());
                                        $download_pdf = add_query_arg([
                                            'action' => 'attc_download_transcript',
                                            'timestamp' => $item['timestamp'],
                                            'nonce' => $download_nonce,
                                            'format' => 'pdf',
                                        ], home_url());
                                        echo '<div class="attc-download-row">'
                                            . '<a href="' . esc_url($download_url) . '" class="attc-mini-link" download>Tải kết quả</a>'
                                            . '<a href="' . esc_url($download_url) . '" class="attc-chip word" download>W</a>'
                                            . '<a href="#" class="attc-chip pdf attc-download-pdf" data-ts="' . (int)$item['timestamp'] . '">PDF</a>'
                                        . '</div>';
                                        echo '<div class="attc-transcript-content">' . nl2br(esc_html($meta['transcript'])) . '</div>';
                                    }
                                }
                            ?>
                        </td>
                        <td>
                            <?php
                                $bullets = $meta['summary_bullets'] ?? [];
                                // Nếu chưa có tóm tắt nhưng đã có transcript, tạo và cập nhật vào mảng lịch sử chính
                                if ((empty($bullets) || !is_array($bullets)) && !empty($meta['transcript'])) {
                                    $bullet_count = max(1, (int) get_option('attc_summary_bullets', 5));
                                    $norm = function_exists('attc_vi_normalize') ? attc_vi_normalize($meta['transcript']) : (string)$meta['transcript'];
                                    $bullets = function_exists('attc_make_summary_bullets') ? attc_make_summary_bullets($norm, $bullet_count) : [];
                                    
                                    if (!empty($bullets)) {
                                        // Tìm đúng key trong mảng $history gốc và cập nhật
                                        foreach ($history as $original_key => $original_item) {
                                            if ((int)($original_item['timestamp'] ?? 0) === (int)$item['timestamp']) {
                                                $history[$original_key]['meta']['summary_bullets'] = $bullets;
                                                $history_updated = true;
                                                break;
                                            }
                                        }
                                    }
                                }
                                if (!empty($bullets) && is_array($bullets)) {
                                    echo '<div><a href="#" class="attc-mini-link attc-download-summary-doc" data-ts="' . (int)$item['timestamp'] . '">Tải tóm tắt (.docx)</a></div>';
                                    echo '<div class="attc-summary-content"><ul style="margin:0; padding-left:18px;">';
                                    foreach ($bullets as $b) { echo '<li>' . esc_html($b) . '</li>'; }
                                    echo '</ul></div>';
                                } else {
                                    echo 'Đang tạo tóm tắt';
                                }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="attc-pagination">
                <?php
                if ($total_pages > 1) {
                    $base_url = remove_query_arg('trang', get_permalink());
                    if ($current_page > 1) {
                        echo '<a href="' . add_query_arg('trang', $current_page - 1, $base_url) . '">« Trước</a>';
                    }

                    for ($i = 1; $i <= $total_pages; $i++) {
                        if ($i == $current_page) {
                            echo '<span class="current">' . $i . '</span>';
                        } else {
                            echo '<a href="' . add_query_arg('trang', $i, $base_url) . '">' . $i . '</a>';
                        }
                    }

                    if ($current_page < $total_pages) {
                        echo '<a href="' . add_query_arg('trang', $current_page + 1, $base_url) . '">Sau »</a>';
                    }
                }
                ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('attc_history', 'attc_history_shortcode');

add_action('wp_footer', function() {
    if (!is_page('lich-su-chuyen-doi') && !is_page('lich-su-giao-dich')) { return; }
    ?>
    <script>
    (function(){
        function loadHtml2Pdf(){
            return new Promise(function(resolve, reject){
                if (window.html2pdf) return resolve();
                var s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js';
                s.onload = function(){ resolve(); };
                s.onerror = function(){ reject(new Error('Không tải được html2pdf.js')); };
                document.head.appendChild(s);
            });
        }

        function prettifyText(t){
            try {
                t = String(t || '').trim().replace(/\s+/g, ' ');
                t = t.replace(/[\u2026]/g, '…');
                t = t.replace(/([\.!?…])\s+/g, '$1\n');
                t = t.replace(/\n{2,}/g, '\n');
                return t;
            } catch(_) { return String(t || ''); }
        }

        document.addEventListener('click', function(e){
            var pdfLink = e.target.closest('.attc-download-pdf');
            if (!pdfLink) return;
            e.preventDefault();

            var row = pdfLink.closest('tr');
            var transcriptBox = row ? row.querySelector('.attc-transcript-content') : null;
            var summaryBox = row ? row.querySelector('.attc-summary-content') : null;
            var text = transcriptBox ? (transcriptBox.textContent || '').trim() : '';
            var ts = pdfLink.getAttribute('data-ts') || String(Date.now());

            if (!text) {
                var href = pdfLink.getAttribute('data-pdf') || pdfLink.getAttribute('href');
                if (href) { window.location.href = href; }
                return;
            }

            var container = document.createElement('div');
            container.style.cssText = 'margin:0; padding:0 12px 12px 12px; font-family: \'Times New Roman\', Times, serif; font-size:12px; line-height:1.5; white-space:pre-wrap; color:#000;';
            // Không kèm tóm tắt trong PDF theo yêu cầu
            var pre = document.createElement('div');
            pre.textContent = prettifyText(text);
            container.appendChild(pre);

            loadHtml2Pdf().then(function(){
                var opt = {
                    margin:       [0, 10, 10, 10],
                    filename:     'attc-transcript-' + ts + '.pdf',
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2 },
                    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };
                window.html2pdf().set(opt).from(container).save().catch(function(){
                    // Fallback: điều hướng tới link server nếu có
                    var href = pdfLink.getAttribute('data-pdf') || pdfLink.getAttribute('href');
                    if (href) window.location.href = href;
                }).finally(function(){
                    container.remove();
                });
            }).catch(function(){
                var href = pdfLink.getAttribute('data-pdf') || pdfLink.getAttribute('href');
                if (href) window.location.href = href;
            });
        });

        // Tải tóm tắt (.docx) – tạo .doc client-side từ bullet list
        document.addEventListener('click', function(e){
            var sumLink = e.target.closest('.attc-download-summary-doc');
            if (!sumLink) return;
            e.preventDefault();
            var row = sumLink.closest('tr');
            var list = row ? row.querySelectorAll('.attc-summary-content li') : null;
            var bullets = [];
            if (list && list.length) {
                list.forEach(function(li){ bullets.push((li.textContent||'').trim()); });
            }
            if (!bullets.length) return;
            var ts = sumLink.getAttribute('data-ts') || String(Date.now());
            var html = '<!doctype html><html><head><meta charset="UTF-8"><title>Tóm tắt nội dung</title>'+
                '<style>body{font-family: \'Times New Roman\', Times, serif; font-size:20px; line-height:1.3; margin:0;}'+
                'ul{margin:0 0 10px 24px; padding:0; line-height:1.3; font-size:20px;} li{margin:0 0 6px 0;}</style></head><body>'+
                '<p style="font-weight:bold; margin:0 0 6px 0;">Tóm tắt nội dung:</p><ul>'+
                bullets.map(function(b){ return '<li>'+String(b).replace(/[&<>]/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c];})+'</li>'; }).join('')+
                '</ul></body></html>';
            try {
                var blob = new Blob([html], {type: 'application/msword;charset=utf-8'});
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'attc-summary-' + ts + '.doc';
                document.body.appendChild(a);
                a.click();
                setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); }, 0);
            } catch(_) {}
        });
    })();
    </script>
    <?php
});

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
