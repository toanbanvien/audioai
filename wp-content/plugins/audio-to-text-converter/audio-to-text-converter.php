<?php
/*
Plugin Name: Audio to Text Converter
Description: Chuyển đổi file ghi âm thành văn bản tiếng Việt sử dụng OpenAI API (Whisper).
Version: 1.1.0
Author: Cascade AI
*/

if (!defined('ABSPATH')) {
    exit; // Chặn truy cập trực tiếp
}

// Chuyển hướng an toàn về trang trước hoặc trang chuyển đổi nếu thiếu Referer
function attc_redirect_back() {
    $url = wp_get_referer();
    if (empty($url)) {
        // Fallback đến trang chuyển đổi giọng nói
        $url = site_url('/chuyen-doi-giong-noi/');
    }
    wp_safe_redirect($url);
    exit;
}

// Endpoint để tải file docx
function attc_handle_download_transcript() {
    if (isset($_GET['action'], $_GET['nonce'], $_GET['timestamp']) && $_GET['action'] === 'attc_download_transcript') {
        if (!is_user_logged_in() || !wp_verify_nonce($_GET['nonce'], 'attc_download_' . $_GET['timestamp'])) {
            wp_die('Invalid request');
        }

        $user_id = get_current_user_id();
        $timestamp = (int)$_GET['timestamp'];
        $history = get_user_meta($user_id, 'attc_wallet_history', true);

        $transcript_content = '';
        if (!empty($history)) {
            foreach ($history as $item) {
                if (($item['timestamp'] ?? 0) === $timestamp) {
                    $transcript_content = $item['meta']['transcript'] ?? '';
                    break;
                }
            }
        }

        if (empty($transcript_content)) {
            wp_die('Không tìm thấy nội dung.');
        }

        // Tạo file .docx hợp lệ bằng ZipArchive
        if (!class_exists('ZipArchive')) {
            wp_die('Máy chủ không hỗ trợ ZipArchive, không thể tạo file .docx');
        }

        $tmp_file = tempnam(sys_get_temp_dir(), 'attc_docx_');
        $zip = new ZipArchive();
        if ($zip->open($tmp_file, ZipArchive::OVERWRITE) !== true) {
            wp_die('Không thể tạo file nén tạm thời.');
        }

        $content_types = '[Content_Types].xml';
        $rels_dir = '_rels/.rels';
        $word_document = 'word/document.xml';
        $word_rels_dir = 'word/_rels/document.xml.rels';

        $content_types_xml = '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>';

        $rels_xml = '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';

        // Chuẩn hóa xuống dòng và tạo nhiều đoạn văn cho dễ đọc, áp dụng font Times New Roman, 16pt
        $normalized = preg_replace("/\r\n|\r/", "\n", $transcript_content);
        $lines = explode("\n", $normalized);
        $paragraphs_xml = '';
        $run_props = '<w:rPr>'
            . '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Times New Roman" />'
            . '<w:sz w:val="32"/><w:szCs w:val="32"/>' // 16pt = 32 half-points
            . '</w:rPr>';
        foreach ($lines as $ln) {
            $text = htmlspecialchars($ln, ENT_QUOTES | ENT_XML1, 'UTF-8');
            if ($text === '') {
                $paragraphs_xml .= "<w:p/>"; // dòng trống
            } else {
                $paragraphs_xml .= '<w:p><w:r>' . $run_props . '<w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
            }
        }

        $document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    ' . $paragraphs_xml . '
  </w:body>
</w:document>';

        $document_rels_xml = '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

        $zip->addFromString($content_types, $content_types_xml);
        $zip->addFromString($rels_dir, $rels_xml);
        $zip->addFromString($word_document, $document_xml);
        $zip->addFromString($word_rels_dir, $document_rels_xml);
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="transcript-' . $timestamp . '.docx"');
        header('Content-Length: ' . filesize($tmp_file));
        readfile($tmp_file);
        @unlink($tmp_file);
        exit;
    }
}
add_action('init', 'attc_handle_download_transcript');

// Nạp các thành phần của plugin
require_once(plugin_dir_path(__FILE__) . 'history-shortcode.php');



// ===== Webhook Helpers =====
function attc_verify_webhook_token($provided, $option_key) {
    $expected = get_option($option_key, '');

    // Ghi log để so sánh 2 giá trị token
    $log_message = sprintf(
        "[Token Verification] Provided: '%s', Expected from DB ('%s'): '%s'\n",
        $provided,
        $option_key,
        $expected
    );
    error_log($log_message, 3, WP_CONTENT_DIR . '/attc_webhook.log');

    $provided = (string) $provided;
    if (empty($expected) || empty($provided)) return false;
    
    if (function_exists('hash_equals')) {
        return hash_equals($expected, $provided);
    }
    return $expected === $provided;
}

function attc_extract_user_from_note($note) {
    $note = (string) $note;
    if (preg_match('/NANGCAP[-_\s:]?(\d+)/i', $note, $m)) {
        return (int) $m[1];
    }
    return 0;
}


// ===== Casso specific parser (default integration) =====
add_filter('attc_parse_bank_webhook', function($parsed, $raw){
    if (is_array($raw) && isset($raw['data'])) {
        $tx = null;
        if (is_array($raw['data']) && isset($raw['data'][0])) {
            $tx = $raw['data'][0];
        } 
        else if (is_array($raw['data']) && isset($raw['data']['amount'])) {
            $tx = $raw['data'];
        }

        if ($tx) {
            if (isset($tx['amount'])) { $parsed['amount'] = (int) $tx['amount']; }
            if (isset($tx['description'])) { $parsed['note'] = (string) $tx['description']; }
            if (isset($tx['tid'])) { $parsed['txid'] = (string) $tx['tid']; }
            elseif (isset($tx['reference'])) { $parsed['txid'] = (string) $tx['reference']; }
        }
    }

    // Handle manual debit
    if (!empty($_POST['attc_manual_debit'])) {
        check_admin_referer('attc_manual_debit_action', 'attc_manual_debit_nonce');

        $user_identifier = sanitize_text_field($_POST['attc_user_identifier_debit'] ?? '');
        $amount = (int) ($_POST['attc_amount_debit'] ?? 0);

        $user = false;
        if (is_numeric($user_identifier)) {
            $user = get_user_by('id', (int)$user_identifier);
        }
        if (!$user) { $user = get_user_by('email', $user_identifier); }
        if (!$user) { $user = get_user_by('login', $user_identifier); }

        if (!$user || $amount <= 0) {
            $message .= '<div class="notice notice-error"><p>Vui lòng nhập người dùng hợp lệ và số tiền > 0 để trừ.</p></div>';
        } else {
            $uid = (int)$user->ID;
            $res = attc_charge_wallet($uid, $amount, [
                'reason' => 'admin_debit',
                'note'   => 'Manual debit by admin',
                'admin'  => get_current_user_id(),
            ]);
            if (is_wp_error($res)) {
                $message .= '<div class="notice notice-error"><p>Không thể trừ tiền: ' . esc_html($res->get_error_message()) . '</p></div>';
            } else {
                $message .= '<div class="notice notice-success"><p>Đã trừ ' . number_format($amount) . 'đ từ ví user #' . $uid . ' (' . esc_html($user->user_email) . ').</p></div>';
            }
        }
    }
    return $parsed;
}, 5, 2);


