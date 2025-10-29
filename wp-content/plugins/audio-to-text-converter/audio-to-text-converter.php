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

// Permission for fake-topup: allow admin OR valid token key
function attc_fake_topup_can(\WP_REST_Request $request) {
    if (current_user_can('manage_options')) return true;
    $provided = (string) ($request->get_param('key') ?? '');
    $expected = (string) get_option('attc_fake_topup_key', '');
    if ($expected === '' || $provided === '') return false;
    if (function_exists('hash_equals')) return hash_equals($expected, $provided);
    return $expected === $provided;
}

// Admin/token: fake top-up to trigger /nang-cap success UI (and optionally add balance)
function attc_fake_topup(\WP_REST_Request $request) {

    $uid    = (int) ($request->get_param('user_id') ?? $request->get_param('uid') ?? 0);
    $amount = (int) ($request->get_param('amount') ?? 0);
    $mode   = sanitize_text_field($request->get_param('mode') ?? 'transient'); // transient|credit

    if ($uid <= 0 || $amount <= 0) {
        return new WP_REST_Response(['status' => 'invalid_params'], 400);
    }

    // LƯU Ý: Xử lý form admin simulate-topup đã được chuyển sang attc_render_wallet_dashboard().

    if ($mode === 'credit') {
        $res = attc_credit_wallet($uid, $amount, [
            'reason' => 'fake_topup',
            'note'   => 'simulate via admin endpoint',
            'admin'  => get_current_user_id(),
        ]);
        if (is_wp_error($res)) {
            return new WP_REST_Response(['status' => 'credit_error', 'message' => $res->get_error_message()], 200);
        }
        // attc_credit_wallet already sets transient for UI
        return new WP_REST_Response(['status' => 'credited', 'user_id' => $uid, 'amount' => $amount], 200);
    }

    // Default: only trigger UI without changing balance (set both transient + user_meta)
    $payment_data = ['amount' => $amount, 'time' => time()];
    set_transient('attc_payment_success_' . $uid, $payment_data, 60);
    update_user_meta($uid, 'attc_payment_success', $payment_data);
    return new WP_REST_Response(['status' => 'triggered', 'user_id' => $uid, 'amount' => $amount], 200);
}

// Gửi email kích hoạt tài khoản cho user (tạo token mới và gửi link)
function attc_send_activation_email($user_id) {
    $user = get_user_by('id', (int)$user_id);
    if (!$user) {
        return new WP_Error('user_not_found', 'Không tìm thấy người dùng.');
    }

    $token = wp_generate_password(20, false, false);
    update_user_meta($user->ID, 'attc_verify_token', $token);

    $activate_url = add_query_arg([
        'attc_verify' => 1,
        'uid'         => (int)$user->ID,
        'token'       => $token,
    ], site_url('/dang-nhap/'));

    $subject = 'Kích hoạt tài khoản AudioAI';
    $message = "Xin chào {$user->display_name},\n\nVui lòng bấm vào liên kết sau để kích hoạt tài khoản của bạn:\n{$activate_url}\n\nNếu bạn không yêu cầu, vui lòng bỏ qua email này.";
    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    $sent = wp_mail($user->user_email, $subject, $message, $headers);
    if (!$sent) {
        return new WP_Error('mail_failed', 'Không thể gửi email kích hoạt.');
    }
    return true;
}

// Hậu xử lý văn bản: sửa lỗi chính tả/dấu tiếng Việt bằng Chat Completions
function attc_vi_correct_text($text, $api_key) {
    if (empty($api_key) || !is_string($text) || $text === '') return $text;
    $endpoint = 'https://api.openai.com/v1/chat/completions';
    $headers = [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type'  => 'application/json',
    ];
    $payload = [
        'model' => 'gpt-3.5-turbo',
        'temperature' => 0,
        'messages' => [
            [ 'role' => 'system', 'content' => 'Bạn là trợ lý biên tập tiếng Việt. Hãy chuẩn hoá chính tả, dấu câu, giữ nguyên nội dung, không thêm/bớt ý.' ],
            [ 'role' => 'user', 'content' => "Hãy chỉnh sửa chính tả tiếng Việt, thêm dấu đầy đủ, giữ nguyên ý nghĩa và định dạng cơ bản (xuống dòng). Chỉ trả về đoạn văn đã chỉnh sửa, không kèm giải thích.\n\n---\n\n" . $text ],
        ],
        'max_tokens' => 2048,
    ];
    $resp = wp_remote_post($endpoint, [
        'timeout' => 60,
        'headers' => $headers,
        'body'    => wp_json_encode($payload),
    ]);
    if (is_wp_error($resp)) return $text;
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200 || empty($body['choices'][0]['message']['content'])) return $text;
    $out = (string) $body['choices'][0]['message']['content'];
    return trim($out) !== '' ? $out : $text;
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

