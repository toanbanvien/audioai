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

// Ghép các dòng rời rạc để tạo câu hoàn chỉnh (tránh tóm tắt lấy giữa câu)
function attc_vi_fix_sentence_boundaries($text) {
    if (!is_string($text) || trim($text) === '') return $text;
    $lines = preg_split('/\r\n|\r|\n/u', $text);
    if (!is_array($lines) || empty($lines)) return $text;
    $joiners = '/^(và|với|cho|nhưng|rồi|thì|là|để|còn|mà|nên|hay|hoặc|tại vì|bởi vì|vì vậy|cũng|còn nữa|rằng)\b/iu';
    $out = [];
    foreach ($lines as $raw) {
        $s = trim($raw);
        if ($s === '') { $out[] = ''; continue; }
        $prevIdx = count($out) - 1;
        $prev = $prevIdx >= 0 ? $out[$prevIdx] : '';
        $prevTrim = trim($prev);
        $prevEndOK = $prevTrim !== '' && preg_match('/[\.\!\?]$/u', $prevTrim);
        $shouldAttach = (!$prevEndOK) || preg_match($joiners, mb_strtolower($s, 'UTF-8'));
        if ($prevIdx >= 0 && $shouldAttach) {
            // Nối vào câu trước
            $glue = ($prevTrim === '') ? '' : ' ';
            $out[$prevIdx] = rtrim($prev) . $glue . ltrim($s);
        } else {
            $out[] = $s;
        }
    }
    // Đảm bảo mỗi câu kết thúc dấu chấm nếu thiếu
    foreach ($out as $i => $ln) {
        $t = rtrim($ln);
        if ($t !== '' && !preg_match('/[\.\!\?]$/u', $t)) {
            $out[$i] = $t . '.';
        }
    }
    return implode("\n", $out);
}


