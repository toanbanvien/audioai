<?php
/*
Plugin Name: Audio to Text Converter
Description: Chuyển đổi file ghi âm thành văn bản tiếng Việt sử dụng OpenAI API (Whisper). Giới hạn thời lượng 2 phút.
Version: 1.0.2
Author: Cascade AI
*/

if (!defined('ABSPATH')) {
    exit; // Chặn truy cập trực tiếp
}

// ===== Khởi tạo plugin =====
function attc_init() {
    add_shortcode('audio_to_text_form', 'attc_display_form');

    // Xử lý submit (frontend)
    add_action('admin_post_attc_process_audio', 'attc_process_audio');
    add_action('admin_post_nopriv_attc_process_audio', 'attc_process_audio');

    // Endpoint tải file Word (.doc)
    add_action('admin_post_attc_download_doc', 'attc_download_doc');
    add_action('admin_post_nopriv_attc_download_doc', 'attc_download_doc');
}
add_action('init', 'attc_init');

// ===== Hiển thị form upload =====
function attc_display_form() {
    ob_start();
    ?>
    <div class="audio-to-text-converter">
        <h2>Chuyển đổi giọng nói thành văn bản</h2>

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
        add_settings_error('attc_messages', 'attc_saved', 'Cài đặt đã được lưu.', 'updated');
    }

    $openai_api_key = get_option('attc_openai_api_key', '');
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
}
register_activation_hook(__FILE__, 'attc_activate');

function attc_deactivate() {
    // Không xoá trang hay thư mục để tránh mất dữ liệu / link
}
register_deactivation_hook(__FILE__, 'attc_deactivate');