// Legacy DOCX-only handler (not used anymore)
function attc_handle_download_transcript_legacy() {
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
            wp_die('Không thể tạo file nén tạm thởi.');
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

    // Admin: Simulate top-up to test /nang-cap UI
    if (!empty($_POST['attc_admin_simulate_topup'])) {
        check_admin_referer('attc_admin_simulate_topup_action', 'attc_admin_simulate_topup_nonce');

        $sim_identifier = sanitize_text_field($_POST['attc_user_identifier_sim'] ?? '');
        $sim_amount = (int) ($_POST['attc_amount_sim'] ?? 0);
        $sim_mode = sanitize_text_field($_POST['attc_mode_sim'] ?? 'transient'); // transient|credit

        $sim_user = false;
        if (is_numeric($sim_identifier)) {
            $sim_user = get_user_by('id', (int)$sim_identifier);
        } elseif (is_email($sim_identifier)) {
            $sim_user = get_user_by('email', $sim_identifier);
        } else {
            $sim_user = get_user_by('login', $sim_identifier);
        }

        if (!$sim_user || $sim_amount <= 0) {
            $simulate_message = '<div class="notice notice-error is-dismissible"><p>Vui lòng nhập người dùng hợp lệ và số tiền > 0 để mô phỏng.</p></div>';
        } else {
            $uid = (int) $sim_user->ID;
            if ($sim_mode === 'credit') {
                $res = attc_credit_wallet($uid, $sim_amount, [
                    'reason' => 'admin_simulate',
                    'note'   => 'Simulate top-up from admin wallet page',
                    'admin'  => get_current_user_id(),
                ]);
                if (is_wp_error($res)) {
                    $simulate_message = '<div class="notice notice-error is-dismissible"><p>Lỗi mô phỏng (credit): ' . esc_html($res->get_error_message()) . '</p></div>';
                } else {
                    $new_bal = attc_get_wallet_balance($uid);
                    $simulate_message = '<div class="notice notice-success is-dismissible"><p>Đã mô phỏng CỘNG ví ' . number_format($sim_amount) . 'đ cho user #' . $uid . '. Trang /nang-cap của user sẽ hiển thị thông báo ngay.</p><p><strong>Số dư hiện tại:</strong> ' . number_format($new_bal) . 'đ</p></div>';
                }
            } else {
                set_transient('attc_payment_success_' . $uid, ['amount' => $sim_amount, 'time' => time()], 60);
                $cur_bal = attc_get_wallet_balance($uid);
                $simulate_message = '<div class="notice notice-success is-dismissible"><p>Đã mô phỏng THÔNG BÁO nạp ' . number_format($sim_amount) . 'đ cho user #' . $uid . ' (không cộng ví). Trang /nang-cap sẽ hiển thị thông báo ngay.</p><p><strong>Số dư hiện tại:</strong> ' . number_format($cur_bal) . 'đ</p></div>';
            }
        }
    }

    // Admin: Resend activation email
    if (!empty($_POST['attc_admin_resend_activation'])) {
        check_admin_referer('attc_admin_resend_activation_action', 'attc_admin_resend_activation_nonce');

        $user_identifier = sanitize_text_field($_POST['attc_user_identifier_resend'] ?? '');
        $user = false;
        if (is_numeric($user_identifier)) {
            $user = get_user_by('id', (int)$user_identifier);
        } elseif (is_email($user_identifier)) {
            $user = get_user_by('email', $user_identifier);
        } else {
            $user = get_user_by('login', $user_identifier);
        }

        if (!$user) {
            $message = '<div class="notice notice-error is-dismissible"><p>Không tìm thấy người dùng để gửi lại email kích hoạt.</p></div>';
        } else {
            $res = attc_send_activation_email($user->ID);
            if (is_wp_error($res)) {
                $message = '<div class="notice notice-error is-dismissible"><p>Lỗi gửi email: ' . esc_html($res->get_error_message()) . '</p></div>';
            } else {
                $message = '<div class="notice notice-success is-dismissible"><p>Đã gửi lại email kích hoạt tới ' . esc_html($user->user_email) . '.</p></div>';
            }
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
function attc_get_daily_free_uploads_remaining($user_id) {
    $override_daily = (int) get_user_meta($user_id, 'attc_free_uploads_per_day_override', true);
    $daily_limit = $override_daily > 0 ? $override_daily : max(1, (int) get_option('attc_free_uploads_per_day', 1));
    if ($daily_limit <= 0) {
        return 0;
    }

    $today = current_time('Y-m-d');
    $last_date = get_user_meta($user_id, '_attc_last_free_upload_date', true);
    $used_today = ($last_date === $today) ? (int) get_user_meta($user_id, '_attc_free_uploads_today', true) : 0;

    return max(0, $daily_limit - max(0, $used_today));
}

function attc_credit_wallet($user_id, $amount, $meta = []) {
    $amount = (int) $amount;
    if ($amount <= 0) return true;
    $bal = attc_get_wallet_balance($user_id);
    attc_set_wallet_balance($user_id, $bal + $amount);

    // Lưu tín hiệu thành công: transient (60s) cho tốc độ và user meta làm fallback
    $payment_data = ['amount' => $amount, 'time' => time()];
    set_transient('attc_payment_success_' . $user_id, $payment_data, 60);
    update_user_meta($user_id, 'attc_payment_success', $payment_data);

    // Xóa cache của user để đảm bảo các phiên khác có thể thấy thay đổi ngay lập tức
    wp_cache_delete($user_id, 'user_meta');
    attc_add_wallet_history($user_id, [
        'type' => 'credit',
        'amount' => $amount,
        'meta' => $meta,
    ]);
    return true;
}


// ===== REST API Routes =====
function attc_check_payment_status(\WP_REST_Request $request) {

    $user_id = get_current_user_id();
    $transient_key = 'attc_payment_success_' . $user_id;

    if ($payment_data = get_transient($transient_key)) {
        delete_transient($transient_key);
        // Đồng thầm xóa cờ meta nếu có để tránh trùng lặp
        delete_user_meta($user_id, 'attc_payment_success');
        return new WP_REST_Response(['status' => 'success', 'data' => $payment_data], 200);
    }

    // Fallback: kiểm tra cờ user meta (bền bỉ hơn transient)
    $meta_data = get_user_meta($user_id, 'attc_payment_success', true);
    if (!empty($meta_data) && is_array($meta_data) && isset($meta_data['amount'])) {
        delete_user_meta($user_id, 'attc_payment_success'); // Xóa ngay sau khi đọc
        return new WP_REST_Response(['status' => 'success', 'data' => $meta_data], 200);
    }


    return new WP_REST_Response(['status' => 'pending'], 200);
}

add_action('rest_api_init', function () {
    register_rest_route('attc/v1', '/payment-status', [
        'methods' => 'GET',
        'callback' => 'attc_check_payment_status',
        'permission_callback' => function (\WP_REST_Request $request) {
            if (is_user_logged_in()) {
                return true;
            }
            // Fallback for requests without cookies (like some fetch/SSE scenarios)
            $nonce = $request->get_header('x-wp-nonce') ?: $request->get_param('_wpnonce');
            if ($nonce && wp_verify_nonce($nonce, 'wp_rest')) {
                // Nonce is valid, so find the user associated with it.
                // This is a simplified way; a more secure method might involve a different token.
                // For this context, we assume the nonce implies a logged-in user context.
                // The key is to re-establish the user for this request.
                $user_id = apply_filters('determine_current_user', false);
                if ($user_id) {
                    wp_set_current_user($user_id);
                    return true;
                }
            }
            return false;
        },
    ]);

    register_rest_route('attc/v1', '/webhook/bank', [
        'methods' => 'POST',
        'callback' => 'attc_handle_webhook_bank',
        'permission_callback' => '__return_true',
    ]);

    // SSE: stream thông báo nạp thành công theo thởi gian thực
    register_rest_route('attc/v1', '/payment-stream', [
        'methods' => 'GET',
        'callback' => 'attc_payment_stream',
        'permission_callback' => function (\WP_REST_Request $request) {
            if (is_user_logged_in()) {
                return true;
            }
            // Fallback for requests without cookies (like some fetch/SSE scenarios)
            $nonce = $request->get_header('x-wp-nonce') ?: $request->get_param('_wpnonce');
            if ($nonce && wp_verify_nonce($nonce, 'wp_rest')) {
                $user_id = apply_filters('determine_current_user', false);
                if ($user_id) {
                    wp_set_current_user($user_id);
                    return true;
                }
            }
            return false;
        },
    ]);

    // Admin-only: simulate top-up for testing (/nang-cap notifications)
    register_rest_route('attc/v1', '/fake-topup', [
        'methods' => ['GET', 'POST'],
        'callback' => 'attc_fake_topup',
        'permission_callback' => 'attc_fake_topup_can',
    ]);
});

// SSE endpoint: trả về sự kiện "payment" khi phát hiện nạp thành công trong 60s, fallback client sẽ polling
function attc_payment_stream(\WP_REST_Request $request) {
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        // Should not happen due to permission_callback, but as a safeguard
        status_header(204);
        return;
    }
    $transient_key = 'attc_payment_success_' . $user_id;

    ignore_user_abort(true);
    @set_time_limit(0);
    status_header(200);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // với nginx

    $start = time();
    while (time() - $start < 60) { // tối đa 60 giây
        // Ưu tiên transient
        $payment_data = get_transient($transient_key);
        if ($payment_data) {
            delete_transient($transient_key);
            // dọn cờ meta nếu có để tránh trùng lặp
            delete_user_meta($user_id, 'attc_payment_success');
            $payload = wp_json_encode(['status' => 'success', 'data' => $payment_data]);
            echo "event: payment\n";
            echo "data: {$payload}\n\n";
            flush();
            return;
        }
        // Fallback: user_meta (bền bỉ hơn transient)
        $meta_data = get_user_meta($user_id, 'attc_payment_success', true);
        if (!empty($meta_data) && is_array($meta_data) && isset($meta_data['amount'])) {
            delete_user_meta($user_id, 'attc_payment_success');
            $payload = wp_json_encode(['status' => 'success', 'data' => $meta_data]);
            echo "event: payment\n";
            echo "data: {$payload}\n\n";
            flush();
            return;
        }
        // keep-alive mỗi 10s để giảm tải server
        echo ": keepalive\n\n";
        flush();
        sleep(10);
    }
    // hết thởi gian, kết thúc stream
    echo ": timeout\n\n";
    flush();
}


function attc_get_price_per_minute() {
    $price = (int) get_option('attc_price_per_minute', 500);
    return max(0, $price);
}


function attc_settings_field_html($args) {
    $name = esc_attr($args['name']);
    $type = isset($args['type']) ? $args['type'] : 'text';
    $val  = get_option($name, '');
    $min  = isset($args['min']) ? ' min="' . (int)$args['min'] . '"' : '';
    $desc = isset($args['desc']) ? '<p class="description">' . esc_html($args['desc']) . '</p>' : '';
    echo '<input name="' . $name . '" id="' . $name . '" type="' . esc_attr($type) . '" value="' . esc_attr($val) . '" class="regular-text"' . $min . ' />' . $desc;
}

// ===== Upgrade Page Shortcode =====
function attc_upgrade_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="attc-auth-prompt"><p>Vui lòng <a href="' . esc_url(site_url('/dang-nhap')) . '">đăng nhập</a> hoặc <a href="' . esc_url(site_url('/dang-ky')) . '">đăng ký</a> để sử dụng chức năng này.</p></div>';
    }
    $display_name = wp_get_current_user()->display_name;
    $logout_url = wp_logout_url(get_permalink());
    $free_threshold = (int) get_option('attc_free_threshold', 500);
    $price_per_min = (int) attc_get_price_per_minute();
    // Đọc cấu hình cổng thanh toán (chỉ ngân hàng)
    $bank_name_opt = get_option('attc_bank_name', 'ACB');
    $bank_acc_name_opt = get_option('attc_bank_account_name', 'DINH CONG TOAN');
    $bank_acc_number_opt = get_option('attc_bank_account_number', '240306539');
    $plans = [
        [ 'id' => 'topup-20k',  'title' => 'Nạp 20.000đ',  'amount' => 20000 ],
        [ 'id' => 'topup-50k',  'title' => 'Nạp 50.000đ',  'amount' => 50000 ],
        [ 'id' => 'topup-100k', 'title' => 'Nạp 100.000đ', 'amount' => 100000, 'highlight' => true ],
    ];

    ob_start();
    ?>
    

    <div class="attc-upgrade">
        <div class="attc-userbar">
            <p class="attc-username"><strong><?php echo esc_html($display_name); ?></strong></p>
            <a class="attc-logout-btn" href="<?php echo esc_url($logout_url); ?>">Đăng xuất</a>
        </div>

        <div id="attc-success-notice" class="attc-notice is-hidden">
        <p><strong>Nạp tiền thành công!</strong></p>
        <p id="attc-success-message"></p>
        <button id="attc-confirm-payment" class="attc-btn-primary">Quay lại chuyển đổi giọng nói</button>
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
    .attc-userbar .attc-username { margin: 0; font-weight: 600; color: #2c3e50; font-size:20px;}
    .attc-userbar .attc-logout-btn { background: #e74c3c; color: #fff !important; text-decoration: none; padding: 8px 12px; border-radius: 6px; font-weight: 600; transition: background-color .2s; }
    .attc-userbar .attc-logout-btn:hover { background: #ff6b61; }
    .attc-userbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; background: #f0f4f8; border: 1px solid #e3e8ee; padding: 12px 16px; border-radius: 8px; margin-bottom: 1.5rem; }
    .attc-userbar .attc-username { margin: 0; font-weight: 600; color: #2c3e50; }
    .attc-userbar .attc-logout-btn { background: #e74c3c; color: #fff !important; text-decoration: none; padding: 8px 12px; border-radius: 6px; font-weight: 600; transition: background-color .2s; }
    .attc-userbar .attc-logout-btn:hover { background: #ff6b61; }
    #attc-payment-details.is-hidden, #attc-pricing-plans.is-hidden { display: none; }
    .attc-payment-columns { display: grid; grid-template-columns: 1fr; gap: 2rem; margin-top: 1.5rem; }
    .attc-payment-column { border: 1px solid #e5e7eb; padding: 0.5rem; border-radius: 8px; background: #fff; text-align: center; }
    .attc-payment-column h4 { margin-top: 0; margin-bottom:0 }
    .attc-qr-code img { max-width: 250px; height: auto; margin: 0 auto; }
    .attc-bank-info { text-align: center; margin-top: 0; }
    .attc-bank-info p { margin: 0.5rem 0; }    
    </style>
    
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
                    <h4>Chuyển khoản Ngân hàng (ACB)</h4>
                    <div class="attc-qr-code" id="attc-bank-qr"></div>
                    <div class="attc-bank-info">
                        <p><strong>Ngân hàng:</strong> <span id="bank-name"><?php echo esc_html($bank_name_opt); ?></span></p>
                        <p><strong>Chủ tài khoản:</strong> <span id="account-name"><?php echo esc_html($bank_acc_name_opt); ?></span></p>
                        <p><strong>Số tài khoản:</strong> <span id="account-number"><?php echo esc_html($bank_acc_number_opt); ?></span></p>
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
    $is_upgrade_page = is_page('nang-cap') || (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'attc_upgrade'));
    if ($is_upgrade_page) {
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
                'stream_url'    => esc_url_raw(rest_url('attc/v1/payment-stream')),
                'price_per_min' => (string) attc_get_price_per_minute(),
                'user_id'       => get_current_user_id(),
                'nonce'         => wp_create_nonce('wp_rest'),
            ]);
        }
    }
}
add_action('wp_enqueue_scripts', 'attc_enqueue_payment_checker_script');

// ===== Download handlers: DOCX/PDF =====
function attc_handle_download_transcript() {
    if (!isset($_GET['action']) || $_GET['action'] !== 'attc_download_transcript') return;
    $ts = isset($_GET['timestamp']) ? (int)$_GET['timestamp'] : 0;
    $nonce = isset($_GET['nonce']) ? $_GET['nonce'] : '';
    $format = isset($_GET['format']) ? strtolower($_GET['format']) : 'docx';
    if (!$ts || !wp_verify_nonce($nonce, 'attc_download_' . $ts)) {
        wp_die('Yêu cầu không hợp lệ.');
    }
    if (!is_user_logged_in()) {
        auth_redirect();
    }
    $user_id = get_current_user_id();
    $history = get_user_meta($user_id, 'attc_wallet_history', true);
    if (!is_array($history) || empty($history)) wp_die('Không tìm thấy dữ liệu.');
    $record = null;
    foreach (array_reverse($history) as $item) {
        if (($item['timestamp'] ?? 0) === $ts) { $record = $item; break; }
    }
    if (!$record) wp_die('Không tìm thấy bản ghi.');
    $meta = $record['meta'] ?? [];
    $transcript = (string)($meta['transcript'] ?? '');
    $summary = (array)($meta['summary_bullets'] ?? []);
    $transcript = attc_vi_normalize($transcript);

    if ($format === 'pdf') {
        // Tìm và load autoload Dompdf nếu có
        $autoload_paths = [
            plugin_dir_path(__FILE__) . 'vendor/autoload.php',
            WP_CONTENT_DIR . '/vendor/autoload.php',
        ];
        foreach ($autoload_paths as $ap) {
            if (file_exists($ap)) { require_once $ap; break; }
        }
        if (!class_exists('Dompdf\\Dompdf')) {
            wp_die('Không thể tạo PDF vì thiếu thư viện Dompdf.');
        }
        $html = '<div style="font-family: \'Times New Roman\', Times, serif; font-size:14px; line-height:1.5;">';
        if (!empty($summary)) {
            $html .= '<p style="margin:0 0 8px 0; font-weight:bold;">Tóm tắt nội dung:</p><ul style="margin:0 0 12px 18px; padding:0; font-size:14px;">';
            foreach ($summary as $b) { $html .= '<li>' . esc_html($b) . '</li>'; }
            $html .= '</ul>';
        }
        $html .= '<div style="white-space:pre-wrap; font-size:14px;">' . nl2br(esc_html($transcript)) . '</div>';
        $html .= '</div>';
        // Render PDF
        $options = new Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Times New Roman');
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml('<meta http-equiv="Content-Type" content="text/html; charset=utf-8" /><style>body{font-family: \'Times New Roman\', Times, serif; font-size:14px; line-height:1.5;} * { font-family: \'Times New Roman\', Times, serif; }</style>' . $html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf = $dompdf->output();
        $filename = 'attc-transcript-' . $ts . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename=' . $filename);
        echo $pdf;
        exit;
    }

    // DOC: xuất HTML tương thích Word với font Times New Roman, size 14
    $filename = 'attc-transcript-' . $ts . '.doc';
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $tx = preg_replace('/\s+/u', ' ', $transcript);
    $tx = trim((string)$tx);
    $tx = preg_replace('/([\.!?…])\s+/u', "$1\n", $tx);
    $tx = preg_replace('/\n{2,}/u', "\n", $tx);
    echo '<!doctype html><html><head><meta charset="UTF-8"><title>Kết quả chuyển đổi</title><style>body{font-family: \'Times New Roman\', Times, serif; font-size:20px; line-height:1.3; margin:0;} ul{margin:0 0 6px 30px; padding:0; line-height:1.3; font-size:20px;} li{margin:0 0 2px 0;} .attc-p{margin:0 0 2px 0;} .content{font-size:20px; line-height:1.3; margin:0;}</style></head><body>';
    if (!empty($summary)) {
        echo '<p style="font-weight:bold; margin:0 0 6px 0;">Tóm tắt nội dung:</p><ul style="font-size:20px; line-height:1.3; margin:0 0 6px 30px;">';
        foreach ($summary as $b) { echo '<li>' . esc_html($b) . '</li>'; }
        echo '</ul>';
    }
    echo '<div class="content">';
    $paras = preg_split("/\n+/u", $tx);
    if (is_array($paras)) {
        foreach ($paras as $pp) {
            $pp = trim((string)$pp);
            if ($pp === '') continue;
            echo '<p class="attc-p">' . esc_html($pp) . '</p>';
        }
    } else {
        echo '<p class="attc-p">' . esc_html($tx) . '</p>';
    }
    echo '</div>';
    echo '</body></html>';
    exit;
}
add_action('template_redirect', 'attc_handle_download_transcript');


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
    // Thêm lối tắt tới trang cài đặt vào dưới menu AudioAI Wallet
    add_submenu_page(
        'attc-wallet',
        'Cài đặt AudioAI',
        'Cài đặt',
        'manage_options',
        'attc-settings',
        'attc_settings_page_html'
    );
}

function attc_render_wallet_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die('Bạn không có quyền truy cập trang này.');
    }

    // Handle manual top-up & debit
    $message = '';
    $simulate_message = '';
    // Hiển thị notice nhanh từ redirect trước đó (PRG)
    $flash = get_transient('attc_admin_notice');
    if ($flash) {
        $message .= $flash;
        delete_transient('attc_admin_notice');
    }
    if (!empty($_POST['attc_manual_debit'])) {
        check_admin_referer('attc_manual_debit_action', 'attc_manual_debit_nonce');

        $user_identifier = sanitize_text_field($_POST['attc_user_identifier_debit'] ?? '');
        $amount = (int) ($_POST['attc_amount_debit'] ?? 0);
        $reason = sanitize_text_field($_POST['attc_reason_debit'] ?? 'Manual debit by admin');

        $user = false;
        if (is_numeric($user_identifier)) {
            $user = get_user_by('id', (int)$user_identifier);
        } elseif (is_email($user_identifier)) {
            $user = get_user_by('email', $user_identifier);
        } else {
            $user = get_user_by('login', $user_identifier);
        }

        if ($user && $amount > 0) {
            $result = attc_charge_wallet($user->ID, $amount, ['reason' => 'manual_debit', 'admin_note' => $reason]);
            if (is_wp_error($result)) {
                $html = '<div class="notice notice-error is-dismissible"><p>Lỗi: ' . esc_html($result->get_error_message()) . '</p></div>';
                set_transient('attc_admin_notice', $html, 30);
            } else {
                $html = '<div class="notice notice-success is-dismissible"><p>Đã trừ ' . number_format($amount) . 'đ từ tài khoản ' . esc_html($user->user_login) . '.</p></div>';
                set_transient('attc_admin_notice', $html, 30);
            }
            $redirect_url = add_query_arg(['page' => 'attc-wallet', 'fast' => 1], admin_url('admin.php'));
            if (!headers_sent()) {
                wp_safe_redirect($redirect_url);
                exit;
            } else {
                $message .= $html;
                // Không redirect được do headers đã gửi; tiếp tục render trang cùng với $message
            }

        } else {
            $html = '<div class="notice notice-error is-dismissible"><p>Không tìm thấy người dùng hoặc số tiền không hợp lệ.</p></div>';
            set_transient('attc_admin_notice', $html, 30);
            $redirect_url = add_query_arg(['page' => 'attc-wallet', 'fast' => 1], admin_url('admin.php'));
            if (!headers_sent()) {
                wp_safe_redirect($redirect_url);
                exit;
            } else {
                $message .= $html;
                // Không redirect được do headers đã gửi; tiếp tục render trang
            }

        }
    } elseif (!empty($_POST['attc_manual_topup'])) {
        check_admin_referer('attc_manual_topup_action', 'attc_manual_topup_nonce');

        $user_identifier = sanitize_text_field($_POST['attc_user_identifier'] ?? '');
        $amount = (int) ($_POST['attc_amount'] ?? 0);

        $user = false;
        if (is_numeric($user_identifier)) {
            $user = get_user_by('id', (int)$user_identifier);
        } elseif (is_email($user_identifier)) {
            $user = get_user_by('email', $user_identifier);
        } else {
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
                $html = '<div class="notice notice-error"><p>Lỗi nạp tiền: ' . esc_html($res->get_error_message()) . '</p></div>';
                set_transient('attc_admin_notice', $html, 30);
            } else {
                $html = '<div class="notice notice-success"><p>Đã nạp ' . number_format($amount) . 'đ vào ví của user #' . $uid . ' (' . esc_html($user->user_email) . ').</p></div>';
                set_transient('attc_admin_notice', $html, 30);
            }
            $redirect_url = add_query_arg(['page' => 'attc-wallet', 'fast' => 1], admin_url('admin.php'));
            if (!headers_sent()) {
                wp_safe_redirect($redirect_url);
                exit;
            } else {
                $message .= $html;
                // Không redirect được do headers đã gửi; tiếp tục render trang
            }

        }
    } elseif (!empty($_POST['attc_set_free_limit'])) {
        // Cập nhật giới hạn miễn phí/ngày cho 1 user cụ thể
        check_admin_referer('attc_set_free_limit_action', 'attc_set_free_limit_nonce');

        $user_identifier = sanitize_text_field($_POST['attc_user_identifier_limit'] ?? '');
        $limit = (int) ($_POST['attc_free_limit'] ?? 0); // 0 = dùng cấu hình chung

        $user = false;
        if (is_numeric($user_identifier)) {
            $user = get_user_by('id', (int)$user_identifier);
        } elseif (is_email($user_identifier)) {
            $user = get_user_by('email', $user_identifier);
        } else {
            $user = get_user_by('login', $user_identifier);
        }

        if (!$user || $limit < 0) {
            $html = '<div class="notice notice-error"><p>Vui lòng nhập người dùng hợp lệ và giới hạn >= 0.</p></div>';
            set_transient('attc_admin_notice', $html, 30);
        } else {
            update_user_meta($user->ID, 'attc_free_uploads_per_day_override', (int)$limit);
            $msg = ($limit > 0)
                ? ('Đã đặt giới hạn miễn phí/ngày cho user ' . esc_html($user->user_login) . ' = ' . (int)$limit)
                : ('Đã xoá override (đặt về 0) cho user ' . esc_html($user->user_login) . ', dùng cấu hình chung');
            $html = '<div class="notice notice-success"><p>' . $msg . '.</p></div>';
            set_transient('attc_admin_notice', $html, 30);
        }
        $redirect_url = add_query_arg(['page' => 'attc-wallet', 'fast' => 1], admin_url('admin.php'));
        if (!headers_sent()) {
            wp_safe_redirect($redirect_url);
            exit;
        } else {
            $message .= $html;
        }

    } elseif (!empty($_POST['attc_admin_simulate_topup'])) {
        // Mô phỏng nạp tiền để test UI /nang-cap (không cần webhook)
        check_admin_referer('attc_admin_simulate_topup_action', 'attc_admin_simulate_topup_nonce');

        $sim_identifier = sanitize_text_field($_POST['attc_user_identifier_sim'] ?? '');
        $sim_amount = (int) ($_POST['attc_amount_sim'] ?? 0);
        $sim_mode = sanitize_text_field($_POST['attc_mode_sim'] ?? 'transient'); // transient|credit

        $sim_user = false;
        if (is_numeric($sim_identifier)) {
            $sim_user = get_user_by('id', (int)$sim_identifier);
        } elseif (is_email($sim_identifier)) {
            $sim_user = get_user_by('email', $sim_identifier);
        } else {
            $sim_user = get_user_by('login', $sim_identifier);
        }

        if (!$sim_user || $sim_amount <= 0) {
            $simulate_message = '<div class="notice notice-error is-dismissible"><p>Vui lòng nhập người dùng hợp lệ và số tiền > 0 để mô phỏng.</p></div>';
        } else {
            $uid = (int) $sim_user->ID;
            if ($sim_mode === 'credit') {
                $res = attc_credit_wallet($uid, $sim_amount, [
                    'reason' => 'admin_simulate',
                    'note'   => 'Simulate top-up from admin wallet page',
                    'admin'  => get_current_user_id(),
                ]);
                if (is_wp_error($res)) {
                    $simulate_message = '<div class="notice notice-error is-dismissible"><p>Lỗi mô phỏng (credit): ' . esc_html($res->get_error_message()) . '</p></div>';
                } else {
                    // attc_credit_wallet sẽ tự set transient + user_meta + xóa cache
                    $new_bal = attc_get_wallet_balance($uid);
                    $simulate_message = '<div class="notice notice-success is-dismissible"><p>Đã mô phỏng CỘNG ví ' . number_format($sim_amount) . 'đ cho user #' . $uid . '. Trang /nang-cap của user sẽ hiển thị thông báo ngay.</p><p><strong>Số dư hiện tại:</strong> ' . number_format($new_bal) . 'đ</p></div>';
                }
            } else {
                // Chỉ bắn thông báo, không cộng ví
                $payment_data = ['amount' => $sim_amount, 'time' => time()];
                set_transient('attc_payment_success_' . $uid, $payment_data, 60);
                $cur_bal = attc_get_wallet_balance($uid);
                $simulate_message = '<div class="notice notice-success is-dismissible"><p>Đã mô phỏng THÔNG BÁO nạp ' . number_format($sim_amount) . 'đ cho user #' . $uid . ' (không cộng ví). Trang /nang-cap sẽ hiển thị thông báo ngay.</p><p><strong>Số dư hiện tại:</strong> ' . number_format($cur_bal) . 'đ</p></div>';
            }
        }
    }

    // Admin: Activate user email verification
    if (!empty($_POST['attc_admin_verify_email'])) {
        check_admin_referer('attc_admin_verify_email_action', 'attc_admin_verify_email_nonce');

        $user_identifier = sanitize_text_field($_POST['attc_user_identifier_verify'] ?? '');
        $user = false;
        if (is_numeric($user_identifier)) {
            $user = get_user_by('id', (int)$user_identifier);
        } elseif (is_email($user_identifier)) {
            $user = get_user_by('email', $user_identifier);
        } else {
            $user = get_user_by('login', $user_identifier);
        }

        if (!$user) {
            $message = '<div class="notice notice-error is-dismissible"><p>Không tìm thấy người dùng để kích hoạt.</p></div>';
        } else {
            $uid = (int)$user->ID;
            update_user_meta($uid, 'attc_email_verified', 1);
            delete_user_meta($uid, 'attc_verify_token');
            $message = '<div class="notice notice-success is-dismissible"><p>Đã kích hoạt tài khoản cho user #' . $uid . ' (' . esc_html($user->user_email) . ').</p></div>';
        }
    }

    $price_per_min = (int) attc_get_price_per_minute();

    // Search/filter + Pagination
    $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    // Fast mode: giảm lượng dữ liệu mỗi trang để tăng tốc
    $fast_mode = isset($_GET['fast']) && (int)$_GET['fast'] === 1;
    $per_page_default = $fast_mode ? 10 : 20;
    $per_page = max(1, (int) apply_filters('attc_admin_wallet_per_page', $per_page_default));
    $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $offset = ($paged - 1) * $per_page;

    $user_query_args = [
        'fields' => ['ID', 'display_name', 'user_email'],
        'number' => $per_page,
        'offset' => $offset,
        // Ở fast mode, bỏ tính tổng để tránh COUNT(*) tốn kém
        'count_total' => !$fast_mode,
    ];
    if ($q !== '') {
        $user_query_args['search'] = '*' . $q . '*';
        $user_query_args['search_columns'] = ['user_login', 'user_email', 'display_name'];
    }
    $user_query = new WP_User_Query($user_query_args);
    $users = $user_query->get_results();
    if ($fast_mode) {
        $total_users = 0; // không dùng
        $total_pages = 1;
    } else {
        $total_users = (int) $user_query->get_total();
        $total_pages = max(1, (int) ceil($total_users / $per_page));
    }

    $rows = [];
    // Prime user meta cache để giảm query get_user_meta trong vòng lặp
    if (!empty($users)) {
        $ids = array_map(function($u){ return (int)$u->ID; }, $users);
        if (function_exists('update_meta_cache')) {
            update_meta_cache('user', $ids);
        }
    }
    $depositor_count = 0;
    foreach ($users as $u) {
        $uid = (int)$u->ID;
        $balance = attc_get_wallet_balance($uid);
        $mins = $price_per_min > 0 ? floor($balance / $price_per_min) : 0;
        // Bỏ qua kiểm tra lịch sử nạp ở fast mode để tăng tốc
        $has_deposit = false;
        if (!$fast_mode) {
            $has_deposit = attc_user_has_made_deposit($uid);
            if ($has_deposit) { $depositor_count++; }
        }
        $is_verified = (int) get_user_meta($uid, 'attc_email_verified', true) === 1;
        $rows[] = [
            'id' => $uid,
            'name' => $u->display_name,
            'email' => $u->user_email,
            'balance' => $balance,
            'minutes' => $mins,
            'has_deposit' => $has_deposit,
            'verified' => $is_verified,
        ];
    }

    // Sort by balance desc
    usort($rows, function($a, $b){ return $b['balance'] <=> $a['balance']; });

    echo '<div class="wrap"><h1>AudioAI Wallet</h1>';
    if ($message) echo $message;
    if ($simulate_message) echo $simulate_message;

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

    // Per-user free uploads/day override form (rendered here)
    echo '<h2>Giới hạn miễn phí theo User</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('attc_set_free_limit_action', 'attc_set_free_limit_nonce');
    echo '<input type="hidden" name="attc_set_free_limit" value="1" />';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="attc_user_identifier_limit">User (ID / Email / Username)</label></th>';
    echo '<td><input name="attc_user_identifier_limit" id="attc_user_identifier_limit" type="text" class="regular-text" required placeholder="ví dụ: 123 hoặc user@example.com"></td></tr>';
    echo '<tr><th scope="row"><label for="attc_free_limit">Số lượt miễn phí/ngày</label></th>';
    echo '<td><input name="attc_free_limit" id="attc_free_limit" type="number" min="0" step="1" value="0">';
    echo '<p class="description">Đặt 0 để dùng theo cấu hình chung (attc_free_uploads_per_day).</p></td></tr>';
    echo '</tbody></table>';
    submit_button('Lưu giới hạn', 'primary');
    echo '</form>';

    // Admin: Activate user email verification form
    echo '<h2>Kích hoạt tài khoản (xác thực email)</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('attc_admin_verify_email_action', 'attc_admin_verify_email_nonce');
    echo '<input type="hidden" name="attc_admin_verify_email" value="1" />';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="attc_user_identifier_verify">User (ID / Email / Username)</label></th>';
    echo '<td><input name="attc_user_identifier_verify" id="attc_user_identifier_verify" type="text" class="regular-text" required placeholder="ví dụ: 123 hoặc user@example.com"></td></tr>';
    echo '</tbody></table>';
    submit_button('Kích hoạt tài khoản', 'primary');
    echo '</form>';

    // Admin: Simulate top-up form
    echo '<h2>Mô phỏng nạp tiền (test /nang-cap)</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('attc_admin_simulate_topup_action', 'attc_admin_simulate_topup_nonce');
    echo '<input type="hidden" name="attc_admin_simulate_topup" value="1" />';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="attc_user_identifier_sim">User (ID / Email / Username)</label></th>';
    echo '<td><input name="attc_user_identifier_sim" id="attc_user_identifier_sim" type="text" class="regular-text" required placeholder="ví dụ: 123 hoặc user@example.com"></td></tr>';
    echo '<tr><th scope="row"><label for="attc_amount_sim">Số tiền (VND)</label></th>';
    echo '<td><input name="attc_amount_sim" id="attc_amount_sim" type="number" min="1" step="1" required></td></tr>';
    echo '<tr><th scope="row"><label for="attc_mode_sim">Chế độ</label></th>';
    echo '<td><select name="attc_mode_sim" id="attc_mode_sim">'
        . '<option value="transient">Chỉ bắn thông báo (không cộng ví)</option>'
        . '<option value="credit">Cộng ví + thông báo</option>'
        . '</select></td></tr>';
    echo '</tbody></table>';
    submit_button('Mô phỏng nạp tiền (1-click)', 'secondary');
    echo '</form>';

    // Admin: Resend activation email form
    echo '<h2>Gửi lại email kích hoạt</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('attc_admin_resend_activation_action', 'attc_admin_resend_activation_nonce');
    echo '<input type="hidden" name="attc_admin_resend_activation" value="1" />';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="attc_user_identifier_resend">User (ID / Email / Username)</label></th>';
    echo '<td><input name="attc_user_identifier_resend" id="attc_user_identifier_resend" type="text" class="regular-text" required placeholder="ví dụ: 123 hoặc user@example.com"></td></tr>';
    echo '</tbody></table>';
    submit_button('Gửi lại email kích hoạt', 'secondary');
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
    if (!$fast_mode && $total_pages > 1) {
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
    echo '<th width="60">User ID</th><th>Tên</th><th>Email</th><th width="140">Số dư (đ)</th><th width="120">Phút khả dụng</th><th width="140">Đã từng nạp?</th><th width="140">Trạng thái</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . (int)$r['id'] . '</td>';
        echo '<td>' . esc_html($r['name']) . '</td>';
        echo '<td>' . esc_html($r['email']) . '</td>';
        echo '<td>' . number_format((int)$r['balance']) . '</td>';
        echo '<td>' . (int)$r['minutes'] . '</td>';
        echo '<td>' . ($r['has_deposit'] ? '<span style="color:green;font-weight:600;">Có</span>' : 'Chưa') . '</td>';
        echo '<td>' . ($r['verified'] ? '<span style="color:green;font-weight:600;">Đã kích hoạt</span>' : '<span style="color:#d63638;font-weight:600;">Chưa kích hoạt</span>') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    // Pagination (bottom)
    if (!$fast_mode && $total_pages > 1) {
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
    $cache_key = 'attc_has_deposit_' . (int)$user_id;
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return (bool)$cached;
    }

    $history = get_user_meta($user_id, 'attc_wallet_history', true);
    if (empty($history) || !is_array($history)) {
        set_transient($cache_key, 0, 10 * MINUTE_IN_SECONDS);
        return false;
    }
    foreach ($history as $item) {
        // Chỉ cần một giao dịch 'credit' từ webhook là đủ để xác nhận đã nạp tiền
        if (isset($item['type'], $item['meta']['reason']) && $item['type'] === 'credit' && $item['meta']['reason'] === 'webhook_bank') {
            set_transient($cache_key, 1, 10 * MINUTE_IN_SECONDS);
            return true;
        }

    }
    set_transient($cache_key, 0, 10 * MINUTE_IN_SECONDS);
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

            // Kiểm tra dung lượng theo cấu hình
            $max_upload_mb = (int) get_option('attc_max_upload_mb', 10);
            $size_mb = (float) ($file['size'] / 1024 / 1024);
            if ($size_mb > max(1, $max_upload_mb)) {
                set_transient('attc_form_error', 'File vượt quá giới hạn ' . (int)$max_upload_mb . ' MB.', 30);
                delete_transient($lock_key);
                attc_redirect_back();
            }

            require_once(ABSPATH . 'wp-admin/includes/media.php');
            $metadata = wp_read_audio_metadata($file['tmp_name']);
            $source_duration = !empty($metadata['length']) ? (int) $metadata['length'] : 0;

            if ($source_duration <= 0) {
                set_transient('attc_form_error', 'Không thể xác định thởi lượng file hoặc file không hợp lệ.', 30);
                delete_transient($lock_key);
                attc_redirect_back();
            }

            // Đọc tham số trim từ form (giây)
            $trim_start = isset($_POST['trim_start_sec']) ? max(0, (int)$_POST['trim_start_sec']) : 0;
            $trim_end   = isset($_POST['trim_end_sec']) ? max(0, (int)$_POST['trim_end_sec']) : 0;
            if ($trim_end > 0 && $trim_end <= $trim_start) {
                set_transient('attc_form_error', 'Khoảng thởi gian cắt không hợp lệ. Thời điểm kết thúc phải lớn hơn bắt đầu.', 30);
                delete_transient($lock_key);
                attc_redirect_back();
            }
            if ($trim_end > 0 && $trim_end > $source_duration) { $trim_end = $source_duration; }
            $effective_duration = ($trim_end > 0) ? max(0, $trim_end - $trim_start) : $source_duration;

            // Tính điều kiện miễn phí và chi phí ở server để đồng bộ với UI và backend
            $balance = (int) attc_get_wallet_balance($user_id);
            $free_threshold = (int) get_option('attc_free_threshold', 500);
            $free_max_minutes = (int) max(1, get_option('attc_free_max_minutes', 2));
            $today = current_time('Y-m-d');
            $last_free_upload_date = get_user_meta($user_id, '_attc_last_free_upload_date', true);
            $free_uploads_today = ($last_free_upload_date === $today) ? (int) get_user_meta($user_id, '_attc_free_uploads_today', true) : 0;
            $override_daily = (int) get_user_meta($user_id, 'attc_free_uploads_per_day_override', true);
            $free_daily_limit = ($override_daily > 0) ? $override_daily : max(1, (int) get_option('attc_free_uploads_per_day', 1));
            $free_uploads_left = max(0, $free_daily_limit - max(0, $free_uploads_today));

            $is_free_tier_eligible = false;
            if ($balance <= $free_threshold) {
                if ($free_uploads_left > 0 && $effective_duration <= ($free_max_minutes * 60)) {
                    $is_free_tier_eligible = true;
                }
            }

            $cost = 0;
            if (!$is_free_tier_eligible) {
                $minutes = ($effective_duration > 0) ? ($effective_duration / 60.0) : 0.0;
                $cost = (int) max(0, round($minutes * (float) $price_per_minute));
            }

            // === ENQUEUE JOB XỬ LÝ NỀN ===
            $upload_dir = wp_upload_dir();
            $job_dir = trailingslashit($upload_dir['basedir']) . 'attc_jobs/';
            if (!file_exists($job_dir)) { wp_mkdir_p($job_dir); }

            $job_id = uniqid('attc_job_', true);
            $dest_path = $job_dir . $job_id . '-' . sanitize_file_name($file['name']);
            if (!@move_uploaded_file($file['tmp_name'], $dest_path)) {
                set_transient('attc_form_error', 'Không thể lưu file tạm để xử lý nền.', 30);
                delete_transient($lock_key);
                attc_redirect_back();
            }

            // Tránh chạy ffmpeg đồng bộ để không bị 504: lưu tham số trim, xử lý ở job nền
            // $dest_path giữ nguyên file gốc, job nền sẽ cắt nếu cần

            $job = [
                'id' => $job_id,
                'user_id' => $user_id,
                'file_path' => $dest_path,
                'file_name' => basename($dest_path),
                'duration' => $effective_duration,
                'trim_start' => (int)$trim_start,
                'trim_end' => (int)($trim_end > 0 ? $trim_end : $source_duration),
                'is_free' => (bool) $is_free_tier_eligible,
                'cost' => (int) $cost,
                'status' => 'queued',
                'created_at' => time(),
            ];

            // Lưu job vào option, bỏ autoload=false để get_option trong job nền có thể đọc được
            update_option('attc_job_' . $job_id, $job);

    // Đồng bộ lịch sử: chèn transcript đã chuẩn hoá + summary
    $history = get_user_meta($user_id, 'attc_wallet_history', true);
    if (is_array($history) && !empty($history)) {
        $last_index = array_key_last($history);
        if ($last_index !== null && isset($history[$last_index]['meta'])) {
            $history[$last_index]['meta']['transcript'] = $normalized;
            $history[$last_index]['meta']['summary_bullets'] = $summary_bullets;
            update_user_meta($user_id, 'attc_wallet_history', $history);
        }
    }

            if (!wp_next_scheduled('attc_run_job', [$job_id])) {
                wp_schedule_single_event(time() + 1, 'attc_run_job', [$job_id]);
                set_transient('attc_last_job_' . $user_id, $job_id, HOUR_IN_SECONDS);
            }

            // Kick-off job ngay (asynchronous) qua REST nội bộ, giữ WP-Cron làm fallback
            try {
                // Gắn token vào job để xác thực endpoint spawn
                $job_key = 'attc_job_' . $job_id;
                $job_spawn = get_option($job_key, []);
                if (is_array($job_spawn)) {
                    if (empty($job_spawn['spawn_token'])) {
                        $job_spawn['spawn_token'] = wp_generate_password(20, false, false);
                        update_option($job_key, $job_spawn, false);
                    }
                    $spawn_url = rest_url('attc/v1/jobs/' . rawurlencode($job_id) . '/run-now');
                    $args = [
                        'timeout' => 2,
                        'blocking' => false,
                        'body' => ['token' => (string) $job_spawn['spawn_token']],
                    ];
                    // Bắn request nội bộ, không chờ phản hồi
                    $spawn_response = wp_remote_post($spawn_url, $args);
                    if (is_wp_error($spawn_response)) {
                        error_log(sprintf('[%s] Spawn run-now failed for %s: %s. Fallback to direct processing.', date('Y-m-d H:i:s'), $job_id, $spawn_response->get_error_message()), 3, WP_CONTENT_DIR . '/attc_debug.log');
                        attc_process_job($job_id);
                    } else {
                        $spawn_code = (int) wp_remote_retrieve_response_code($spawn_response);
                        if ($spawn_code && $spawn_code >= 400) {
                            error_log(sprintf('[%s] Spawn run-now HTTP %d for %s. Fallback to direct processing.', date('Y-m-d H:i:s'), $spawn_code, $job_id), 3, WP_CONTENT_DIR . '/attc_debug.log');
                            attc_process_job($job_id);
                        } else {
                            error_log(sprintf('[%s] Spawn run-now triggered for %s to %s', date('Y-m-d H:i:s'), $job_id, $spawn_url), 3, WP_CONTENT_DIR . '/attc_debug.log');
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Im lặng: WP-Cron sẽ xử lý như fallback
                error_log(sprintf('[%s] Spawn run-now failed for %s: %s', date('Y-m-d H:i:s'), $job_id, $e->getMessage()), 3, WP_CONTENT_DIR . '/attc_debug.log');
                attc_process_job($job_id);
            }

            // Lưu job_id gần nhất vào user meta để client có thể polling
            update_user_meta($user_id, 'attc_last_job', $job_id);
            error_log(sprintf("[%s] Form Submission: Set user_meta attc_last_job for user %d to %s\n", date('Y-m-d H:i:s'), $user_id, $job_id), 3, WP_CONTENT_DIR . '/attc_debug.log');

            // Thông báo hàng đợi cho UI
            set_transient('attc_queue_notice', 'Yêu cầu đã được đưa vào hàng đợi xử lý. Bạn có thể đóng trang hoặc chờ tại trang này, kết quả sẽ hiển thị khi hoàn tất.', 60);

            // Kết thúc request sớm để người dùng không phải chờ
            delete_transient($lock_key);
            attc_redirect_back();
        }
    }
}
add_action('init', 'attc_handle_form_submission');

add_action('rest_api_init', function () {
    register_rest_route('attc/v1', '/jobs/latest', [
        'methods' => 'GET',
        // Cho phép public, tự xử lý đăng nhập trong callback để tránh 401
        'permission_callback' => '__return_true',
        'callback' => function(\WP_REST_Request $req) {
            $user_id = get_current_user_id();
            if ($user_id <= 0) {
                // Chưa đăng nhập: không trả 401 để tránh lỗi console, chỉ trả none
                return new WP_REST_Response(['status' => 'none'], 200);
            }
            $job_id = get_user_meta($user_id, 'attc_last_job', true);
            error_log(sprintf("[%s] /jobs/latest: Got user_meta attc_last_job for user %d, value: %s\n", date('Y-m-d H:i:s'), $user_id, var_export($job_id, true)), 3, WP_CONTENT_DIR . '/attc_debug.log');
            if (empty($job_id)) {
                return new WP_REST_Response(['status' => 'none'], 200);
            }
            $job = get_option('attc_job_' . $job_id, []);
            if (empty($job) || (int)($job['user_id'] ?? 0) !== $user_id) {
                return new WP_REST_Response(['status' => 'none'], 200);
            }
            $job_status = (string) ($job['status'] ?? 'queued');
            $is_active = in_array($job_status, ['queued', 'running'], true);
            if (!$is_active) {
                delete_user_meta($user_id, 'attc_last_job');
                delete_transient('attc_last_job_' . $user_id);
            }

            $payload = [
                'status' => $job_status,
                // fallback dùng $job_id từ tham số route nếu trong job thiếu id
                'job_id' => (string) (!empty($job['id']) ? $job['id'] : $job_id),
            ];
            if (!empty($job['transcript'])) {
                $payload['transcript'] = (string) $job['transcript'];
            }
            // Trả thêm summary_bullets để client có đủ dữ liệu ẩn loading và hiển thị kết quả
            if (!empty($job['summary_bullets']) && is_array($job['summary_bullets'])) {
                $payload['summary_bullets'] = array_values($job['summary_bullets']);
            }
            if (!empty($job['error'])) {
                $payload['error'] = (string) $job['error'];
            }
            if (!empty($job['file_name'])) {
                $payload['file_name'] = (string) $job['file_name'];
            }
            if (!empty($job['finished_at'])) {
                $payload['finished_at'] = (int) $job['finished_at'];
            }
            if (!empty($job['history_timestamp'])) {
                $ts = (int) $job['history_timestamp'];
                $nonce = wp_create_nonce('attc_download_' . $ts);
                $payload['download_link_doc'] = add_query_arg([
                    'action' => 'attc_download_transcript',
                    'timestamp' => $ts,
                    'nonce' => $nonce,
                    'format' => 'doc',
                ], home_url());
                $payload['download_link_pdf'] = add_query_arg([
                    'action' => 'attc_download_transcript',
                    'timestamp' => $ts,
                    'nonce' => $nonce,
                    'format' => 'pdf',
                ], home_url());
            }
            return new WP_REST_Response($payload, 200);
        }
    ]);

    // Run-now endpoint: kích hoạt xử lý job ngay lập tức (asynchronous trigger)
    register_rest_route('attc/v1', '/jobs/(?P<id>[^/]+)/run-now', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function(\WP_REST_Request $req) {
            $job_id = sanitize_text_field($req->get_param('id'));
            $token  = (string) $req->get_param('token');
            if (!$job_id || $token === '') {
                error_log(sprintf('[%s] Run-now bad request for %s (missing params)', date('Y-m-d H:i:s'), $job_id), 3, WP_CONTENT_DIR . '/attc_debug.log');
                return new WP_REST_Response(['status' => 'bad_request'], 400);
            }
            $job_key = 'attc_job_' . $job_id;
            $job = get_option($job_key, []);
            if (empty($job) || !is_array($job)) {
                error_log(sprintf('[%s] Run-now not_found for %s', date('Y-m-d H:i:s'), $job_id), 3, WP_CONTENT_DIR . '/attc_debug.log');
                return new WP_REST_Response(['status' => 'not_found'], 404);
            }
            $expected = (string) ($job['spawn_token'] ?? '');
            if ($expected === '' || !hash_equals($expected, $token)) {
                error_log(sprintf('[%s] Run-now forbidden for %s (bad token)', date('Y-m-d H:i:s'), $job_id), 3, WP_CONTENT_DIR . '/attc_debug.log');
                return new WP_REST_Response(['status' => 'forbidden'], 403);
            }
            $status = (string) ($job['status'] ?? 'queued');
            if ($status === 'running' || $status === 'done') {
                return new WP_REST_Response(['status' => 'already_started'], 200);
            }
            try {
                do_action('attc_run_job', $job_id);
                error_log(sprintf('[%s] Run-now started for %s', date('Y-m-d H:i:s'), $job_id), 3, WP_CONTENT_DIR . '/attc_debug.log');
            } catch (\Throwable $e) {
                error_log(sprintf('[%s] Spawn run-now error for %s: %s', date('Y-m-d H:i:s'), $job_id, $e->getMessage()), 3, WP_CONTENT_DIR . '/attc_debug.log');
            }
            return new WP_REST_Response(['status' => 'started'], 202);
        }
    ]);

    register_rest_route('attc/v1', '/jobs/(?P<id>[^/]+)/status', [
        'methods' => 'GET',
        // Cho phép public, tự xử lý đăng nhập trong callback để tránh 401
        'permission_callback' => '__return_true',
        'callback' => function(\WP_REST_Request $req) {
            $user_id = get_current_user_id();
            if ($user_id <= 0) {
                return new WP_REST_Response(['status' => 'none'], 200);
            }
            $job_id = sanitize_text_field($req->get_param('id'));
            $job = get_option('attc_job_' . $job_id, []);
            if (empty($job) || (int)($job['user_id'] ?? 0) !== $user_id) {
                // Không trả 401 để tránh lỗi console
                return new WP_REST_Response(['status' => 'none'], 200);
            }
            $payload = [
                'status' => (string) ($job['status'] ?? 'queued'),
                // luôn trả lại id từ tham số route nếu job thiếu id
                'job_id' => (string) (!empty($job['id']) ? $job['id'] : $job_id),
            ];
            if (!empty($job['transcript'])) {
                $payload['transcript'] = (string) $job['transcript'];
            }
            // Trả thêm summary_bullets để client có đủ dữ liệu ẩn loading và hiển thị kết quả
            if (!empty($job['summary_bullets']) && is_array($job['summary_bullets'])) {
                $payload['summary_bullets'] = array_values($job['summary_bullets']);
            }
            if (!empty($job['error'])) {
                $payload['error'] = (string) $job['error'];
            }
            if (!empty($job['file_name'])) {
                $payload['file_name'] = (string) $job['file_name'];
            }
            if (!empty($job['finished_at'])) {
                $payload['finished_at'] = (int) $job['finished_at'];
            }
            if (!empty($job['history_timestamp'])) {
                $ts = (int) $job['history_timestamp'];
                $nonce = wp_create_nonce('attc_download_' . $ts);
                $payload['download_link_doc'] = add_query_arg([
                    'action' => 'attc_download_transcript',
                    'timestamp' => $ts,
                    'nonce' => $nonce,
                    'format' => 'doc',
                ], home_url());
                $payload['download_link_pdf'] = add_query_arg([
                    'action' => 'attc_download_transcript',
                    'timestamp' => $ts,
                    'nonce' => $nonce,
                    'format' => 'pdf',
                ], home_url());
            }
            return new WP_REST_Response($payload, 200);
        }
    ]);

    // Retry a job by id (only owner)
    register_rest_route('attc/v1', '/jobs/(?P<id>[^/]+)/retry', [
        'methods' => 'POST',
        'permission_callback' => function() { return is_user_logged_in(); },
        'callback' => function(\WP_REST_Request $req) {
            $user_id = get_current_user_id();
            $job_id = sanitize_text_field($req->get_param('id'));
            $job_key = 'attc_job_' . $job_id;
            $job = get_option($job_key, []);
            if (empty($job) || (int)($job['user_id'] ?? 0) !== $user_id) {
                return new WP_REST_Response(['status' => 'not_found'], 404);
            }
            // Only allow retry if failed or queued but not running/done
            $status = (string)($job['status'] ?? 'queued');
            if ($status === 'done') {
                return new WP_REST_Response(['status' => 'already_done'], 200);
            }
            // Validate file still exists
            $file_path = (string) ($job['file_path'] ?? '');
            if (!file_exists($file_path)) {
                return new WP_REST_Response(['status' => 'cannot_retry', 'error' => 'Không tìm thấy file gốc để xử lý lại. Vui lòng tải lại file.'], 400);
            }
            // Reset status and reschedule
            $job['status'] = 'queued';
            unset($job['error']);
            unset($job['transcript']);
            update_option($job_key, $job, false);

            if (!wp_next_scheduled('attc_run_job', [$job_id])) {
                wp_schedule_single_event(time() + 1, 'attc_run_job', [$job_id]);
            }

            // update latest job id transient
            set_transient('attc_last_job_' . $user_id, $job_id, HOUR_IN_SECONDS);

            return new WP_REST_Response(['status' => 'requeued', 'job_id' => $job_id], 200);
        }
    ]);
});

// ===== Job Processor (WP-Cron) =====
add_action('attc_run_job', 'attc_process_job', 10, 1);
function attc_process_job($job_id) {
    error_log(sprintf("[%s] Background job 'attc_process_job' started for job_id: %s\n", date('Y-m-d H:i:s'), $job_id), 3, WP_CONTENT_DIR . '/attc_debug.log');
    $job = get_option('attc_job_' . $job_id, []);
    error_log(sprintf("[%s] Job data retrieved for %s: %s\n", date('Y-m-d H:i:s'), $job_id, json_encode($job)), 3, WP_CONTENT_DIR . '/attc_debug.log');
    if (empty($job) || !is_array($job)) { return; }

    $status = (string) ($job['status'] ?? 'queued');
    if ($status === 'running') {
        error_log(sprintf("[%s] Job %s already running, skip duplicate trigger.\n", date('Y-m-d H:i:s'), $job_id), 3, WP_CONTENT_DIR . '/attc_debug.log');
        return;
    }
    if ($status === 'done') {
        error_log(sprintf("[%s] Job %s already completed, skip processing.\n", date('Y-m-d H:i:s'), $job_id), 3, WP_CONTENT_DIR . '/attc_debug.log');
        return;
    }

    $job['status'] = 'running';
    update_option('attc_job_' . $job_id, $job, false);

    ignore_user_abort(true);
    @set_time_limit(0);

    $user_id = (int) ($job['user_id'] ?? 0);
    $file_path = (string) ($job['file_path'] ?? '');
    $is_free = !empty($job['is_free']);
    $cost = (int) ($job['cost'] ?? 0);

    if (!file_exists($file_path)) {
        $job['status'] = 'failed';
        $job['error'] = 'File không tồn tại.';
        error_log(sprintf("[%s] Job %s: Failed. Reason: File not found at %s\n", date('Y-m-d H:i:s'), $job_id, $file_path), 3, WP_CONTENT_DIR . '/attc_debug.log');
        update_option('attc_job_' . $job_id, $job);
        return;
    }

    // Nếu có yêu cầu cắt đoạn, xử lý ở job nền bằng ffmpeg để tránh 504 ở phía submit
    $trim_start = (int) ($job['trim_start'] ?? 0);
    $trim_end   = (int) ($job['trim_end'] ?? 0);
    $original_path = $file_path;
    $temp_trimmed = '';
    if ($trim_end > 0 && $trim_end > $trim_start) {
        $ffmpeg = get_option('attc_ffmpeg_path', 'ffmpeg');
        $trimmed_path = dirname($file_path) . '/' . basename($file_path, '.' . pathinfo($file_path, PATHINFO_EXTENSION)) . '-trimmed.' . pathinfo($file_path, PATHINFO_EXTENSION);
        $cmd = sprintf('%s -y -i %s -ss %s -to %s -c copy %s',
            escapeshellarg($ffmpeg),
            escapeshellarg($file_path),
            escapeshellarg(gmdate('H:i:s', max(0, (int)$trim_start))),
            escapeshellarg(gmdate('H:i:s', max(0, (int)$trim_end))),
            escapeshellarg($trimmed_path)
        );
        @exec($cmd, $out, $ret);
        if ($ret === 0 && file_exists($trimmed_path)) {
            error_log(sprintf("[%s] Job %s: Trimmed segment with ffmpeg to %s\n", date('Y-m-d H:i:s'), $job_id, $trimmed_path), 3, WP_CONTENT_DIR . '/attc_debug.log');
            $file_path = $trimmed_path;
            $temp_trimmed = $trimmed_path;
        } else {
            error_log(sprintf("[%s] Job %s: FFmpeg trim failed, fallback to original file %s\n", date('Y-m-d H:i:s'), $job_id, $original_path), 3, WP_CONTENT_DIR . '/attc_debug.log');
        }
    }

    $api_key = get_option('attc_openai_api_key');
    if (empty($api_key)) {
        $job['status'] = 'failed';
        $job['error'] = 'Thiếu OpenAI API Key.';
        error_log(sprintf("[%s] Job %s: Failed. Reason: Missing OpenAI API Key.\n", date('Y-m-d H:i:s'), $job_id), 3, WP_CONTENT_DIR . '/attc_debug.log');
        update_option('attc_job_' . $job_id, $job);
        return;
    }

    $api_url = 'https://api.openai.com/v1/audio/transcriptions';
    $headers = ['Authorization: Bearer ' . $api_key];
    $post_fields = [
        'model' => 'whisper-1',
        'language' => 'vi',
        'temperature' => 0,
        'file' => curl_file_create($file_path, mime_content_type($file_path), basename($file_path))
    ];

    error_log(sprintf("[%s] Job %s: Preparing to call OpenAI API at %s\n", date('Y-m-d H:i:s'), $job_id, $api_url), 3, WP_CONTENT_DIR . '/attc_debug.log');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);

    $response_body_str = curl_exec($ch);
    $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    error_log(sprintf("[%s] Job %s: cURL response. Code: %d. Body: %s. cURL Error: %s\n", date('Y-m-d H:i:s'), $job_id, $response_code, $response_body_str, $curl_error), 3, WP_CONTENT_DIR . '/attc_debug.log');

    if ($curl_error) {
        $job['status'] = 'failed';
        $job['error'] = 'cURL: ' . $curl_error;
        update_option('attc_job_' . $job_id, $job);
        if ($user_id > 0) { delete_user_meta($user_id, 'attc_last_job'); }
        return;
    }

    $response_body = json_decode($response_body_str, true);
    if ($response_code !== 200) {
        $job['status'] = 'failed';
        $job['error'] = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'OpenAI error.';
        update_option('attc_job_' . $job_id, $job);
        if ($user_id > 0) { delete_user_meta($user_id, 'attc_last_job'); }
        return;
    }

    $transcript = $response_body['text'] ?? '';

    // Hậu xử lý: chuẩn hoá chính tả tiếng Việt bằng OpenAI Chat (tuỳ chọn, an toàn lỗi)
    if (!empty($transcript)) {
        $corrected = attc_vi_correct_text($transcript, $api_key);
        if (is_string($corrected) && $corrected !== '') {
            $transcript = $corrected;
        }
    }

    // Chuẩn hoá transcript TV và sinh tóm tắt
    $normalized = attc_vi_normalize($transcript);
    $bullet_count = max(1, (int) get_option('attc_summary_bullets', 5));
    $summary_bullets = attc_make_summary_bullets($normalized, $bullet_count);

    // Chỉ trừ tiền khi API trả về thành công
    if (!$is_free && $cost > 0) {
        $charge_meta = [
            'reason' => 'audio_conversion',
            'duration' => (int)($job['duration'] ?? 0),
            'transcript' => $transcript,
            'created_at' => (int)($job['created_at'] ?? time()),
            'source_file_path' => (string)($original_path ?? ''),
        ];
        $charge_result = attc_charge_wallet($user_id, $cost, $charge_meta);
        if (is_wp_error($charge_result)) {
            // Không thể trừ tiền: vẫn lưu transcript vào lịch sử với amount 0 để không mất dữ liệu
            attc_add_wallet_history($user_id, [
                'type' => 'debit',
                'amount' => 0,
                'meta' => $charge_meta,
            ]);
        }
    } else {
        // Miễn phí: vẫn lưu transcript vào lịch sử
        attc_add_wallet_history($user_id, [
            'type' => 'conversion_free',
            'amount' => 0,
            'meta' => [
                'reason' => 'free_tier',
                'duration' => (int)($job['duration'] ?? 0),
                'transcript' => $transcript,
                'created_at' => (int)($job['created_at'] ?? time()),
                'source_file_path' => (string)($original_path ?? ''),
            ],
        ]);
        // Ghi nhận đã sử dụng 1 lượt miễn phí trong ngày (chỉ khi job thành công)
        $today_free = current_time('Y-m-d');
        $last_date = get_user_meta($user_id, '_attc_last_free_upload_date', true);
        if ($last_date === $today_free) {
            $used_today = (int) get_user_meta($user_id, '_attc_free_uploads_today', true);
            update_user_meta($user_id, '_attc_free_uploads_today', max(0, $used_today) + 1);
        } else {
            update_user_meta($user_id, '_attc_last_free_upload_date', $today_free);
            update_user_meta($user_id, '_attc_free_uploads_today', 1);
        }
    }

    $job['status'] = 'done';
    $job['transcript'] = $normalized;
    $job['summary_bullets'] = $summary_bullets;
    $job['finished_at'] = time();
    // Lưu timestamp lịch sử gần nhất để sinh link tải .docx
    $history = get_user_meta($user_id, 'attc_wallet_history', true);
    if (!empty($history)) {
        $last_item = end($history);
        if (!empty($last_item['timestamp'])) {
            $job['history_timestamp'] = (int) $last_item['timestamp'];
        }
    }
    update_option('attc_job_' . $job_id, $job);
    error_log(sprintf("[%s] Job %s: Final status updated to 'done'.\n", date('Y-m-d H:i:s'), $job_id), 3, WP_CONTENT_DIR . '/attc_debug.log');

    if ($user_id > 0) {
        delete_user_meta($user_id, 'attc_last_job');
        delete_transient('attc_last_job_' . $user_id);
    }

    // Dọn file tạm: xoá file trim nếu tạo; giữ file gốc nếu cần, hoặc xoá cả hai nếu phù hợp với chính sách lưu trữ
    if ($temp_trimmed && file_exists($temp_trimmed)) { @unlink($temp_trimmed); }
    // Vẫn xoá file gốc đã upload để giải phóng dung lượng
    if ($original_path && file_exists($original_path)) { @unlink($original_path); }
}