// Tóm tắt trích xuất miễn phí: trả về 5-10 ý chính dạng gạch đầu dòng, tránh trùng lặp ý
function attc_summarize_points($text, $max_points = 7) {
    if (!is_string($text) || trim($text) === '') return '';
    $max_points = max(1, min(20, (int)$max_points));
    $sentences = attc_split_sentences($text);
    if (empty($sentences)) return '';

    // Danh sách stopwords tiếng Việt cơ bản (có thể mở rộng)
    $stop = ['là','và','của','những','các','đang','sẽ','như','rằng','vì','thì','này','kia','được','trong','khi','với','cho','đến','từ','hay','hoặc','một','nhỉ','ạ','à','ừ','ờ','nhé','nhá','vâng','dạ','ờm','ah','à','ừm'];
    $stop_map = array_fill_keys($stop, true);

    // Tính điểm từ khóa theo tần suất
    $freq = [];
    foreach ($sentences as $s) {
        $lc = mb_strtolower($s, 'UTF-8');
        $lc = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lc);
        foreach (preg_split('/\s+/u', trim($lc)) as $w) {
            if ($w === '' || isset($stop_map[$w])) continue;
            $freq[$w] = ($freq[$w] ?? 0) + 1;
        }
    }
    if (empty($freq)) return '';

    // Chuẩn hóa điểm từ về [0,1]
    $maxf = max($freq);
    foreach ($freq as $k => $v) { $freq[$k] = $v / $maxf; }

    // Chấm điểm câu
    $scored = [];
    foreach ($sentences as $idx => $s) {
        $lc = mb_strtolower($s, 'UTF-8');
        $lc = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lc);
        $words = array_filter(preg_split('/\s+/u', trim($lc)));
        if (empty($words)) continue;
        $score = 0.0;
        foreach ($words as $w) {
            if (isset($stop_map[$w]) || !isset($freq[$w])) continue;
            $score += $freq[$w];
        }
        // Heuristic: phạt câu quá ngắn hoặc quá dài
        $word_count = count(preg_split('/\s+/u', trim($s)));
        // Phạt nặng câu quá ngắn (dưới 5 từ)
        if ($word_count < 5) {
            $score *= 0.05; 
        } else if ($word_count > 30) { // Phạt câu quá dài (hơn 30 từ)
            $score *= 0.5;
        } else if ($word_count > 20) { // Phạt nhẹ câu hơi dài (20-30 từ)
            $score *= 0.8;
        } else { // Thưởng cho câu có độ dài lý tưởng (5-20 từ)
            $score *= 1.2;
        }
        // Ưu tiên câu bắt đầu rõ ràng (chữ hoa, chủ ngữ + vị ngữ), giảm điểm câu mở đầu bằng liên từ/tiểu từ
        $starts = mb_strtolower(trim($s), 'UTF-8');
        if (preg_match('/^(và|với|cho|nhưng|rồi|thì|là|để|còn|mà|nên|hay|hoặc|tại vì|bởi vì|vì vậy)\b/u', $starts)) {
            $score *= 0.6;
        }
        $firstChar = mb_substr(trim($s), 0, 1, 'UTF-8');
        if ($firstChar !== '' && preg_match('/[\p{Lu}Đ]/u', $firstChar)) {
            $score *= 1.1;
        }
        // Nhẹ ưu tiên câu xuất hiện sớm (gần đầu đoạn)
        $score *= (1.0 + max(0, 0.15 - min(0.15, $idx * 0.01)));
        $scored[] = ['i' => $idx, 's' => $s, 'score' => $score, 'set' => array_unique($words)];
    }
    if (empty($scored)) return '';

    // Sắp xếp theo điểm giảm dần
    usort($scored, function($a, $b) { return $b['score'] <=> $a['score']; });

    // Chọn top N, loại trùng ý bằng Jaccard
    $chosen = [];
    $chosen_sets = [];
    $threshold = 0.6;
    foreach ($scored as $cand) {
        $dup = false;
        foreach ($chosen_sets as $set) {
            $inter = count(array_intersect($set, $cand['set']));
            $union = count(array_unique(array_merge($set, $cand['set'])));
            if ($union > 0 && ($inter / $union) >= $threshold) { $dup = true; break; }
        }
        if ($dup) continue;
        $chosen[] = $cand;
        $chosen_sets[] = $cand['set'];
        if (count($chosen) >= $max_points) break;
    }
    if (empty($chosen)) return '';
    // Giữ lại thứ tự xuất hiện theo transcript để đọc tự nhiên
    usort($chosen, function($a, $b){ return $a['i'] <=> $b['i']; });
    $lines = array_map(function($x){ return '- ' . trim($x['s']); }, $chosen);
    return implode("\n", $lines);
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
    if (strpos($api_key, 'sk-proj-') === 0) {
        $attc_proj = get_option('attc_openai_project_id');
        if (!empty($attc_proj)) {
            $headers['OpenAI-Project'] = $attc_proj;
        }
    }

    // Prompt mạnh hơn: đầu vào là N dòng, yêu cầu giữ NGUYÊN số dòng, sửa từng dòng độc lập, không gộp/tách
    $base_messages = [
        [ 'role' => 'system', 'content' => 'Bạn là biên tập viên tiếng Việt. Hãy CHỈ chỉnh chính tả, dấu câu và từ vựng cho đúng nghĩa, KHÔNG thêm/bớt ý, KHÔNG biên soạn lại. Đầu vào có nhiều dòng, mỗi dòng là một câu/đoạn. Hãy giữ NGUYÊN số dòng và thứ tự dòng, sửa từng dòng độc lập, KHÔNG gộp hoặc tách dòng. Ưu tiên thuật ngữ tôn giáo đúng: "Chúa", "Đức Chúa Trời"; tránh nhầm lẫn với "chú" (người thân) trừ khi ngữ cảnh bắt buộc.' ],
        [ 'role' => 'user', 'content' => "Yêu cầu:\n- Chỉnh chính tả tiếng Việt, thêm dấu, giữ nguyên ý nghĩa.\n- Giữ NGUYÊN số dòng, mỗi dòng tương ứng 1 dòng đầu vào.\n- Chỉ trả lời bằng văn bản đã chỉnh, KHÔNG giải thích.\n\n---\n\n" . $text ],
    ];

    // Thử model tốt hơn trước, fallback sang model cũ nếu lỗi
    $models = ['gpt-4o-mini', 'gpt-3.5-turbo'];
    foreach ($models as $model) {
        $payload = [
            'model' => $model,
            'temperature' => 0,
            'messages' => $base_messages,
            'max_tokens' => 4096,
        ];
        $tries = 0; $max_tries = 2; $delay = 0.8;
        while ($tries < $max_tries) {
            $resp = wp_remote_post($endpoint, [
                'timeout' => 90,
                'headers' => $headers,
                'body'    => wp_json_encode($payload),
            ]);
            if (!is_wp_error($resp)) {
                $code = wp_remote_retrieve_response_code($resp);
                $body = json_decode(wp_remote_retrieve_body($resp), true);
                if ($code === 200 && !empty($body['choices'][0]['message']['content'])) {
                    $out = (string) $body['choices'][0]['message']['content'];
                    if (trim($out) !== '') return $out;
                }
            }
            $tries++;
            usleep((int)($delay * 1000000));
            $delay *= 1.5;
        }
        // thử model tiếp theo nếu thất bại
    }
    return $text;
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

        // Chuẩn hoá xuống dòng, tạo nhiều đoạn văn; font Times New Roman 16pt. Bỏ hoàn toàn in đậm.
        $normalized = preg_replace("/\r\n|\r/", "\n", $transcript_content);
        $lines = explode("\n", $normalized);
        $paragraphs_xml = '';
        $run_props = '<w:rPr>'
            . '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Times New Roman" />'
            . '<w:sz w:val="32"/><w:szCs w:val="32"/>'
            . '</w:rPr>';

        foreach ($lines as $ln) {
            $text = htmlspecialchars(trim($ln), ENT_QUOTES | ENT_XML1, 'UTF-8');
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

// Endpoint để tải file PDF (transcript)
function attc_handle_download_transcript_pdf() {
    if (isset($_GET['action'], $_GET['nonce'], $_GET['timestamp']) && $_GET['action'] === 'attc_download_transcript_pdf') {
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

        // ====== Render PDF với GD + TrueType (UTF-8) ======
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            wp_die('Máy chủ thiếu GD hoặc TTF để tạo PDF UTF-8.');
        }

        // Chọn font TTF có hỗ trợ tiếng Việt
        $font_path = (string) get_option('attc_pdf_ttf_path', '');
        $candidates = [];
        if (!empty($font_path)) { $candidates[] = $font_path; }
        // Windows phổ biến
        $candidates[] = 'C:\\Windows\\Fonts\\arial.ttf';
        $candidates[] = 'C:\\Windows\\Fonts\\times.ttf';
        $candidates[] = 'C:\\Windows\\Fonts\\timesbd.ttf';
        $candidates[] = 'C:\\Windows\\Fonts\\tahoma.ttf';
        // Linux phổ biến
        $candidates[] = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $candidates[] = '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf';

        $chosen_font = '';
        foreach ($candidates as $p) { if ($p && file_exists($p)) { $chosen_font = $p; break; } }
        if (empty($chosen_font)) {
            wp_die('Không tìm thấy font TTF để tạo PDF UTF-8. Vui lòng cấu hình đường dẫn font tại attc_pdf_ttf_path (ví dụ C:\\Windows\\Fonts\\arial.ttf).');
        }

        // Chuẩn hoá và thêm dấu câu cơ bản
        $normalized = preg_replace("/\r\n|\r/", "\n", $transcript_content);
        $lines = explode("\n", $normalized);
        $processed = [];
        foreach ($lines as $ln) {
            $t = trim($ln);
            if ($t === '') { $processed[] = ''; continue; }
            if (!preg_match('/[\.!?;:]$/u', $t)) { $t .= '.'; }
            $processed[] = $t;
        }
        $text = implode("\n", $processed);

        // Thông số trang (px)
        $page_w = 1240; // ~A4 @150dpi
        $page_h = 1754;
        $margin = 80;
        $font_size = 14; // giảm thêm 2px (~11pt)
        $line_spacing = 32; // điều chỉnh khoảng cách dòng tương ứng
        $max_w = $page_w - 2 * $margin;
        $max_h = $page_h - 2 * $margin;

        // Tách chữ thành dòng theo word-wrap
        $paragraphs = explode("\n", $text);
        $all_lines = [];
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') { $all_lines[] = ''; continue; }
            $words = preg_split('/\s+/u', $para);
            $cur = '';
            foreach ($words as $w) {
                $test = $cur === '' ? $w : ($cur . ' ' . $w);
                $bbox = imagettfbbox($font_size, 0, $chosen_font, $test);
                $width = abs($bbox[2] - $bbox[0]);
                if ($width > $max_w && $cur !== '') {
                    $all_lines[] = $cur;
                    $cur = $w;
                } else {
                    $cur = $test;
                }
            }
            if ($cur !== '') { $all_lines[] = $cur; }
        }

        // Render từng trang ra JPEG và gom vào PDF
        $pages = [];
        $y = $margin;
        $im = imagecreatetruecolor($page_w, $page_h);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 15, 23, 42);
        imagefilledrectangle($im, 0, 0, $page_w, $page_h, $white);

        foreach ($all_lines as $ln) {
            if ($ln === '') {
                $y += $line_spacing; // dòng trống
            } else {
                $bbox = imagettfbbox($font_size, 0, $chosen_font, $ln);
                $line_h = abs($bbox[1] - $bbox[7]);
                $line_h = max($line_h, $line_spacing);
                if ($y + $line_h > $page_h - $margin) {
                    // xuất trang cũ
                    ob_start(); imagejpeg($im, null, 85); $jpg = ob_get_clean();
                    imagedestroy($im);
                    $pages[] = ['w' => $page_w, 'h' => $page_h, 'jpg' => $jpg];
                    // trang mới
                    $im = imagecreatetruecolor($page_w, $page_h);
                    $white = imagecolorallocate($im, 255, 255, 255);
                    $black = imagecolorallocate($im, 15, 23, 42);
                    imagefilledrectangle($im, 0, 0, $page_w, $page_h, $white);
                    $y = $margin;
                }
                imagettftext($im, $font_size, 0, $margin, $y, $black, $chosen_font, $ln);
                $y += $line_spacing;
            }
        }
        // xuất trang cuối
        ob_start(); imagejpeg($im, null, 85); $jpg = ob_get_clean();
        imagedestroy($im);
        $pages[] = ['w' => $page_w, 'h' => $page_h, 'jpg' => $jpg];

        // Xây PDF từ các ảnh JPEG
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $objs = [];

        // Tạo objects: mỗi trang gồm 1 XObject image + 1 content stream + 1 page; cuối cùng pages + catalog
        $kids = [];
        $obj_index = 1;
        foreach ($pages as $pi => $pg) {
            $img_obj = $obj_index++; // image object id
            $cnt_obj = $obj_index++; // content object id
            $pag_obj = $obj_index++; // page object id

            // image object
            $img = $pg['jpg'];
            $img_len = strlen($img);
            $objs[$img_obj] = "{$img_obj} 0 obj\n<< /Type /XObject /Subtype /Image /Width {$pg['w']} /Height {$pg['h']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$img_len} >>\nstream\n" . $img . "\nendstream\nendobj\n";

            // content stream (vẽ ảnh full trang)
            $content = "q {$pg['w']} 0 0 {$pg['h']} 0 0 cm /Im{$img_obj} Do Q";
            $objs[$cnt_obj] = "{$cnt_obj} 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream\nendobj\n";

            // page object
            $objs[$pag_obj] = "{$pag_obj} 0 obj\n<< /Type /Page /Parent %PAGES% /MediaBox [0 0 {$pg['w']} {$pg['h']}] /Resources << /XObject << /Im{$img_obj} {$img_obj} 0 R >> >> /Contents {$cnt_obj} 0 R >>\nendobj\n";
            $kids[] = "{$pag_obj} 0 R";
        }

        // pages object id và catalog id
        $pages_id = $obj_index++;
        $cat_id = $obj_index++;

        $kids_str = '[ ' . implode(' ', $kids) . ' ]';
        $objs[$pages_id] = "{$pages_id} 0 obj\n<< /Type /Pages /Kids {$kids_str} /Count " . count($kids) . " >>\nendobj\n";
        $objs[$cat_id] = "{$cat_id} 0 obj\n<< /Type /Catalog /Pages {$pages_id} 0 R >>\nendobj\n";

        // kết hợp và tính offset
        foreach ($objs as $id => $obj_str) {
            // thay %PAGES% placeholder sau khi biết pages_id
            $obj_str = str_replace('%PAGES%', $pages_id . ' 0 R', $obj_str);
            $offsets[$id] = strlen($pdf);
            $pdf .= $obj_str;
        }

        // xref
        $xref_pos = strlen($pdf);
        $pdf .= "xref\n0 " . ($cat_id + 1) . "\n0000000000 65535 f \n";
        for ($i=1; $i <= $cat_id; $i++) {
            $off = isset($offsets[$i]) ? $offsets[$i] : 0;
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size " . ($cat_id + 1) . " /Root {$cat_id} 0 R >>\nstartxref\n" . $xref_pos . "\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="transcript-' . $timestamp . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}
add_action('init', 'attc_handle_download_transcript_pdf');

// Endpoint để tải file docx (tóm tắt)
function attc_handle_download_summary() {
    if (isset($_GET['action'], $_GET['nonce'], $_GET['timestamp']) && $_GET['action'] === 'attc_download_summary') {
        if (!is_user_logged_in() || !wp_verify_nonce($_GET['nonce'], 'attc_download_' . $_GET['timestamp'])) {
            wp_die('Invalid request');
        }

        $user_id = get_current_user_id();
        $timestamp = (int)$_GET['timestamp'];
        $history = get_user_meta($user_id, 'attc_wallet_history', true);

        $summary_content = '';
        if (!empty($history)) {
            foreach ($history as $item) {
                if (($item['timestamp'] ?? 0) === $timestamp) {
                    $summary_content = $item['meta']['summary'] ?? '';
                    break;
                }
            }
        }

        if (empty($summary_content)) {
            wp_die('Không tìm thấy nội dung tóm tắt.');
        }

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

        // Chuẩn hoá xuống dòng, tạo nhiều đoạn văn; font Times New Roman 16pt. Bỏ hoàn toàn in đậm.
        $normalized = preg_replace("/\r\n|\r/", "\n", $summary_content);
        $lines = explode("\n", $normalized);
        $paragraphs_xml = '';
        $run_props = '<w:rPr>'
            . '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Times New Roman" />'
            . '<w:sz w:val="32"/><w:szCs w:val="32"/>'
            . '</w:rPr>';

        foreach ($lines as $ln) {
            $text = htmlspecialchars(trim($ln), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if ($text === '') {
                $paragraphs_xml .= "<w:p/>"; // dòng trống
            } else {
                $paragraphs_xml .= '<w:p><w:r>' . $run_props . '<w:t xml:space="preserve">- ' . ltrim($text, '-') . '</w:t></w:r></w:p>';
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
        header('Content-Disposition: attachment; filename="summary-' . $timestamp . '.docx"');
        header('Content-Length: ' . filesize($tmp_file));
        readfile($tmp_file);
        @unlink($tmp_file);
        exit;
    }
}
add_action('init', 'attc_handle_download_summary');

// CSS tùy chỉnh cho sticky footer (Flexbox - Target cho theme cụ thể)
function attc_sticky_footer_styles() {
    echo <<<CSS
<style>
    html, body { height: 100% !important; }
    /* Fallback: body as flex container */
    body {
        display: flex !important;
        flex-direction: column !important;
        min-height: 100vh !important;
        margin: 0 !important;
    }
    /* Primary: make site root act as flex container */
    #page, .site {
        display: flex !important;
        flex-direction: column !important;
        min-height: 100vh !important;
        width: 100% !important;
    }
    /* Content area grows */
    .site-wrapper { flex: 1 0 auto !important; min-height: 0 !important; }
    /* Footer sticks to bottom */
    #colophon { margin-top: auto !important; flex-shrink: 0 !important; position: static !important; }
</style>
CSS;
}
// Inject late to override theme styles
add_action('wp_footer', 'attc_sticky_footer_styles', 999);

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

    // SSE: stream thông báo nạp thành công theo thầm gian thực
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
    // hết thầm gian, kết thúc stream
    echo ": timeout\n\n";
    flush();
}


function attc_get_price_per_minute() {
    $price = (int) get_option('attc_price_per_minute', 500);
    return max(0, $price);
}

// Cấu hình tóm tắt: bật/tắt và số ý
function attc_is_summary_enabled() {
    return get_option('attc_summary_enabled', '1') === '1';
}
function attc_get_summary_points() {
    $n = (int) get_option('attc_summary_points', 7);
    return max(3, min(20, $n));
}

function attc_detect_lang_vi_en($text) {
    if (!is_string($text) || $text === '') return 'en';
    // 1) Diacritics check (strong VI signal)
    if (preg_match('/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu', $text)) {
        return 'vi';
    }
    $lc = mb_strtolower($text, 'UTF-8');
    // 2) Common Vietnamese words without diacritics
    $vi_words = ['la','khong','chuong','chung','toi','ban','cua','ngai','hay','nhung','thi','da','dang','se','anh','chi','em','voi','nhu','ngay','nguoi','dung','vi','qua','xin','cam','on'];
    $vi_hits = 0;
    foreach ($vi_words as $w) {
        if (preg_match('/\b'.preg_quote($w, '/').'\b/u', $lc)) { $vi_hits++; }
    }
    // 3) Common English words
    $en_words = ['the','and','you','your','to','for','of','is','are','in','on','with','but','this','that','have','has','be','not'];
    $en_hits = 0;
    foreach ($en_words as $w) {
        if (preg_match('/\b'.preg_quote($w, '/').'\b/u', $lc)) { $en_hits++; }
    }
    if ($vi_hits > $en_hits) return 'vi';
    if ($en_hits > $vi_hits) return 'en';
    // 4) Fallback: if majority ASCII letters and spaces, lean EN; else VI
    $ascii_only = preg_match('/^[\x00-\x7F]+$/', $text) === 1;
    return $ascii_only ? 'en' : 'vi';
}
function attc_split_sentences($text) {
    if (!is_string($text) || $text === '') return [];
    // Tách theo các dấu câu phổ biến. Giữ lại dấu câu bằng lookbehind.
    $parts = preg_split('/(?<=[\.\!\?…])\s+/u', trim($text));
    if (!is_array($parts)) return [$text];
    // Gom các mảnh nhỏ bất thường
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $out[] = $p;
    }
    return $out;
}

// Chuẩn hoá một số từ/cụm dễ nghe nhầm trong tiếng Việt (áp dụng trước/sau AI)
function attc_vi_normalize_lexicon($text) {
    if (!is_string($text) || $text === '') return $text;
    $orig = $text;
    // Các thay thế mục tiêu, an toàn theo ngữ cảnh
    $replacements = [
        // Ưu tiên "Chúa" khi đi sau "của" hoặc không dấu "cua"
        '/\bcủa\s+chú\b/ui' => 'của Chúa',
        '/\bcua\s+chu\b/ui' => 'của Chúa',
        // Cụm thông dụng
        '/\bdơ\s+tay\b/ui'  => 'giơ tay',
        // Sửa chính tả phổ biến
        '/\bruồn\s*bỏ\b/ui' => 'ruồng bỏ',
        '/\bruon\s*bo\b/ui' => 'ruồng bỏ',
        '/\bruồn\b/ui'       => 'ruồng',
        '/\bruon\b/ui'       => 'ruồng',
    ];
    foreach ($replacements as $pattern => $rep) {
        $text = preg_replace($pattern, $rep, $text);
    }
    return $text === null ? $orig : $text;
}

function attc_cleanup_transcript($text) {
    if (!is_string($text) || $text === '') return $text;

    // 1. Lọc bỏ các cụm từ vô nghĩa, sai lệch do API
    $meaningless_phrases = [
        'Sánh khí đồng,',
        'sánh khí đồng,',
        '1 câu 1 nói gì quý vị',
        'Cảm ơn quý vị đã lắng nghe.', // Thường là nhiễu ở cuối
        'Thank you for listening.',
    ];
    $text = str_ireplace($meaningless_phrases, '', $text);

    // 2. Xử lý câu lặp lại liên tiếp
    $sentences = attc_split_sentences($text);
    if (empty($sentences)) return trim($text);

    $clean = [];
    $prev = '';
    $repeatCount = 0;
    foreach ($sentences as $s) {
        $trimmed_s = trim($s);
        if ($trimmed_s === '') continue;

        if (mb_strtolower($trimmed_s) === mb_strtolower($prev)) {
            $repeatCount++;
            if ($repeatCount >= 2) { // Giữ lại tối đa 2 lần lặp
                continue;
            }
        } else {
            $repeatCount = 0;
        }
        $clean[] = $trimmed_s;
        $prev = $trimmed_s;
    }

    $out = implode("\n", $clean); // Nối bằng xuống dòng để giữ cấu trúc

    // 3. Lọc các chuỗi lặp lại vô nghĩa như 'ha ha ha', 'la la la'
    $out = preg_replace('/(\b(ha\s*){3,}|(la\s*){3,})/iu', '', $out);

    return trim($out);
}

// ===== Upgrade Page Shortcode =====
function attc_upgrade_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="attc-auth-prompt"><p>Vui lòng <a href="' . esc_url(site_url('/dang-nhap')) . '">đăng nhập</a> hoặc <a href="' . esc_url(site_url('/dang-ky')) . '">đăng ký</a> để sử dụng chức năng này.</p></div>';
    }

    // Tải script và truyền dữ liệu cần thiết cho trang nâng cấp
    wp_enqueue_script(
        'attc-payment-checker',
        plugin_dir_url(__FILE__) . 'assets/js/payment-checker.js',
        [], // không có dependency
        '1.1.1', // Tăng phiên bản để tránh cache
        true // Tải ở footer
    );

    wp_localize_script(
        'attc-payment-checker',
        'attcPaymentData',
        [
            'user_id'       => get_current_user_id(),
            'rest_url'      => rest_url('attc/v1/payment-status'),
            'stream_url'    => rest_url('attc/v1/payment-stream'),
            'nonce'         => wp_create_nonce('wp_rest'),
            'price_per_min' => attc_get_price_per_minute(),
        ]
    );
    $display_name = wp_get_current_user()->display_name;
    $logout_url = wp_logout_url(get_permalink());
    $free_threshold = (int) get_option('attc_free_threshold', 500);
    $price_per_min = (int) attc_get_price_per_minute();
    // Đọc cấu hình cổng thanh toán
    $bank_name_opt = get_option('attc_bank_name', 'ACB');
    $bank_acc_name_opt = get_option('attc_bank_account_name', 'DINH CONG TOAN');
    $bank_acc_number_opt = get_option('attc_bank_account_number', '240306539');
    $momo_phone_opt = get_option('attc_momo_phone', '0772729789');
    $momo_name_opt = get_option('attc_momo_name', 'DINH CONG TOAN');
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
        <p style="font-size:28px"><strong>Nạp tiền thành công!</strong></p>
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
    .attc-payment-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 1.5rem; }
    .attc-payment-column { border: 1px solid #e5e7eb; padding: 1.5rem; border-radius: 8px; background: #fff; text-align: center; }
    .attc-payment-column h4 { margin-top: 0; margin-bottom: 1rem; }
    .attc-qr-code img { max-width: 250px; height: auto; margin: 0 auto; }
    .attc-bank-info { text-align: left; margin-top: 1rem; }
    .attc-bank-info p { margin: 0.5rem 0; }
    #attc-success-message{font-weight: bold;color: green;font-size: 22px;}  
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
                    <h4>Ví MoMo</h4>
                    <div class="attc-qr-code" id="attc-momo-qr"></div>
                    <div class="attc-bank-info">
                        <p><strong>Số điện thoại:</strong> <span id="momo-phone"><?php echo esc_html($momo_phone_opt); ?></span></p>
                        <p><strong>Tên MoMo:</strong> <span id="momo-name"><?php echo esc_html($momo_name_opt); ?></span></p>
                        <p><strong>Số tiền:</strong> <strong class="price-payment" id="momo-amount"></strong></p>
                        <p><strong>Nội dung:</strong> <strong class="price-payment" id="momo-memo"></strong></p>
                    </div>
                    <p class="attc-qr-fallback">Mở MoMo, chọn "Quét Mã" và quét mã QR ở trên.</p>
                </div>
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


// ===== ADMIN: Wallet Dashboard & Manual Top-up =====
add_action('admin_menu', 'attc_register_admin_pages');
function attc_register_admin_pages() {
    $hook = add_menu_page(
        'AudioAI Wallet',
        'AudioAI Wallet',
        'manage_options',
        'attc-wallet',
        'attc_render_wallet_dashboard',
        'dashicons-money-alt',
        58
    );
    // Hook xử lý form action TRƯỚC KHI trang được render
    add_action('load-' . $hook, 'attc_handle_wallet_actions');
}

// Hàm xử lý các form action trên trang Wallet (trừ tiền, cộng tiền, ...)
function attc_handle_wallet_actions() {
    if (!current_user_can('manage_options')) return;

    // Save summary settings
    if (!empty($_POST['attc_save_summary_settings']) && check_admin_referer('attc_summary_settings_action', 'attc_summary_settings_nonce')) {
        $enabled = isset($_POST['attc_summary_enabled']) ? '1' : '0';
        $points  = isset($_POST['attc_summary_points']) ? (int) $_POST['attc_summary_points'] : 7;
        $points  = max(3, min(20, $points));
        update_option('attc_summary_enabled', $enabled);
        update_option('attc_summary_points', $points);
        set_transient('attc_admin_notice', '<div class="notice notice-success is-dismissible"><p>Đã lưu cấu hình tóm tắt (bật: ' . ($enabled === '1' ? 'Có' : 'Không') . ', số ý: ' . (int)$points . ').</p></div>', 30);
        wp_safe_redirect(add_query_arg('page', 'attc-wallet', remove_query_arg(['_wpnonce', 'attc_save_summary_settings'])));
        exit;
    }

    // Save per-user daily free upload quota
    if (!empty($_POST['attc_save_user_free_quota']) && check_admin_referer('attc_user_free_quota_action', 'attc_user_free_quota_nonce')) {
        $identifier = sanitize_text_field($_POST['attc_user_identifier_quota'] ?? '');
        $quota      = (int) ($_POST['attc_user_daily_free_quota'] ?? 1);
        $quota      = max(0, min(20, $quota));

        $user = false;
        if (is_numeric($identifier)) $user = get_user_by('id', (int)$identifier);
        elseif (is_email($identifier)) $user = get_user_by('email', $identifier);
        else $user = get_user_by('login', $identifier);

        if ($user) {
            update_user_meta($user->ID, '_attc_daily_free_quota', $quota);
            set_transient('attc_admin_notice', '<div class="notice notice-success is-dismissible"><p>Đã lưu quota miễn phí mỗi ngày = ' . (int)$quota . ' cho user #' . (int)$user->ID . '.</p></div>', 30);
        } else {
            set_transient('attc_admin_notice', '<div class="notice notice-error is-dismissible"><p>Không tìm thấy người dùng.</p></div>', 30);
        }
        wp_safe_redirect(add_query_arg('page', 'attc-wallet', remove_query_arg(['_wpnonce', 'attc_save_user_free_quota'])));
        exit;
    }

    // Handle manual debit
    if (!empty($_POST['attc_manual_debit']) && check_admin_referer('attc_manual_debit_action', 'attc_manual_debit_nonce')) {
        $user_identifier = sanitize_text_field($_POST['attc_user_identifier_debit'] ?? '');
        $amount = (int) ($_POST['attc_amount_debit'] ?? 0);
        $reason = sanitize_text_field($_POST['attc_reason_debit'] ?? 'Manual debit by admin');

        $user = false;
        if (is_numeric($user_identifier)) $user = get_user_by('id', (int)$user_identifier);
        elseif (is_email($user_identifier)) $user = get_user_by('email', $user_identifier);
        else $user = get_user_by('login', $user_identifier);

        if ($user && $amount > 0) {
            $result = attc_charge_wallet($user->ID, $amount, ['reason' => 'manual_debit', 'admin_note' => $reason]);
            if (is_wp_error($result)) {
                set_transient('attc_admin_notice', '<div class="notice notice-error is-dismissible"><p>Lỗi: ' . $result->get_error_message() . '</p></div>', 30);
            } else {
                set_transient('attc_admin_notice', '<div class="notice notice-success is-dismissible"><p>Đã trừ ' . number_format($amount) . 'đ từ tài khoản ' . esc_html($user->user_login) . '.</p></div>', 30);
            }
        } else {
            set_transient('attc_admin_notice', '<div class="notice notice-error is-dismissible"><p>Không tìm thấy người dùng hoặc số tiền không hợp lệ.</p></div>', 30);
        }
        wp_safe_redirect(add_query_arg('page', 'attc-wallet', remove_query_arg(['_wpnonce', 'attc_manual_debit'])));
        exit;
    }

    // Handle manual top-up
    if (!empty($_POST['attc_manual_topup']) && check_admin_referer('attc_manual_topup_action', 'attc_manual_topup_nonce')) {
        $user_identifier = sanitize_text_field($_POST['attc_user_identifier'] ?? '');
        $amount = (int) ($_POST['attc_amount'] ?? 0);

        $user = false;
        if (is_numeric($user_identifier)) $user = get_user_by('id', (int)$user_identifier);
        elseif (is_email($user_identifier)) $user = get_user_by('email', $user_identifier);
        else $user = get_user_by('login', $user_identifier);

        if ($user && $amount > 0) {
            attc_credit_wallet($user->ID, $amount, ['reason' => 'admin_topup', 'note' => 'Manual top-up by admin', 'admin' => get_current_user_id()]);
            set_transient('attc_admin_notice', '<div class="notice notice-success"><p>Đã nạp ' . number_format($amount) . 'đ vào ví của user #' . $user->ID . ' (' . esc_html($user->user_email) . ').</p></div>', 30);
        } else {
            set_transient('attc_admin_notice', '<div class="notice notice-error"><p>Vui lòng nhập người dùng hợp lệ và số tiền > 0.</p></div>', 30);
        }
        wp_safe_redirect(add_query_arg('page', 'attc-wallet', remove_query_arg(['_wpnonce', 'attc_manual_topup'])));
        exit;
    }
}

function attc_render_wallet_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die('Bạn không có quyền truy cập trang này.');
    }

    $message = '';
    $simulate_message = '';
    // Hiển thị notice nhanh từ redirect trước đó (PRG)
    $flash = get_transient('attc_admin_notice');
    if ($flash) {
        $message .= $flash;
        delete_transient('attc_admin_notice');
    }

    // Xử lý các form không redirect (simulate, verify, resend) vẫn giữ ở đây vì chúng hiển thị thông báo trực tiếp
    if (!empty($_POST['attc_admin_simulate_topup'])) {
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
                update_user_meta($uid, 'attc_payment_success', $payment_data);
                // Xóa cache user_meta để phiên khác thấy ngay
                if (function_exists('wp_cache_delete')) { wp_cache_delete($uid, 'user_meta'); }
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

    // Summary settings form
    $cur_enabled = get_option('attc_summary_enabled', '1') === '1';
    $cur_points  = (int) get_option('attc_summary_points', 7);
    echo '<h2>Cấu hình tóm tắt</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('attc_summary_settings_action', 'attc_summary_settings_nonce');
    echo '<input type="hidden" name="attc_save_summary_settings" value="1" />';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">Bật tóm tắt</th><td><label><input type="checkbox" name="attc_summary_enabled" value="1"' . ($cur_enabled ? ' checked' : '') . '> Kích hoạt tạo tóm tắt miễn phí (không gọi API)</label></td></tr>';
    echo '<tr><th scope="row"><label for="attc_summary_points">Số lượng ý</label></th><td><input name="attc_summary_points" id="attc_summary_points" type="number" min="3" max="20" value="' . (int)$cur_points . '"> <span class="description">Mặc định 5–10 ý, khuyến nghị 7</span></td></tr>';
    echo '</tbody></table>';
    submit_button('Lưu cấu hình tóm tắt');
    echo '</form>';

    // Per-user daily free upload quota form
    echo '<h2>Quota miễn phí theo người dùng</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('attc_user_free_quota_action', 'attc_user_free_quota_nonce');
    echo '<input type="hidden" name="attc_save_user_free_quota" value="1" />';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="attc_user_identifier_quota">User (ID / Email / Username)</label></th>';
    echo '<td><input name="attc_user_identifier_quota" id="attc_user_identifier_quota" type="text" class="regular-text" required placeholder="ví dụ: 123 hoặc user@example.com"></td></tr>';
    echo '<tr><th scope="row"><label for="attc_user_daily_free_quota">Số lượt upload miễn phí mỗi ngày</label></th>';
    echo '<td><input name="attc_user_daily_free_quota" id="attc_user_daily_free_quota" type="number" min="0" max="20" step="1" value="1"> <span class="description">0 = không cho dùng thử; 1 = mặc định hiện tại</span></td></tr>';
    echo '</tbody></table>';
    submit_button('Lưu quota miễn phí');
    echo '</form>';

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
            'base' => add_query_arg(['page' => 'attc-wallet', 'q' => $q, 'paged' => '%#%'], admin_url('admin.php')),
            'format' => '',
            'current' => $paged,
            'total' => $total_pages,
            'prev_text' => '« Trước',
            'next_text' => 'Sau »',
            'type' => 'list',
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
            'base' => add_query_arg(['page' => 'attc-wallet', 'q' => $q, 'paged' => '%#%'], admin_url('admin.php')),
            'format' => '',
            'current' => $paged,
            'total' => $total_pages,
            'prev_text' => '« Trước',
            'next_text' => 'Sau »',
            'type' => 'list',
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
            
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            $metadata = wp_read_audio_metadata($file['tmp_name']);
            $duration = !empty($metadata['length']) ? (int) $metadata['length'] : 0;
            $trim_start = isset($_POST['trim_start']) ? max(0, (int) $_POST['trim_start']) : 0;
            $trim_end = isset($_POST['trim_end']) ? max(0, (int) $_POST['trim_end']) : 0;
            if ($duration > 0) {
                $trim_start = min($trim_start, $duration);
                $trim_end = ($trim_end > 0) ? min($trim_end, $duration) : $duration;
                if ($trim_end < $trim_start) { $trim_end = $trim_start; }
            }
            $segment_duration = max(0, $trim_end - $trim_start);
            if ($segment_duration === 0 && $duration > 0) { $segment_duration = $duration; $trim_start = 0; $trim_end = $duration; }

            if ($duration <= 0) {
                set_transient('attc_form_error', 'Không thể xác định thầm lượng file hoặc file không hợp lệ.', 30);
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
                // Per-user quota (default 1 if not set)
                $daily_free_quota = (int) get_user_meta($user_id, '_attc_daily_free_quota', true);
                if ($daily_free_quota <= 0) { $daily_free_quota = 1; }

                if ($free_uploads_today >= $daily_free_quota) {
                    set_transient('attc_form_error', 'Bạn đã hết lượt tải lên miễn phí hôm nay. Vui lòng chọn "Nâng cấp" để chuyển đổi.', 30);
                    delete_transient($lock_key);
                    attc_redirect_back();
                }
                $limit_in_minutes = (int) get_option('attc_daily_free_limit_minutes', 5);
                $daily_free_limit_seconds = $limit_in_minutes * 60;
                if ($segment_duration > $daily_free_limit_seconds) {
                    $error_message = sprintf('File của bạn vượt quá %d phút. Lượt dùng thử chỉ áp dụng cho file dướithan %d phút.', $limit_in_minutes, $limit_in_minutes);
                    set_transient('attc_form_error', $error_message, 30);
                    delete_transient($lock_key);
                    attc_redirect_back();
                }

                $is_free_tier_eligible = true;
                $cost = 0;
                update_user_meta($user_id, '_attc_last_free_upload_date', $today);
                update_user_meta($user_id, '_attc_free_uploads_today', $free_uploads_today + 1);
                $last_cost_message = 'Chuyển đổi miễn phí thành công!';

            } else {
                // === LUỒNG TRẢ PHÍ (CHỈ TRỪ TIỀN KHI THÀNH CÔNG) ===
                $minutes_ceil = ceil($segment_duration / 60);
                $cost = $minutes_ceil * $price_per_minute;

                if ($balance < $cost) {
                    set_transient('attc_form_error', 'Số dư không đủ. Cần ' . number_format($cost) . 'đ, bạn chỉ có ' . number_format($balance) . 'đ. Vui lòng nạp thêm.', 30);
                    delete_transient($lock_key);
                    attc_redirect_back();
                }
                $last_cost_message = 'Chi phí dự kiến: ' . number_format($cost) . 'đ cho ' . $minutes_ceil . ' phút.';
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

            $job = [
                'id' => $job_id,
                'user_id' => $user_id,
                'file_path' => $dest_path,
                'file_name' => basename($dest_path),
                'duration' => $duration,
                'trim_start' => (int) $trim_start,
                'trim_end' => (int) $trim_end,
                'segment_duration' => (int) $segment_duration,
                'is_free' => (bool) $is_free_tier_eligible,
                'cost' => (int) $cost,
                'status' => 'queued',
                'created_at' => time(),
            ];
            // Lưu job vào option, bỏ autoload=false để get_option trong job nền có thể đọc được
            update_option('attc_job_' . $job_id, $job);

            if (!wp_next_scheduled('attc_run_job', [$job_id])) {
                wp_schedule_single_event(time() + 1, 'attc_run_job', [$job_id]);
                set_transient('attc_last_job_' . $user_id, $job_id, HOUR_IN_SECONDS);
                // Nudge WP-Cron to run soon (Laragon/local có thể không auto-hit wp-cron)
                $cron_url = site_url('wp-cron.php');
                @wp_remote_post($cron_url, ['timeout' => 0.01, 'blocking' => false]);
            }

            // Lưu job_id gần nhất vào user meta để client có thể polling (ổn định hơn transient)
            update_user_meta($user_id, 'attc_last_job', $job_id);
            error_log(sprintf("[%s] Form Submission: Set user_meta attc_last_job for user %d to %s\n", date('Y-m-d H:i:s'), $user_id, $job_id), 3, WP_CONTENT_DIR . '/attc_debug.log');

            // Thông báo hàng đợi cho UI (tách khỏi transcript)
            set_transient('attc_queue_notice', 'Yêu cầu đã được đưa vào hàng đợi xử lý. Bạn có thể đóng trang hoặc chờ tại trang này, kết quả sẽ hiển thị khi hoàn tất.', 60);
            if (!empty($last_cost_message)) { set_transient('attc_last_cost', $last_cost_message, 30); }

            // Kết thúc request sớm để người dùng không phải chờ
            delete_transient($lock_key);
            attc_redirect_back();
        }
    }
}
add_action('init', 'attc_handle_form_submission');

