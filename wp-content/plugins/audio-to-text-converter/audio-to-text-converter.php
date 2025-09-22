<?php
/*
Plugin Name: Audio to Text Converter
Description: Chuyển đổi file ghi âm thành văn bản tiếng Việt sử dụng OpenAI API (Whisper). Giới hạn thời lượng 2 phút.
Version: 1.0.4
Author: Cascade AI
*/

if (!defined('ABSPATH')) {
    exit; // Chặn truy cập trực tiếp
}

// ===== Khởi tạo plugin =====
function attc_init() {
    add_shortcode('audio_to_text_form', 'attc_display_form');
    // Shortcodes front-end đăng nhập/đăng ký
    add_shortcode('attc_login_form', 'attc_login_form');
    add_shortcode('attc_register_form', 'attc_register_form');
    // Shortcode lịch sử chuyển đổi
    add_shortcode('attc_history', 'attc_history_shortcode');

    // Xử lý submit (frontend)
    add_action('admin_post_attc_process_audio', 'attc_process_audio');
    add_action('admin_post_nopriv_attc_process_audio', 'attc_process_audio');

    // Endpoint tải file Word (.doc)
    add_action('admin_post_attc_download_doc', 'attc_download_doc');
    add_action('admin_post_nopriv_attc_download_doc', 'attc_download_doc');

    // Handlers cho login/đăng ký front-end
    add_action('admin_post_nopriv_attc_do_login', 'attc_do_login');
    add_action('admin_post_nopriv_attc_do_register', 'attc_do_register');

    // Đảm bảo hai trang front-end luôn tồn tại (nếu bị xóa nhầm)
    add_action('init', 'attc_maybe_create_frontend_pages', 20);

    // Enqueue script reCAPTCHA nếu đã cấu hình key
    add_action('wp_enqueue_scripts', 'attc_enqueue_recaptcha_script');
}
add_action('init', 'attc_init');

// Enqueue script Google reCAPTCHA v2 nếu site key/secret đã cấu hình
function attc_enqueue_recaptcha_script() {
    $site_key   = get_option('attc_recaptcha_site_key', '');
    $secret_key = get_option('attc_recaptcha_secret_key', '');
    if (is_admin()) return;
    if (empty($site_key) || empty($secret_key)) return;
    // Chỉ enqueue ở frontend
    wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js', [], null, true);
}

// Tạo lại trang front-end đăng nhập/đăng ký nếu thiếu
function attc_maybe_create_frontend_pages() {
    $created = false;
    if (!get_page_by_path('dang-nhap')) {
        wp_insert_post([
            'post_title'   => 'Đăng nhập',
            'post_name'    => 'dang-nhap',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[attc_login_form]'
        ]);
        $created = true;
    }
    if (!get_page_by_path('dang-ky')) {
        wp_insert_post([
            'post_title'   => 'Đăng ký',
            'post_name'    => 'dang-ky',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[attc_register_form]'
        ]);
        $created = true;
    }
    if (!get_page_by_path('lich-su-chuyen-doi')) {
        wp_insert_post([
            'post_title'   => 'Lịch sử chuyển đổi',
            'post_name'    => 'lich-su-chuyen-doi',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[attc_history]'
        ]);
        $created = true;
    }
    // Nếu vừa tạo trang mới, flush rewrite để tránh 404 ngay lập tức
    if ($created) {
        flush_rewrite_rules(false);
    }
}