function attc_form_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="attc-auth-prompt"><p>Vui lòng <a href="' . esc_url(site_url('/dang-nhap')) . '">đăng nhập</a> hoặc <a href="' . esc_url(site_url('/dang-ky')) . '">đăng ký</a> để sử dụng chức năng này.</p></div>';
    }

    $user_id = get_current_user_id();
    $balance = attc_get_wallet_balance($user_id);
    $price_per_minute = attc_get_price_per_minute();
    $max_minutes = $price_per_minute > 0 ? floor($balance / $price_per_minute) : 0;
    $free_threshold = (int) get_option('attc_free_threshold', 500);

    $error_message = get_transient('attc_form_error');
    if ($error_message) delete_transient('attc_form_error');

    $success_message = get_transient('attc_form_success');
    if ($success_message) delete_transient('attc_form_success');
    // Thông báo hàng đợi (nếu vừa enqueue job)
    $queue_notice = get_transient('attc_queue_notice');
    if ($queue_notice) delete_transient('attc_queue_notice');

    // Kiểm tra job gần nhất: chỉ giữ lại khi còn đang chạy
    $attc_last_job_id = (string) get_user_meta($user_id, 'attc_last_job', true);
    $attc_has_active_job = false;
    if ($attc_last_job_id !== '') {
        $attc_last_job = get_option('attc_job_' . $attc_last_job_id, []);
        $attc_last_status = is_array($attc_last_job) ? (string) ($attc_last_job['status'] ?? '') : '';
        if (in_array($attc_last_status, ['queued', 'running'], true)) {
            $attc_has_active_job = true;
        } else {
            // Job đã xong hoặc không hợp lệ: dọn dẹp để tránh render lại sau reload
            delete_user_meta($user_id, 'attc_last_job');
            delete_transient('attc_last_job_' . $user_id);
            $attc_last_job_id = '';
        }
    }

    // Giữ lại attc_last_job để client có thể tiếp tục polling sau reload

    if (!$attc_has_active_job) {
        $queue_notice = '';
    }

    $last_cost = get_transient('attc_last_cost');
    if ($last_cost) delete_transient('attc_last_cost');
    // Đọc cấu hình giới hạn dung lượng upload (MB)
    $max_upload_mb = (int) get_option('attc_max_upload_mb', 10);

    ob_start();
    ?>
    <style>
        .attc-converter-wrap { max-width: 900px; margin: 1rem auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
        .attc-wallet-box { background: #d6e4fd; border-radius: 8px; padding: 1.5rem; display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        .attc-wallet-balance p { margin: 0; font-size: 1.1rem; } .attc-wallet-balance p strong { font-size: 1.5rem; color: #2c3e50; }
        .attc-wallet-sub { color: #5a6e82; font-size: 0.9rem; }
        .attc-wallet-actions a { text-decoration: none; padding: 0.6rem 1rem; border-radius: 5px; font-weight: 500; transition: background-color 0.2s; }
        .attc-wallet-actions .upgrade-btn { background-color: #3498db; color: white; }
        .attc-wallet-actions .history-btn { background-color: #95a5a6; color: white; }
        .attc-wallet-actions .logout-btn { background-color: #e74c3c; color: white; margin-left: 0.5rem; }
        .attc-alert { padding: 1rem; border-radius: 5px; border: 1px solid transparent; }
        .attc-error { color: #c0392b; background-color: #fbeae5; border-color: #f4c2c2; }
        .attc-info { color: #2980b9; background-color: #eaf5fb; border-color: #b3dcf4; }
        .attc-form-upload { border: 2px dashed #bdc3c7; border-radius: 8px; padding: 1rem; text-align: center; background-color: #fafafa; transition: background-color 0.2s, border-color 0.2s;margin-top: 1.5rem; }
        .attc-form-upload.is-dragover { background-color: #ecf0f1; border-color: #3498db; }
        .attc-form-upload p { margin: 0; font-size: 1.2rem; color: #7f8c8d; }
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
        #attc-result-tools {display: none;}
        /* Progress UI */
        .is-hidden { display: none; }
        .attc-progress { margin-top: 14px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; }
        .attc-progress-label { display:flex; align-items:center; gap:8px; color:#0f172a; font-weight:600; margin-bottom:8px; }
        .attc-spinner { width:16px; height:16px; border:2px solid #93c5fd; border-top-color:#1d4ed8; border-radius:50%; animation: attc-spin 0.8s linear infinite; }
        @keyframes attc-spin { from { transform: rotate(0deg);} to { transform: rotate(360deg);} }
        .attc-progress-outer { width:100%; height:10px; background:#e5e7eb; border-radius:999px; overflow:hidden; }
        .attc-progress-inner { height:100%; width:0%; background:#1d4ed8; transition: width .3s ease; }
        .attc-progress-text { font-size:12px; color:#334155; margin-top:6px; }
        .attc-user-name { font-size: 20px; }
        .attc-result-text { max-height: 300px; overflow-y: auto; background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 6px; line-height: 1.6; white-space: pre-wrap; }
        /* Tip box style */
        .attc-tip { background:#eaf4ff; border:1px solid #dbeafe; border-radius:12px; padding:12px 14px; display:flex; gap:12px; align-items:flex-start; color:#0f172a; box-shadow: 0 1px 2px rgba(0,0,0,.04); margin-bottom:1rem; }
        .attc-tip .attc-tip-icon { width:22px; height:22px; border-radius:50%; background:#eff6ff; border:1px solid #bfdbfe; display:flex; align-items:center; justify-content:center; color:#2563eb; font-weight:700; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji"; font-size:14px; flex:0 0 22px; margin-top:1px; }
        .attc-tip .attc-tip-content strong { font-weight:700; }
        .attc-tip .attc-tip-content { line-height:1.6; }
        /* Download icon buttons */
        .attc-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border:1px solid #e1e1e1;border-radius:6px;background:#fff;color:#1d4ed8;text-decoration:none}
        .attc-icon-btn + .attc-icon-btn{margin-left:8px}
        .attc-icon-btn.pdf{color:#16a34a}
        .attc-icon{width:18px;height:18px;display:block}
    </style>

    <?php
    $user_id = get_current_user_id();
    $has_made_deposit = attc_user_has_made_deposit($user_id);
    $free_uploads_left = attc_get_daily_free_uploads_remaining($user_id);
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
                    <p class="attc-wallet-sub">Mỗi ngày miễn phí chuyển đổi 1 File ghi âm < <?php echo (int) max(1, get_option('attc_free_max_minutes', 2)); ?> phút</p>
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
            Đơn giá hiện tại: <strong><?php echo number_format((int)$price_per_minute, 0, ',', '.'); ?>đ/phút</strong>
        </p>

        <div class="attc-alert attc-info attc-tip" style="margin-top:-4px;">
            <div class="attc-tip-icon" aria-hidden="true">i</div>
            <div class="attc-tip-content">
                <div><strong>Mẹo để kết quả chính xác hơn</strong></div>
                <div>Kết quả chuyển đổi phụ thuộc vào chất lượng ghi âm. Hãy chọn file <strong>giọng nói rõ</strong>, <strong>ít tiếng ồn</strong>, <strong>tốc độ nói vừa phải</strong> để tăng độ chính xác</div>
            </div>
        </div>

        <?php 
        $success_notification = get_transient('attc_registration_success');
        if ($success_notification) {
            delete_transient('attc_registration_success');
            echo '<div class="attc-alert attc-info">' . esc_html($success_notification) . '</div>';
        }
        ?>
        <?php if ($error_message): ?><div class="attc-alert attc-error"><?php echo esc_html($error_message); ?></div><?php endif; ?>
        <?php if ($last_cost): ?><div class="attc-alert attc-info"><?php echo esc_html($last_cost); ?></div><?php endif; ?>
        <?php if ($queue_notice): ?><div class="attc-alert attc-info" id="attc-queue-notice"><?php echo esc_html($queue_notice); ?></div><?php endif; ?>

        <?php $free_max_minutes = (int) get_option('attc_free_max_minutes', 2); ?>
        <form action="" method="post" enctype="multipart/form-data" id="attc-upload-form" data-price-per-minute="<?php echo esc_attr((float)$price_per_minute); ?>" data-max-mb="<?php echo esc_attr((int)$max_upload_mb); ?>" data-free-tier="<?php echo ($balance <= $free_threshold) ? '1' : '0'; ?>" data-free-max-min="<?php echo esc_attr( max(1, $free_max_minutes) ); ?>" data-free-left="<?php echo (int) $free_uploads_left; ?>" data-free-daily="<?php echo (int) $free_daily_limit; ?>">
            <?php wp_nonce_field('attc_form_action', 'attc_nonce'); ?>
            <div class="attc-form-upload" id="attc-drop-zone">
                <p>Kéo và thả file ghi âm vào đây, hoặc</p>
                <label for="audio_file" class="file-input-label">Chọn từ máy tính</label>
                <input type="file" id="audio_file" name="audio_file" accept="audio/*">
                <div id="attc-file-info"></div>
                <p class="attc-wallet-sub" style="margin-top:8px;color:#5a6e82;">Giới hạn kích thước tối đa: <strong><?php echo (int)$max_upload_mb; ?> MB</strong></p>
                <p id="attc-price-estimate" class="attc-wallet-sub" style="margin-top:6px;color:#2c3e50;"></p>
            </div>
            <div style="margin-top:16px;">
                <p style="font-weight:600; margin-bottom:8px;">Chọn đoạn chuyển đổi (phút:giây):</p>
                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <label>Bắt đầu (phút:giây)
                        <input type="text" id="attc-trim-start" placeholder="0:00" inputmode="numeric" pattern="[0-9:]*" maxlength="6" style="width:90px; margin-left:6px;">
                    </label>
                    <label>Kết thúc (phút:giây)
                        <input type="text" id="attc-trim-end" placeholder="0:00" inputmode="numeric" pattern="[0-9:]*" maxlength="6" style="width:90px; margin-left:6px;">
                    </label>
                    <span id="attc-duration-label" class="attc-wallet-sub" style="color:#5a6e82;">Độ dài: 0:00</span>
                </div>
                <audio id="attc-audio" controls style="width:100%; margin-top:10px;"></audio>
                <?php if ($balance <= $free_threshold): ?>
                    <div id="attc-free-warning" class="attc-alert attc-error">Tài khoản miễn phí: vui lòng chọn đoạn <= <?php echo (int) max(1, $free_max_minutes); ?> phút để chuyển đổi.</div>
                <?php else: ?>
                    <div id="attc-free-warning" class="attc-alert attc-error" style="display:none;"></div>
                <?php endif; ?>
                <input type="hidden" name="trim_start_sec" id="attc-trim-start-sec" value="0">
                <input type="hidden" name="trim_end_sec" id="attc-trim-end-sec" value="0">
            </div>
            <button type="submit" class="attc-submit-btn" id="attc-submit-button" disabled>Chuyển đổi</button>
            <div id="attc-progress" class="attc-progress is-hidden" aria-live="polite">
                <div class="attc-progress-label"><span class="attc-spinner" aria-hidden="true"></span><span>Đang xử lý...</span></div>
                <div class="attc-progress-outer"><div class="attc-progress-inner" id="attc-progress-bar"></div></div>
                <div class="attc-progress-text" id="attc-progress-text">Bắt đầu xử lý (0%)</div>
            </div>
        </form>

        <?php 
            // Luôn render khối kết quả để JS có thể chèn transcript khi xong
            $download_link = '';
            $download_link_pdf = '';
            $summary_server = [];
            if ($success_message) {
                $history = get_user_meta($user_id, 'attc_wallet_history', true);
                if (!empty($history)) {
                    $last_item = end($history);
                    $ts = $last_item['timestamp'] ?? 0;
                    $reason = $last_item['meta']['reason'] ?? '';
                    if ($ts && in_array($reason, ['audio_conversion','free_tier'], true)) {
                        $download_nonce = wp_create_nonce('attc_download_' . $ts);
                        $download_link = add_query_arg([
                            'action' => 'attc_download_transcript',
                            'timestamp' => $ts,
                            'nonce' => $download_nonce,
                            'format' => 'doc',
                        ], home_url());
                        $download_link_pdf = add_query_arg([
                            'action' => 'attc_download_transcript',
                            'timestamp' => $ts,
                            'nonce' => $download_nonce,
                            'format' => 'pdf',
                        ], home_url());
                        if (!empty($last_item['meta']['summary_bullets']) && is_array($last_item['meta']['summary_bullets'])) {
                            $summary_server = $last_item['meta']['summary_bullets'];
                        }
                    }
                }
            }
        ?>
        <?php $result_hidden = empty($success_message); ?>
        <div class="attc-result<?php echo $result_hidden ? ' is-hidden' : ''; ?>" id="attc-result">
            <h3>Kết quả chuyển đổi:</h3>
            <div class="attc-result-text"><?php echo $success_message ? nl2br(esc_html($success_message)) : ''; ?></div>
            <?php if ($download_link): ?>
            <div class="attc-result-actions">
                <a href="<?php echo esc_url($download_link); ?>" class="attc-icon-btn attc-download-doc" download aria-label="Tải Word (.doc)">
                    <svg class="attc-icon" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M6 2h8l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm8 0v4h4"/></svg>
                </a>
                <a href="#" class="attc-icon-btn attc-download-pdf pdf" data-ts="<?php echo isset($ts) ? (int)$ts : 0; ?>" aria-label="Tải PDF">
                    <svg class="attc-icon" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M6 2h8l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm8 0v4h4"/><path d="M8 15h8" stroke="currentColor" stroke-width="2"/><text x="7" y="13" font-size="6" font-family="Arial" fill="currentColor">PDF</text></svg>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($summary_server)): ?>
        <div class="attc-result" id="attc-summary" style="margin-top:12px;">
            <h3>Tóm tắt nội dung:</h3>
            <ul>
                <?php foreach ($summary_server as $b): ?>
                    <li><?php echo esc_html($b); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
    </div>

    <script>
    // Cung cấp nonce REST cho client, không phụ thuộc theme
    window.wpApiSettings = window.wpApiSettings || {};
    window.wpApiSettings.nonce = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Guard sớm: nếu không có form chuyển đổi thì không khởi tạo bất kỳ JS nào
        if (!document.getElementById('attc-upload-form')) {
            return;
        }
        const dropZone = document.getElementById('attc-drop-zone');
        const fileInput = document.getElementById('audio_file');
        const fileInfo = document.getElementById('attc-file-info');
        const submitButton = document.getElementById('attc-submit-button');
        const uploadForm = document.getElementById('attc-upload-form');
        const progressWrap = document.getElementById('attc-progress');
        const progressBar = document.getElementById('attc-progress-bar');
        const progressText = document.getElementById('attc-progress-text');
        // Giá/phút và giới hạn dung lượng từ cấu hình (không dùng optional chaining để tránh lỗi parse)
        var ds = (uploadForm && uploadForm.dataset) ? uploadForm.dataset : {};
        const pricePerMinute = parseFloat(ds.pricePerMinute || '0');
        const maxMb = parseFloat(ds.maxMb || '10');
        const isFreeTier = (String(ds.freeTier || '') === '1');
        const freeMaxMin = parseInt(ds.freeMaxMin || '2', 10);
        const freeMaxSec = Math.max(60, (isNaN(freeMaxMin) ? 2 : freeMaxMin) * 60);
        const freeLeft = parseInt(ds.freeLeft || '0', 10);
        const freeDaily = parseInt(ds.freeDaily || '1', 10);
        const summaryMax = parseInt('<?php echo (int) get_option('attc_summary_bullets', 5); ?>', 10) || 5;
        const estimateEl = document.getElementById('attc-price-estimate');
        const audioEl = document.getElementById('attc-audio');
        const trimStartInput = document.getElementById('attc-trim-start');
        const trimEndInput = document.getElementById('attc-trim-end');
        const trimStartSec = document.getElementById('attc-trim-start-sec');
        const trimEndSec = document.getElementById('attc-trim-end-sec');
        const durationLabel = document.getElementById('attc-duration-label');
        const freeWarning = document.getElementById('attc-free-warning');
        let totalDuration = 0;
        let blockedBySize = false;

        function formatMmSs(sec){
            sec = Math.max(0, Math.floor(sec||0));
            const m = Math.floor(sec/60);
            const s = sec%60;
            return m + ':' + String(s).padStart(2,'0');
        }
        function cleanRaw(el, clampSec){
            if (!el) return;
            let v = (el.value || '').toString();
            // giữ lại chỉ số và ':'
            v = v.replace(/[^0-9:]/g, '');
            // chỉ cho phép một dấu ':'
            const first = v.indexOf(':');
            if (first !== -1) {
                v = v.slice(0, first + 1) + v.slice(first + 1).replace(/:/g, '');
            }
            const parts = v.split(':');
            if (parts.length === 2) {
                parts[0] = parts[0].replace(/^0+(\d)/, '$1'); // bỏ 0 thừa ở phút
                parts[1] = parts[1].slice(0,2);
                if (clampSec) {
                    const sec = Math.min(59, parseInt(parts[1] || '0', 10) || 0);
                    parts[1] = String(sec).padStart(2,'0');
                }
                v = parts[0] + ':' + parts[1];
            } else {
                // chỉ số giây đơn
                parts[0] = parts[0].replace(/^0+(\d)/, '$1');
                v = parts[0];
            }
            el.value = v;
        }
        function parseMmSs(txt){
            if (!txt) return null;
            const raw = String(txt).trim();
            // Không cho nhập chữ, chỉ số và dấu ':'
            if (!/^[0-9:]+$/.test(raw)) return null;
            const parts = raw.split(':');
            if (parts.length === 1) {
                const s = parseInt(parts[0],10);
                if (isNaN(s) || s < 0) return null;
                return s;
            }
            if (parts.length === 2) {
                const m = parseInt(parts[0],10);
                const s = parseInt(parts[1],10);
                if (isNaN(m) || isNaN(s) || m < 0 || s < 0 || s > 59) return null;
                return m*60 + s;
            }
            return null;
        }
        function updateTrimDerived(triggerEl, formatNow){
            let start = parseMmSs(trimStartInput.value);
            let end = parseMmSs(trimEndInput.value);
            // Nếu giá trị không hợp lệ: trả về 0:00
            if (start === null) { start = 0; trimStartInput.value = '0:00'; }
            if (end === null) {
                // nếu chưa có tổng thởi lượng, coi như 0; nếu có thì đặt = tổng thởi lượng
                end = totalDuration > 0 ? totalDuration : 0; 
                trimEndInput.value = formatMmSs(end);
            }
            // Giới hạn theo tổng thởi lượng (chỉ khi đã biết tổng thởi lượng)
            if (totalDuration > 0) {
                start = Math.max(0, Math.min(start, totalDuration));
                end = Math.max(0, Math.min(end, totalDuration || end));
            } else {
                start = Math.max(0, start);
                end = Math.max(0, end);
            }
            // Bắt đầu luôn nhỏ hơn kết thúc
            if (end <= start) {
                // Nếu biết ô nào đang chỉnh, reset ô đó về 0:00
                if (triggerEl === trimStartInput) {
                    start = 0; trimStartInput.value = '0:00';
                } else if (triggerEl === trimEndInput) {
                    // đặt end = tổng thởi lượng nếu có, ngược lại 0
                    end = totalDuration > 0 ? totalDuration : 0; trimEndInput.value = formatMmSs(end);
                } else {
                    start = 0; trimStartInput.value = '0:00';
                }
                if (end <= start) {
                    // đảm bảo luôn end > start
                    end = Math.min(totalDuration || (start+1), start + 1);
                    trimEndInput.value = formatMmSs(end);
                }
            }
            trimStartSec.value = String(start);
            trimEndSec.value = String(end);
            if (formatNow) {
                trimStartInput.value = formatMmSs(start);
                trimEndInput.value = formatMmSs(end);
            }
            const effective = Math.max(0, end - start);
            if (durationLabel) durationLabel.textContent = 'Độ dài: ' + formatMmSs(effective);
            // Free tier warning
            if (isFreeTier && freeWarning) {
                if (effective > freeMaxSec) {
                    freeWarning.style.display = '';
                    freeWarning.textContent = 'Tài khoản miễn phí: vui lòng chọn đoạn <= ' + freeMaxMin + ' phút để chuyển đổi.';
                } else {
                    freeWarning.style.display = 'none';
                }
            }
            // Cập nhật trạng thái nút submit theo đoạn đã chọn (không can thiệp nếu bị chặn bởi số lượt còn lại)
            if (submitButton) {
                if (blockedBySize) {
                    submitButton.disabled = true;
                } else if (isFreeTier) {
                    // Cho phép bật nút ngay cả khi chưa chọn file (để người dùng biết mốc thởi gian hợp lệ)
                    const ok = (effective > 0) && (effective <= freeMaxSec) && (freeLeft > 0);
                    submitButton.disabled = !ok;
                } else {
                    const ok = (effective > 0);
                    submitButton.disabled = !ok;
                }
            }
            // Update estimate using effective duration
            if (estimateEl && pricePerMinute > 0) {
                const minutes = effective/60.0;
                const cost = Math.round(minutes * pricePerMinute);
                if (effective>0) {
                    estimateEl.textContent = 'Ước tính ' + minutes.toFixed(2) + ' phút = ' + cost.toLocaleString('vi-VN') + 'đ';
                }
            }
        }
        // Khai báo trước để dùng được trong submit handler
        const resultContainer = document.querySelector('.attc-result');
        const resultTextBox = document.querySelector('.attc-result-text');
        const resultToolbarId = 'attc-result-tools';
        let progressTimer = null;
        let fakeProgressStarted = false;

        // ===== PDF Download (client-side) for converter page =====
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
            try{
                t = String(t||'').trim().replace(/\s+/g,' ');
                t = t.replace(/[\u2026]/g,'…');
                t = t.replace(/([\.!?…])\s+/g,'$1\n');
                t = t.replace(/\n{2,}/g,'\n');
                return t;
            }catch(_){ return String(t||''); }
        }
        document.addEventListener('click', function(e){
            var btn = e.target.closest('.attc-download-pdf');
            if (!btn) return;
            e.preventDefault();
            var textBox = document.querySelector('#attc-result .attc-result-text');
            var text = textBox ? (textBox.textContent||'').trim() : '';
            if (!text){ return; }
            var tsAttr = btn.getAttribute('data-ts');
            var ts = tsAttr && tsAttr !== '0' ? tsAttr : String(Date.now());
            var container = document.createElement('div');
            container.style.cssText = 'margin:0; padding:0 12px 12px 12px; font-family: \'Times New Roman\', Times, serif; font-size:12px; line-height:1.5; white-space:pre-wrap; color:#000;';
            var pre = document.createElement('div');
            pre.textContent = prettifyText(text);
            container.appendChild(pre);
            loadHtml2Pdf().then(function(){
                var opt = {
                    margin: [0,10,10,10],
                    filename: 'attc-transcript-' + ts + '.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2 },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };
                window.html2pdf().set(opt).from(container).save().finally(function(){ container.remove(); });
            }).catch(function(){ /* no-op */ });
        });

        // Tránh cuộn hai lần: dùng cờ để chỉ cuộn một lần theo yêu cầu (bền qua reload)
        let hasScrolledToWallet = (sessionStorage.getItem('attc_scrolled_once') === '1');
        // Theo dõi lượt miễn phí hiện tại để cập nhật UI ngay khi xong
        let attcIsCurrentJobFree = false;
        let attcFreeDecremented = false;

        if (!dropZone || !uploadForm) return;

        // Chặn hành vi mặc định của trình duyệt trên toàn trang để tránh mở file khi kéo ra ngoài vùng drop
        ['dragenter','dragover','dragleave','drop'].forEach(function(evt){
            window.addEventListener(evt, function(e){ e.preventDefault(); e.stopPropagation(); }, false);
            document.addEventListener(evt, function(e){ e.preventDefault(); e.stopPropagation(); }, false);
        });

        // Sửa drag & drop file ghi âm (trên vùng drop)
        ;['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function(eventName){
            dropZone.addEventListener(eventName, function(e){
                e.preventDefault();
                e.stopPropagation();
            }, false);
        });
        ;['dragenter', 'dragover'].forEach(function(eventName){
            dropZone.addEventListener(eventName, function(e){
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.add('is-dragover');
            }, false);
        });
        ;['dragleave', 'drop'].forEach(function(eventName){
            dropZone.addEventListener(eventName, function(e){
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.remove('is-dragover');
            }, false);
        });
        dropZone.addEventListener('drop', function(e){
            e.preventDefault();
            e.stopPropagation();
            var dt = e.dataTransfer;
            var files = dt.files;
            if (files.length > 0) {
                fileInput.files = files;
                var event = new Event('change');
                fileInput.dispatchEvent(event);
            }
        }, false);

        // Hiện tên file khi chọn + bật/tắt nút submit
        function updateFileUI(){
            if (fileInput.files && fileInput.files.length > 0) {
                var file = fileInput.files[0];
                var sizeMB = (file.size / 1024 / 1024);
                fileInfo.textContent = 'File đã chọn: ' + file.name + ' (' + sizeMB.toFixed(2) + ' MB)';
                // Kiểm tra giới hạn dung lượng
                if (sizeMB > maxMb) {
                    blockedBySize = true;
                    submitButton.disabled = true;
                    if (estimateEl) estimateEl.textContent = 'File vượt quá giới hạn ' + maxMb + ' MB. Vui lòng chọn file nhỏ hơn.';
                    return;
                }
                blockedBySize = false;
                submitButton.disabled = false;
                // Nếu đã hết lượt miễn phí trong ngày ở free-tier thì chặn submit
                if (isFreeTier && freeLeft <= 0) {
                    submitButton.disabled = true;
                }
                // Nạp audio preview và thiết lập trim mặc định
                try {
                    const objectUrl = URL.createObjectURL(file);
                    if (audioEl) {
                        audioEl.src = objectUrl;
                        audioEl.onloadedmetadata = function(){
                            totalDuration = Math.max(0, parseFloat(audioEl.duration||0));
                            trimStartInput.value = '0:00';
                            trimEndInput.value = formatMmSs(totalDuration);
                            updateTrimDerived();
                        };
                        audioEl.onerror = function(){ totalDuration = 0; };
                    }
                } catch(e) { /* no-op */ }
            } else {
                fileInfo.textContent = '';
                blockedBySize = false;
                submitButton.disabled = true;
                if (estimateEl) estimateEl.textContent = '';
            }
        }
        fileInput.addEventListener('change', updateFileUI);
        // Lắng nghe thay đổi trên input trim để cập nhật ngay và bật/tắt nút theo hiệu lực
        if (trimStartInput) {
            trimStartInput.addEventListener('input', function(){ cleanRaw(trimStartInput, false); updateTrimDerived(trimStartInput, false); });
            trimStartInput.addEventListener('blur', function(){ cleanRaw(trimStartInput, true); updateTrimDerived(trimStartInput, true); });
        }
        if (trimEndInput) {
            trimEndInput.addEventListener('input', function(){ cleanRaw(trimEndInput, false); updateTrimDerived(trimEndInput, false); });
            trimEndInput.addEventListener('blur', function(){ cleanRaw(trimEndInput, true); updateTrimDerived(trimEndInput, true); });
        }
        // Khởi tạo trạng thái nút submit theo file hiện có (nếu có)
        updateFileUI();
        // Nếu free-tier và hết lượt thì chặn ngay cả khi chưa chọn file
        if (isFreeTier && freeLeft <= 0) {
            submitButton.disabled = true;
        }

        // Khi bấm submit: hiện thanh loading và thông báo hàng đợi trong lúc điều hướng/PRG
        let attcCurrentJobId = '';

        if (uploadForm) {
            uploadForm.addEventListener('submit', function(e){
                // Kiểm tra độ dài đoạn chọn trước khi gửi
                const s = parseInt(trimStartSec.value || '0', 10) || 0;
                const ed = parseInt(trimEndSec.value || '0', 10) || 0;
                const effective = Math.max(0, ed - s);
                if ((isFreeTier && effective > freeMaxSec) || effective <= 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (freeWarning) {
                        freeWarning.style.display = '';
                        freeWarning.textContent = 'Đoạn chọn vượt quá ' + freeMaxMin + ' phút hoặc không hợp lệ. Hãy điều chỉnh mốc Bắt đầu/Kết thúc.';
                    }
                    if (submitButton) submitButton.disabled = true;
                    return false;
                }
                // Passed validation → hiện thanh loading và thông báo xếp hàng
                if (progressWrap) {
                    progressWrap.classList.remove('is-hidden');
                }
                // Hiển thị thông báo hàng đợi/info ngay sau khi submit
                var infoAlert = document.querySelector('.attc-alert.attc-info.attc-tip');
                if (infoAlert) { infoAlert.classList.remove('is-hidden'); infoAlert.style.display = ''; }
                var qn = document.getElementById('attc-queue-notice');
                if (qn) {
                    qn.classList.remove('is-hidden');
                    qn.style.display = '';
                    // Đặt thông báo hàng đợi ngay bên dướikết quả
                    if (infoAlert && infoAlert.parentNode) {
                        try { infoAlert.parentNode.insertBefore(qn, infoAlert.nextSibling); } catch(e) {}
                    }
                } else {
                    // Nếu trang chưa có sẵn queue notice, tạo nhanh để báo cho người dùng
                    try {
                        qn = document.createElement('div');
                        qn.id = 'attc-queue-notice';
                        qn.className = 'attc-alert attc-info';
                        qn.textContent = 'Yêu cầu đã được đưa vào hàng đợi xử lý. Bạn có thể đóng trang hoặc chờ tại trang này, kết quả sẽ hiển thị khi hoàn tất.';
                        if (infoAlert && infoAlert.parentNode) {
                            infoAlert.parentNode.insertBefore(qn, infoAlert.nextSibling);
                        } else {
                            uploadForm.parentNode.insertBefore(qn, uploadForm);
                        }
                    } catch(_) {}
                }
                if (progressBar) { progressBar.style.width = '25%'; }
                if (progressText) { progressText.textContent = 'Đang đưa yêu cầu vào hàng đợi (25%)'; }
                if (typeof startFakeProgress === 'function') { startFakeProgress(); }
                // Đánh dấu job miễn phí nếu đủ điều kiện để khi xong sẽ trừ ngay UI
                try {
                    const freeTier = (uploadForm.getAttribute('data-free-tier') === '1');
                    const freeLeft = parseInt(uploadForm.getAttribute('data-free-left') || '0', 10);
                    attcIsCurrentJobFree = !!(freeTier && freeLeft > 0);
                    attcFreeDecremented = false;
                } catch(_) {}
                // Bắt đầu polling: lên lịch theo backoff, tránh gọi lặp ngay lập tức
                attcCurrentJobId = (uploadForm.getAttribute('data-last-job') || '').trim();
                pollActive = true; pollAttempts = 0; scheduleNextPoll();
                window.setTimeout(() => {
                    try {
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    } catch(_) {}
                }, 50);
                // Cuộn nhẹ lên phần thông tin hàng đợi (một lần duy nhất)
                const infoTop = document.querySelector('.attc-alert.attc-info.attc-tip') || document.querySelector('.attc-wallet-box');
                if (!hasScrolledToWallet && infoTop && infoTop.scrollIntoView) {
                    hasScrolledToWallet = true;
                    try { sessionStorage.setItem('attc_scrolled_once', '1'); } catch(_) {}
                    infoTop.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }

        // Polling trạng thái job mới nhất để auto hiển thị transcript
        const attcRestLatestPath = '<?php echo esc_js( wp_parse_url(rest_url('attc/v1/jobs/latest'), PHP_URL_PATH) ); ?>';
        const attcRestJobPathBase = '<?php echo esc_js( wp_parse_url(rest_url('attc/v1/jobs/'), PHP_URL_PATH) ); ?>';
        const jobLatestUrl = attcRestLatestPath ? (window.location.origin.replace(/\/$/, '') + attcRestLatestPath) : '<?php echo esc_url_raw( rest_url('attc/v1/jobs/latest') ); ?>';
        // Fallback từ server: job_id gần nhất lưu trong user meta (render vào HTML)
        const attcLastJobId = '<?php echo esc_js($attc_last_job_id); ?>';
        const attcHasActiveJob = <?php echo $attc_has_active_job ? 'true' : 'false'; ?>;
        // Base URL để truy vấn trạng thái theo job_id (fallback)
        const jobStatusBase = attcRestJobPathBase ? (window.location.origin.replace(/\/$/, '') + attcRestJobPathBase) : '<?php echo esc_url_raw( rest_url('attc/v1/jobs/') ); ?>';

        function renderFinalResult_legacy(data) {
            // 1. Dừng polling ngay lập tức
            if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }

            // 2. Chuẩn bị dữ liệu transcript & tóm tắt (tạo nhanh ở client nếu thiếu)
            const hasTranscript = !!(data.transcript && typeof data.transcript === 'string' && data.transcript.trim().length > 0);
            let summaryArr = (Array.isArray(data.summary_bullets) ? data.summary_bullets : []);
            if (hasTranscript && (!summaryArr || summaryArr.length === 0)) {
                summaryArr = makeSummaryBullets(data.transcript, summaryMax);
            }

            // 3. Dọn dẹp UI: ẩn progress chỉ khi đã có transcript VÀ tóm tắt từ server
            const hasServerSummary = Array.isArray(data.summary_bullets) && data.summary_bullets.length > 0;
            if (hasTranscript && hasServerSummary) {
                if (progressWrap) progressWrap.classList.add('is-hidden');
                if (typeof resetFakeProgress === 'function') { resetFakeProgress(); }
                document.querySelectorAll('.attc-alert.attc-info').forEach(function(el) { el.style.display = 'none'; });
                var qn = document.getElementById('attc-queue-notice');
                if (qn) { qn.classList.add('is-hidden'); qn.style.display = 'none'; }
            } else {
                // Giữ loading nếu chưa đủ dữ liệu
                if (progressWrap) progressWrap.classList.remove('is-hidden');
                var qn2 = document.getElementById('attc-queue-notice');
                if (qn2) { qn2.classList.remove('is-hidden'); qn2.style.display = ''; }
            }

            // 4. Hiển thị khối kết quả
            if (resultContainer) { resultContainer.classList.remove('is-hidden'); }

            // 5. Render văn bản
            const text = (data.transcript && typeof data.transcript === 'string' && data.transcript.trim().length > 0) ? data.transcript : '(Không có nội dung hoặc API trả về rỗng)';
            if (resultTextBox) {
                resultTextBox.textContent = text;
            }

            // 6. Render nút tải về (icon)
            let actions = document.querySelector('.attc-result-actions');
            if (data.download_link_doc || data.download_link_pdf) {
                if (!actions) {
                    actions = document.createElement('div');
                    actions.className = 'attc-result-actions';
                    if (resultContainer) resultContainer.appendChild(actions);
                }
                const tsForBtn = (data.finished_at && String(data.finished_at)) ? String(data.finished_at) : String(Date.now());
                const parts = [];
                if (data.download_link_doc) {
                    parts.push(`<a href="${data.download_link_doc}" class="attc-icon-btn attc-download-doc" download aria-label="Tải Word (.doc)"><svg class="attc-icon" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M6 2h8l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm8 0v4h4"/></svg></a>`);
                }
                // PDF dùng client-side
                parts.push(`<a href="#" class="attc-icon-btn attc-download-pdf pdf" data-ts="${tsForBtn}" aria-label="Tải PDF"><svg class="attc-icon" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M6 2h8l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm8 0v4h4"/><path d="M8 15h8" stroke="currentColor" stroke-width="2"/><text x="7" y="13" font-size="6" font-family="Arial" fill="currentColor">PDF</text></svg></a>`);
                actions.innerHTML = parts.join(' ');
            } else if (actions) {
                actions.innerHTML = '';
            }

            // 6.1. Render tóm tắt nội dung ngay bên dướikết quả
            let summaryWrap = document.getElementById('attc-summary');
            if (!summaryWrap) {
                summaryWrap = document.createElement('div');
                summaryWrap.id = 'attc-summary';
                summaryWrap.className = 'attc-result';
                summaryWrap.style.marginTop = '12px';
                if (resultContainer && resultContainer.parentNode) {
                    resultContainer.parentNode.insertBefore(summaryWrap, resultContainer.nextSibling);
                } else {
                    document.body.prepend(summaryWrap);
                }
            }
            if (summaryArr && Array.isArray(summaryArr) && summaryArr.length) {
                const lis = summaryArr.map(b => `<li>${String(b)}</li>`).join('');
                summaryWrap.innerHTML = `<h3>Tóm tắt nội dung:</h3><ul>${lis}</ul>`;
            } else {
                summaryWrap.innerHTML = '<h3>Tóm tắt nội dung:</h3><div>Đang tạo tóm tắt</div>';
            }

            // 7. Tự động sao chép
            if (data.transcript && typeof data.transcript === 'string' && data.transcript.trim().length > 0) {
                autoCopyText(data.transcript);
            }

            // 8. Không tự cuộn xuống kết quả để tránh giật trang khi đã cuộn lên khu vực thông báo
        }

        // Thông báo ngắn gọn
        function showToast(message) {
            try {
                var old = document.getElementById('attc-toast');
                if (old && old.parentNode) old.parentNode.removeChild(old);
                var t = document.createElement('div');
                t.id = 'attc-toast';
                t.textContent = message;
                t.style.position = 'fixed';
                t.style.right = '16px';
                t.style.bottom = '16px';
                t.style.background = '#16a34a';
                t.style.color = '#fff';
                t.style.padding = '10px 14px';
                t.style.borderRadius = '8px';
                t.style.boxShadow = '0 4px 12px rgba(0,0,0,.15)';
                t.style.zIndex = '9999';
                document.body.appendChild(t);
                setTimeout(function(){ if (t && t.parentNode) t.parentNode.removeChild(t); }, 2500);
            } catch(e) {}
        }

        // Tự động sao chép văn bản vào clipboard (có fallback)
        function autoCopyText(text) {
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).catch(() => {});
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.top = '-9999px';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); } catch(e) {}
                    document.body.removeChild(ta);
                }
            } catch(e) {}
        }

        // Tóm tắt client-side để hiển thị nhanh, không phụ thuộc server
        function makeSummaryBullets(text, maxItems) {
            try {
                const t = String(text || '').trim();
                if (!t) return [];
                const parts = t.split(/(?<=[\.!\?])\s+|\n+/).filter(Boolean);
                const bullets = [];
                for (let p of parts) {
                    p = String(p).trim();
                    if (!p) continue;
                    const words = p.split(/\s+/);
                    if (words.length > 20) {
                        p = words.slice(0, 20).join(' ') + '...';
                    }
                    if (p.length > 100) {
                        p = p.slice(0, 100) + '...';
                    }
                    bullets.push(p);
                    if (bullets.length >= Math.max(1, maxItems|0)) break;
                }
                return bullets;
            } catch(_) { return []; }
        }

        let pollTimer = null;
        let pollIntervalMs = 2000;
        let pollAttempts = 0;
        let pollActive = false;

        function scheduleNextPoll() {
            if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
            if (!pollActive) return;
            // Backoff cân bằng tải: <30 lần: 2s, 30-89: 5s, >=90: 12s
            if (pollAttempts >= 90) pollIntervalMs = 12000; else if (pollAttempts >= 30) pollIntervalMs = 5000; else pollIntervalMs = 2000;
            pollTimer = setTimeout(pollLatestJob, pollIntervalMs);
        }

        function pollLatestJob(){
            if (!pollActive) return;
            // console.debug('[ATTG] Poll jobs');
            const bust = (jobLatestUrl.indexOf('?') > -1 ? '&' : '?') + 't=' + Date.now();
            fetch(jobLatestUrl + bust, { credentials: 'same-origin', headers: { 'X-WP-Nonce': (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '' }, cache: 'no-store' })
                .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP '+r.status)))
                .then(data => {
                    pollAttempts++;
                    if (!data || typeof data !== 'object') { scheduleNextPoll(); return; }
                    // Nếu không có job nào và không có lastJob fallback => dừng polling để tránh tốn tài nguyên
                    if ((data.status === 'none' || !data.status) && (!attcLastJobId || String(attcLastJobId).trim() === '')) {
                        pollActive = false;
                        return;
                    }
                    // Nếu job mới nhất đã hoàn tất
                    if (data.status === 'done') {
                        const hasTranscript = !!(data.transcript && typeof data.transcript === 'string' && data.transcript.trim().length > 0);
                        if (hasTranscript) {
                            // Hiển thị ngay và dừng polling để tránh treo 90%
                            renderFinalResult(data);
                            pollActive = false;
                            return;
                        }
                        // Thiếu transcript: thử lấy chi tiết theo job_id
                        if (data.job_id) {
                            const bust3 = '?t=' + Date.now();
                            fetch(jobStatusBase + encodeURIComponent(data.job_id) + '/status' + bust3, {
                                method: 'GET', credentials: 'same-origin',
                                headers: { 'X-WP-Nonce': (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '' },
                                cache: 'no-store'
                            })
                            .then(r => r.json())
                            .then(d2 => {
                                if (!d2) { scheduleNextPoll(); return; }
                                const okT = !!(d2.transcript && typeof d2.transcript === 'string' && d2.transcript.trim().length > 0);
                                renderFinalResult(d2);
                                pollActive = true;
                                if (!okT) scheduleNextPoll(); else pollActive = false;
                            })
                            .catch(function(){ scheduleNextPoll(); });
                            return;
                        }
                        // Không có transcript và không có job_id: tiếp tục poll
                        scheduleNextPoll();
                        return;
                    }
                    if (data.status === 'failed') {
                        if (progressWrap) progressWrap.classList.add('is-hidden');
                        if (typeof resetFakeProgress === 'function') { resetFakeProgress(); }
                        var qnFail = document.getElementById('attc-queue-notice');
                        if (qnFail) qnFail.classList.add('is-hidden');
                        pollActive = false;
                        return;
                    }
                    // Nếu latest trả 'none' nhưng chúng ta có attcLastJobId được in từ server, thử fallback theo id
                    if ((data.status === 'none' || !data.status) && attcLastJobId) {
                        const bust2 = '?t=' + Date.now();
                        fetch(jobStatusBase + encodeURIComponent(attcLastJobId) + '/status' + bust2, {
                            method: 'GET',
                            credentials: 'same-origin',
                            headers: { 'X-WP-Nonce': (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '' },
                            cache: 'no-store'
                        })
                            .then(function(r){ return r.json(); })
                            .then(function(d){
                                if (!d) return;
                                // Nếu server đã có transcript, render ngay
                                if (d.status === 'done') {
                                    const hasTranscript2 = !!(d.transcript && typeof d.transcript === 'string' && d.transcript.trim().length > 0);
                                    const hasServerSummary2 = Array.isArray(d.summary_bullets) && d.summary_bullets.length > 0;
                                    renderFinalResult(d);
                                    if (hasTranscript2 && hasServerSummary2) {
                                        pollActive = false;
                                    } else {
                                        scheduleNextPoll();
                                    }
                                    return;
                                }
                            })
                            .catch(function(){});
                        // tiếp tục phần còn lại để vẽ toolbar nếu cần
                    }

                    // Nếu có thông báo hàng đợi (sau khi upload và redirect), tự hiển thị progress
                    const queueNoticeEl = document.getElementById('attc-queue-notice');
                    if (queueNoticeEl && progressWrap) {
                        progressWrap.classList.remove('is-hidden');
                        startFakeProgress();
                    }
                    scheduleNextPoll();
                })
                .catch(function(err){
                    try {
                        if (progressText) progressText.textContent = 'Mất kết nối, đang thử lại...';
                    } catch(_) {}
                    scheduleNextPoll();
                });
        }

        // Nếu có thông báo hàng đợi (sau khi upload và redirect), tự hiển thị progress
        const queueNoticeEl = document.getElementById('attc-queue-notice');
        if (queueNoticeEl && progressWrap) {
            progressWrap.classList.remove('is-hidden');
            startFakeProgress();
            // Đặt thông báo hàng đợi ngay bên dướikết quả
            const infoAlertLoad = document.querySelector('.attc-alert.attc-info.attc-tip');
            if (infoAlertLoad && infoAlertLoad.parentNode) {
                try { infoAlertLoad.parentNode.insertBefore(queueNoticeEl, infoAlertLoad.nextSibling); } catch(e) {}
            }
        }
        // Chỉ kích hoạt polling khi có queue notice hoặc đã có lastJobId VÀ chắc chắn đang ở trang bộ chuyển đổi (có upload form)
        const hasUploadForm = !!document.getElementById('attc-upload-form');
        if (hasUploadForm && attcHasActiveJob && ((queueNoticeEl && progressWrap) || (attcLastJobId && String(attcLastJobId).trim() !== ''))) {
            pollActive = true;
            pollLatestJob();
        } else {
            pollActive = false;
            if (resultContainer) resultContainer.classList.add('is-hidden');
            let summaryWrap = document.getElementById('attc-summary');
            if (summaryWrap) summaryWrap.classList.add('is-hidden');
        }

function renderFinalResult(data) {
    // 1. Dừng polling ngay lập tức
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }

    // 2. Chuẩn bị dữ liệu transcript & tóm tắt (chỉ dùng tóm tắt từ server để hiển thị)
    const hasTranscript = !!(data.transcript && typeof data.transcript === 'string' && data.transcript.trim().length > 0);
    const summaryArr = (Array.isArray(data.summary_bullets) ? data.summary_bullets : []);

    // 3. Dọn dẹp UI: ẩn progress NGAY khi đã có transcript (tóm tắt có thể đến sau)
    const hasServerSummary = Array.isArray(data.summary_bullets) && data.summary_bullets.length > 0;
    const summaryRequired = false;
    if (hasTranscript && (!summaryRequired || hasServerSummary)) {
        if (progressWrap) progressWrap.classList.add('is-hidden');
        document.querySelectorAll('.attc-alert.attc-info').forEach(function(el) { el.style.display = 'none'; });
        var qn = document.getElementById('attc-queue-notice');
        if (qn) { qn.classList.add('is-hidden'); qn.style.display = 'none'; }
        // Nếu là job miễn phí và chưa trừ lượt ở UI, cập nhật ngay không cần reload
        if (attcIsCurrentJobFree && !attcFreeDecremented) {
            try {
                const freeLeftAttr = parseInt((uploadForm && uploadForm.getAttribute('data-free-left')) || '0', 10);
                const newLeft = Math.max(0, (isNaN(freeLeftAttr) ? 0 : freeLeftAttr) - 1);
                if (uploadForm) uploadForm.setAttribute('data-free-left', String(newLeft));
                const walletBox = document.querySelector('.attc-wallet-box');
                if (walletBox) {
                    const p = Array.from(walletBox.querySelectorAll('p')).find(function(node){ return (node.textContent || '').indexOf('Hôm nay bạn còn:') !== -1; });
                    if (p) {
                        const strongEl = p.querySelector('strong');
                        if (strongEl) { strongEl.textContent = String(newLeft); }
                    }
                }
            } catch(_) {}
            attcFreeDecremented = true;
            attcIsCurrentJobFree = false;
        }
    } else {
        // Giữ loading nếu chưa đủ dữ liệu
        if (progressWrap) progressWrap.classList.remove('is-hidden');
        var qn2 = document.getElementById('attc-queue-notice');
        if (qn2) { qn2.classList.remove('is-hidden'); qn2.style.display = ''; }
    }

    // 4. Hiển thị khối kết quả ngay khi có transcript (tóm tắt sẽ cập nhật sau nếu có)
    if (resultContainer) {
        if (hasTranscript) {
            resultContainer.classList.remove('is-hidden');
        } else {
            resultContainer.classList.add('is-hidden');
        }
    }

    // 5. Render văn bản
    const text = (data.transcript && typeof data.transcript === 'string' && data.transcript.trim().length > 0) ? data.transcript : '(Không có nội dung hoặc API trả về rỗng)';
    if (resultTextBox) {
        resultTextBox.textContent = text;
    }

    // 6. Render nút tải về (icon)
    let actions = document.querySelector('.attc-result-actions');
    if (data.download_link_doc || data.download_link_pdf) {
        if (!actions) {
            actions = document.createElement('div');
            actions.className = 'attc-result-actions';
            if (resultContainer) resultContainer.appendChild(actions);
        }
        const tsForBtn = (data.finished_at && String(data.finished_at)) ? String(data.finished_at) : String(Date.now());
        const parts = [];
        if (data.download_link_doc) {
            parts.push(`<a href="${data.download_link_doc}" class="attc-icon-btn attc-download-doc" download aria-label="Tải Word (.doc)"><svg class=\"attc-icon\" viewBox=\"0 0 24 24\" fill=\"currentColor\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"M6 2h8l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm8 0v4h4\"/></svg></a>`);
        }
        parts.push(`<a href="#" class="attc-icon-btn attc-download-pdf pdf" data-ts="${tsForBtn}" aria-label="Tải PDF"><svg class=\"attc-icon\" viewBox=\"0 0 24 24\" fill=\"currentColor\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"M6 2h8l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm8 0v4h4\"/><path d=\"M8 15h8\" stroke=\"currentColor\" stroke-width=\"2\"/><text x=\"7\" y=\"13\" font-size=\"6\" font-family=\"Arial\" fill=\"currentColor\">PDF</text></svg></a>`);
        actions.innerHTML = parts.join(' ');
    } else if (actions) {
        actions.innerHTML = '';
    }

    // 6.1. Render tóm tắt nội dung: nếu chưa có từ server, hiển thị trạng thái chờ
    if (hasTranscript) {
        let summaryWrap = document.getElementById('attc-summary');
        if (!summaryWrap) {
            summaryWrap = document.createElement('div');
            summaryWrap.id = 'attc-summary';
            summaryWrap.className = 'attc-result';
            summaryWrap.style.marginTop = '12px';
            if (resultContainer && resultContainer.parentNode) {
                resultContainer.parentNode.insertBefore(summaryWrap, resultContainer.nextSibling);
            } else {
                document.body.prepend(summaryWrap);
            }
        }
        if (hasServerSummary && summaryArr && Array.isArray(summaryArr) && summaryArr.length) {
            const lis = summaryArr.map(b => `<li>${String(b)}</li>`).join('');
            summaryWrap.innerHTML = `<h3>Tóm tắt nội dung:</h3><ul>${lis}</ul>`;
        } else {
            summaryWrap.innerHTML = '<h3>Tóm tắt nội dung:</h3><div>Đang tạo tóm tắt...</div>';
        }
    }

    // 7. Tự động sao chép
    if (data.transcript && typeof data.transcript === 'string' && data.transcript.trim().length > 0) {
        autoCopyText(data.transcript);
    }

    // 8. Không tự cuộn xuống kết quả để tránh giật trang khi đã cuộn lên khu vực thông báo
}

// Thông báo ngắn gọn
function showToast(message) {
    try {
        var old = document.getElementById('attc-toast');
        if (old && old.parentNode) old.parentNode.removeChild(old);
        var t = document.createElement('div');
        t.id = 'attc-toast';
        t.textContent = message;
        t.style.position = 'fixed';
        t.style.right = '16px';
        t.style.bottom = '16px';
        t.style.background = '#16a34a';
        t.style.color = '#fff';
        t.style.padding = '10px 14px';
        t.style.borderRadius = '8px';
        t.style.boxShadow = '0 4px 12px rgba(0,0,0,.15)';
        t.style.zIndex = '9999';
        document.body.appendChild(t);
        setTimeout(function(){ if (t && t.parentNode) t.parentNode.removeChild(t); }, 2500);
    } catch(e) {}
}

// Tự động sao chép văn bản vào clipboard (có fallback)
function autoCopyText(text) {
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(() => {});
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.top = '-9999px';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch(e) {}
            document.body.removeChild(ta);
        }
    } catch(e) {}
}

// Tóm tắt client-side để hiển thị nhanh, không phụ thuộc server
function makeSummaryBullets(text, maxItems) {
    try {
        const t = String(text || '').trim();
        if (!t) return [];
        const parts = t.split(/(?<=[\.!\?])\s+|\n+/).filter(Boolean);
        const bullets = [];
        for (let p of parts) {
            p = String(p).trim();
            if (!p) continue;
            const words = p.split(/\s+/);
            if (words.length > 20) {
                p = words.slice(0, 20).join(' ') + '...';
            }
            if (p.length > 100) {
                p = p.slice(0, 100) + '...';
            }
            bullets.push(p);
            if (bullets.length >= Math.max(1, maxItems|0)) break;
        }
        return bullets;
    } catch(_) { return []; }
}

function startFakeProgress() {
    if (fakeProgressStarted) return;
    fakeProgressStarted = true;
    let p = 0;
    try {
        if (progressBar && progressBar.style && progressBar.style.width) {
            const cur = parseInt(progressBar.style.width, 10);
            if (!isNaN(cur)) p = Math.min(90, Math.max(0, cur));
        }
    } catch(_) {}
    updateProgress(p);
    clearInterval(progressTimer);
    progressTimer = setInterval(() => {
        // Tăng chậm dần đến 90%, chờ server trả về dữ liệu thật
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
    fakeProgressStarted = false;
}

function updateProgress(val) {
    if (progressBar) progressBar.style.width = val + '%';
    if (progressText) progressText.textContent = 'Đang xử lý (' + val + '%)';
}
});
</script>

<?php
    return ob_get_clean();
}
// Đăng ký shortcode hiển thị form chuyển đổi
add_shortcode('audio_to_text_form', 'attc_form_shortcode');

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
                max-width: 700px;
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
                margin-top: 1rem;
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
    $activation_success = get_transient('attc_activation_success');
    if ($activation_success) delete_transient('attc_activation_success');
    $activation_success = get_transient('attc_activation_success');
    if ($activation_success) delete_transient('attc_activation_success');

    ob_start();
    ?>
    <div class="attc-auth-form-wrap">
        <h2>Đăng nhập</h2>
        <?php if ($activation_success): ?>
            <div class="attc-auth-info"><p><?php echo esc_html($activation_success); ?></p></div>
        <?php endif; ?>
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
            <p><a href="<?php echo esc_url(site_url('/quen-mat-khau')); ?>">Quên mật khẩu?</a></p>
            <p>Chưa có tài khoản? <a href="<?php echo esc_url(site_url('/dang-ky')); ?>">Đăng ký ngay</a></p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('attc_login_form', 'attc_login_form_shortcode');

function attc_handle_custom_login_form() {

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['attc_login_submit']) && wp_verify_nonce($_POST['attc_login_nonce'], 'attc_login_action')) {
        
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
            // Kiểm tra xem email đã được xác thực chưa
            $is_verified = (int) get_user_meta($user->ID, 'attc_email_verified', true);
            if ($is_verified !== 1) {
                wp_logout(); // Đăng xuất người dùng ngay lập tức
                set_transient('attc_login_errors', 'Tài khoản của bạn chưa được kích hoạt. Vui lòng kiểm tra Email để lấy link kích hoạt.', 60);
                wp_redirect(site_url('/dang-nhap'));
                exit;
            }

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
    $reg_info = get_transient('attc_registration_info');
    if ($reg_info) delete_transient('attc_registration_info');

    ob_start();
    ?>
    <div class="attc-auth-form-wrap">
        <h2>Đăng ký tài khoản</h2>
        <?php
        if ($reg_info) {
            echo '<div class="attc-auth-info"><p>' . esc_html($reg_info) . '</p></div>';
        }
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
            <div class="form-row"><label for="attc_reg_email">Email</label><input type="email" name="reg_email" id="attc_reg_email" required><small style=\"display:block;color:#555;margin-top:6px;\">Vui lòng nhập đúng Email của bạn. Hệ thống sẽ gửi liên kết kích hoạt để xác thực tài khoản.</small></div>
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

function attc_handle_email_verification() {
    if (isset($_GET['attc_verify'], $_GET['uid'], $_GET['token'])) {
        $user_id = (int) $_GET['uid'];
        $token = sanitize_text_field($_GET['token']);
        $user = get_user_by('id', $user_id);

        if ($user) {
            $saved_token = get_user_meta($user_id, 'attc_verify_token', true);
            if ($saved_token && hash_equals($saved_token, $token)) {
                // Token hợp lệ, kích hoạt tài khoản
                update_user_meta($user_id, 'attc_email_verified', 1);
                delete_user_meta($user_id, 'attc_verify_token');
                set_transient('attc_activation_success', 'Tài khoản của bạn đã được kích hoạt thành công. Vui lòng đăng nhập.', 60);
            } else {
                // Token không hợp lệ hoặc đã hết hạn
                set_transient('attc_login_errors', 'Liên kết kích hoạt không chính xác hoặc đã được sử dụng. Vui lòng thử lại hoặc liên hệ hỗ trợ.', 60);
            }
        } else {
            set_transient('attc_login_errors', 'Người dùng không tồn tại.', 60);
        }
        wp_redirect(site_url('/dang-nhap/'));
        exit;
    }
}
add_action('template_redirect', 'attc_handle_email_verification');

function attc_handle_custom_registration_form() {

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['attc_register_submit']) && wp_verify_nonce($_POST['attc_register_nonce'], 'attc_register_action')) {
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
                
                // Đặt trạng thái chưa xác thực và gửi email
                update_user_meta($user_id, 'attc_email_verified', 0);
                $token = wp_generate_password(32, false, false);
                update_user_meta($user_id, 'attc_verify_token', $token);

                $verify_link = add_query_arg([
                    'attc_verify' => 1,
                    'uid' => $user_id,
                    'token' => $token,
                ], home_url('/')); // Gửi đến trang chủ, hook sẽ bắt và chuyển hướng đến trang đăng nhập

                $subject = 'Kích hoạt tài khoản của bạn tại AudioAI';
                $message = "Xin chào {$username},\n\nCảm ơn bạn đã đăng ký. Vui lòng nhấp vào liên kết sau để kích hoạt tài khoản của bạn:\n{$verify_link}\n\nNếu bạn không thực hiện đăng ký này, vui lòng bỏ qua email.";
                wp_mail($email, $subject, $message);

                set_transient('attc_registration_info', 'Đăng ký thành công. Vui lòng kiểm tra Email của bạn để kích hoạt tài khoản.', 300);
                wp_redirect(site_url('/dang-ky'));
                exit;
            } else {
                // Nếu wp_create_user thất bại, lấy lỗi và hiển thị
                set_transient('attc_registration_errors', $user_id->get_error_messages(), 30);
                wp_redirect(site_url('/dang-ky'));
                exit;
            }
        }
    }

    // Admin: Simulate top-up to test upgrade page UI
    if (!empty($_POST['attc_admin_simulate_topup'])) {
        check_admin_referer('attc_admin_simulate_topup_action', 'attc_admin_simulate_topup_nonce');

        $user_identifier = sanitize_text_field($_POST['attc_user_identifier_sim'] ?? '');
        $amount_sim = (int) ($_POST['attc_amount_sim'] ?? 0);
        $mode_sim = sanitize_text_field($_POST['attc_mode_sim'] ?? 'transient'); // transient|credit

        $user = false;
        if (is_numeric($user_identifier)) {
            $user = get_user_by('id', (int)$user_identifier);
        } elseif (is_email($user_identifier)) {
            $user = get_user_by('email', $user_identifier);
        } else {
            $user = get_user_by('login', $user_identifier);
        }

        if (!$user || $amount_sim <= 0) {
            $message = '<div class="notice notice-error is-dismissible"><p>Vui lòng nhập người dùng hợp lệ và số tiền > 0 để mô phỏng.</p></div>';
        } else {
            $uid = (int) $user->ID;
            if ($mode_sim === 'credit') {
                $res = attc_credit_wallet($uid, $amount_sim, [
                    'reason' => 'admin_simulate',
                    'note'   => 'Simulate top-up from admin wallet page',
                    'admin'  => get_current_user_id(),
                ]);
                if (is_wp_error($res)) {
                    $message = '<div class="notice notice-error is-dismissible"><p>Lỗi mô phỏng (credit): ' . esc_html($res->get_error_message()) . '</p></div>';
                } else {
                    $message = '<div class="notice notice-success is-dismissible"><p>Đã mô phỏng CỘNG ví ' . number_format($amount_sim) . 'đ cho user #' . $uid . '. Trang /nang-cap của user sẽ hiển thị thông báo ngay.</p></div>';
                }
            } else {
                // Only trigger UI notification without changing balance
                set_transient('attc_payment_success_' . $uid, ['amount' => $amount_sim, 'time' => time()], 60);
                $message = '<div class="notice notice-success is-dismissible"><p>Đã mô phỏng THÔNG BÁO nạp ' . number_format($amount_sim) . 'đ cho user #' . $uid . ' (không cộng ví). Trang /nang-cap sẽ hiển thị thông báo ngay.</p></div>';
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
    // Payment Section
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
    // Số gạch đầu dòng tóm tắt
    register_setting('attc_settings', 'attc_summary_bullets');
    add_settings_field(
        'attc_summary_bullets',
        'Số gạch đầu dòng tóm tắt',
        'attc_settings_field_html',
        'attc-settings',
        'attc_pricing_section',
        ['name' => 'attc_summary_bullets', 'type' => 'number', 'desc' => 'Mặc định 5. Số ý chính sẽ hiển thị trong phần tóm tắt.']
    );
    // Số lượt upload miễn phí mỗi ngày
    register_setting('attc_settings', 'attc_free_uploads_per_day');
    add_settings_field(
        'attc_free_uploads_per_day',
        'Số lượt miễn phí mỗi ngày',
        'attc_settings_field_html',
        'attc-settings',
        'attc_pricing_section',
        ['name' => 'attc_free_uploads_per_day', 'type' => 'number', 'desc' => 'Mặc định 1. Mỗi user được phép chuyển đổi miễn phí tối đa số lần này mỗi ngày.']
    );

    // History Section
    register_setting('attc_settings', 'attc_history_retention_days');
    add_settings_section('attc_history_section', 'Cài đặt Lịch sử', null, 'attc-settings');
    add_settings_field(
        'attc_history_retention_days',
        'Số ngày giữ lịch sử',
        'attc_settings_field_html',
        'attc-settings',
        'attc_history_section',
        ['name' => 'attc_history_retention_days', 'type' => 'number', 'desc' => 'Mặc định 7. Lịch sử và file ghi âm (nếu còn) sẽ tự xoá sau số ngày này kể từ lúc tạo file.']
    );

    // Upload & Processing Section
    register_setting('attc_settings', 'attc_max_upload_mb');
    register_setting('attc_settings', 'attc_ffmpeg_path');
    register_setting('attc_settings', 'attc_free_max_minutes');
    add_settings_section('attc_upload_section', 'Cài đặt Upload & Xử lý', null, 'attc-settings');
    add_settings_field(
        'attc_max_upload_mb',
        'Giới hạn dung lượng tối đa (MB)',
        'attc_settings_field_html',
        'attc-settings',
        'attc_upload_section',
        ['name' => 'attc_max_upload_mb', 'type' => 'number', 'desc' => 'Giới hạn kích thước file upload tối đa (MB). Mặc định 10.']
    );
    add_settings_field(
        'attc_ffmpeg_path',
        'Đường dẫn FFmpeg',
        'attc_settings_field_html',
        'attc-settings',
        'attc_upload_section',
        ['name' => 'attc_ffmpeg_path', 'type' => 'text', 'desc' => 'Đường dẫn thực thi FFmpeg trên server (ví dụ: ffmpeg, /usr/bin/ffmpeg). Dùng để cắt đoạn audio trước khi gửi OpenAI.']
    );
    add_settings_field(
        'attc_free_max_minutes',
        'Giới hạn thởi lượng miễn phí (phút)',
        'attc_settings_field_html',
        'attc-settings',
        'attc_upload_section',
        ['name' => 'attc_free_max_minutes', 'type' => 'number', 'desc' => 'Mặc định 2. Áp dụng cho tài khoản miễn phí.']
    );

    // Payment Gateways Section
    add_settings_section('attc_payment_section', 'Cài đặt Cổng Thanh Toán', null, 'attc-settings');
    // Bank settings
    register_setting('attc_settings', 'attc_bank_name');
    register_setting('attc_settings', 'attc_bank_account_name');
    register_setting('attc_settings', 'attc_bank_account_number');
    add_settings_field(
        'attc_bank_name',
        'Ngân hàng (VietQR)',
        'attc_settings_field_html',
        'attc-settings',
        'attc_payment_section',
        ['name' => 'attc_bank_name', 'type' => 'text', 'desc' => 'Ví dụ: ACB, VCB, TCB... (phù hợp với API VietQR).']
    );
    add_settings_field(
        'attc_bank_account_name',
        'Chủ tài khoản',
        'attc_settings_field_html',
        'attc-settings',
        'attc_payment_section',
        ['name' => 'attc_bank_account_name', 'type' => 'text', 'desc' => 'Tên chủ tài khoản ngân hàng.']
    );
    add_settings_field(
        'attc_bank_account_number',
        'Số tài khoản',
        'attc_settings_field_html',
        'attc-settings',
        'attc_payment_section',
        ['name' => 'attc_bank_account_number', 'type' => 'text', 'desc' => 'Số tài khoản ngân hàng.']
    );
    // Gỡ bỏ cấu hình MoMo khỏi settings để đơn giản hoá luồng thanh toán

    // Fake Top-up Testing Key
    register_setting('attc_settings', 'attc_fake_topup_key');
    add_settings_field(
        'attc_fake_topup_key',
        'Fake Top-up Key',
        'attc_settings_field_html',
        'attc-settings',
        'attc_payment_section',
        ['name' => 'attc_fake_topup_key', 'type' => 'text', 'desc' => 'Khóa bí mật để gọi endpoint giả lập nạp tiền: /wp-json/attc/v1/fake-topup?user_id=...&amount=...&mode=transient&key=YOUR_KEY']
    );
}
add_action('admin_init', 'attc_settings_init');

// Schedule daily cleanup for history retention
function attc_schedule_cleanup_event() {
    if (!wp_next_scheduled('attc_cleanup_history_daily')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'attc_cleanup_history_daily');
    }
}
add_action('wp', 'attc_schedule_cleanup_event');

add_action('attc_cleanup_history_daily', 'attc_cleanup_history_daily_cb');
function attc_cleanup_history_daily_cb() {
    $days = (int) get_option('attc_history_retention_days', 7);
    if ($days <= 0) return;
    $cutoff = current_time('timestamp') - ($days * DAY_IN_SECONDS);

    $number = 200; $offset = 0;
    while (true) {
        $uq = new \WP_User_Query([
            'fields' => 'ID',
            'number' => $number,
            'offset' => $offset,
            'meta_query' => [ [ 'key' => 'attc_wallet_history', 'compare' => 'EXISTS' ] ],
        ]);
        $user_ids = $uq->get_results();
        if (empty($user_ids)) break;
        foreach ($user_ids as $uid) {
            $history = get_user_meta($uid, 'attc_wallet_history', true);
            if (!is_array($history) || empty($history)) continue;
            $changed = false; $new = [];
            foreach ($history as $entry) {
                $meta = isset($entry['meta']) && is_array($entry['meta']) ? $entry['meta'] : [];
                $created = (int) ($meta['created_at'] ?? ($entry['timestamp'] ?? 0));
                if ($created > 0 && $created <= $cutoff) {
                    // Try remove uploaded file if path is known and exists
                    $paths = [];
                    if (!empty($meta['source_file_path'])) $paths[] = (string)$meta['source_file_path'];
                    if (!empty($meta['file_path'])) $paths[] = (string)$meta['file_path'];
                    foreach ($paths as $p) {
                        if ($p && file_exists($p)) { @unlink($p); }
                    }
                    $changed = true; // skip (delete) this entry
                    continue;
                }
                $new[] = $entry;
            }
            if ($changed) {
                if (!empty($new)) update_user_meta($uid, 'attc_wallet_history', $new); else delete_user_meta($uid, 'attc_wallet_history');
            }
        }
        $offset += $number;
    }
}

function attc_user_free_uploads_override_field($user) {
    if (!current_user_can('manage_options')) return;
    $val = (int) get_user_meta($user->ID, 'attc_free_uploads_per_day_override', true);
    echo '<h2>AudioAI</h2>';
    echo '<table class="form-table" role="presentation">';
    echo '<tr><th><label for="attc_free_uploads_per_day_override">Số lượt miễn phí/ngày (override)</label></th>';
    echo '<td><input name="attc_free_uploads_per_day_override" type="number" id="attc_free_uploads_per_day_override" value="' . esc_attr($val) . '" class="regular-text" min="0">';
    echo '<p class="description">Để 0 để dùng theo cấu hình chung.</p></td></tr>';
    echo '</table>';
}
add_action('show_user_profile', 'attc_user_free_uploads_override_field');
add_action('edit_user_profile', 'attc_user_free_uploads_override_field');

function attc_save_user_free_uploads_override($user_id) {
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['attc_free_uploads_per_day_override'])) return;
    $val = (int) $_POST['attc_free_uploads_per_day_override'];
    $val = max(0, $val);
    update_user_meta($user_id, 'attc_free_uploads_per_day_override', $val);
}
add_action('personal_options_update', 'attc_save_user_free_uploads_override');
add_action('edit_user_profile_update', 'attc_save_user_free_uploads_override');

// ===== Vietnamese normalization & Summary helpers =====
function attc_vi_normalize($text) {
    $text = (string)$text;
    // Chuẩn hoá khoảng trắng
    $text = preg_replace('/[\t\x0B\f\r]+/u', ' ', $text);
    $text = preg_replace('/\s+\n/u', "\n", $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);
    // Viết hoa đầu câu cơ bản
    $text = preg_replace_callback('/(^|[\.!\?]\s+)([a-zà-ỹ])/u', function($m){ return $m[1] . mb_strtoupper($m[2], 'UTF-8'); }, $text);
    // Bỏ khoảng trắng thừa trước dấu câu
    $text = preg_replace('/\s+([\.,;:!\?])/u', '$1', $text);
    return trim($text);
}

function attc_make_summary_bullets($text, $max_items = 5) {
    $text = trim((string)$text);
    if ($text === '') return [];
    // Tách câu đơn giản theo . ! ? và xuống dòng
    $parts = preg_split('/(?<=[\.!\?])\s+|\n+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $bullets = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        // Rút gọn tối đa 20 từ hoặc 100 ký tự
        $words = preg_split('/\s+/u', $p);
        if (count($words) > 20) {
            $words = array_slice($words, 0, 20);
            $p = implode(' ', $words) . '...';
        }
        if (mb_strlen($p, 'UTF-8') > 100) {
            $p = mb_substr($p, 0, 100, 'UTF-8') . '...';
        }
        $bullets[] = $p;
        if (count($bullets) >= max(1, (int)$max_items)) break;
    }
    return $bullets;
}

 