// ===== REST: Job status =====
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
            $payload = [
                'status' => (string) ($job['status'] ?? 'queued'),
                // fallback dùng $job_id từ transient nếu trong job thiếu id
                'job_id' => (string) (!empty($job['id']) ? $job['id'] : $job_id),
            ];
            if (!empty($job['transcript'])) {
                $payload['transcript'] = (string) $job['transcript'];
            }
            if (!empty($job['summary'])) {
                $payload['summary'] = (string) $job['summary'];
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
                $payload['download_link'] = add_query_arg([
                    'action' => 'attc_download_transcript',
                    'timestamp' => $ts,
                    'nonce' => $nonce,
                ], home_url());
                $payload['download_pdf_link'] = add_query_arg([
                    'action' => 'attc_download_transcript_pdf',
                    'timestamp' => $ts,
                    'nonce' => $nonce,
                ], home_url());
            }
            return new WP_REST_Response($payload, 200);
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
                $payload['download_link'] = add_query_arg([
                    'action' => 'attc_download_transcript',
                    'timestamp' => $ts,
                    'nonce' => $nonce,
                ], home_url());
                $payload['download_pdf_link'] = add_query_arg([
                    'action' => 'attc_download_transcript_pdf',
                    'timestamp' => $ts,
                    'nonce' => $nonce,
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
            update_option($job_key, $job);

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

    ignore_user_abort(true);
    @set_time_limit(0);

    $user_id = (int) ($job['user_id'] ?? 0);
    $file_path = (string) ($job['file_path'] ?? '');
    $trim_start = (int) ($job['trim_start'] ?? 0);
    $segment_duration = (int) ($job['segment_duration'] ?? 0);
    $is_free = !empty($job['is_free']);
    $cost = (int) ($job['cost'] ?? 0);

    if (!file_exists($file_path)) {
        $job['status'] = 'failed';
        $job['error'] = 'File không tồn tại.';
        error_log(sprintf("[%s] Job %s: Failed. Reason: File not found at %s\n", date('Y-m-d H:i:s'), $job_id, $file_path), 3, WP_CONTENT_DIR . '/attc_webhook.log');
        update_option('attc_job_' . $job_id, $job);
        return;
    }

    $api_key = trim((string) get_option('attc_openai_api_key'));
    if (empty($api_key)) {
        $job['status'] = 'failed';
        $job['error'] = 'Thiếu OpenAI API Key.';
        error_log(sprintf("[%s] Job %s: Failed. Reason: Missing OpenAI API Key.\n", date('Y-m-d H:i:s'), $job_id), 3, WP_CONTENT_DIR . '/attc_webhook.log');
        update_option('attc_job_' . $job_id, $job);
        return;
    }

    $send_path = $file_path;
    $tmp_trim = '';
    if (file_exists($file_path) && $segment_duration > 0) {
        $orig_duration = (int) ($job['duration'] ?? 0);
        if ($orig_duration <= 0 || $segment_duration <= $orig_duration) {
            $upload_dir = wp_upload_dir();
            $tmp_dir = trailingslashit($upload_dir['basedir']) . 'attc_jobs';
            if (!file_exists($tmp_dir)) { wp_mkdir_p($tmp_dir); }
            $ss = max(0, (int) $trim_start);
            $tt = max(0, (int) $segment_duration);
            $ffmpeg_bin = trim((string) get_option('attc_ffmpeg_path', 'ffmpeg'));
            $shell_enabled = function_exists('shell_exec');
            $disabled_funcs = (string) ini_get('disable_functions');
            $env_path = (string) getenv('PATH');
            error_log(sprintf('[%s] Job %s: shell_exec=%s, disable_functions="%s", PATH="%s", ffmpeg_bin="%s"\n', date('Y-m-d H:i:s'), $job_id, $shell_enabled ? 'yes' : 'no', $disabled_funcs, $env_path, $ffmpeg_bin), 3, WP_CONTENT_DIR . '/attc_debug.log');
            if ($shell_enabled) {
                $ver_out = @shell_exec($ffmpeg_bin . ' -version 2>&1');
                error_log(sprintf('[%s] Job %s: ffmpeg -version output: %s\n', date('Y-m-d H:i:s'), $job_id, (string)$ver_out), 3, WP_CONTENT_DIR . '/attc_debug.log');
            }
            $tmp_trim = trailingslashit($tmp_dir) . $job_id . '-trimmed-opus.webm';
            $cmd = $ffmpeg_bin . ' -y -ss ' . escapeshellarg((string)$ss) . ' -t ' . escapeshellarg((string)$tt) . ' -i ' . escapeshellarg($file_path) . ' -vn -map a:0 -ac 1 -ar 16000 -c:a libopus -b:a 16k -vbr on -compression_level 10 -application voip ' . escapeshellarg($tmp_trim) . ' 2>&1';
            $t_ffmpeg_start = microtime(true);
            $out = $shell_enabled ? @shell_exec($cmd) : '';
            $t_ffmpeg_end = microtime(true);
            $ffmpeg_secs = number_format($t_ffmpeg_end - $t_ffmpeg_start, 3);
            $out_size = (file_exists($tmp_trim) ? filesize($tmp_trim) : 0);
            error_log(sprintf('[%s] Job %s: ffmpeg trim+opus (%.3fs, %d bytes) cmd: %s\nOutput: %s\n', date('Y-m-d H:i:s'), $job_id, $ffmpeg_secs, $out_size, $cmd, (string)$out), 3, WP_CONTENT_DIR . '/attc_debug.log');
            if (file_exists($tmp_trim) && filesize($tmp_trim) > 0) {
                $send_path = $tmp_trim;
            } else {
                $tmp_trim = trailingslashit($tmp_dir) . $job_id . '-trimmed-' . basename($file_path);
                $cmd_fallback = $ffmpeg_bin . ' -y -ss ' . escapeshellarg((string)$ss) . ' -t ' . escapeshellarg((string)$tt) . ' -i ' . escapeshellarg($file_path) . ' -c copy ' . escapeshellarg($tmp_trim) . ' 2>&1';
                $out_fb = $shell_enabled ? @shell_exec($cmd_fallback) : '';
                error_log(sprintf('[%s] Job %s: ffmpeg trim fallback copy cmd: %s\nOutput: %s\n', date('Y-m-d H:i:s'), $job_id, $cmd_fallback, (string)$out_fb), 3, WP_CONTENT_DIR . '/attc_debug.log');
                if (file_exists($tmp_trim) && filesize($tmp_trim) > 0) {
                    $send_path = $tmp_trim;
                } else {
                    $tmp_trim = trailingslashit($tmp_dir) . $job_id . '-trimmed-encoded-' . basename($file_path);
                    $cmd2 = $ffmpeg_bin . ' -y -ss ' . escapeshellarg((string)$ss) . ' -t ' . escapeshellarg((string)$tt) . ' -i ' . escapeshellarg($file_path) . ' -c:a aac -b:a 192k -vn ' . escapeshellarg($tmp_trim) . ' 2>&1';
                    $out2 = $shell_enabled ? @shell_exec($cmd2) : '';
                    error_log(sprintf('[%s] Job %s: ffmpeg trim fallback aac cmd: %s\nOutput: %s\n', date('Y-m-d H:i:s'), $job_id, $cmd2, (string)$out2), 3, WP_CONTENT_DIR . '/attc_debug.log');
                    if (file_exists($tmp_trim) && filesize($tmp_trim) > 0) {
                        $send_path = $tmp_trim;
                    } else {
                        $job['status'] = 'failed';
                        $job['error'] = $shell_enabled ? 'Không thể cắt đoạn audio với ffmpeg.' : 'Máy chủ không cho phép shell_exec. Không thể chạy ffmpeg.';
                        update_option('attc_job_' . $job_id, $job);
                        return;
                    }
                }
            }
        }
    }

    $api_url = 'https://api.openai.com/v1/audio/transcriptions';
    $headers_in = ['Authorization: Bearer ' . $api_key];
    if (strpos($api_key, 'sk-proj-') === 0) {
        $attc_proj = trim((string) get_option('attc_openai_project_id'));
        if (!empty($attc_proj)) { $headers_in[] = 'OpenAI-Project: ' . $attc_proj; }
    }
    $headers_in[] = 'Expect:';
    $headers_in[] = 'Connection: keep-alive';
    $post_fields = [
        'model' => 'whisper-1',
        // Không đặt 'language' để Whisper tự nhận diện và xử lý đa ngôn ngữ
        'response_format' => 'verbose_json',
        'temperature' => 0,
        'file' => curl_file_create($send_path, mime_content_type($send_path), basename($send_path))
    ];

    error_log(sprintf("[%s] Job %s: Preparing to call OpenAI API at %s (file: %s, size: %d bytes)\n", date('Y-m-d H:i:s'), $job_id, $api_url, basename($send_path), (file_exists($send_path)?filesize($send_path):0)), 3, WP_CONTENT_DIR . '/attc_debug.log');

    $max_attempts = 3;
    $attempt = 0;
    $response_code = 0;
    $response_body_str = '';
    $curl_error = '';
    $response_body = null;

    while ($attempt < $max_attempts) {
        $attempt++;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_in);
        if (defined('CURL_HTTP_VERSION_2TLS')) { curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS); }
        if (defined('CURLOPT_TCP_KEEPALIVE')) { curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1); }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);

        $t_curl_start = microtime(true);
        $response_body_str = curl_exec($ch);
        $t_curl_end = microtime(true);
        $curl_secs = number_format($t_curl_end - $t_curl_start, 3);
        $response_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = (string) curl_error($ch);
        curl_close($ch);
        error_log(sprintf("[%s] Job %s: Attempt %d/%d cURL response (%.3fs). Code: %d. Body: %s. cURL Error: %s\n", date('Y-m-d H:i:s'), $job_id, $attempt, $max_attempts, $curl_secs, $response_code, $response_body_str, $curl_error), 3, WP_CONTENT_DIR . '/attc_debug.log');

        // Retry conditions: cURL error, 5xx, empty body, or server generic error message
        $should_retry = false;
        if (!empty($curl_error)) { $should_retry = true; }
        if ($response_code >= 500) { $should_retry = true; }
        if ($response_code === 0 || $response_body_str === '' || $response_body_str === false) { $should_retry = true; }
        if (strpos($response_body_str, 'The server had an error while processing your request') !== false) { $should_retry = true; }

        if (!$should_retry) { break; }

        // Backoff: 1s, 2s
        if ($attempt < $max_attempts) { sleep($attempt); }
    }

    if (!empty($curl_error)) {
        $job['status'] = 'failed';
        $job['error'] = 'cURL: ' . $curl_error;
        update_option('attc_job_' . $job_id, $job);
        if (!empty($tmp_trim) && file_exists($tmp_trim)) { @unlink($tmp_trim); }
        return;
    }

    $response_body = json_decode((string)$response_body_str, true);

    // Clean up trimmed temp file when done
    if (!empty($tmp_trim) && file_exists($tmp_trim)) { @unlink($tmp_trim); }
    if ($response_code !== 200) {
        $friendly = 'OpenAI error.';
        if (is_array($response_body) && isset($response_body['error']['message'])) {
            $friendly = (string) $response_body['error']['message'];
        } else {
            // đính kèm mã lỗi để dễ debug
            $friendly = sprintf('OpenAI HTTP %d. %s', $response_code, mb_substr((string)$response_body_str, 0, 300));
        }
        $job['status'] = 'failed';
        $job['error'] = $friendly;
        update_option('attc_job_' . $job_id, $job);
        if (!empty($tmp_trim) && file_exists($tmp_trim)) { @unlink($tmp_trim); }
        return;
    }

    // Hậu xử lý đa ngôn ngữ: giữ nguyên thứ tự thầm gian segments, gom theo nhóm ngôn ngữ liên tiếp,
    // mỗi câu một dòng, chèn dòng trống khi đổi ngôn ngữ, và chỉ hiệu chỉnh câu tiếng Việt.
    $transcript = '';
    $has_segments = isset($response_body['segments']) && is_array($response_body['segments']);
    // groups: [{ lang: 'vi'|'en', sentences: [..] }]
    $groups = [];
    $last_lang = null; // ngôn ngữ của câu gần nhất đã thêm (duy trì mạch nội dung)
    $append_to_group = function(array &$groups, string $lang_hint, string $text) use (&$last_lang) {
        $text = trim($text);
        if ($text === '') return;
        // Tách câu và xác định ngôn ngữ cho từng câu để tránh lẫn lộn trong cùng 1 segment
        $sent_list = attc_split_sentences($text);
        foreach ($sent_list as $s) {
            $s = trim($s);
            if ($s === '') continue;
            $s_lang = attc_detect_lang_vi_en($s);
            // nếu không xác định rõ, dùng gợi ý từ segment
            if ($s_lang !== 'vi' && $s_lang !== 'en') { $s_lang = $lang_hint; }
            // Nắn bias: nếu câu được nhận diện EN nhưng segment hint và mạch trước đó là VI, giữ VI để không mất câu VI không dấu
            if ($s_lang === 'en' && $lang_hint === 'vi' && $last_lang === 'vi') {
                // Chỉ đổi về VI khi câu không có từ khóa EN mạnh (the, and, you, your, to, for, of)
                $lc = mb_strtolower($s, 'UTF-8');
                if (!preg_match('/\b(the|and|you|your|to|for|of|is|are|in|on|with|but|this|that)\b/u', $lc)) {
                    $s_lang = 'vi';
                }
            }
            $last_idx = count($groups) - 1;
            if ($last_idx >= 0 && $groups[$last_idx]['lang'] === $s_lang) {
                $groups[$last_idx]['sentences'][] = $s;
            } else {
                $groups[] = ['lang' => $s_lang, 'sentences' => [$s]];
            }
            $last_lang = $s_lang;
        }
    };

    if ($has_segments) {
        foreach ($response_body['segments'] as $seg) {
            $seg_text = is_array($seg) && isset($seg['text']) ? trim((string)$seg['text']) : '';
            if ($seg_text === '') continue;
            $lang = attc_detect_lang_vi_en($seg_text);
            $append_to_group($groups, $lang, $seg_text);
        }
        if (empty($groups)) {
            $fallback_text = (string) ($response_body['text'] ?? '');
            if ($fallback_text !== '') {
                $append_to_group($groups, attc_detect_lang_vi_en($fallback_text), $fallback_text);
            }
        }
    } else {
        // Fallback không có segments: xử lý theo câu
        $raw_text = (string) ($response_body['text'] ?? '');
        if ($raw_text !== '') {
            $sentences = attc_split_sentences($raw_text);
            if (!empty($sentences)) {
                foreach ($sentences as $s) {
                    $lang = attc_detect_lang_vi_en($s);
                    $append_to_group($groups, $lang, trim($s));
                }
            } else {
                $append_to_group($groups, attc_detect_lang_vi_en($raw_text), $raw_text);
            }
        }
    }

    // Áp dụng làm sạch và hiệu chỉnh theo nhóm, theo từng câu để không mất câu và giữ xuống dòng
    if (!empty($groups)) {
        $blocks = [];
        foreach ($groups as $g) {
            $sents = [];
            $buffer = [];
            $buf_chars = 0;
            $flush = function() use (&$buffer, &$sents, $api_key) {
                if (empty($buffer)) return;
                $orig = $buffer;
                $joined = implode("\n", array_map('attc_vi_normalize_lexicon', $orig));
                $resp = attc_vi_correct_text($joined, $api_key);
                if (is_string($resp) && $resp !== '') {
                    $parts = preg_split('/\r\n|\r|\n/u', $resp);
                    if (is_array($parts) && count($parts) === count($orig)) {
                        foreach ($parts as $p) { $sents[] = trim(attc_vi_normalize_lexicon($p)); }
                    } else {
                        foreach ($orig as $p) { $sents[] = trim(attc_vi_normalize_lexicon($p)); }
                    }
                } else {
                    foreach ($orig as $p) { $sents[] = trim(attc_vi_normalize_lexicon($p)); }
                }
                $buffer = [];
            };
            foreach ($g['sentences'] as $s) {
                $s = trim($s);
                if ($s === '') continue;
                if ($g['lang'] === 'vi') {
                    $len = mb_strlen($s, 'UTF-8');
                    if ($len < 8) { $sents[] = $s; continue; }
                    $buffer[] = $s;
                    $buf_chars += $len;
                    if (count($buffer) >= 6 || $buf_chars >= 800) { $flush(); $buf_chars = 0; }
                } else {
                    $sents[] = $s;
                }
            }
            if (!empty($buffer)) { $flush(); }
            if (!empty($sents)) { $blocks[] = implode("\n", $sents); }
        }
        $transcript = trim(implode("\n\n", $blocks));
    }

    // Chỉ trừ tiền khi API trả về thành công
    if (!$is_free && $cost > 0) {
        $charge_meta = ['reason' => 'audio_conversion', 'duration' => (int)($job['duration'] ?? 0), 'transcript' => $transcript];
        $charge_result = attc_charge_wallet($user_id, $cost, $charge_meta);
        if (is_wp_error($charge_result)) {
            // Không thể trừ tiền: vẫn lưu transcript vào lịch sử với amount 0 để không mất dữ liệu
            attc_add_wallet_history($user_id, [
                'type' => 'conversion',
                'amount' => 0,
                'meta' => $charge_meta,
            ]);
        }
    } else {
        // Miễn phí: vẫn lưu transcript vào lịch sử
        $free_meta = [
            'type' => 'conversion_free',
            'amount' => 0,
            'meta' => ['reason' => 'free_tier', 'duration' => (int)($job['duration'] ?? 0), 'transcript' => $transcript],
        ];
        attc_add_wallet_history($user_id, $free_meta);
    }

    $job['status'] = 'done';
    $job['transcript'] = $transcript;
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

    // Hẹn tạo tóm tắt sau khi transcript sẵn sàng (không chặn việc trả transcript)
    if ($transcript !== '' && attc_is_summary_enabled()) {
        if (!wp_next_scheduled('attc_run_summary', [$job_id])) {
            wp_schedule_single_event(time() + 1, 'attc_run_summary', [$job_id]);
        }
    }

    // Dọn file tạm
    @unlink($file_path);
}