// ===== Hiển thị form upload =====
function attc_display_form() {
    ob_start();
    ?>
    <div class="audio-to-text-converter">
        <h2>Chuyển đổi giọng nói thành văn bản</h2>

        <?php if (is_user_logged_in()): ?>
            <nav class="attc-top-nav">
                <a class="attc-nav-link" href="<?php echo esc_url(home_url('/lich-su-chuyen-doi/')); ?>">Lịch sử chuyển đổi</a>
                <a class="attc-nav-link" href="<?php echo esc_url(wp_logout_url(home_url('/chuyen-doi-giong-noi/'))); ?>">Đăng xuất</a>
            </nav>
        <?php endif; ?>

        <?php
        // Nếu chưa đăng nhập: hiển thị thông báo + nút đăng nhập/đăng ký và dừng tại đây
        if (!is_user_logged_in()) {
            // Lấy permalink chính xác của trang front-end (an toàn hơn khi cấu trúc link khác)
            $redirect_to = home_url('/chuyen-doi-giong-noi/');
            $login_page = get_page_by_path('dang-nhap');
            $register_page = get_page_by_path('dang-ky');
            $login_url = $login_page ? get_permalink($login_page) : home_url('/dang-nhap/');
            $register_url = $register_page ? get_permalink($register_page) : home_url('/dang-ky/');
            // Gắn redirect_to để quay lại trang chuyển đổi sau khi đăng nhập/đăng ký
            $login_url = add_query_arg('redirect_to', rawurlencode($redirect_to), $login_url);
            $register_url = add_query_arg('redirect_to', rawurlencode($redirect_to), $register_url);
            echo '<div class="attc-alert attc-alert-error">Bạn cần đăng nhập để sử dụng chức năng tải lên và chuyển đổi.</div>';
            echo '<p class="attc-auth-actions">'
               . '<a class="attc-login-btn" href="' . esc_url($login_url) . '">Đăng nhập</a>'
               . '<a class="attc-register-btn" href="' . esc_url($register_url) . '">Đăng ký</a>'
               . '</p>';
            echo '</div>';
            ?>
            <style>
                .attc-auth-actions{display:flex; gap:10px; margin:12px 0 4px}
                .attc-login-btn{display:inline-block; background:#2271b1; color:#fff !important; padding:10px 14px; border-radius:4px; text-decoration:none; font-weight:600}
                .attc-login-btn:hover{background:#1b5c8f}
                .attc-register-btn{display:inline-block; background:#6c757d; color:#fff !important; padding:10px 14px; border-radius:4px; text-decoration:none; font-weight:600}
                .attc-register-btn:hover{background:#5a6268}
            </style>
            <?php
            return ob_get_clean();
        }
        ?>

        <?php if (isset($_GET['attc_error'])): ?>
            <div class="attc-alert attc-alert-error"><?php echo esc_html(urldecode(sanitize_text_field($_GET['attc_error']))); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['attc_result'])): ?>
            <div class="attc-alert attc-alert-success">
                <h3>Kết quả</h3>
                <p><?php echo nl2br(esc_html(urldecode(sanitize_text_field($_GET['attc_result'])))); ?></p>
                <?php
                // Hiển thị nút tải file Word nếu có token
                $token = isset($_GET['attc_token']) ? sanitize_text_field($_GET['attc_token']) : '';
                if ($token) {
                    $download_url = wp_nonce_url(
                        add_query_arg([
                            'action' => 'attc_download_doc',
                            'token'  => $token,
                        ], admin_url('admin-post.php')),
                        'attc_download_' . $token
                    );
                    echo '<p><a class="attc-download-btn" href="' . esc_url($download_url) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 20h14a1 1 0 0 0 1-1v-3h-2v2H6v-2H4v3a1 1 0 0 0 1 1zm7-3l5-5h-3V4h-4v8H7l5 5z"></path></svg><span>Tải kết quả (.doc)</span></a></p>';
                }
                ?>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="attc_process_audio">
            <?php wp_nonce_field('attc_upload_nonce', 'attc_nonce'); ?>

            <div class="form-group">
                <label for="audio_file">Chọn file ghi âm (MP3, WAV, M4A; tối đa 2 phút):</label>
                <input type="file" name="audio_file" id="audio_file" accept="audio/mp3,audio/mpeg,audio/wav,audio/x-wav,audio/m4a,audio/x-m4a,audio/mp4" required>
                <p class="description">Lưu ý: Nếu file vượt quá 2 phút, hệ thống sẽ từ chối.</p>
            </div>

            <div class="form-actions">
                <button type="submit" class="button button-primary">Chuyển đổi thành văn bản</button>
            </div>
        </form>
    </div>

    <style>
        .audio-to-text-converter {max-width: 820px; margin: 0 auto; padding: 20px;}
        .attc-alert {padding: 12px 16px; border-radius: 6px; margin: 16px 0;}
        .attc-alert-error {background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb;}
        .attc-alert-success {background: #edf7ed; color: #1b5e20; border: 1px solid #c3e6cb;}
        .form-group {margin-bottom: 16px;}
        .form-group label {display: block; font-weight: 600; margin-bottom: 6px;}
        .description {color: #666; font-size: 13px;}
        .form-actions {margin-top: 12px;}
        .button.button-primary {background: #2271b1; color: #fff; border: none; padding: 10px 16px; border-radius: 4px; cursor: pointer;}
        .button.button-primary:disabled {opacity: .7; cursor: not-allowed;}
        /* Nút tải .doc kiểu button download */
        .attc-download-btn {display:inline-flex; align-items:center; gap:8px; background:#28a745; border:1px solid #1e7e34; padding:10px 14px; border-radius:4px; color:#fff !important; text-decoration:none; font-weight:600;}
        .attc-download-btn:hover {background:#218838; border-color:#1c7430;}
        .attc-download-btn svg {width:16px; height:16px; fill: currentColor;}
        .attc-top-nav{display:flex; gap:12px; margin:8px 0 16px}
        .attc-nav-link{display:inline-block; padding:8px 12px; border:1px solid #cfd4d9; border-radius:6px; text-decoration:none; color:#2271b1}
        .attc-nav-link:hover{background:#f3f6f9}
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const fileInput = document.getElementById('audio_file');
        const form = fileInput.closest('form');
        const submitBtn = form.querySelector('button[type="submit"]');

        form.addEventListener('submit', function(e) {
            const file = fileInput.files[0];
            if (!file) {
                e.preventDefault();
                alert('Vui lòng chọn file ghi âm.');
                return;
            }

            const validTypes = [
                'audio/mp3','audio/mpeg','audio/wav','audio/x-wav','audio/m4a','audio/x-m4a','audio/mp4'
            ];
            if (!validTypes.includes(file.type)) {
                e.preventDefault();
                alert('Định dạng không hợp lệ. Vui lòng chọn MP3, WAV hoặc M4A.');
                return;
            }

            // Kích thước tối đa (tuỳ chọn): 20MB
            const MAX_SIZE = 20 * 1024 * 1024;
            if (file.size > MAX_SIZE) {
                e.preventDefault();
                alert('Kích thước file quá lớn (tối đa 20MB).');
                return;
            }

            // Kiểm tra thời lượng ở client trước khi submit
            e.preventDefault();
            submitBtn.disabled = true; const original = submitBtn.textContent; submitBtn.textContent = 'Đang kiểm tra...';

            const audio = new Audio();
            audio.preload = 'metadata';
            audio.onloadedmetadata = function() {
                URL.revokeObjectURL(audio.src);
                const duration = audio.duration; // giây
                if (duration > 120) {
                    alert('File ghi âm vượt quá giới hạn 2 phút. Vui lòng chọn file ngắn hơn.');
                    submitBtn.disabled = false; submitBtn.textContent = original;
                    return;
                }
                // Hợp lệ => submit thực sự
                submitBtn.textContent = 'Đang gửi...';
                form.submit();
            };
            audio.onerror = function() {
                // Không đọc được metadata => vẫn gửi để server kiểm tra
                form.submit();
            };
            audio.src = URL.createObjectURL(file);
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

// ===== Xử lý upload và gọi API =====
function attc_process_audio() {
    if (!isset($_POST['attc_nonce']) || !wp_verify_nonce($_POST['attc_nonce'], 'attc_upload_nonce')) {
        wp_redirect(add_query_arg('attc_error', urlencode('Lỗi bảo mật. Vui lòng thử lại.'), wp_get_referer()));
        exit;
    }

    // Chặn phía server nếu chưa đăng nhập
    if (!is_user_logged_in()) {
        wp_redirect(add_query_arg('attc_error', urlencode('Vui lòng đăng nhập để tải lên và chuyển đổi.'), wp_get_referer()));
        exit;
    }

    if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
        wp_redirect(add_query_arg('attc_error', urlencode('Có lỗi khi tải lên file. Vui lòng thử lại.'), wp_get_referer()));
        exit;
    }

    $file = $_FILES['audio_file'];

    // Kiểm tra định dạng
    $allowed_mimes = [
        'audio/mp3','audio/mpeg','audio/wav','audio/x-wav','audio/m4a','audio/x-m4a','audio/mp4'
    ];
    $filetype = wp_check_filetype($file['name']);
    if (!in_array($file['type'], $allowed_mimes) && !in_array($filetype['type'], $allowed_mimes)) {
        wp_redirect(add_query_arg('attc_error', urlencode('Định dạng file không được hỗ trợ. Chọn MP3, WAV hoặc M4A.'), wp_get_referer()));
        exit;
    }

    // Giới hạn kích thước: 20MB
    if ($file['size'] > 20 * 1024 * 1024) {
        wp_redirect(add_query_arg('attc_error', urlencode('Kích thước file quá lớn (tối đa 20MB).'), wp_get_referer()));
        exit;
    }

    // Lưu file tạm
    $upload_dir = wp_upload_dir();
    $attc_dir = trailingslashit($upload_dir['basedir']) . 'attc_uploads';
    if (!file_exists($attc_dir)) {
        wp_mkdir_p($attc_dir);
    }

    $safe_name = sanitize_file_name($file['name']);
    $target = trailingslashit($attc_dir) . uniqid('attc_') . '_' . $safe_name;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        wp_redirect(add_query_arg('attc_error', urlencode('Không thể lưu file. Vui lòng thử lại.'), wp_get_referer()));
        exit;
    }

    // Kiểm tra thời lượng ở server (bắt buộc)
    $duration = attc_get_audio_duration($target);
    if ($duration === false) {
        @unlink($target);
        wp_redirect(add_query_arg('attc_error', urlencode('Không thể đọc thời lượng file. Vui lòng thử file khác.'), wp_get_referer()));
        exit;
    }
    if ($duration > 120) {
        @unlink($target);
        wp_redirect(add_query_arg('attc_error', urlencode('File ghi âm vượt quá giới hạn 2 phút. Hệ thống không gọi API.'), wp_get_referer()));
        exit;
    }

    // Gọi OpenAI Whisper
    $transcription = attc_transcribe_audio($target);

    // Xoá file tạm
    @unlink($target);

    if (is_wp_error($transcription)) {
        wp_redirect(add_query_arg('attc_error', urlencode('Lỗi khi chuyển đổi: ' . $transcription->get_error_message()), wp_get_referer()));
        exit;
    }

    // Hậu xử lý: thêm dấu câu (đặc biệt dấu chấm và phẩy), sửa chính tả tiếng Việt
    $final_text = $transcription;
    if (function_exists('attc_postprocess_text')) {
        $polished = attc_postprocess_text($transcription);
        if (!is_wp_error($polished) && !empty($polished)) {
            $final_text = $polished;
        }
    }

    // Lưu lịch sử cho người dùng (nếu đã đăng nhập)
    if (is_user_logged_in()) {
        attc_add_history(get_current_user_id(), $final_text);
    }

    // Tạo token tạm để tải file .doc và redirect kèm token
    $token = wp_generate_uuid4();
    set_transient('attc_doc_' . $token, $final_text, 10 * MINUTE_IN_SECONDS);

    $redirect_url = add_query_arg([
        'attc_result' => urlencode($final_text),
        'attc_token'  => $token,
    ], wp_get_referer());

    wp_redirect($redirect_url);
    exit;
}

// ===== Lấy thời lượng file (giây) =====
function attc_get_audio_duration($file_path) {
    if (!file_exists($file_path)) return false;

    if (!function_exists('wp_read_audio_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }
    $meta = wp_read_audio_metadata($file_path);
    if (isset($meta['length'])) {
        return (int) ceil(floatval($meta['length']));
    }
    return false;
}

// ===== Gọi API OpenAI Whisper =====
function attc_transcribe_audio($file_path) {
    $api_key = get_option('attc_openai_api_key');
    if (empty($api_key)) {
        return new WP_Error('no_api_key', 'Chưa cấu hình OpenAI API Key. Vào Cài đặt > Audio to Text.');
    }

    if (!function_exists('mime_content_type')) {
        // Fallback đơn giản
        $mime = 'application/octet-stream';
    } else {
        $mime = mime_content_type($file_path);
    }

    $ch = curl_init();
    $post_fields = [
        'model' => 'whisper-1',
        'file' => new CURLFile($file_path, $mime, basename($file_path)),
        'response_format' => 'json',
        'language' => 'vi'
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.openai.com/v1/audio/transcriptions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_fields,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if (!empty($curl_err)) {
        return new WP_Error('api_error', 'Lỗi kết nối API: ' . $curl_err);
    }

    $data = json_decode($response, true);
    if ($http_code !== 200) {
        $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Lỗi không xác định từ API';
        return new WP_Error('api_error', 'Lỗi từ API: ' . $msg);
    }

    return isset($data['text']) ? $data['text'] : 'Không nhận được văn bản chuyển đổi.';
}

// ===== Hậu xử lý văn bản: thêm dấu câu, sửa chính tả =====
if (!function_exists('attc_postprocess_text')):
function attc_postprocess_text($text) {
    $api_key = get_option('attc_openai_api_key');
    if (empty($api_key)) {
        return new WP_Error('no_api_key', 'Chưa cấu hình OpenAI API Key.');
    }

    $payload = [
        'model' => 'gpt-4o-mini',
        'temperature' => 0.1,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Bạn là biên tập viên tiếng Việt. Nhiệm vụ: (1) thêm dấu câu chuẩn cho tiếng Việt, đặc biệt là dấu chấm để kết thúc câu và dấu phẩy để tách các mệnh đề/ý; (2) viết hoa đầu câu; (3) sửa lỗi chính tả và dính từ; (4) không thêm/bớt thông tin. Giữ nguyên nội dung, chỉ chuẩn hoá dấu câu rõ ràng, câu không quá dài. Trả về văn bản thuần (plain text).'
            ],
            [ 'role' => 'user', 'content' => $text ],
        ],
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => wp_json_encode($payload),
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if (!empty($curl_err)) {
        return new WP_Error('api_error', 'Lỗi kết nối API (postprocess): ' . $curl_err);
    }

    $data = json_decode($response, true);
    if ($http_code !== 200) {
        $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Lỗi không xác định từ API';
        return new WP_Error('api_error', 'Lỗi API (postprocess): ' . $msg);
    }

    $content = $data['choices'][0]['message']['content'] ?? '';
    return trim($content);
}
endif;

// ===== Lịch sử chuyển đổi =====
function attc_add_history($user_id, $text) {
    $key = 'attc_history';
    $history = get_user_meta($user_id, $key, true);
    if (!is_array($history)) $history = [];
    $history[] = [
        'time' => time(),
        'text' => $text,
    ];
    // Giới hạn 50 mục gần nhất
    if (count($history) > 50) {
        $history = array_slice($history, -50);
    }
    update_user_meta($user_id, $key, $history);
}

function attc_history_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="attc-alert attc-alert-error">Vui lòng đăng nhập để xem lịch sử.</div>';
    }
    $user_id = get_current_user_id();
    $history = get_user_meta($user_id, 'attc_history', true);
    if (!is_array($history) || empty($history)) {
        return '<div class="attc-alert attc-alert-success">Chưa có lịch sử chuyển đổi.</div>';
    }

    // Render danh sách, mới nhất trước
    $items = array_reverse($history);
    ob_start();
    ?>
    <div class="attc-history">
        <h2>Lịch sử chuyển đổi</h2>
        <div class="attc-top-nav" style="margin-top:0;">
            <a class="attc-nav-link" href="<?php echo esc_url(home_url('/chuyen-doi-giong-noi/')); ?>">Quay lại chuyển đổi</a>
            <a class="attc-nav-link" href="<?php echo esc_url(wp_logout_url(home_url('/chuyen-doi-giong-noi/'))); ?>">Đăng xuất</a>
        </div>
        <ul class="attc-history-list">
            <?php foreach ($items as $idx => $entry):
                $ts = isset($entry['time']) ? intval($entry['time']) : time();
                $text = isset($entry['text']) ? (string)$entry['text'] : '';
                // Tạo link tải .doc bằng transient tức thời
                $token = wp_generate_uuid4();
                set_transient('attc_doc_' . $token, $text, 10 * MINUTE_IN_SECONDS);
                $download_url = wp_nonce_url(
                    add_query_arg([
                        'action' => 'attc_download_doc',
                        'token'  => $token,
                    ], admin_url('admin-post.php')),
                    'attc_download_' . $token
                );
            ?>
            <li class="attc-history-item">
                <div class="attc-history-meta">
                    <span class="attc-history-time"><?php echo esc_html(date_i18n('d/m/Y H:i', $ts)); ?></span>
                    <a class="attc-download-btn" href="<?php echo esc_url($download_url); ?>">Tải .doc</a>
                </div>
                <details>
                    <summary>Xem nội dung</summary>
                    <div class="attc-history-content"><?php echo nl2br(esc_html($text)); ?></div>
                </details>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <style>
        .attc-history{max-width:860px;margin:0 auto;padding:20px}
        .attc-history-list{list-style:none; padding:0; margin:0}
        .attc-history-item{border:1px solid #e5e7eb; border-radius:8px; padding:12px 14px; margin:12px 0; background:#fff}
        .attc-history-meta{display:flex; gap:10px; align-items:center; justify-content:space-between}
        .attc-history-time{color:#555}
        .attc-history-content{white-space:pre-wrap; margin-top:8px}
    </style>
    <?php
    return ob_get_clean();
}

// ===== Tải kết quả về file Word (.doc) =====
if (!function_exists('attc_download_doc')):
function attc_download_doc() {
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
    if (empty($token) || !wp_verify_nonce($nonce, 'attc_download_' . $token)) {
        wp_die('Yêu cầu không hợp lệ.');
    }

    $text = get_transient('attc_doc_' . $token);
    if ($text === false) {
        wp_die('Phiên tải xuống đã hết hạn hoặc không tồn tại.');
    }

    $filename = 'chuyen-doi-' . date('Ymd-His') . '.doc';
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Bỏ heading, ép Word dùng Times New Roman 16pt bằng inline style và đơn vị pt
    echo "<html><head><meta http-equiv='Content-Type' content='text/html; charset=utf-8'><meta charset='UTF-8'><style>body, div, p, span{font-family:'Times New Roman', Times, serif; font-size:16pt; line-height:1.7;} .attc-content{white-space:pre-wrap;}</style></head>";
    echo "<body style=\"font-family:'Times New Roman', Times, serif; font-size:16pt; line-height:1.7;\">";
    echo '<div class="attc-content">' . esc_html($text) . '</div>';
    echo '</body></html>';
    exit;
}
endif;

// ===== Shortcode: Form đăng nhập front-end =====
function attc_login_form() {
    if (is_user_logged_in()) {
        return '<div class="attc-alert attc-alert-success">Bạn đã đăng nhập.</div>';
    }
    $redirect = isset($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : home_url('/chuyen-doi-giong-noi/');
    $site_key = get_option('attc_recaptcha_site_key', '');
    ob_start();
    ?>
    <div class="attc-auth-form attc-card">
        <h2 class="attc-card__title">Đăng nhập</h2>
         <?php if (isset($_GET['attc_error'])): ?>
             <div class="attc-alert attc-alert-error"><?php echo esc_html(urldecode(sanitize_text_field($_GET['attc_error']))); ?></div>
         <?php endif; ?>
         <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
             <input type="hidden" name="action" value="attc_do_login">
             <?php wp_nonce_field('attc_login_nonce', 'attc_login'); ?>
             <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect); ?>">
             <p class="attc-field"><label>Tên đăng nhập hoặc Email</label><input class="attc-input" type="text" name="log" required></p>
             <p class="attc-field"><label>Mật khẩu</label><input class="attc-input" type="password" name="pwd" required></p>
             <p class="attc-field attc-field--inline"><label><input type="checkbox" name="rememberme" value="1"> Ghi nhớ đăng nhập</label></p>
             <?php if (!empty($site_key)) : ?>
                 <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($site_key); ?>"></div>
             <?php endif; ?>
             <p><button type="submit" class="button button-primary attc-btn-wide">Đăng nhập</button></p>
         </form>
         <p class="attc-card__footer">Chưa có tài khoản? <a href="<?php echo esc_url(home_url('/dang-ky/')); ?>">Đăng ký</a></p>
     </div>
     <?php if (!empty($site_key)) : ?>
     <script src="https://www.google.com/recaptcha/api.js" async defer></script>
     <?php endif; ?>
     <style>
         .attc-card{max-width:520px;margin:24px auto;padding:24px 20px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
         .attc-card__title{margin:0 0 12px;font-size:20px}
         .attc-card__footer{margin-top:12px;color:#555}
         .attc-field{margin:12px 0}
         .attc-field label{display:block;margin-bottom:6px;font-weight:600}
         .attc-input{width:100%;padding:10px 12px;border:1px solid #cfd4d9;border-radius:6px;outline:none}
         .attc-input:focus{border-color:#2271b1;box-shadow:0 0 0 3px rgba(34,113,177,.15)}
         .attc-btn-wide{width:100%;padding:10px 14px}
         .g-recaptcha{margin:10px 0}
     </style>
     <?php
     return ob_get_clean();
 }

// ===== Shortcode: Form đăng ký front-end =====
function attc_register_form() {
    if (is_user_logged_in()) {
        return '<div class="attc-alert attc-alert-success">Bạn đã đăng nhập.</div>';
    }
    if (!get_option('users_can_register')) {
        return '<div class="attc-alert attc-alert-error">Trang web hiện đang tạm tắt chức năng đăng ký.</div>';
    }
    $redirect = isset($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : home_url('/chuyen-doi-giong-noi/');
    $site_key = get_option('attc_recaptcha_site_key', '');
    ob_start();
    ?>
    <div class="attc-auth-form attc-card">
        <h2 class="attc-card__title">Đăng ký</h2>
         <?php if (isset($_GET['attc_error'])): ?>
             <div class="attc-alert attc-alert-error"><?php echo esc_html(urldecode(sanitize_text_field($_GET['attc_error']))); ?></div>
         <?php endif; ?>
         <?php if (empty($site_key) && current_user_can('manage_options')): ?>
             <div class="attc-alert attc-alert-error">Chưa cấu hình reCAPTCHA Site Key/Secret trong Cài đặt > Audio to Text. reCAPTCHA sẽ không hiển thị cho đến khi cấu hình.</div>
         <?php endif; ?>
         <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
             <input type="hidden" name="action" value="attc_do_register">
             <?php wp_nonce_field('attc_register_nonce', 'attc_register'); ?>
             <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect); ?>">
             <p class="attc-field"><label>Tên đăng nhập</label><input class="attc-input" type="text" name="user_login" required></p>
             <p class="attc-field"><label>Email</label><input class="attc-input" type="email" name="user_email" required></p>
             <p class="attc-field"><label>Mật khẩu</label><input class="attc-input" type="password" name="user_pass" required></p>
             <p class="attc-field"><label>Xác nhận mật khẩu</label><input class="attc-input" type="password" name="user_pass2" required></p>
             <?php if (!empty($site_key)) : ?>
                 <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($site_key); ?>"></div>
             <?php endif; ?>
             <p><button type="submit" class="button button-primary attc-btn-wide">Đăng ký</button></p>
         </form>
         <p class="attc-card__footer">Đã có tài khoản? <a href="<?php echo esc_url(home_url('/dang-nhap/')); ?>">Đăng nhập</a></p>
     </div>
     <?php if (!empty($site_key)) : ?>
     <script src="https://www.google.com/recaptcha/api.js" async defer></script>
     <?php endif; ?>
     <style>
         .attc-card{max-width:520px;margin:24px auto;padding:24px 20px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
         .attc-card__title{margin:0 0 12px;font-size:20px}
         .attc-card__footer{margin-top:12px;color:#555}
         .attc-field{margin:12px 0}
         .attc-field label{display:block;margin-bottom:6px;font-weight:600}
         .attc-input{width:100%;padding:10px 12px;border:1px solid #cfd4d9;border-radius:6px;outline:none}
         .attc-input:focus{border-color:#2271b1;box-shadow:0 0 0 3px rgba(34,113,177,.15)}
         .attc-btn-wide{width:100%;padding:10px 14px}
         .g-recaptcha{margin:10px 0}
     </style>
     <?php
     return ob_get_clean();
 }

// ===== Handler: Đăng nhập =====
function attc_do_login() {
    if (!isset($_POST['attc_login']) || !wp_verify_nonce($_POST['attc_login'], 'attc_login_nonce')) {
        wp_redirect(add_query_arg('attc_error', urlencode('Yêu cầu không hợp lệ.'), wp_get_referer()));
        exit;
    }
    // reCAPTCHA (nếu được cấu hình)
    if (!attc_verify_recaptcha_if_configured()) {
        wp_redirect(add_query_arg('attc_error', urlencode('Vui lòng xác nhận CAPTCHA.'), wp_get_referer()));
        exit;
    }
    $creds = [
        'user_login'    => sanitize_text_field($_POST['log'] ?? ''),
        'user_password' => $_POST['pwd'] ?? '',
        'remember'      => !empty($_POST['rememberme']),
    ];
    $user = wp_signon($creds, is_ssl());
    $redirect = !empty($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : home_url('/chuyen-doi-giong-noi/');
    if (is_wp_error($user)) {
        wp_redirect(add_query_arg('attc_error', urlencode($user->get_error_message()), wp_get_referer()));
        exit;
    }
    wp_redirect($redirect);
    exit;
}

// ===== Handler: Đăng ký (tự đăng nhập sau khi tạo tài khoản) =====
function attc_do_register() {
    if (!isset($_POST['attc_register']) || !wp_verify_nonce($_POST['attc_register'], 'attc_register_nonce')) {
        wp_redirect(add_query_arg('attc_error', urlencode('Yêu cầu không hợp lệ.'), wp_get_referer()));
        exit;
    }
    // Kiểm tra xác nhận mật khẩu
    $pass  = $_POST['user_pass'] ?? '';
    $pass2 = $_POST['user_pass2'] ?? '';
    if ($pass !== $pass2) {
        wp_redirect(add_query_arg('attc_error', urlencode('Mật khẩu xác nhận không khớp.'), wp_get_referer()));
        exit;
    }
    // reCAPTCHA (nếu được cấu hình)
    if (!attc_verify_recaptcha_if_configured()) {
        wp_redirect(add_query_arg('attc_error', urlencode('Vui lòng xác nhận CAPTCHA.'), wp_get_referer()));
        exit;
    }
    if (!get_option('users_can_register')) {
        wp_redirect(add_query_arg('attc_error', urlencode('Trang web hiện không cho phép đăng ký.'), wp_get_referer()));
        exit;
    }
    $username = sanitize_user($_POST['user_login'] ?? '');
    $email    = sanitize_email($_POST['user_email'] ?? '');
    // $pass đã lấy ở trên
    if (empty($username) || empty($email) || empty($pass)) {
        wp_redirect(add_query_arg('attc_error', urlencode('Vui lòng điền đủ thông tin.'), wp_get_referer()));
        exit;
    }
    if (username_exists($username) || email_exists($email)) {
        wp_redirect(add_query_arg('attc_error', urlencode('Tên đăng nhập hoặc email đã tồn tại.'), wp_get_referer()));
        exit;
    }
    $user_id = wp_create_user($username, $pass, $email);
    if (is_wp_error($user_id)) {
        wp_redirect(add_query_arg('attc_error', urlencode($user_id->get_error_message()), wp_get_referer()));
        exit;
    }
    // Gán vai trò mặc định
    $user = new WP_User($user_id);
    $user->set_role(get_option('default_role', 'subscriber'));

    // Tự đăng nhập
    $signed = wp_signon([
        'user_login'    => $username,
        'user_password' => $pass,
        'remember'      => true,
    ], is_ssl());
    $redirect = !empty($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : home_url('/chuyen-doi-giong-noi/');
    if (is_wp_error($signed)) {
        wp_redirect(add_query_arg('attc_error', urlencode($signed->get_error_message()), wp_get_referer()));
        exit;
    }
    wp_redirect($redirect);
    exit;
}

// ===== reCAPTCHA helpers =====
function attc_verify_recaptcha_if_configured() {
    $site_key   = get_option('attc_recaptcha_site_key', '');
    $secret_key = get_option('attc_recaptcha_secret_key', '');
    if (empty($site_key) || empty($secret_key)) {
        return true; // chưa cấu hình => bỏ qua
    }
    $response = isset($_POST['g-recaptcha-response']) ? sanitize_text_field($_POST['g-recaptcha-response']) : '';
    if (empty($response)) return false;
    $verify = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
        'body' => [
            'secret'   => $secret_key,
            'response' => $response,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ],
        'timeout' => 15,
    ]);
    if (is_wp_error($verify)) return false;
    $data = json_decode(wp_remote_retrieve_body($verify), true);
    return !empty($data['success']);
}

// ===== Trang cài đặt trong Admin =====
function attc_add_admin_menu() {
    add_options_page(
        'Cài đặt Audio to Text Converter',
        'Audio to Text',
        'manage_options',
        'audio-to-text-converter',
        'attc_options_page'
    );
}
add_action('admin_menu', 'attc_add_admin_menu');

function attc_options_page() {
    if (isset($_POST['attc_save_settings'])) {
        if (!isset($_POST['attc_settings_nonce']) || !wp_verify_nonce($_POST['attc_settings_nonce'], 'attc_save_settings')) {
            wp_die('Lỗi bảo mật. Vui lòng thử lại.');
        }
        update_option('attc_openai_api_key', sanitize_text_field($_POST['attc_openai_api_key'] ?? ''));
        update_option('attc_recaptcha_site_key', sanitize_text_field($_POST['attc_recaptcha_site_key'] ?? ''));
        update_option('attc_recaptcha_secret_key', sanitize_text_field($_POST['attc_recaptcha_secret_key'] ?? ''));
        add_settings_error('attc_messages', 'attc_saved', 'Cài đặt đã được lưu.', 'updated');
    }

    $openai_api_key = get_option('attc_openai_api_key', '');
    $recaptcha_site_key = get_option('attc_recaptcha_site_key', '');
    $recaptcha_secret_key = get_option('attc_recaptcha_secret_key', '');
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <?php settings_errors('attc_messages'); ?>
        <form method="post">
            <?php wp_nonce_field('attc_save_settings', 'attc_settings_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="attc_openai_api_key">OpenAI API Key</label></th>
                    <td>
                        <input type="password" id="attc_openai_api_key" name="attc_openai_api_key" class="regular-text" value="<?php echo esc_attr($openai_api_key); ?>" required>
                        <p class="description">Lấy API key tại <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">OpenAI</a>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_recaptcha_site_key">reCAPTCHA Site Key</label></th>
                    <td>
                        <input type="text" id="attc_recaptcha_site_key" name="attc_recaptcha_site_key" class="regular-text" value="<?php echo esc_attr($recaptcha_site_key); ?>">
                        <p class="description">Tạo khóa tại <a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener">Google reCAPTCHA</a> (chọn v2 Checkbox).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_recaptcha_secret_key">reCAPTCHA Secret Key</label></th>
                    <td>
                        <input type="password" id="attc_recaptcha_secret_key" name="attc_recaptcha_secret_key" class="regular-text" value="<?php echo esc_attr($recaptcha_secret_key); ?>">
                    </td>
                </tr>
            </table>
            <?php submit_button('Lưu cài đặt', 'primary', 'attc_save_settings'); ?>
        </form>

        <h2>Hướng dẫn sử dụng</h2>
        <ol>
            <li>Vào Cài đặt > Audio to Text và nhập API Key.</li>
            <li>Sử dụng shortcode <code>[audio_to_text_form]</code> trên trang/bài viết, hoặc dùng trang đã tạo sẵn khi kích hoạt.</li>
            <li>Upload file ≤ 2 phút để chuyển thành văn bản tiếng Việt.</li>
        </ol>
    </div>
    <?php
}

// ===== Links nhanh trong danh sách plugin =====
function attc_add_action_links($links) {
    $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=audio-to-text-converter')) . '">Cài đặt</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'attc_add_action_links');

// ===== Kích hoạt / Hủy kích hoạt =====
function attc_activate() {
    // Tạo thư mục upload riêng
    $upload_dir = wp_upload_dir();
    $attc_dir = trailingslashit($upload_dir['basedir']) . 'attc_uploads';
    if (!file_exists($attc_dir)) {
        wp_mkdir_p($attc_dir);
    }

    // Viết .htaccess để bảo vệ (nếu có quyền)
    $htaccess = trailingslashit($attc_dir) . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\nDeny from all\n");
    }

    // Tạo trang chứa shortcode nếu chưa có
    $page_title = 'Chuyển đổi giọng nói';
    $page_slug  = 'chuyen-doi-giong-noi';

    $existing = get_page_by_path($page_slug);
    if (!$existing) {
        $page_id = wp_insert_post([
            'post_title'   => $page_title,
            'post_name'    => $page_slug,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[audio_to_text_form]'
        ]);
    }

    // Tạo trang đăng nhập/đăng ký front-end nếu chưa có
    if (!get_page_by_path('dang-nhap')) {
        wp_insert_post([
            'post_title'   => 'Đăng nhập',
            'post_name'    => 'dang-nhap',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[attc_login_form]'
        ]);
    }
    if (!get_page_by_path('dang-ky')) {
        wp_insert_post([
            'post_title'   => 'Đăng ký',
            'post_name'    => 'dang-ky',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[attc_register_form]'
        ]);
    }
    if (!get_page_by_path('lich-su-chuyen-doi')) {
        wp_insert_post([
            'post_title'   => 'Lịch sử chuyển đổi',
            'post_name'    => 'lich-su-chuyen-doi',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[attc_history]'
        ]);
    }
}
register_activation_hook(__FILE__, 'attc_activate');

function attc_deactivate() {
    // Không xoá trang hay thư mục để tránh mất dữ liệu / link
}
register_deactivation_hook(__FILE__, 'attc_deactivate');