// ===== Webhook Bank/VietQR =====
function attc_handle_webhook_bank() {
    $log_message = sprintf(
        "[%s] Webhook Bank Received: Method=%s, IP=%s, Query=%s, Body=%s\n",
        date('Y-m-d H:i:s'),
        $_SERVER['REQUEST_METHOD'] ?? 'N/A',
        $_SERVER['REMOTE_ADDR'] ?? 'N/A',
        http_build_query($_GET),
        file_get_contents('php://input')
    );
    error_log($log_message, 3, WP_CONTENT_DIR . '/attc_webhook.log');

    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    if (!attc_verify_webhook_token($token, 'attc_webhook_bank_token')) {
        status_header(403);
        wp_send_json_error(['message' => 'Invalid token'], 403);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { $data = $_POST; }

    $parsed = apply_filters('attc_parse_bank_webhook', [
        'amount'  => 0,
        'note'    => '',
        'txid'    => '',
        'user_id' => 0,
        'currency'=> 'VND',
    ], $data);

    if (empty($parsed['user_id'])) {
        $parsed['user_id'] = attc_extract_user_from_note($parsed['note'] ?? '');
    }

    $user_id = (int) ($parsed['user_id'] ?? 0);
    $amount  = (int) ($parsed['amount'] ?? 0);

    if ($user_id <= 0 || $amount <= 0) {
        wp_send_json_success(['status' => 'no_action_taken', 'reason' => 'Invalid user_id or amount from note.']);
        return;
    }

    $result = attc_credit_wallet($user_id, $amount, [
        'reason' => 'webhook_bank',
        'note'   => (string) ($parsed['note'] ?? ''),
        'txid'   => (string) ($parsed['txid'] ?? ''),
        'raw'    => $data,
    ]);

    if (is_wp_error($result)) {
        error_log("[Credit Error] User ID {$user_id}: " . $result->get_error_message() . "\n", 3, WP_CONTENT_DIR . '/attc_webhook.log');
        wp_send_json_success(['status' => 'credit_error', 'message' => $result->get_error_message()]);
    } else {
        wp_send_json_success(['status' => 'credited', 'amount' => $amount, 'user_id' => $user_id]);
    }
}


// ===== Wallet Functions =====
function attc_get_wallet_balance($user_id) {
    return (int) get_user_meta($user_id, 'attc_wallet_balance', true);
}

function attc_set_wallet_balance($user_id, $balance) {
    update_user_meta($user_id, 'attc_wallet_balance', (int) $balance);
}

function attc_add_wallet_history($user_id, $entry) {
    $history = get_user_meta($user_id, 'attc_wallet_history', true);
    if (!is_array($history)) {
        $history = [];
    }
    $entry['timestamp'] = time();
    $history[] = $entry;
    update_user_meta($user_id, 'attc_wallet_history', $history);
}

function attc_credit_wallet($user_id, $amount, $meta = []) {
    $amount = (int) $amount;
    if ($amount <= 0) return true;
    $bal = attc_get_wallet_balance($user_id);
    attc_set_wallet_balance($user_id, $bal + $amount);

    // Lưu một 'cờ' tạm thời để thông báo cho client biết đã nạp thành công
    // Tồn tại trong 60 giây
    set_transient('attc_payment_success_' . $user_id, ['amount' => $amount, 'time' => time()], 60);
    attc_add_wallet_history($user_id, [
        'type' => 'credit',
        'amount' => $amount,
        'meta' => $meta,
    ]);
    return true;
}


// ===== REST API Routes =====
function attc_check_payment_status() {
    if (!is_user_logged_in()) {
        return new WP_REST_Response(['status' => 'not_logged_in'], 200);
    }

    $user_id = get_current_user_id();
    $transient_key = 'attc_payment_success_' . $user_id;

    if ($payment_data = get_transient($transient_key)) {
        delete_transient($transient_key);
        return new WP_REST_Response(['status' => 'success', 'data' => $payment_data], 200);
    }

    return new WP_REST_Response(['status' => 'pending'], 200);
}

add_action('rest_api_init', function () {
    register_rest_route('attc/v1', '/payment-status', [
        'methods' => 'GET',
        'callback' => 'attc_check_payment_status',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('attc/v1', '/webhook/bank', [
        'methods' => 'POST',
        'callback' => 'attc_handle_webhook_bank',
        'permission_callback' => '__return_true',
    ]);
});


function attc_get_price_per_minute() {
    $price = (int) get_option('attc_price_per_minute', 500);
    return max(0, $price);
}

// ===== Upgrade Page Shortcode =====
function attc_upgrade_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="attc-auth-prompt"><p>Vui lòng <a href="' . esc_url(site_url('/dang-nhap')) . '">đăng nhập</a> hoặc <a href="' . esc_url(site_url('/dang-ky')) . '">đăng ký</a> để sử dụng chức năng này.</p></div>';
    }
    $display_name = wp_get_current_user()->display_name;
    $free_threshold = (int) get_option('attc_free_threshold', 500);
    $logout_url = wp_logout_url(get_permalink());
    if (!is_user_logged_in()) {
        return '<div class="attc-auth-prompt"><p>Vui lòng <a href="' . esc_url(site_url('/dang-nhap')) . '">đăng nhập</a> hoặc <a href="' . esc_url(site_url('/dang-ky')) . '">đăng ký</a> để sử dụng chức năng này.</p></div>';
    }
    $display_name = wp_get_current_user()->display_name;
    $logout_url = wp_logout_url(get_permalink());
    $price_per_min = (int) attc_get_price_per_minute();
    $plans = [
        [ 'id' => 'topup-20k',  'title' => 'Nạp 20.000đ',  'amount' => 20000 ],
        [ 'id' => 'topup-50k',  'title' => 'Nạp 50.000đ',  'amount' => 50000 ],
        [ 'id' => 'topup-100k', 'title' => 'Nạp 100.000đ', 'amount' => 100000, 'highlight' => true ],
    ];

    ob_start();
    ?>
    <div id="attc-success-notice" class="attc-notice is-hidden">
        <p><strong>Nạp tiền thành công!</strong></p>
        <p id="attc-success-message"></p>
        <p>Trang sẽ được tải lại sau giây lát để cập nhật số dư...</p>
    </div>

    <style>
    .attc-notice { background: #fff; border-left: 4px solid #4CAF50; padding: 12px 20px; margin: 20px 0; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
    .attc-notice.is-hidden { display: none; }
    .attc-upgrade{max-width:920px;margin:0 auto;padding:20px}
    .attc-pricing{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
    .attc-card{border:1px solid #e5e7eb;border-radius:10px;padding:16px;background:#fff}
    .attc-card-title{font-weight:700;margin-bottom:8px}
    .attc-card-price strong{font-size:24px;}
    .attc-plan-select{display:inline-block;background:#2271b1;color:#fff !important;padding:10px 12px;border-radius:6px;text-decoration:none;font-weight:600;margin-top:10px;}
    .attc-userbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; background: #f0f4f8; border: 1px solid #e3e8ee; padding: 12px 16px; border-radius: 8px; margin-bottom: 1.5rem; }
    .attc-userbar .attc-username { margin: 0; font-weight: 600; color: #2c3e50; }
    .attc-userbar .attc-logout-btn { background: #e74c3c; color: #fff !important; text-decoration: none; padding: 8px 12px; border-radius: 6px; font-weight: 600; transition: background-color .2s; }
    .attc-userbar .attc-logout-btn:hover { background: #ff6b61; }
    .attc-userbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; background: #f0f4f8; border: 1px solid #e3e8ee; padding: 12px 16px; border-radius: 8px; margin-bottom: 1.5rem; }
    .attc-userbar .attc-username { margin: 0; font-weight: 600; color: #2c3e50; }
    .attc-userbar .attc-logout-btn { background: #e74c3c; color: #fff !important; text-decoration: none; padding: 8px 12px; border-radius: 6px; font-weight: 600; transition: background-color .2s; }
    .attc-userbar .attc-logout-btn:hover { background: #ff6b61; }
    #attc-payment-details.is-hidden, #attc-pricing-plans.is-hidden { display: none; }
    .attc-payment-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 1.5rem; }
    .attc-payment-column { border: 1px solid #e5e7eb; padding: 1.5rem; border-radius: 8px; background: #fff; text-align: center; }
    .attc-payment-column h4 { margin-top: 0; margin-bottom: 1rem; }
    .attc-qr-code img { max-width: 250px; height: auto; margin: 0 auto; }
    .attc-bank-info { text-align: left; margin-top: 1rem; }
    .attc-bank-info p { margin: 0.5rem 0; }    
    </style>

    <div class="attc-upgrade">
        <div class="attc-userbar">
            <p class="attc-username"><strong><?php echo esc_html($display_name); ?></strong></p>
            <a class="attc-logout-btn" href="<?php echo esc_url($logout_url); ?>">Đăng xuất</a>
        </div>
        <div id="attc-pricing-plans">
            <h2>Nâng cấp tài khoản</h2>
            <div class="attc-pricing">
                <?php foreach ($plans as $plan): $amt = (int)($plan['amount'] ?? 0); $mins = $price_per_min > 0 ? floor($amt / $price_per_min) : 0; ?>
                <div class="attc-card">
                    <div class="attc-card-title"><?php echo esc_html($plan['title']); ?></div>
                    <div class="attc-card-price">
                        <strong><?php echo number_format($amt, 0, ',', '.'); ?>đ</strong>
                        <span>≈ <?php echo (int)$mins; ?> phút</span>
                    </div>
                    <a class="attc-plan-select" href="#" data-amount="<?php echo (int)$amt; ?>" data-user-id="<?php echo (int) get_current_user_id(); ?>">Chọn gói này</a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="attc-payment-details" class="is-hidden">
            <button id="attc-back-to-plans">&larr; Quay lại chọn gói</button>
            <h3>Chi tiết thanh toán</h3>

            <div style="margin-bottom: 1rem; color: red;"><i>Lưu ý: Sau khi chuyển khoản thành công với đúng nội dung thanh toán, bạn hãy vào lại trang "Chuyển đổi giọng nói" để thực hiện chuyển đổi!</i></div>
            <div class="attc-payment-columns">
                <div class="attc-payment-column">
                    <h4>Ví MoMo</h4>
                    <div class="attc-qr-code" id="attc-momo-qr"></div>
                    <p class="attc-qr-fallback">Mở MoMo, chọn "Quét Mã" và quét mã QR ở trên.</p>
                </div>
                <div class="attc-payment-column">
                    <h4>Chuyển khoản Ngân hàng (ACB)</h4>
                    <div class="attc-qr-code" id="attc-bank-qr"></div>
                    <div class="attc-bank-info">
                        <p><strong>Ngân hàng:</strong> <span id="bank-name">ACB</span></p>
                        <p><strong>Chủ tài khoản:</strong> <span id="account-name">DINH CONG TOAN</span></p>
                        <p><strong>Số tài khoản:</strong> <span id="account-number">240306539</span></p>
                        <p><strong>Số tiền:</strong> <strong class="price-payment" id="bank-amount"></strong></p>
                        <p><strong>Nội dung:</strong> <strong class="price-payment" id="bank-memo"></strong></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('attc_upgrade', 'attc_upgrade_shortcode');

// ===== Nạp và cấu hình script kiểm tra thanh toán =====
function attc_enqueue_payment_checker_script() {
    global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'attc_upgrade')) {
        // Enqueue CSS
        $css_path = plugin_dir_path(__FILE__) . 'assets/css/frontend.css';
        if (file_exists($css_path)) {
            $css_url = plugins_url('assets/css/frontend.css', __FILE__);
            $css_ver = filemtime($css_path);
            wp_enqueue_style('attc-frontend', $css_url, [], $css_ver);
        }

        // Enqueue JS
        $script_path = plugin_dir_path(__FILE__) . 'assets/js/payment-checker.js';
        if (file_exists($script_path)) {
            $script_url = plugins_url('assets/js/payment-checker.js', __FILE__);
            $version = filemtime($script_path);
            wp_enqueue_script('attc-payment-checker', $script_url, [], $version, true);
            wp_localize_script('attc-payment-checker', 'attcPaymentData', [
                'rest_url'      => esc_url_raw(rest_url('attc/v1/payment-status')),
                'price_per_min' => (string) attc_get_price_per_minute(),
                'user_id'       => get_current_user_id(),
            ]);
        }
    }
}
add_action('wp_enqueue_scripts', 'attc_enqueue_payment_checker_script');


// ===== ADMIN: Wallet Dashboard & Manual Top-up =====
add_action('admin_menu', 'attc_register_admin_pages');
function attc_register_admin_pages() {
    add_menu_page(
        'AudioAI Wallet',
        'AudioAI Wallet',
        'manage_options',
        'attc-wallet',
        'attc_render_wallet_dashboard',
        'dashicons-money-alt',
        58
    );
}

function attc_render_wallet_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die('Bạn không có quyền truy cập trang này.');
    }

    // Handle manual top-up
    $message = '';
    if (!empty($_POST['attc_manual_topup'])) {
        check_admin_referer('attc_manual_topup_action', 'attc_manual_topup_nonce');

        $user_identifier = sanitize_text_field($_POST['attc_user_identifier'] ?? '');
        $amount = (int) ($_POST['attc_amount'] ?? 0);

        $user = false;
        if (is_numeric($user_identifier)) {
            $user = get_user_by('id', (int)$user_identifier);
        }
        if (!$user) {
            $user = get_user_by('email', $user_identifier);
        }
        if (!$user) {
            $user = get_user_by('login', $user_identifier);
        }

        if (!$user || $amount <= 0) {
            $message = '<div class="notice notice-error"><p>Vui lòng nhập người dùng hợp lệ và số tiền > 0.</p></div>';
        } else {
            $uid = (int)$user->ID;
            $res = attc_credit_wallet($uid, $amount, [
                'reason' => 'admin_topup',
                'note'   => 'Manual top-up by admin',
                'admin'  => get_current_user_id(),
            ]);
            if (is_wp_error($res)) {
                $message = '<div class="notice notice-error"><p>Lỗi nạp tiền: ' . esc_html($res->get_error_message()) . '</p></div>';
            } else {
                $message = '<div class="notice notice-success"><p>Đã nạp ' . number_format($amount) . 'đ vào ví của user #' . $uid . ' (' . esc_html($user->user_email) . ').</p></div>';
            }
        }
    }

    $price_per_min = (int) attc_get_price_per_minute();

    // Search/filter + Pagination
    $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    $per_page = max(1, (int) apply_filters('attc_admin_wallet_per_page', 20));
    $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $offset = ($paged - 1) * $per_page;

    $user_query_args = [
        'fields' => ['ID', 'display_name', 'user_email'],
        'number' => $per_page,
        'offset' => $offset,
    ];
    if ($q !== '') {
        $user_query_args['search'] = '*' . $q . '*';
        $user_query_args['search_columns'] = ['user_login', 'user_email', 'display_name'];
    }
    $user_query = new WP_User_Query($user_query_args);
    $users = $user_query->get_results();
    $total_users = (int) $user_query->get_total();
    $total_pages = max(1, (int) ceil($total_users / $per_page));

    $rows = [];
    $depositor_count = 0;
    foreach ($users as $u) {
        $uid = (int)$u->ID;
        $balance = attc_get_wallet_balance($uid);
        $mins = $price_per_min > 0 ? floor($balance / $price_per_min) : 0;
        $has_deposit = attc_user_has_made_deposit($uid);
        if ($has_deposit) { $depositor_count++; }
        $rows[] = [
            'id' => $uid,
            'name' => $u->display_name,
            'email' => $u->user_email,
            'balance' => $balance,
            'minutes' => $mins,
            'has_deposit' => $has_deposit,
        ];
    }

    // Sort by balance desc
    usort($rows, function($a, $b){ return $b['balance'] <=> $a['balance']; });

    echo '<div class="wrap"><h1>AudioAI Wallet</h1>';
    if ($message) echo $message;

    echo '<h2>Thống kê</h2>';
    echo '<p>- Người dùng đã từng nạp: <strong>' . (int)$depositor_count . '</strong></p>';
    echo '<p>- Đơn giá mỗi phút: <strong>' . number_format($price_per_min) . 'đ/phút</strong></p>';

    echo '<h2>Nạp tiền thủ công</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('attc_manual_topup_action', 'attc_manual_topup_nonce');
    echo '<input type="hidden" name="attc_manual_topup" value="1" />';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="attc_user_identifier">User (ID / Email / Username)</label></th>';
    echo '<td><input name="attc_user_identifier" id="attc_user_identifier" type="text" class="regular-text" required placeholder="ví dụ: 123 hoặc user@example.com"></td></tr>';
    echo '<tr><th scope="row"><label for="attc_amount">Số tiền (VND)</label></th>';
    echo '<td><input name="attc_amount" id="attc_amount" type="number" min="1" step="1" required></td></tr>';
    echo '</tbody></table>';
    submit_button('Nạp tiền');
    echo '</form>';

    echo '<h2>Trừ tiền thủ công</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('attc_manual_debit_action', 'attc_manual_debit_nonce');
    echo '<input type="hidden" name="attc_manual_debit" value="1" />';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="attc_user_identifier_debit">User (ID / Email / Username)</label></th>';
    echo '<td><input name="attc_user_identifier_debit" id="attc_user_identifier_debit" type="text" class="regular-text" required placeholder="ví dụ: 123 hoặc user@example.com"></td></tr>';
    echo '<tr><th scope="row"><label for="attc_amount_debit">Số tiền (VND)</label></th>';
    echo '<td><input name="attc_amount_debit" id="attc_amount_debit" type="number" min="1" step="1" required></td></tr>';
    echo '</tbody></table>';
    submit_button('Trừ tiền', 'delete');
    echo '</form>';

    // Search form
    echo '<h2>Tìm kiếm người dùng</h2>';
    $base_url = admin_url('admin.php?page=attc-wallet');
    echo '<form method="get" style="margin-bottom:12px;">';
    echo '<input type="hidden" name="page" value="attc-wallet" />';
    echo '<input type="search" name="q" value="' . esc_attr($q) . '" class="regular-text" placeholder="Email, Username hoặc Tên hiển thị"> ';
    submit_button('Tìm', 'secondary', '', false);
    echo ' <a class="button" href="' . esc_url($base_url) . '">Xóa lọc</a>';
    echo '</form>';

    echo '<h2>Danh sách ví người dùng</h2>';
    // Pagination (top)
    if ($total_pages > 1) {
        $pagination = paginate_links([
            'base'      => add_query_arg(['page' => 'attc-wallet', 'q' => $q, 'paged' => '%#%'], admin_url('admin.php')),
            'format'    => '',
            'current'   => $paged,
            'total'     => $total_pages,
            'prev_text' => '« Trước',
            'next_text' => 'Sau »',
            'type'      => 'list',
        ]);
        if ($pagination) {
            echo $pagination;
        }
        $start_i = $offset + 1;
        $end_i = $offset + count($users);
        echo '<p>Hiển thị <strong>' . (int)$start_i . '–' . (int)$end_i . '</strong> trên tổng <strong>' . (int)$total_users . '</strong> người dùng</p>';
    }
    echo '<table class="widefat fixed striped"><thead><tr>';
    echo '<th width="60">User ID</th><th>Tên</th><th>Email</th><th width="140">Số dư (đ)</th><th width="120">Phút khả dụng</th><th width="140">Đã từng nạp?</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . (int)$r['id'] . '</td>';
        echo '<td>' . esc_html($r['name']) . '</td>';
        echo '<td>' . esc_html($r['email']) . '</td>';
        echo '<td>' . number_format((int)$r['balance']) . '</td>';
        echo '<td>' . (int)$r['minutes'] . '</td>';
        echo '<td>' . ($r['has_deposit'] ? '<span style="color:green;font-weight:600;">Có</span>' : 'Chưa') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    // Pagination (bottom)
    if ($total_pages > 1) {
        $pagination = paginate_links([
            'base'      => add_query_arg(['page' => 'attc-wallet', 'q' => $q, 'paged' => '%#%'], admin_url('admin.php')),
            'format'    => '',
            'current'   => $paged,
            'total'     => $total_pages,
            'prev_text' => '« Trước',
            'next_text' => 'Sau »',
            'type'      => 'list',
        ]);
        if ($pagination) {
            echo $pagination;
        }
    }
    echo '</div>';
}


// ===== Audio Conversion Form Shortcode =====

function attc_charge_wallet($user_id, $amount, $meta = []) {
    $amount = (int) $amount;
    if ($amount <= 0) return true;

    $bal = attc_get_wallet_balance($user_id);
    if ($bal < $amount) {
        return new WP_Error('insufficient_funds', 'Số dư không đủ. Vui lòng nạp thêm.');
    }

    attc_set_wallet_balance($user_id, $bal - $amount);
    attc_add_wallet_history($user_id, [
        'type' => 'debit',
        'amount' => $amount,
        'meta' => $meta,
    ]);
    return true;
}

// Hàm kiểm tra xem người dùng đã từng nạp tiền chưa
function attc_user_has_made_deposit($user_id) {
    $history = get_user_meta($user_id, 'attc_wallet_history', true);
    if (empty($history) || !is_array($history)) {
        return false;
    }
    foreach ($history as $item) {
        // Chỉ cần một giao dịch 'credit' từ webhook là đủ để xác nhận đã nạp tiền
        if (isset($item['type'], $item['meta']['reason']) && $item['type'] === 'credit' && $item['meta']['reason'] === 'webhook_bank') {
            return true;
        }
    }
    return false;
}

function attc_handle_form_submission() {
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['attc_nonce']) && wp_verify_nonce($_POST['attc_nonce'], 'attc_form_action')) {
        if (!is_user_logged_in()) {
            wp_die('Vui lòng đăng nhập để sử dụng chức năng này.');
        }

        if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['audio_file'];
            $user_id = get_current_user_id();

            // Concurrency lock (mutex) per user to avoid multiple parallel API calls
            $lock_key = 'attc_processing_lock_' . $user_id;
            if (get_transient($lock_key)) {
                set_transient('attc_form_error', 'Yêu cầu trước của bạn đang được xử lý. Vui lòng đợi hoàn tất trước khi gửi thêm.', 20);
                attc_redirect_back();
            }
            // Set lock for 10 minutes max
            set_transient($lock_key, 1, 10 * MINUTE_IN_SECONDS);
            $price_per_minute = attc_get_price_per_minute();
            
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            $metadata = wp_read_audio_metadata($file['tmp_name']);
            $duration = !empty($metadata['length']) ? (int) $metadata['length'] : 0;

            if ($duration <= 0) {
                set_transient('attc_form_error', 'Không thể xác định thời lượng file hoặc file không hợp lệ.', 30);
                delete_transient($lock_key);
                attc_redirect_back();
            }

            // Rule miễn phí chỉ áp dụng khi số dư ví rất thấp (<= ngưỡng cấu hình)
            $balance = attc_get_wallet_balance($user_id);
            $free_threshold = (int) get_option('attc_free_threshold', 500);
            $is_free_tier_eligible = false;
            $cost = 0;
            $last_cost_message = '';

            // Logic Dùng thử vs Trả phí
            if ($balance <= $free_threshold) {
                // === LOGIC LUỒNG DÙNG THỬ ===
                $today = current_time('Y-m-d');
                $last_free_upload_date = get_user_meta($user_id, '_attc_last_free_upload_date', true);
                $free_uploads_today = ($last_free_upload_date === $today) ? (int)get_user_meta($user_id, '_attc_free_uploads_today', true) : 0;

                if ($free_uploads_today >= 1) {
                    set_transient('attc_form_error', 'Bạn đã hết lượt tải lên miễn phí hôm nay. Vui lòng chọn "Nâng cấp" để chuyển đổi.', 30);
                    delete_transient($lock_key);
                    attc_redirect_back();
                }
                if ($duration > 120) { // Giới hạn 2 phút
                    set_transient('attc_form_error', 'File của bạn vượt quá 2 phút. Lượt dùng thử chỉ áp dụng cho file dưới 2 phút.', 30);
                    delete_transient($lock_key);
                    attc_redirect_back();
                }

                $is_free_tier_eligible = true;
                $cost = 0;
                update_user_meta($user_id, '_attc_last_free_upload_date', $today);
                update_user_meta($user_id, '_attc_free_uploads_today', $free_uploads_today + 1);
                $last_cost_message = 'Chuyển đổi miễn phí thành công!';

            } else {
                // === LOGIC LUỒNG TRẢ PHÍ ===
                $minutes_ceil = ceil($duration / 60);
                $cost = $minutes_ceil * $price_per_minute;

                if ($balance < $cost) {
                    set_transient('attc_form_error', 'Số dư không đủ. Cần ' . number_format($cost) . 'đ, bạn chỉ có ' . number_format($balance) . 'đ. Vui lòng nạp thêm.', 30);
                    delete_transient($lock_key);
                    attc_redirect_back();
                }

                $charge_meta = ['reason' => 'audio_conversion', 'duration' => $duration, 'transcript' => ''];
                $charge_result = attc_charge_wallet($user_id, $cost, $charge_meta);
                 if (is_wp_error($charge_result)) {
                    set_transient('attc_form_error', $charge_result->get_error_message(), 30);
                    delete_transient($lock_key);
                    attc_redirect_back();
                }
                $last_cost_message = 'Chi phí cho file vừa rồi: ' . number_format($cost) . 'đ cho ' . $minutes_ceil . ' phút.';
            }

            // === XỬ LÝ CHUNG: GỌI API VÀ TRẢ KẾT QUẢ ===
            $api_key = get_option('attc_openai_api_key');
            if (empty($api_key)) {
                set_transient('attc_form_error', 'Lỗi: Quản trị viên chưa cấu hình OpenAI API Key.', 30);
                if (!$is_free_tier_eligible && $cost > 0) {
                    attc_credit_wallet($user_id, $cost, ['reason' => 'refund_api_key_missing']);
                }
                delete_transient($lock_key);
                attc_redirect_back();
            }

            $api_url = 'https://api.openai.com/v1/audio/transcriptions';
            $headers = ['Authorization: Bearer ' . $api_key];
            $post_fields = [
                'model' => 'whisper-1',
                'file' => curl_file_create($file['tmp_name'], mime_content_type($file['tmp_name']), basename($file['name']))
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);

            $response_body_str = curl_exec($ch);
            $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                set_transient('attc_form_error', 'Lỗi cURL: ' . $curl_error, 30);
                if (!$is_free_tier_eligible && $cost > 0) { attc_credit_wallet($user_id, $cost, ['reason' => 'refund_curl_error']); }
                delete_transient($lock_key);
                attc_redirect_back();
            }

            $response_body = json_decode($response_body_str, true);

            if ($response_code !== 200) {
                $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Lỗi không xác định từ OpenAI.';
                set_transient('attc_form_error', 'Lỗi từ OpenAI (Code: ' . $response_code . '): ' . $error_message, 30);
                if (!$is_free_tier_eligible && $cost > 0) { attc_credit_wallet($user_id, $cost, ['reason' => 'refund_openai_error']); }
                delete_transient($lock_key);
                attc_redirect_back();
            }

            $transcript = $response_body['text'] ?? '';

            // Cập nhật lịch sử giao dịch với nội dung transcript TRƯỚC khi chuyển hướng
            if (!$is_free_tier_eligible) {
                $history = get_user_meta($user_id, 'attc_wallet_history', true);
                if (!empty($history)) {
                    $last_key = array_key_last($history);
                    if (isset($history[$last_key]['meta']['reason']) && $history[$last_key]['meta']['reason'] === 'audio_conversion') {
                        $history[$last_key]['meta']['transcript'] = $transcript;
                        update_user_meta($user_id, 'attc_wallet_history', $history);
                    }
                }
            }
            
            set_transient('attc_form_success', $transcript, 30);
            set_transient('attc_last_cost', $last_cost_message, 30);

            // Chuyển hướng để tải lại trang và cập nhật giao diện
            delete_transient($lock_key);
            attc_redirect_back();
        }
    }
}
add_action('init', 'attc_handle_form_submission');

function attc_form_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="attc-auth-prompt"><p>Vui lòng <a href="' . esc_url(site_url('/dang-nhap')) . '">đăng nhập</a> hoặc <a href="' . esc_url(site_url('/dang-ky')) . '">đăng ký</a> để sử dụng chức năng này.</p></div>';
    }

    $user_id = get_current_user_id();
    $balance = attc_get_wallet_balance($user_id);
    $price_per_minute = attc_get_price_per_minute();
    $max_minutes = $price_per_minute > 0 ? floor($balance / $price_per_minute) : 0;

    $error_message = get_transient('attc_form_error');
    if ($error_message) delete_transient('attc_form_error');

    $success_message = get_transient('attc_form_success');
    if ($success_message) delete_transient('attc_form_success');

    $last_cost = get_transient('attc_last_cost');
    if ($last_cost) delete_transient('attc_last_cost');

    ob_start();
    ?>
    <style>
        .attc-converter-wrap { max-width: 700px; margin: 2rem auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
        .attc-wallet-box { background: #f0f4f8; border-radius: 8px; padding: 1.5rem; display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        .attc-wallet-balance p { margin: 0; font-size: 1.1rem; } .attc-wallet-balance p strong { font-size: 1.5rem; color: #2c3e50; }
        .attc-wallet-sub { color: #5a6e82; font-size: 0.9rem; }
        .attc-wallet-actions a { text-decoration: none; padding: 0.6rem 1rem; border-radius: 5px; font-weight: 500; transition: background-color 0.2s; }
        .attc-wallet-actions .upgrade-btn { background-color: #3498db; color: white; }
        .attc-wallet-actions .history-btn { background-color: #95a5a6; color: white; }
        .attc-wallet-actions .logout-btn { background-color: #e74c3c; color: white; margin-left: 0.5rem; }
        .attc-alert { padding: 1rem; margin-bottom: 1.5rem; border-radius: 5px; border: 1px solid transparent; }
        .attc-error { color: #c0392b; background-color: #fbeae5; border-color: #f4c2c2; }
        .attc-info { color: #2980b9; background-color: #eaf5fb; border-color: #b3dcf4; }
        .attc-form-upload { border: 2px dashed #bdc3c7; border-radius: 8px; padding: 2rem; text-align: center; background-color: #fafafa; transition: background-color 0.2s, border-color 0.2s; }
        .attc-form-upload.is-dragover { background-color: #ecf0f1; border-color: #3498db; }
        .attc-form-upload p { margin: 0 0 1rem; font-size: 1.2rem; color: #7f8c8d; }
        .attc-form-upload .file-input-label { cursor: pointer; color: #3498db; font-weight: 500; display: inline-block; padding: 0.5rem 1rem; border: 1px solid #3498db; border-radius: 5px; } 
        .attc-form-upload input[type="file"] { display: none; }
        #attc-file-info { margin-top: 1rem; font-style: italic; color: #2c3e50; }
        .attc-submit-btn { width: 100%; background-color: #27ae60; color: white; font-size: 1.2rem; padding: 0.8rem; border: none; border-radius: 5px; cursor: pointer; margin-top: 1.5rem; transition: background-color 0.2s; }
        .attc-submit-btn:hover { background-color: #2ecc71; }
        .attc-submit-btn:disabled { background-color: #95a5a6; cursor: not-allowed; }
        .attc-result { margin-top: 2rem; } .attc-result h3 { margin-bottom: 0.5rem; } 
.attc-result-text { width: 100%; border-radius: 5px; border: 1px solid #dfe4e8; padding: 1rem; background-color: #fdfdfd; font-size: 1.1em; line-height: 1.6; min-height: 150px; }
.attc-result-actions { margin-top: 1.5rem; text-align: right; }
.attc-result-actions .download-btn { 
    display: inline-block;
    background-color: #2980b9;
    color: white !important;
    padding: 0.8rem 1.5rem;
    border-radius: 5px;
    text-decoration: none;
    font-weight: 500;
    transition: background-color 0.2s;
}
.attc-result-actions .download-btn:hover { background-color: #3498db; }
        .attc-result-actions { margin-top: 1.5rem; text-align: right; }
        .attc-result-actions .download-btn { 
            display: inline-block;
            background-color: #2980b9;
            color: white !important;
            padding: 0.8rem 1.5rem;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            transition: background-color 0.2s;
        }
        .attc-result-actions .download-btn:hover { background-color: #3498db; }
        /* Progress UI */
        .is-hidden { display: none; }
        .attc-progress { margin-top: 14px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; }
        .attc-progress-label { display:flex; align-items:center; gap:8px; color:#0f172a; font-weight:600; margin-bottom:8px; }
        .attc-spinner { width:16px; height:16px; border:2px solid #93c5fd; border-top-color:#1d4ed8; border-radius:50%; animation: attc-spin 0.8s linear infinite; }
        @keyframes attc-spin { from { transform: rotate(0deg);} to { transform: rotate(360deg);} }
        .attc-progress-outer { width:100%; height:10px; background:#e5e7eb; border-radius:999px; overflow:hidden; }
        .attc-progress-inner { height:100%; width:0%; background:#1d4ed8; transition: width .3s ease; }
        .attc-progress-text { font-size:12px; color:#334155; margin-top:6px; }
    </style>

    <?php
    $user_id = get_current_user_id();
    $has_made_deposit = attc_user_has_made_deposit($user_id);
    $today = current_time('Y-m-d');
    $last_free_upload_date = get_user_meta($user_id, '_attc_last_free_upload_date', true);
    $free_uploads_today = ($last_free_upload_date === $today) ? (int)get_user_meta($user_id, '_attc_free_uploads_today', true) : 0;
    $free_uploads_left = max(0, 1 - $free_uploads_today);
    $display_name = wp_get_current_user()->display_name;
    ?>
    <div class="attc-converter-wrap">
        <div class="attc-wallet-box">
            <div class="attc-wallet-balance">
                <?php if ($balance > $free_threshold): ?>
                    <p><strong><?php echo number_format($balance, 0, ',', '.'); ?>đ</strong></p>
                    <p class="attc-wallet-sub">Tương đương <strong><?php echo $max_minutes; ?></strong> phút chuyển đổi</p>
                <?php else: ?>
                    <p>Hôm nay bạn còn: <strong style="color: red; font-weight: bold; "><?php echo $free_uploads_left; ?></strong> lượt chuyển đổi miễn phí</p>
                    <p class="attc-wallet-sub">Mỗi ngày miễn phí chuyển đổi 1 File ghi âm < 2 phút</p>
                <?php endif; ?>
            </div>
            <p class="attc-user-name"><strong><?php echo esc_html($display_name); ?></strong></p>
            <div class="attc-wallet-actions">
                <a href="/lich-su-chuyen-doi" class="history-btn">Lịch sử</a>
                <a href="/nang-cap" class="upgrade-btn">Nâng cấp</a>
                <a href="<?php echo wp_logout_url(get_permalink()); ?>" class="logout-btn">Đăng xuất</a>
            </div>
        </div>

        <p class="attc-wallet-sub" style="margin-top:-8px; margin-bottom: 16px; color:#5a6e82;">
            Đơn giá hiện tại: <strong><?php echo number_format((int)$price_per_minute, 0, ',', '.'); ?> đ/phút</strong>
        </p>

        <?php 
        $success_notification = get_transient('attc_registration_success');
        if ($success_notification) {
            delete_transient('attc_registration_success');
            echo '<div class="attc-alert attc-info">' . esc_html($success_notification) . '</div>';
        }
        ?>
        <?php if ($error_message): ?><div class="attc-alert attc-error"><?php echo esc_html($error_message); ?></div><?php endif; ?>
        <?php if ($last_cost): ?><div class="attc-alert attc-info"><?php echo esc_html($last_cost); ?></div><?php endif; ?>

        <form action="" method="post" enctype="multipart/form-data" id="attc-upload-form">
            <?php wp_nonce_field('attc_form_action', 'attc_nonce'); ?>
            <div class="attc-form-upload" id="attc-drop-zone">
                <p>Kéo và thả file ghi âm vào đây, hoặc</p>
                <label for="audio_file" class="file-input-label">Chọn từ máy tính</label>
                <input type="file" id="audio_file" name="audio_file" accept="audio/*">
                <div id="attc-file-info"></div>
            </div>
            <button type="submit" class="attc-submit-btn" id="attc-submit-button" disabled>Chuyển đổi</button>
            <div id="attc-progress" class="attc-progress is-hidden" aria-live="polite">
                <div class="attc-progress-label"><span class="attc-spinner" aria-hidden="true"></span><span>Đang xử lý...</span></div>
                <div class="attc-progress-outer"><div class="attc-progress-inner" id="attc-progress-bar"></div></div>
                <div class="attc-progress-text" id="attc-progress-text">Bắt đầu xử lý (0%)</div>
            </div>
        </form>

        <?php if ($success_message): 
            $download_link = '';
            $history = get_user_meta($user_id, 'attc_wallet_history', true);
            if (!empty($history)) {
                $last_item = end($history);
                if (($last_item['meta']['reason'] ?? '') === 'audio_conversion') {
                     $download_nonce = wp_create_nonce('attc_download_' . $last_item['timestamp']);
                     $download_link = add_query_arg([
                        'action' => 'attc_download_transcript',
                        'timestamp' => $last_item['timestamp'],
                        'nonce' => $download_nonce,
                    ], home_url());
                }
            }
        ?>
        <div class="attc-result">
            <h3>Kết quả chuyển đổi:</h3>
            <div class="attc-result-text"><?php echo nl2br(esc_html($success_message)); ?></div>
            <?php if ($download_link): ?>
            <div class="attc-result-actions">
                <a href="<?php echo esc_url($download_link); ?>" class="download-btn">Tải kết quả (.docx)</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const dropZone = document.getElementById('attc-drop-zone');
        const fileInput = document.getElementById('audio_file');
        const fileInfo = document.getElementById('attc-file-info');
        const submitButton = document.getElementById('attc-submit-button');
        const uploadForm = document.getElementById('attc-upload-form');
        const progressWrap = document.getElementById('attc-progress');
        const progressBar = document.getElementById('attc-progress-bar');
        const progressText = document.getElementById('attc-progress-text');
        let progressTimer = null;

        if (!dropZone || !uploadForm) return;

        uploadForm.addEventListener('submit', function() {
            if (submitButton.disabled) return;
            submitButton.disabled = true;
            submitButton.textContent = 'Đang xử lý, vui lòng chờ...';
            if (progressWrap) {
                progressWrap.classList.remove('is-hidden');
                startFakeProgress();
            }
        });

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.add('is-dragover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.remove('is-dragover'), false);
        });

        dropZone.addEventListener('drop', handleDrop, false);
        fileInput.addEventListener('change', handleFileSelect, false);

        function handleDrop(e) {
            let dt = e.dataTransfer;
            let files = dt.files;
            handleFiles(files);
        }

        function handleFileSelect(e) {
            handleFiles(e.target.files);
        }

        function handleFiles(files) {
            if (files.length > 0) {
                fileInput.files = files; // Gán file vào input để form có thể submit
                const file = files[0];
                fileInfo.textContent = `File đã chọn: ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
                submitButton.disabled = false;
                resetFakeProgress();
            }
        }

        function startFakeProgress() {
            let p = 0;
            updateProgress(p);
            clearInterval(progressTimer);
            progressTimer = setInterval(() => {
                // Tăng chậm dần đến 90%, chờ server trả về sẽ reload trang
                if (p < 90) {
                    const step = p < 50 ? 4 : (p < 75 ? 2 : 1);
                    p = Math.min(90, p + step);
                    updateProgress(p);
                }
            }, 500);
        }

        function resetFakeProgress() {
            if (!progressWrap) return;
            progressWrap.classList.add('is-hidden');
            updateProgress(0);
            clearInterval(progressTimer);
        }

        function updateProgress(val) {
            if (progressBar) progressBar.style.width = val + '%';
            if (progressText) progressText.textContent = `Đang xử lý (${val}%)`;
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('audio_to_text_form', 'attc_form_shortcode');


// ===== AUTHENTICATION SHORTCODES & HANDLERS =====

// Chuyển hướng người dùng đã đăng nhập khỏi trang đăng nhập/đăng ký
function attc_redirect_logged_in_user() {
    if (is_user_logged_in() && (is_page('dang-nhap') || is_page('dang-ky'))) {
        wp_redirect(site_url('/chuyen-doi-giong-noi/'));
        exit();
    }
}
add_action('template_redirect', 'attc_redirect_logged_in_user');

// Nạp scripts và styles cho các trang xác thực
function attc_auth_assets() {
    if (is_page('dang-nhap') || is_page('dang-ky') || is_page('quen-mat-khau') || is_page('dat-lai-mat-khau')) {
        // Nạp script reCAPTCHA
        $site_key = get_option('attc_recaptcha_site_key');
        if (!empty($site_key)) {
            wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js', [], null, true);
        }

        // In CSS cho form
        $auth_css = "
        <style>
            .attc-auth-form-wrap {
                max-width: 420px;
                margin: 3rem auto;
                padding: 2.5rem;
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
                font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif;
            }
            .attc-auth-form-wrap h2 {
                text-align: center;
                margin-bottom: 2rem;
                font-size: 1.8rem;
                color: #333;
            }
            .attc-auth-form-wrap .form-row {
                margin-bottom: 1.5rem;
            }
            .attc-auth-form-wrap label {
                display: block;
                margin-bottom: 0.5rem;
                font-weight: 600;
                color: #555;
            }
            .attc-auth-form-wrap input[type='text'], 
            .attc-auth-form-wrap input[type='password'], 
            .attc-auth-form-wrap input[type='email'] {
                box-sizing: border-box;
                width: 100%;
                padding: 0.8rem 1rem;
                border: 1px solid #ddd;
                border-radius: 8px;
                transition: border-color 0.2s, box-shadow 0.2s;
            }
            .attc-auth-form-wrap input:focus {
                outline: none;
                border-color: #3498db;
                box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            }
            .attc-auth-form-wrap .submit-row {
                margin-top: 2rem;
            }
            .attc-auth-form-wrap .submit-row input {
                width: 100%;
                padding: 0.9rem;
                background: #27ae60;
                color: white;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                font-size: 1.1em;
                font-weight: 600;
                transition: background-color 0.2s;
            }
            .attc-auth-form-wrap .submit-row input:hover {
                background: #2ecc71;
            }
            .attc-auth-errors, .attc-auth-info {
                padding: 1rem;
                border-radius: 8px;
                margin-bottom: 1.5rem;
                list-style: none;
                margin-left: 0;
            }
            .attc-auth-errors { color: #c0392b; background: #fbeae5; }
            .attc-auth-info { color: #2980b9; background-color: #eaf5fb; }
            .attc-auth-switch {
                text-align: center;
                margin-top: 2rem;
                display: flex;
                flex-direction: column;
                gap: 0.8rem;
                color: #555;
            }
            .attc-auth-switch a { color: #3498db; text-decoration: none; font-weight: 600; }
            .attc-auth-switch a:hover { text-decoration: underline; }
            .g-recaptcha {
                margin-bottom: 1.5rem;
                display: flex;
                justify-content: center;
            }
        </style>
        ";
        echo $auth_css;
    }
}
add_action('wp_head', 'attc_auth_assets');

// Hàm xác thực reCAPTCHA
function attc_verify_recaptcha($response) {
    $secret_key = get_option('attc_recaptcha_secret_key');
    if (empty($secret_key)) return true; // Bỏ qua nếu chưa cấu hình
    if (empty($response)) return false;

    $verification_url = 'https://www.google.com/recaptcha/api/siteverify';
    $verification_response = wp_remote_post($verification_url, [
        'body' => [
            'secret'   => $secret_key,
            'response' => $response,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ]
    ]);

    if (is_wp_error($verification_response)) return false;

    $result = json_decode(wp_remote_retrieve_body($verification_response), true);
    return $result['success'] ?? false;
}

// ---- Login Form ----
function attc_login_form_shortcode() {
    if (is_user_logged_in()) return '';

    $login_errors = get_transient('attc_login_errors');
    if ($login_errors) delete_transient('attc_login_errors');

    ob_start();
    ?>
    <div class="attc-auth-form-wrap">
        <h2>Đăng nhập</h2>
        <?php if ($login_errors): ?>
            <div class="attc-auth-errors"><p><?php echo esc_html($login_errors); ?></p></div>
        <?php endif; ?>
        <form action="" method="post">
            <div class="form-row">
                <label for="attc_log">Tên đăng nhập hoặc Email</label>
                <input type="text" name="log" id="attc_log" required>
            </div>
            <div class="form-row">
                <label for="attc_pwd">Mật khẩu</label>
                <input type="password" name="pwd" id="attc_pwd" required>
            </div>
            <?php $site_key = get_option('attc_recaptcha_site_key'); if (!empty($site_key)): ?>
            <div class="form-row g-recaptcha" data-sitekey="<?php echo esc_attr($site_key); ?>"></div>
            <?php endif; ?>
            <div class="submit-row">
                <?php wp_nonce_field('attc_login_action', 'attc_login_nonce'); ?>
                <input type="submit" name="attc_login_submit" value="Đăng nhập">
            </div>
        </form>
        <div class="attc-auth-switch">
            <p><a href="<?php echo esc_url(site_url('/quen-mat-khau/')); ?>">Quên mật khẩu?</a></p>
            <p>Chưa có tài khoản? <a href="<?php echo esc_url(site_url('/dang-ky')); ?>">Đăng ký ngay</a></p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('attc_login_form', 'attc_login_form_shortcode');

function attc_handle_custom_login_form() {
    // Chỉ thực thi khi form đăng nhập được gửi đi
    if (!isset($_POST['attc_login_submit'])) {
        return;
    }

    if (wp_verify_nonce($_POST['attc_login_nonce'], 'attc_login_action')) {
        
        $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
        if (!attc_verify_recaptcha($recaptcha_response)) {
            set_transient('attc_login_errors', 'Xác thực reCAPTCHA không thành công. Vui lòng thử lại.', 30);
            wp_redirect(site_url('/dang-nhap'));
            exit;
        }

        $creds = [
            'user_login'    => $_POST['log'],
            'user_password' => $_POST['pwd'],
            'remember'      => true
        ];
        $user = wp_signon($creds, false);

        if (is_wp_error($user)) {
            set_transient('attc_login_errors', 'Sai tên đăng nhập hoặc mật khẩu.', 30);
            wp_redirect(site_url('/dang-nhap'));
            exit;
        } else {
            wp_redirect(site_url('/chuyen-doi-giong-noi/'));
            exit;
        }
    }
}
add_action('template_redirect', 'attc_handle_custom_login_form');


// ---- Register Form ----
function attc_register_form_shortcode() {
    if (is_user_logged_in()) return '';

    $reg_errors = get_transient('attc_registration_errors');

    ob_start();
    ?>
    <div class="attc-auth-form-wrap">
        <h2>Đăng ký tài khoản</h2>
        <?php 
        if ($reg_errors && is_array($reg_errors)) {
            echo '<div class="attc-auth-errors">';
            foreach ($reg_errors as $error) {
                echo '<p>' . esc_html($error) . '</p>';
            }
            echo '</div>';
            delete_transient('attc_registration_errors'); // Xóa transient SAU KHI hiển thị
        }
        ?>
        <form action="" method="post">
            <div class="form-row"><label for="attc_reg_user">Tên người dùng</label><input type="text" name="reg_user" id="attc_reg_user" required></div>
            <div class="form-row"><label for="attc_reg_email">Email</label><input type="email" name="reg_email" id="attc_reg_email" required></div>
            <div class="form-row"><label for="attc_reg_pass">Mật khẩu</label><input type="password" name="reg_pass" id="attc_reg_pass" required></div>
            <div class="form-row"><label for="attc_reg_pass2">Xác nhận mật khẩu</label><input type="password" name="reg_pass2" id="attc_reg_pass2" required></div>
            <?php $site_key = get_option('attc_recaptcha_site_key'); if (!empty($site_key)): ?>
            <div class="form-row g-recaptcha" data-sitekey="<?php echo esc_attr($site_key); ?>"></div>
            <?php endif; ?>
            <div class="submit-row">
                <?php wp_nonce_field('attc_register_action', 'attc_register_nonce'); ?>
                <input type="submit" name="attc_register_submit" value="Đăng ký">
            </div>
        </form>
        <div class="attc-auth-switch">
            <p>Đã có tài khoản? <a href="<?php echo esc_url(site_url('/dang-nhap')); ?>">Đăng nhập</a></p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('attc_register_form', 'attc_register_form_shortcode');

function attc_handle_custom_registration_form() {
    // Chỉ thực thi khi form đăng ký được gửi đi
    if (!isset($_POST['attc_register_submit'])) {
        return;
    }

    if (wp_verify_nonce($_POST['attc_register_nonce'], 'attc_register_action')) {
        $username = sanitize_user($_POST['reg_user']);
        $email = sanitize_email($_POST['reg_email']);
        $password = $_POST['reg_pass'];
        $password2 = $_POST['reg_pass2'];
        $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
        $errors = new WP_Error();

        if (!attc_verify_recaptcha($recaptcha_response)) {
            $errors->add('recaptcha_failed', 'Xác thực reCAPTCHA không thành công. Vui lòng thử lại.');
        }
        if (empty($username) || empty($email) || empty($password) || empty($password2)) {
            $errors->add('field_required', 'Tất cả các trường là bắt buộc.');
        }
        if ($password !== $password2) {
            $errors->add('password_mismatch', 'Mật khẩu xác nhận không khớp.');
        }
        if (strlen($password) < 6) {
            $errors->add('password_length', 'Mật khẩu phải có ít nhất 6 ký tự.');
        }
        if (username_exists($username)) {
            $errors->add('username_exists', 'Tên người dùng này đã tồn tại.');
        }
        if (email_exists($email)) {
            $errors->add('email_exists', 'Địa chỉ email này đã được sử dụng.');
        }
        if (!is_email($email)) {
            $errors->add('invalid_email', 'Địa chỉ email không hợp lệ.');
        }

        if ($errors->has_errors()) {
            set_transient('attc_registration_errors', $errors->get_error_messages(), 30);
            wp_redirect(site_url('/dang-ky'));
            exit;
        } else {
            $user_id = wp_create_user($username, $password, $email);
            if (!is_wp_error($user_id)) {
                // Đặt transient thông báo thành công
                set_transient('attc_registration_success', 'Đăng ký thành công! Bạn đã được tự động đăng nhập.', 30);
                
                // Tự động đăng nhập
                wp_set_current_user($user_id, $username);
                wp_set_auth_cookie($user_id, true, false);
                wp_redirect(site_url('/chuyen-doi-giong-noi/'));
                exit;
            } else {
                // Nếu wp_create_user thất bại, lấy lỗi và hiển thị
                set_transient('attc_registration_errors', $user_id->get_error_messages(), 30);
                wp_redirect(site_url('/dang-ky'));
                exit;
            }
        }
    }
}
add_action('template_redirect', 'attc_handle_custom_registration_form');


// ---- Lost & Reset Password Forms ----

function attc_lost_password_form_shortcode() {
    if (is_user_logged_in()) return '';
    $message = get_transient('attc_password_reset_message');
    if ($message) delete_transient('attc_password_reset_message');

    ob_start();
    ?>
    <div class="attc-auth-form-wrap">
        <h2>Quên mật khẩu</h2>
        <?php if ($message): ?>
            <div class="attc-auth-info"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>
        <p>Vui lòng nhập địa chỉ email của bạn. Bạn sẽ nhận được một liên kết để tạo mật khẩu mới qua email.</p>
        <form action="" method="post">
            <div class="form-row">
                <label for="attc_user_login">Email</label>
                <input type="email" name="user_login" id="attc_user_login" required>
            </div>
            <div class="submit-row">
                <?php wp_nonce_field('attc_lost_password_action', 'attc_lost_password_nonce'); ?>
                <input type="submit" name="attc_lost_password_submit" value="Lấy mật khẩu mới">
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('attc_forgot_form', 'attc_lost_password_form_shortcode');

function attc_handle_lost_password() {
    if (isset($_POST['attc_lost_password_submit']) && wp_verify_nonce($_POST['attc_lost_password_nonce'], 'attc_lost_password_action')) {
        $user_login = $_POST['user_login'];
        $user_data = get_user_by('email', trim($user_login));

        if (empty($user_data)) {
            $user_data = get_user_by('login', trim($user_login));
        }

        if (!$user_data) {
            set_transient('attc_password_reset_message', 'Lỗi: Không có người dùng nào được đăng ký với địa chỉ email hoặc tên người dùng đó.', 30);
            wp_redirect(site_url('/quen-mat-khau'));
            exit;
        }

        $key = get_password_reset_key($user_data);
        if (is_wp_error($key)) {
            set_transient('attc_password_reset_message', 'Lỗi: Không thể tạo khóa đặt lại mật khẩu.', 30);
            wp_redirect(site_url('/quen-mat-khau'));
            exit;
        }

        $reset_link = add_query_arg(['key' => $key, 'login' => rawurlencode($user_data->user_login)], site_url('/dat-lai-mat-khau/'));
        
        $message = "Ai đó đã yêu cầu đặt lại mật khẩu cho tài khoản sau:\r\n\r\n";
        $message .= network_home_url('/') . "\r\n\r\n";
        $message .= sprintf("Tên người dùng: %s\r\n\r\n", $user_data->user_login);
        $message .= "Nếu đây là một lỗi, chỉ cần bỏ qua email này và sẽ không có gì xảy ra.\r\n\r\n";
        $message .= "Để đặt lại mật khẩu của bạn, hãy truy cập địa chỉ sau:\r\n\r\n";
        $message .= $reset_link . "\r\n";

        wp_mail($user_data->user_email, 'Yêu cầu đặt lại mật khẩu', $message);

        set_transient('attc_password_reset_message', 'Kiểm tra email của bạn để có liên kết xác nhận.', 300);
        wp_redirect(site_url('/quen-mat-khau'));
        exit;
    }
}
add_action('template_redirect', 'attc_handle_lost_password');

function attc_reset_password_form_shortcode() {
    $reset_key = $_GET['key'] ?? '';
    $reset_login = $_GET['login'] ?? '';
    $user = check_password_reset_key($reset_key, $reset_login);

    $message = get_transient('attc_password_reset_message');
    if ($message) delete_transient('attc_password_reset_message');

    ob_start();
    if (is_wp_error($user)) {
        echo '<div class="attc-auth-form-wrap"><div class="attc-auth-errors"><p>Link đặt lại mật khẩu không hợp lệ hoặc đã hết hạn. Vui lòng thử lại.</p></div></div>';
    } else {
        ?>
        <div class="attc-auth-form-wrap">
            <h2>Đặt lại mật khẩu</h2>
            <?php if ($message): ?>
                <div class="attc-auth-errors"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>
            <form action="" method="post">
                <input type="hidden" name="reset_key" value="<?php echo esc_attr($reset_key); ?>">
                <input type="hidden" name="reset_login" value="<?php echo esc_attr($reset_login); ?>">
                <div class="form-row">
                    <label for="pass1">Mật khẩu mới</label>
                    <input type="password" name="pass1" id="pass1" required>
                </div>
                <div class="form-row">
                    <label for="pass2">Nhập lại mật khẩu mới</label>
                    <input type="password" name="pass2" id="pass2" required>
                </div>
                <div class="submit-row">
                     <?php wp_nonce_field('attc_reset_password_action', 'attc_reset_password_nonce'); ?>
                    <input type="submit" name="attc_reset_password_submit" value="Lưu mật khẩu">
                </div>
            </form>
        </div>
        <?php
    }
    return ob_get_clean();
}
add_shortcode('attc_reset_password_form', 'attc_reset_password_form_shortcode');

function attc_handle_reset_password() {
    if (isset($_POST['attc_reset_password_submit']) && wp_verify_nonce($_POST['attc_reset_password_nonce'], 'attc_reset_password_action')) {
        $key = $_POST['reset_key'];
        $login = $_POST['reset_login'];
        $pass1 = $_POST['pass1'];
        $pass2 = $_POST['pass2'];

        $user = check_password_reset_key($key, $login);
        if (is_wp_error($user)) {
            set_transient('attc_password_reset_message', $user->get_error_message(), 30);
            wp_redirect(add_query_arg(['key' => $key, 'login' => $login], site_url('/dat-lai-mat-khau/')));
            exit;
        }

        if ($pass1 !== $pass2) {
            set_transient('attc_password_reset_message', 'Hai mật khẩu không khớp.', 30);
            wp_redirect(add_query_arg(['key' => $key, 'login' => $login], site_url('/dat-lai-mat-khau/')));
            exit;
        }

        reset_password($user, $pass1);
        set_transient('attc_login_errors', 'Mật khẩu của bạn đã được đặt lại thành công. Vui lòng đăng nhập.', 30);
        wp_redirect(site_url('/dang-nhap'));
        exit;
    }
}
add_action('template_redirect', 'attc_handle_reset_password');

// ===== Plugin Settings Page =====

function attc_register_settings_page() {
    add_options_page(
        'Audio to Text Converter Settings',
        'Audio to Text Converter',
        'manage_options',
        'attc-settings',
        'attc_settings_page_html'
    );
}
add_action('admin_menu', 'attc_register_settings_page');

function attc_settings_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_GET['settings-updated'])) {
        add_settings_error('attc_messages', 'attc_message', 'Cài đặt đã được lưu.', 'updated');
    }

    settings_errors('attc_messages');
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('attc_settings');
            do_settings_sections('attc-settings');
            submit_button('Lưu cài đặt');
            ?>
        </form>
    </div>
    <?php
}

function attc_settings_init() {
    // OpenAI Section
    register_setting('attc_settings', 'attc_openai_api_key');
    add_settings_section('attc_openai_section', 'Cài đặt OpenAI API', null, 'attc-settings');
    add_settings_field(
        'attc_openai_api_key',
        'OpenAI API Key',
        'attc_settings_field_html',
        'attc-settings',
        'attc_openai_section',
        ['name' => 'attc_openai_api_key', 'type' => 'password', 'desc' => 'Nhập API Key của bạn từ tài khoản OpenAI.']
    );

    // reCAPTCHA Section
    register_setting('attc_settings', 'attc_recaptcha_site_key');
    register_setting('attc_settings', 'attc_recaptcha_secret_key');
    add_settings_section('attc_recaptcha_section', 'Cài đặt Google reCAPTCHA v2', null, 'attc-settings');
    add_settings_field(
        'attc_recaptcha_site_key',
        'Site Key',
        'attc_settings_field_html',
        'attc-settings',
        'attc_recaptcha_section',
        ['name' => 'attc_recaptcha_site_key', 'type' => 'text', 'desc' => 'Lấy từ trang quản trị Google reCAPTCHA.']
    );
    add_settings_field(
        'attc_recaptcha_secret_key',
        'Secret Key',
        'attc_settings_field_html',
        'attc-settings',
        'attc_recaptcha_section',
        ['name' => 'attc_recaptcha_secret_key', 'type' => 'password', 'desc' => 'Lấy từ trang quản trị Google reCAPTCHA.']
    );

    // Pricing/Rules Section
    register_setting('attc_settings', 'attc_price_per_minute');
    register_setting('attc_settings', 'attc_free_threshold');
    add_settings_section('attc_pricing_section', 'Cài đặt Quy tắc & Giá', null, 'attc-settings');
    add_settings_field(
        'attc_price_per_minute',
        'Đơn giá mỗi phút (VND/phút)',
        'attc_settings_field_html',
        'attc-settings',
        'attc_pricing_section',
        ['name' => 'attc_price_per_minute', 'type' => 'number', 'desc' => 'Ví dụ 500 (đồng/phút). Dùng để tính phí chuyển đổi.']
    );
    add_settings_field(
        'attc_free_threshold',
        'Ngưỡng miễn phí (VND)',
        'attc_settings_field_html',
        'attc-settings',
        'attc_pricing_section',
        ['name' => 'attc_free_threshold', 'type' => 'number', 'desc' => 'Nếu số dư ví <= giá trị này thì áp dụng lượt miễn phí (mặc định 500).']
    );
}
add_action('admin_init', 'attc_settings_init');

function attc_settings_field_html($args) {
    $name = $args['name'];
    $type = $args['type'];
    $desc = $args['desc'];
    $value = get_option($name);
    printf(
        '<input type="%s" name="%s" value="%s" class="regular-text"> <p class="description">%s</p>',
        esc_attr($type),
        esc_attr($name),
        esc_attr($value),
        esc_html($desc)
    );
}