// Cron: tạo tóm tắt sau khi transcript đã sẵn sàng (không chặn luồng chính)
add_action('attc_run_summary', 'attc_process_summary', 10, 1);
function attc_process_summary($job_id) {
    $job_key = 'attc_job_' . $job_id;
    $job = get_option($job_key, []);
    if (empty($job) || !is_array($job)) return;
    if (!attc_is_summary_enabled()) return;
    $transcript = (string) ($job['transcript'] ?? '');
    if ($transcript === '') return;
    // Nếu đã có summary thì bỏ qua
    if (!empty($job['summary'])) return;

    $points = attc_get_summary_points();
    $clean_transcript = attc_vi_fix_sentence_boundaries($transcript);
    $summary = attc_summarize_points($clean_transcript, $points);
    if (!is_string($summary) || trim($summary) === '') return;
    // Đảm bảo không vượt quá số ý cấu hình do các trường hợp xuống dòng bất ngờ
    $lines = preg_split('/\r\n|\r|\n/u', trim($summary));
    if (is_array($lines) && count($lines) > $points) {
        $lines = array_slice($lines, 0, $points);
        $summary = implode("\n", $lines);
    }

    // Cập nhật job
    $job['summary'] = $summary;
    update_option($job_key, $job);

    // Ghi bổ sung vào lịch sử gần nhất có timestamp trùng job (nếu có)
    $user_id = (int) ($job['user_id'] ?? 0);
    if ($user_id > 0) {
        $history = get_user_meta($user_id, 'attc_wallet_history', true);
        if (!empty($history) && is_array($history)) {
            // Tìm phần tử lịch sử mới nhất có meta.transcript trùng với bản hiện tại
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if (!empty($history[$i]['meta']['transcript']) && $history[$i]['meta']['transcript'] === $transcript) {
                    $history[$i]['meta']['summary'] = $summary;
                    update_user_meta($user_id, 'attc_wallet_history', $history);
                    break;
                }
            }
        }
    }
}

function attc_form_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="attc-auth-prompt"><p>Vui lòng <a href="' . esc_url(site_url('/dang-nhap')) . '">đăng nhập</a> hoặc <a href="' . esc_url(site_url('/dang-ky')) . '">đăng ký</a> để sử dụng chức năng này.</p></div>';
    }

    $user_id = get_current_user_id();
    $balance = attc_get_wallet_balance($user_id);
    $price_per_minute = attc_get_price_per_minute();
    $max_minutes = $price_per_minute > 0 ? floor($balance / $price_per_minute) : 0;
    // Ngưỡng miễn phí giống backend
    $free_threshold = (int) get_option('attc_free_threshold', 500);

    $error_message = get_transient('attc_form_error');
    if ($error_message) delete_transient('attc_form_error');

    $success_message = get_transient('attc_form_success');
    if ($success_message) delete_transient('attc_form_success');
    // Thông báo hàng đợi (nếu vừa enqueue job)
    $queue_notice = get_transient('attc_queue_notice');
    if ($queue_notice) delete_transient('attc_queue_notice');

    // Nếu là lần vào trang "sạch" (không có kết quả ngay, không có thông báo hàng đợi)
    // thì xoá marker job cũ để UI không tự hiện kết quả cũ
    if (empty($success_message) && empty($queue_notice)) {
        delete_user_meta($user_id, 'attc_last_job');
    }

    $last_cost = get_transient('attc_last_cost');
    if ($last_cost) delete_transient('attc_last_cost');

    ob_start();
    ?>
    <style>
        .attc-converter-wrap { max-width: 900px; margin: 2rem auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
        .attc-wallet-box { background: #d6e4fd; border-radius: 8px; padding: 1.5rem; display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
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
    color: white !important;
    border-radius: 5px;
    text-decoration: none;
    font-weight: 500;
    transition: background-color 0.2s;
}

        .attc-result-actions { margin-top: 1.5rem; text-align: right; }
        .attc-result-actions .download-btn { 
            display: inline-block;
            color: white !important;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            transition: background-color 0.2s;
        }
        
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
   </style>

    <?php
    $user_id = get_current_user_id();
    $has_made_deposit = attc_user_has_made_deposit($user_id);
    $today = current_time('Y-m-d');
    $last_free_upload_date = get_user_meta($user_id, '_attc_last_free_upload_date', true);
    $free_uploads_today = ($last_free_upload_date === $today) ? (int) get_user_meta($user_id, '_attc_free_uploads_today', true) : 0;
    $daily_free_quota = (int) get_user_meta($user_id, '_attc_daily_free_quota', true);
    if ($daily_free_quota <= 0) { $daily_free_quota = 1; }
    $free_uploads_left = max(0, $daily_free_quota - $free_uploads_today);
    $display_name = wp_get_current_user()->display_name;
    ?>
    <div class="attc-converter-wrap">
        <div class="attc-wallet-box">
            <div class="attc-wallet-balance">
                <?php if ($balance > $free_threshold): ?>
                    <p>Số dư: <strong><?php echo number_format($balance, 0, ',', '.'); ?>đ</strong></p>
                    <p class="attc-wallet-sub">Tương đương <strong><?php echo $max_minutes; ?></strong> phút chuyển đổi</p>
                <?php else: ?>
                    <p>Hôm nay bạn còn: <strong style="color: red; font-weight: bold; "><?php echo $free_uploads_left; ?></strong> lượt chuyển đổi miễn phí</p>
                    <?php 
                        $limit_in_minutes = (int) get_option('attc_daily_free_limit_minutes', 5);
                    ?>
                    <p class="attc-wallet-sub">Mỗi ngày miễn phí chuyển đổi 1 File ghi âm < <?php echo $limit_in_minutes; ?> phút</p>
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

        <form action="" method="post" enctype="multipart/form-data" id="attc-upload-form">
            <?php wp_nonce_field('attc_form_action', 'attc_nonce'); ?>
            <div class="attc-form-upload" id="attc-drop-zone">
                <p>Kéo và thả file ghi âm vào đây, hoặc</p>
                <label for="audio_file" class="file-input-label">Chọn từ máy tính</label>
                <input type="file" id="audio_file" name="audio_file" accept="audio/*">
                <div id="attc-file-info"></div>
                <div id="attc-estimate" style="margin-top:8px; font-weight:600; color:#0f172a;"></div>
            </div>
            <div id="attc-trim-controls" class="is-hidden" style="margin-top:12px; text-align:left;">
                <label style="display:block; margin-bottom:6px; font-weight:600;">Chọn đoạn chuyển đổi (phút:giây):</label>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <label>Bắt đầu (phút:giây)
                        <input type="text" id="attc-trim-start-mmss" value="0:00" placeholder="0:00" style="width:90px; margin-left:6px;">
                        <input type="hidden" id="attc-trim-start" name="trim_start" value="0">
                    </label>
                    <label>Kết thúc (phút:giây)
                        <input type="text" id="attc-trim-end-mmss" value="0:00" placeholder="4:30" style="width:90px; margin-left:6px;">
                        <input type="hidden" id="attc-trim-end" name="trim_end" value="0">
                    </label>
                    <span id="attc-trim-length" style="font-style:italic; color:#475569;">Độ dài: 0:00</span>
                </div>
                <audio id="attc-audio-preview" controls style="margin-top:8px; width:100%; display:none;"></audio>
                <div id="attc-trim-warning" class="attc-alert attc-error is-hidden" style="margin-top:8px;"></div>
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
            $download_pdf_link = '';
            if ($success_message) {
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
                        $download_pdf_link = add_query_arg([
                            'action' => 'attc_download_transcript_pdf',
                            'timestamp' => $last_item['timestamp'],
                            'nonce' => $download_nonce,
                        ], home_url());
                    }
                }
            }
        ?>
        <?php $result_hidden = empty($success_message); ?>
        <div class="attc-result<?php echo $result_hidden ? ' is-hidden' : ''; ?>" id="attc-result">
            <h3>Kết quả chuyển đổi:</h3>
            <div class="attc-result-text"><?php echo $success_message ? nl2br(esc_html($success_message)) : ''; ?></div>
            <div class="attc-summary-panel is-hidden" id="attc-summary">
                <h3>Tóm tắt điểm chính:</h3>
                <ul id="attc-summary-list" style="margin:0; padding-left: 20px;"></ul>
            </div>
            <?php if ($download_link): ?>
            <div class="attc-result-actions">
            <span style="position: relative;top: -10px;font-style: italic;color: blue;text-decoration: underline;">Tải kết quả</span>
                <a href="<?php echo esc_url($download_link); ?>" class="download-btn" aria-label="Tải DOCX" title="Tải DOCX">
                    <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <rect x="3" y="3" width="14" height="18" rx="2" ry="2" fill="#1E3A8A"/>
                        <rect x="7" y="3" width="14" height="18" rx="2" ry="2" fill="#2563EB"/>
                        <text x="14" y="16" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="9" fill="#ffffff" font-weight="700">W</text>
                    </svg>
                </a>
                <?php if ($download_pdf_link): ?>
                <a href="<?php echo esc_url($download_pdf_link); ?>" class="download-btn" style="aria-label="Tải PDF" title="Tải PDF">
                    <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2" fill="#DC2626"/>
                        <text x="12" y="16" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="8" fill="#ffffff" font-weight="700">PDF</text>
                    </svg>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
    </div>

    <script>
    // Cung cấp nonce REST cho client, không phụ thuộc theme
    window.wpApiSettings = window.wpApiSettings || {};
    window.wpApiSettings.nonce = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';
    </script>

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
        // Khai báo trước để dùng được trong submit handler
        const resultContainer = document.querySelector('.attc-result');
        const resultTextBox = document.querySelector('.attc-result-text');
        const resultToolbarId = 'attc-result-tools';
        let progressTimer = null;
        // Dùng để tránh mở hộp thoại chọn file 2 lần liền nhau
        let lastFileDialogAt = 0;

        // Estimate + Trim controls
        const estimateEl = document.getElementById('attc-estimate');
        const trimWrap = document.getElementById('attc-trim-controls');
        const trimStartHidden = document.getElementById('attc-trim-start');
        const trimEndHidden = document.getElementById('attc-trim-end');
        const trimStartMMSS = document.getElementById('attc-trim-start-mmss');
        const trimEndMMSS = document.getElementById('attc-trim-end-mmss');
        const trimLen = document.getElementById('attc-trim-length');
        const trimWarn = document.getElementById('attc-trim-warning');
        const audioPreview = document.getElementById('attc-audio-preview');
        const pricePerMinute = <?php echo (int) $price_per_minute; ?>;
        const walletBalance = <?php echo (int) $balance; ?>;
        const freeLimitMinutes = <?php echo (int) get_option('attc_daily_free_limit_minutes', 5); ?>;
        let fileDuration = 0; // seconds

        function updateEstimate(seconds) {
            try {
                const secs = Math.max(0, Number.isFinite(seconds) ? seconds : fileDuration);
                if (!estimateEl) return;
                if (!secs) { estimateEl.textContent = ''; return; }
                const mins = secs / 60;
                const cost = Math.ceil(mins * pricePerMinute);
                estimateEl.textContent = `Ước tính: ${mins.toFixed(2)} phút ≈ ${cost.toLocaleString('vi-VN')}đ`;
            } catch(e) {}
        }

        function toSeconds(txt) {
            if (typeof txt !== 'string') return 0;
            const parts = txt.trim().split(':').map(x=>x.trim());
            if (parts.length === 1) { // seconds only
                const s = parseInt(parts[0],10); return isFinite(s) && s>=0 ? s : 0;
            }
            if (parts.length === 2) { // m:s
                const m = parseInt(parts[0],10); const s = parseInt(parts[1],10);
                if (!isFinite(m) || !isFinite(s) || m<0 || s<0 || s>59) return 0;
                return m*60+s;
            }
            // h:m:s
            const h = parseInt(parts[0],10); const m = parseInt(parts[1],10); const s = parseInt(parts[2],10);
            if (!isFinite(h) || !isFinite(m) || !isFinite(s) || h<0 || m<0 || m>59 || s<0 || s>59) return 0;
            return h*3600 + m*60 + s;
        }
        function toMMSS(secs) {
            secs = Math.max(0, Math.floor(secs||0));
            const h = Math.floor(secs/3600);
            const m = Math.floor((secs%3600)/60);
            const s = secs%60;
            return (h>0 ? (h+':'+String(m).padStart(2,'0')) : String(m)) + ':' + String(s).padStart(2,'0');
        }

        function applyTrimConstraints() {
            if (!trimWrap) return;
            let s = toSeconds(trimStartMMSS ? trimStartMMSS.value : '0:00');
            let e = toSeconds(trimEndMMSS ? trimEndMMSS.value : '0:00');
            // Always clamp non-negative
            s = Math.max(0, s);
            e = Math.max(0, e);
            // If end exceeds duration, cap to duration
            if (fileDuration > 0 && e > fileDuration) e = fileDuration;
            // If start exceeds duration OR start > end, reset start to 0:00 (as requested)
            if ((fileDuration > 0 && s > fileDuration) || (e > 0 && s > e)) {
                s = 0;
            }
            // Ensure end >= start after potential reset
            if (e < s) e = s;
            if (trimStartHidden) trimStartHidden.value = Math.floor(s);
            if (trimEndHidden) trimEndHidden.value = Math.floor(e);
            if (trimStartMMSS) trimStartMMSS.value = toMMSS(s);
            if (trimEndMMSS) trimEndMMSS.value = toMMSS(e);
            const selected = Math.max(0, e - s);
            if (trimLen) trimLen.textContent = `Độ dài: ${toMMSS(selected)}`;

            // Enforce free limit when balance is zero
            let overLimit = false;
            if (walletBalance <= 0) {
                const freeSecs = Math.max(0, freeLimitMinutes * 60);
                if (selected > freeSecs) overLimit = true;
            }
            if (overLimit) {
                if (trimWarn) {
                    trimWarn.classList.remove('is-hidden');
                    trimWarn.textContent = `Tài khoản miễn phí: vui lòng chọn đoạn <= ${freeLimitMinutes} phút để chuyển đổi.`;
                }
                submitButton.disabled = true;
            } else {
                if (trimWarn) trimWarn.classList.add('is-hidden');
                submitButton.disabled = false;
            }
            updateEstimate(selected || fileDuration);
        }

        // Hàm tiện ích: cuộn về tiêu đề trang (ưu tiên .entry-title, sau đó .entry-header, cuối cùng top window)
        function attcScrollToTitle() {
            const el = document.querySelector('.entry-title') || document.querySelector('.entry-header');
            if (el && typeof el.scrollIntoView === 'function') {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch(e) { window.scrollTo(0,0); }
            }
        }

        if (!dropZone || !uploadForm) return;

        // Kích hoạt input file khi click vào dropzone (trừ khi bấm trực tiếp vào label/input)
        dropZone.addEventListener('click', function(e) {
            const t = e.target;
            // Nếu bấm vào chính input hoặc label liên kết, để hành vi mặc định xử lý (tránh mở 2 lần)
            if (t && (t.id === 'audio_file' || (t.closest && t.closest('label[for="audio_file"]')))) {
                return;
            }
            // Nếu vừa chọn file xong (trong vòng 800ms), bỏ qua để tránh mở lại
            if (Date.now() - lastFileDialogAt < 800) {
                return;
            }
            fileInput.click();
        });

        

        // Hiện tên file khi chọn + bật/tắt nút submit
        function updateFileUI(){
            if (fileInput.files && fileInput.files.length > 0) {
                var file = fileInput.files[0];
                fileInfo.textContent = 'File đã chọn: ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
                submitButton.disabled = false;
            } else {
                fileInfo.textContent = '';
                submitButton.disabled = true;
            }
        }
        function loadAudioForAnalysis(file){
            try {
                if (!file || !audioPreview) return;
                const url = URL.createObjectURL(file);
                audioPreview.src = url;
                audioPreview.style.display = 'block';
                // Show trim block immediately while waiting for metadata
                if (trimWrap) trimWrap.classList.remove('is-hidden');
                if (estimateEl) estimateEl.textContent = 'Đang đọc thời lượng file...';
                if (trimEndMMSS && (!fileDuration || fileDuration === 0)) {
                    // Default end = free limit (if wallet is zero) else 120s for preview
                    const def = (walletBalance <= 0 ? Math.max(1, freeLimitMinutes) * 60 : 120);
                    trimEndMMSS.value = toMMSS(def);
                    if (trimEndHidden) trimEndHidden.value = def;
                }
                audioPreview.addEventListener('loadedmetadata', function onMeta(){
                    audioPreview.removeEventListener('loadedmetadata', onMeta);
                    fileDuration = Math.floor(audioPreview.duration || 0);
                    if (trimWrap) trimWrap.classList.remove('is-hidden');
                    if (trimEndMMSS) trimEndMMSS.value = toMMSS(fileDuration || 0);
                    if (trimEndHidden) trimEndHidden.value = Math.floor(fileDuration || 0);
                    applyTrimConstraints();
                });
            } catch(e) {
                // fallback: hide preview but still allow upload
                if (audioPreview) { audioPreview.style.display = 'none'; }
            }
        }

        fileInput.addEventListener('change', function(){
            lastFileDialogAt = Date.now();
            updateFileUI();
            if (fileInput.files && fileInput.files[0]) {
                loadAudioForAnalysis(fileInput.files[0]);
            }
        });
        // Khởi tạo trạng thái nút submit theo file hiện có (nếu có)
        updateFileUI();

        uploadForm.addEventListener('submit', function() {
            if (submitButton.disabled) return;
            submitButton.disabled = true;
            submitButton.textContent = 'Đang xử lý, vui lòng chờ...';
            // Không hiển thị progress ngay tại submit để tránh nháy 2 lần.
            // Progress sẽ hiển thị sau khi redirect với queue_notice (PRG).
            attcScrollToTitle();
        });

        // Polling trạng thái job mới nhất để auto hiển thị transcript
        const jobLatestUrl = '<?php echo esc_url_raw( rest_url('attc/v1/jobs/latest') ); ?>';
        // Fallback từ server: job_id gần nhất lưu trong user meta (render vào HTML)
        const attcLastJobId = '<?php echo esc_js( (string) get_user_meta(get_current_user_id(), 'attc_last_job', true) ); ?>';
        // Base URL để truy vấn trạng thái theo job_id (fallback)
        const jobStatusBase = '<?php echo esc_url_raw( rest_url('attc/v1/jobs/') ); ?>';

        let waitSummaryAttempts = 0;
        const waitSummaryMax = 6; // ~tối đa 6 lần

        function renderFinalResult(data) {
            // 1. Dừng polling chỉ khi đã có summary hoặc đã quá số lần chờ
            const hasSummary = !!(data && typeof data.summary === 'string' && data.summary.trim().length > 0);
            if (hasSummary || waitSummaryAttempts >= waitSummaryMax) {
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            }

            // 2. Dọn dẹp UI: ẩn progress và mọi thông báo info
            if (progressWrap) progressWrap.classList.add('is-hidden');
            document.querySelectorAll('.attc-alert.attc-info').forEach(function(el) { el.style.display = 'none'; });
            var qn = document.getElementById('attc-queue-notice');
            if (qn) { qn.classList.add('is-hidden'); qn.style.display = 'none'; }

            // 3. Hiển thị khối kết quả
            if (resultContainer) { resultContainer.classList.remove('is-hidden'); }

            // 4. Render văn bản
            const text = (data.transcript && typeof data.transcript === 'string' && data.transcript.trim().length > 0) ? data.transcript : '(Không có nội dung hoặc API trả về rỗng)';
            if (resultTextBox) {
                resultTextBox.textContent = text;
            }

            // 4.1. Render tóm tắt điểm chính (nếu có)
            const summaryPanel = document.getElementById('attc-summary');
            const summaryList = document.getElementById('attc-summary-list');
            if (summaryPanel && summaryList) {
                if (data.summary && typeof data.summary === 'string' && data.summary.trim().length > 0) {
                    summaryPanel.classList.remove('is-hidden');
                    summaryList.innerHTML = '';
                    try {
                        data.summary.split('\n').forEach(function(line){
                            var t = (line || '').replace(/^\-\s*/, '').trim();
                            if (!t) return;
                            var li = document.createElement('li');
                            li.textContent = t;
                            summaryList.appendChild(li);
                        });
                    } catch(e) {
                        // nếu lỗi parse, ẩn panel
                        summaryPanel.classList.add('is-hidden');
                        summaryList.innerHTML = '';
                    }
                } else {
                    summaryPanel.classList.add('is-hidden');
                    summaryList.innerHTML = '';
                    // Chưa có summary: tiếp tục polling để chờ tóm tắt xuất hiện
                    if (waitSummaryAttempts < waitSummaryMax) {
                        waitSummaryAttempts++;
                        if (!pollTimer) {
                            pollTimer = setInterval(pollLatestJob, 3000);
                        }
                    }
                }
            }

            // 5. Render nút tải về
            let actions = document.querySelector('.attc-result-actions');
            if (data.download_link) {
                if (!actions) {
                    actions = document.createElement('div');
                    actions.className = 'attc-result-actions';
                    if (resultContainer) resultContainer.appendChild(actions);
                }
                let html = `<a href="${data.download_link}" class="download-btn" aria-label="Tải DOCX" title="Tải DOCX">
                    <svg xmlns=\"http://www.w3.org/2000/svg\" width=\"30\" height=\"30\" viewBox=\"0 0 24 24\" fill=\"none\">
                        <rect x=\"3\" y=\"3\" width=\"14\" height=\"18\" rx=\"2\" ry=\"2\" fill=\"#1E3A8A\"/>
                        <rect x=\"7\" y=\"3\" width=\"14\" height=\"18\" rx=\"2\" ry=\"2\" fill=\"#2563EB\"/>
                        <text x=\"14\" y=\"16\" text-anchor=\"middle\" font-family=\"Segoe UI,Arial,sans-serif\" font-size=\"9\" fill=\"#ffffff\" font-weight=\"700\">W</text>
                    </svg>
                </a>`;
                if (data.download_pdf_link) {
                    html += ` <a href="${data.download_pdf_link}" class="download-btn" style="aria-label="Tải PDF" title="Tải PDF">
                        <svg xmlns=\"http://www.w3.org/2000/svg\" width=\"30\" height=\"30\" viewBox=\"0 0 24 24\" fill=\"none\">
                            <rect x=\"3\" y=\"3\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\" fill=\"#DC2626\"/>
                            <text x=\"12\" y=\"16\" text-anchor=\"middle\" font-family=\"Segoe UI,Arial,sans-serif\" font-size=\"8\" fill=\"#ffffff\" font-weight=\"700\">PDF</text>
                        </svg>
                    </a>`;
                }
                actions.innerHTML = html;
            } else if (actions) {
                actions.innerHTML = ''; // Xóa nút tải cũ nếu có
            }

            // 6. Tự động sao chép
            if (data.transcript && typeof data.transcript === 'string' && data.transcript.trim().length > 0) {
                autoCopyText(data.transcript);
            }

            // 7. Cuộn tới kết quả
            if (resultContainer) {
                resultContainer.scrollIntoView({ behavior:'smooth', block:'start' });
            }
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
            if (!text) return;
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(function(){
                        showToast('Đã sao chép kết quả vào clipboard');
                    }).catch(function(){});
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.top = '-9999px';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); showToast('Đã sao chép kết quả vào clipboard'); } catch(e) {}
                    document.body.removeChild(ta);
                }
            } catch(e) {}
        }

        let pollTimer = null;
        // Nếu có thông báo hàng đợi (sau khi upload và redirect), tự hiển thị progress
        const queueNoticeEl = document.getElementById('attc-queue-notice');
        if (queueNoticeEl && progressWrap) {
            progressWrap.classList.remove('is-hidden');
            startFakeProgress();
            // Sau reload có queue notice, đảm bảo cuộn về đầu trang để thấy thông báo và tiến trình
            attcScrollToTitle();
        }
        function pollLatestJob(){
            console.log('[ATTG_DEBUG] Polling for latest job status at: ' + jobLatestUrl);
            fetch(jobLatestUrl, { credentials: 'same-origin', headers: { 'X-WP-Nonce': (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '' } })
                .then(r => {
                    if (!r.ok) {
                        throw new Error('Network response was not ok: ' + r.statusText);
                    }
                    return r.json();
                })
                .then(data => {
                    console.log('[ATTG_DEBUG] Received data from /jobs/latest:', data);

                    // Bắt đầu xử lý dữ liệu job...
                    if (data && data.status) {
                        // Tạo thanh công cụ chứa thông tin job
                        let tools = document.getElementById(resultToolbarId);
                        if (!tools) {
                            tools = document.createElement('div');
                            tools.id = resultToolbarId;
                            tools.className = 'attc-result-tools';
                            if (resultTextBox && resultTextBox.parentNode) {
                                resultTextBox.parentNode.insertBefore(tools, resultTextBox);
                            }
                        }
                        tools.innerHTML = ''; // Xóa sạch để render lại

                        // Hàm trợ giúp tạo badge thông tin
                        const mkBadge = (label, value) => {
                            const b = document.createElement('span');
                            b.className = 'attc-badge';
                            b.innerHTML = `<strong>${label}:</strong> ${value}`;
                            return b;
                        };
                        const jobIdDisplay = (data.job_id || attcLastJobId || '').trim();
                        if (jobIdDisplay) {
                            tools.appendChild(mkBadge('Job ID', jobIdDisplay));
                        }
                        if (data.file_name) {
                            tools.appendChild(mkBadge('File', data.file_name));
                        }
                        if (data.finished_at) {
                            const dt = new Date(data.finished_at * 1000);
                            tools.appendChild(mkBadge('Hoàn tất', dt.toLocaleString()))
                        }
                        if (data.status === 'failed') {
                            const retryBtn = document.createElement('button');
                            retryBtn.type = 'button';
                            retryBtn.textContent = 'Thử lại';
                            retryBtn.style.background = '#2271b1';
                            retryBtn.style.color = '#fff';
                            retryBtn.style.border = 'none';
                            retryBtn.style.borderRadius = '6px';
                            retryBtn.style.padding = '6px 12px';
                            retryBtn.style.cursor = 'pointer';
                            retryBtn.addEventListener('click', function(){
                                if (!data.job_id) return;
                                fetch('<?php echo esc_url_raw( rest_url('attc/v1/jobs/') ); ?>' + encodeURIComponent(data.job_id) + '/retry', {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: { 'X-WP-Nonce': (window.wpApiSettings && wpApiSettings.nonce) ? wpApiSettings.nonce : '' }
                                })
                                .then(r => r.json())
                                .then(resp => {
                                    // reset UI
                                    const errBox = document.getElementById('attc-job-error');
                                    if (errBox) errBox.remove();
                                    if (progressWrap) progressWrap.classList.remove('is-hidden');
                                    startFakeProgress();
                                    // resume polling immediately
                                    if (!pollTimer) {
                                        pollTimer = setInterval(pollLatestJob, 5000);
                                    }
                                    pollLatestJob();
                                })
                                .catch(()=>{});
                            });
                            tools.appendChild(retryBtn);
                        }
                    }
                    if (data.status === 'done') {
                        renderFinalResult(data);
                    } else if (data.status === 'failed') {
                        if (progressWrap) progressWrap.classList.add('is-hidden');
                        const qn = document.getElementById('attc-queue-notice');
                        if (qn) { qn.classList.add('is-hidden'); }
                        // Tạo/hiện thông báo lỗi UI
                        const msg = data.error || 'Xử lý chuyển đổi thất bại. Vui lòng thử lại sau.';
                        let errBox = document.getElementById('attc-job-error');
                        if (!errBox) {
                            errBox = document.createElement('div');
                            errBox.id = 'attc-job-error';
                            errBox.className = 'attc-alert attc-error';
                            // chèn trước khu vực kết quả nếu có
                            if (resultContainer && resultContainer.parentNode) {
                                resultContainer.parentNode.insertBefore(errBox, resultContainer);
                            } else {
                                document.body.prepend(errBox);
                            }
                        }
                        errBox.textContent = msg;
                        errBox.scrollIntoView({ behavior:'smooth', block:'start' });
                        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
                    }
                })
                .catch(error => {
                    console.error('[ATTG_DEBUG] Error fetching job status:', error);
                });
        }

        // Bắt đầu polling mỗi 5s khi trang mở
        pollTimer = setInterval(pollLatestJob, 5000);
        // Gọi ngay lần đầu để nhanh thấy kết quả nếu đã xong
        pollLatestJob();

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });
        // Toggle visual state on drag
        ['dragenter','dragover'].forEach(evt => dropZone.addEventListener(evt, ()=>{ dropZone.classList.add('is-dragover'); }));
        ['dragleave','drop'].forEach(evt => dropZone.addEventListener(evt, ()=>{ dropZone.classList.remove('is-dragover'); }));
        // Handle actual drop to select file
        dropZone.addEventListener('drop', handleDrop, false);
        // Áp dụng trên toàn trang để tránh trình duyệt mở file khi thả ra ngoài dropzone
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            document.body.addEventListener(eventName, preventDefaults, false);
        });
        ['input','change','blur'].forEach(evt=>{
            if (trimStartMMSS) trimStartMMSS.addEventListener(evt, applyTrimConstraints);
            if (trimEndMMSS) trimEndMMSS.addEventListener(evt, applyTrimConstraints);
        });

        // Prevent submit if invalid (NaN or empty)
        uploadForm.addEventListener('submit', function(e){
            const s = toSeconds(trimStartMMSS ? trimStartMMSS.value : '0:00');
            const t = toSeconds(trimEndMMSS ? trimEndMMSS.value : '0:00');
            if (!isFinite(s) || !isFinite(t) || t < s || (fileDuration>0 && (s>fileDuration || t>fileDuration))) {
                e.preventDefault();
                if (trimWarn) {
                    trimWarn.classList.remove('is-hidden');
                    trimWarn.textContent = 'Vui lòng nhập thời gian hợp lệ trong phạm vi của audio.';
                }
                return false;
            }
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

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
                fileInfo.textContent = 'File đã chọn: ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
                submitButton.disabled = false;
                // Phân tích audio để hiện preview, trim và ước tính
                loadAudioForAnalysis(file);
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
            if (progressText) progressText.textContent = 'Đang xử lý (' + val + '%)';
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
        exit;
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
            .attc-auth-errors { color: #c0392b; background-color: #fbeae5; }
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
        $user_id = (int)$_GET['uid'];
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
    // Payment Section
    register_setting('attc_settings', 'attc_openai_api_key');
    register_setting('attc_settings', 'attc_openai_project_id');
    register_setting('attc_settings', 'attc_daily_free_limit_minutes'); // Đổi tên tùy chọn từ giây sang phút
    add_settings_section('attc_openai_section', 'Cài đặt OpenAI API', null, 'attc-settings');
    add_settings_field(
        'attc_openai_api_key',
        'OpenAI API Key',
        'attc_settings_field_html',
        'attc-settings',
        'attc_openai_section',
        ['name' => 'attc_openai_api_key', 'type' => 'password', 'desc' => 'Nhập API Key của bạn từ tài khoản OpenAI.']
    );
    add_settings_field(
        'attc_openai_project_id',
        'OpenAI Project ID',
        'attc_settings_field_html',
        'attc-settings',
        'attc_openai_section',
        ['name' => 'attc_openai_project_id', 'type' => 'text', 'desc' => 'Bắt buộc nếu dùng API Key dạng sk-proj- (Project-based API key).']
    );

    // General Settings Section
    add_settings_section('attc_general_section', 'Cài đặt chung', null, 'attc-settings');
    add_settings_field(
        'attc_daily_free_limit_minutes',
        'Giới hạn miễn phí hàng ngày (phút)',
        'attc_settings_field_html',
        'attc-settings',
        'attc_general_section',
        [
            'name' => 'attc_daily_free_limit_minutes',
            'type' => 'number',
            'desc' => 'Số phút tối đa cho mỗi lần upload của người dùng miễn phí. Mặc định: 5 (phút).',
            'default' => 5
        ]
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
    // MoMo settings
    register_setting('attc_settings', 'attc_momo_phone');
    register_setting('attc_settings', 'attc_momo_name');
    add_settings_field(
        'attc_momo_phone',
        'Số điện thoại MoMo',
        'attc_settings_field_html',
        'attc-settings',
        'attc_payment_section',
        ['name' => 'attc_momo_phone', 'type' => 'text', 'desc' => 'Số điện thoại tài khoản MoMo nhận tiền.']
    );
    add_settings_field(
        'attc_momo_name',
        'Tên MoMo',
        'attc_settings_field_html',
        'attc-settings',
        'attc_payment_section',
        ['name' => 'attc_momo_name', 'type' => 'text', 'desc' => 'Tên hiển thị của tài khoản MoMo.']
    );

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
