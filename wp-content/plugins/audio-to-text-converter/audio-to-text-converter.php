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

// ===== Webhook Helpers =====
function attc_verify_webhook_token($provided, $option_key) {
    $expected = get_option($option_key, '');
    $provided = (string) $provided;
    if (empty($expected) || empty($provided)) return false;
    // So sánh cố định độ dài để tránh timing attack đơn giản
    if (function_exists('hash_equals')) {
        return hash_equals($expected, $provided);
    }
    return $expected === $provided;
}

function attc_extract_user_from_note($note) {
    // Mặc định parse dạng: NANGCAP-<user_id>
    $note = (string) $note;
    if (preg_match('/NANGCAP-(\d+)/i', $note, $m)) {
        return (int) $m[1];
    }
    return 0;
}

// ===== Webhook MoMo =====
function attc_handle_webhook_momo() {
    // Xác thực token
    $token = isset($_REQUEST['token']) ? sanitize_text_field($_REQUEST['token']) : '';
    if (!attc_verify_webhook_token($token, 'attc_webhook_momo_token')) {
        status_header(403);
        wp_send_json_error(['message' => 'Invalid token'], 403);
    }

    // Lấy raw body & cho phép filter để parse theo chuẩn MoMo tích hợp
    $raw = file_get_contents('php://input');
    // Optional HMAC verification
    $hHeader = trim((string) get_option('attc_webhook_momo_hmac_header', ''));
    $hSecret = (string) get_option('attc_webhook_momo_hmac_secret', '');
    if (!empty($hHeader) && !empty($hSecret)) {
        $providedSig = isset($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $hHeader))]) ? trim($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $hHeader))]) : '';
        $calcSig = hash_hmac('sha256', $raw, $hSecret);
        $okSig = apply_filters('attc_verify_momo_signature', hash_equals($providedSig, $calcSig), $providedSig, $calcSig, $raw);
        if (!$okSig) { status_header(403); wp_send_json_error(['message' => 'Invalid signature'], 403); }
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) { $data = $_POST; }
    $parsed = apply_filters('attc_parse_momo_webhook', [
        'amount'  => isset($data['amount']) ? (int)$data['amount'] : 0,
        'note'    => isset($data['note']) ? (string)$data['note'] : '',
        'txid'    => isset($data['transId']) ? (string)$data['transId'] : '',
        'user_id' => 0,
        'currency'=> isset($data['currency']) ? (string)$data['currency'] : 'VND',
    ], $data);

    if (empty($parsed['user_id'])) {
        $parsed['user_id'] = attc_extract_user_from_note($parsed['note'] ?? '');
    }

    $user_id = (int) ($parsed['user_id'] ?? 0);
    $amount  = (int) ($parsed['amount'] ?? 0);
    if ($user_id <= 0 || $amount <= 0) {
        status_header(400);
        wp_send_json_error(['message' => 'Invalid payload'], 400);
    }

    // Ghi có vào ví
    $ok = attc_credit_wallet($user_id, $amount, [
        'reason' => 'webhook_momo',
        'note'   => (string) ($parsed['note'] ?? ''),
        'txid'   => (string) ($parsed['txid'] ?? ''),
        'raw'    => $data,
    ]);
    if (is_wp_error($ok)) {
        status_header(500);
        wp_send_json_error(['message' => $ok->get_error_message()], 500);
    }
    wp_send_json_success(['credited' => $amount, 'user_id' => $user_id]);
}

// ===== Default filters from saved options (MoMo/Bank/Patterns) =====
add_filter('attc_momo_info', function($info){
    $phone = get_option('attc_momo_phone', '');
    $name  = get_option('attc_momo_name', '');
    // Defaults if not set in options
    if (empty($phone)) $phone = '0772729789';
    if (empty($name))  $name  = 'Đinh Công Toàn';
    if (!empty($phone)) $info['phone'] = $phone;
    if (!empty($name))  $info['name']  = $name;
    return $info;
}, 5);

// ===== Casso specific parser (default integration) =====
// Casso thường gửi JSON dạng { data: [ { amount, description, tid/transaction_id/... } ] }
add_filter('attc_parse_bank_webhook', function($parsed, $raw){
    if (is_array($raw) && isset($raw['data']) && is_array($raw['data']) && !empty($raw['data'])) {
        $tx = $raw['data'][0];
        if (isset($tx['amount']) || isset($tx['description']) || isset($tx['content']) || isset($tx['tid']) || isset($tx['transaction_id'])) {
            if (isset($tx['amount'])) { $parsed['amount'] = (int) $tx['amount']; }
            if (isset($tx['description'])) { $parsed['note'] = (string) $tx['description']; }
            elseif (isset($tx['content'])) { $parsed['note'] = (string) $tx['content']; }
            if (isset($tx['tid'])) { $parsed['txid'] = (string) $tx['tid']; }
            elseif (isset($tx['transaction_id'])) { $parsed['txid'] = (string) $tx['transaction_id']; }
        }
    }
    return $parsed;
}, 5, 2);

add_filter('attc_momo_link_pattern', function($pattern){
    $opt = get_option('attc_momo_link_pattern', '');
    if (!empty($opt)) return $opt;
    // Default to nhantien link for provided phone
    return 'https://nhantien.momo.vn/0772729789?amount={amount}&message={note}';
}, 5);

add_filter('attc_vietqr_image_pattern', function($pattern){
    $opt = get_option('attc_vietqr_image_pattern', '');
    if (!empty($opt)) return $opt;
    return $pattern;
}, 5);

add_filter('attc_bank_accounts', function($banks){
    // If options set for ACB, ensure at least one ACB account is present or fill defaults
    $acc = get_option('attc_bank_acb_account_no', '');
    $name = get_option('attc_bank_acb_account_name', '');
    if (empty($acc)) $acc = '240306539';
    if (empty($name)) $name = 'Dinh Cong Toan';
    if (empty($acc) || empty($name)) return $banks;
    // If banks is empty or contains placeholder, set to ACB only
    if (!is_array($banks) || empty($banks)) {
        return [[
            'bank_code' => 'ACB',
            'bank_name' => 'ACB',
            'account_no'=> $acc,
            'account_name' => $name,
            'note' => 'NANGCAP-' . get_current_user_id(),
            'qr_img' => '',
        ]];
    }
    // Otherwise map any ACB placeholders
    foreach ($banks as &$b) {
        if (isset($b['bank_code']) && strtoupper($b['bank_code']) === 'ACB') {
            if (empty($b['account_no'])) $b['account_no'] = $acc;
            if (empty($b['account_name'])) $b['account_name'] = $name;
        }
    }
    return $banks;
}, 5);

// ===== Webhook Bank/VietQR =====
function attc_handle_webhook_bank() {
    // Xác thực token
    $token = isset($_REQUEST['token']) ? sanitize_text_field($_REQUEST['token']) : '';
    if (!attc_verify_webhook_token($token, 'attc_webhook_bank_token')) {
        status_header(403);
        wp_send_json_error(['message' => 'Invalid token'], 403);
    }

    $raw = file_get_contents('php://input');
    // Optional HMAC verification
    $hHeader = trim((string) get_option('attc_webhook_bank_hmac_header', ''));
    $hSecret = (string) get_option('attc_webhook_bank_hmac_secret', '');
    if (!empty($hHeader) && !empty($hSecret)) {
        $providedSig = isset($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $hHeader))]) ? trim($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $hHeader))]) : '';
        $calcSig = hash_hmac('sha256', $raw, $hSecret);
        $okSig = apply_filters('attc_verify_bank_signature', hash_equals($providedSig, $calcSig), $providedSig, $calcSig, $raw);
        if (!$okSig) { status_header(403); wp_send_json_error(['message' => 'Invalid signature'], 403); }
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) { $data = $_POST; }
    $parsed = apply_filters('attc_parse_bank_webhook', [
        'amount'  => isset($data['amount']) ? (int)$data['amount'] : 0,
        'note'    => isset($data['description']) ? (string)$data['description'] : (isset($data['note']) ? (string)$data['note'] : ''),
        'txid'    => isset($data['tid']) ? (string)$data['tid'] : (isset($data['transId']) ? (string)$data['transId'] : ''),
        'user_id' => 0,
        'currency'=> isset($data['currency']) ? (string)$data['currency'] : 'VND',
    ], $data);

    if (empty($parsed['user_id'])) {
        $parsed['user_id'] = attc_extract_user_from_note($parsed['note'] ?? '');
    }

    $user_id = (int) ($parsed['user_id'] ?? 0);
    $amount  = (int) ($parsed['amount'] ?? 0);
    if ($user_id <= 0 || $amount <= 0) {
        status_header(400);
        wp_send_json_error(['message' => 'Invalid payload'], 400);
    }

    $ok = attc_credit_wallet($user_id, $amount, [
        'reason' => 'webhook_bank',
        'note'   => (string) ($parsed['note'] ?? ''),
        'txid'   => (string) ($parsed['txid'] ?? ''),
        'raw'    => $data,
    ]);
    if (is_wp_error($ok)) {
        status_header(500);
        wp_send_json_error(['message' => $ok->get_error_message()], 500);
    }
    wp_send_json_success(['credited' => $amount, 'user_id' => $user_id]);
}

// ===== Admin: Trang nạp ví thủ công =====
function attc_wallet_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Bạn không có quyền truy cập.');
    }

    $message = '';
    if (isset($_POST['attc_wallet_submit'])) {
        check_admin_referer('attc_wallet_action', 'attc_wallet_nonce');
        $user_field = sanitize_text_field($_POST['attc_wallet_user'] ?? '');
        $amount = (int) ($_POST['attc_wallet_amount'] ?? 0);
        $type = sanitize_text_field($_POST['attc_wallet_type'] ?? 'credit');
        $note = sanitize_text_field($_POST['attc_wallet_note'] ?? '');

        $user = false;
        if (is_numeric($user_field)) { $user = get_user_by('id', (int)$user_field); }
        if (!$user) { $user = get_user_by('email', $user_field); }
        if (!$user) { $user = get_user_by('login', $user_field); }

        if (!$user) {
            $message = '<div class="notice notice-error"><p>Không tìm thấy người dùng.</p></div>';
        } elseif ($amount <= 0) {
            $message = '<div class="notice notice-error"><p>Số tiền phải lớn hơn 0.</p></div>';
        } else {
            if ($type === 'debit') {
                $res = attc_charge_wallet($user->ID, $amount, ['reason' => 'admin_debit', 'note' => $note]);
            } else {
                $res = attc_credit_wallet($user->ID, $amount, ['reason' => 'admin_credit', 'note' => $note]);
            }
            if (is_wp_error($res)) {
                $message = '<div class="notice notice-error"><p>' . esc_html($res->get_error_message()) . '</p></div>';
            } else {
                $message = '<div class="notice notice-success"><p>Thao tác thành công cho user ID ' . (int)$user->ID . '.</p></div>';
            }
        }
    }

    ?>
    <div class="wrap">
        <h1>Ví người dùng</h1>
        <?php echo $message; ?>
        <form method="post">
            <?php wp_nonce_field('attc_wallet_action', 'attc_wallet_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="attc_wallet_user">Người dùng (ID / Email / Username)</label></th>
                    <td><input type="text" name="attc_wallet_user" id="attc_wallet_user" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_wallet_amount">Số tiền (VND)</label></th>
                    <td><input type="number" min="1" name="attc_wallet_amount" id="attc_wallet_amount" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_wallet_type">Loại</label></th>
                    <td>
                        <select name="attc_wallet_type" id="attc_wallet_type">
                            <option value="credit">Cộng tiền (+)</option>
                            <option value="debit">Trừ tiền (-)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_wallet_note">Ghi chú</label></th>
                    <td><input type="text" name="attc_wallet_note" id="attc_wallet_note" class="regular-text"></td>
                </tr>
            </table>
            <?php submit_button('Thực hiện', 'primary', 'attc_wallet_submit'); ?>
        </form>
    </div>
    <?php
}

// ===== Trang Nâng Cấp: Pricing + Thanh toán MoMo/Ngân hàng =====
function attc_upgrade_shortcode() {
    // Giá/phút hiện hành
    $price_per_min = (int) attc_get_price_per_minute();

    // Mặc định: gói nạp ví dựa trên 500 VND/phút
    // 20k ~ 40 phút, 50k ~ 100 phút, 100k ~ 200 phút
    $default_plans = [
        [ 'id' => 'topup-20k',  'title' => 'Nạp 20.000đ',  'amount' => 20000 ],
        [ 'id' => 'topup-50k',  'title' => 'Nạp 50.000đ',  'amount' => 50000 ],
        [ 'id' => 'topup-100k', 'title' => 'Nạp 100.000đ', 'amount' => 100000, 'highlight' => true ],
    ];
    $plans = apply_filters('attc_upgrade_plans', $default_plans, $price_per_min);

    $momo = apply_filters('attc_momo_info', [
        'phone' => '',        // SĐT/ví MoMo nhận
        'name'  => '',        // Tên chủ ví
        'note'  => 'NANGCAP-' . get_current_user_id(), // nội dung CK để đối soát
        'qr_img'=> '',        // URL ảnh QR MoMo (tùy chọn, nếu có sẵn)
    ]);

    // Mẫu liên kết/thanh toán MoMo (có thể override để tạo deeplink thực sự)
    // Dùng placeholder {amount} và {note}
    $momo_link_pattern = apply_filters('attc_momo_link_pattern', '');

    $banks = apply_filters('attc_bank_accounts', [
        [
            'bank_code' => 'ACB',     // Ví dụ: 'ACB', 'VCB', 'TCB'
            'bank_name' => 'ACB',
            'account_no'=> '',
            'account_name' => '',
            'note' => 'NANGCAP-' . get_current_user_id(),
            'qr_img' => '', // Nếu để rỗng, sẽ tự tạo URL VietQR theo pattern bên dưới
        ],
    ]);

    // Mẫu ảnh VietQR: https://img.vietqr.io/image/{bank_code}-{account_no}-compact.png?amount={amount}&addInfo={note}&accountName={account_name}
    $vietqr_pattern = apply_filters('attc_vietqr_image_pattern', 'https://img.vietqr.io/image/{bank_code}-{account_no}-compact.png?amount={amount}&addInfo={note}&accountName={account_name}');

    ob_start();
    ?>
    <div class="attc-upgrade">
        <h2>Nâng cấp tài khoản</h2>
        <p class="attc-upgrade-sub">Dành cho sinh viên Việt Nam: giá rẻ, dễ thanh toán qua MoMo hoặc chuyển khoản ngân hàng.</p>

        <div class="attc-pricing">
            <?php foreach ($plans as $plan): $amt = (int)($plan['amount'] ?? 0); $mins = $price_per_min > 0 ? floor($amt / $price_per_min) : 0; ?>
            <div class="attc-card <?php echo !empty($plan['highlight']) ? 'is-hot' : ''; ?>">
                <div class="attc-card-title"><?php echo esc_html($plan['title']); ?></div>
                <div class="attc-card-price">
                    <strong><?php echo number_format($amt, 0, ',', '.'); ?>đ</strong>
                    <span>≈ <?php echo (int)$mins; ?> phút</span>
                </div>
                <a class="attc-plan-select" href="#attc-payments" data-plan="<?php echo esc_attr($plan['id']); ?>" data-amount="<?php echo (int)$amt; ?>">Chọn gói này</a>
            </div>
            <?php endforeach; ?>
        </div>

        <div id="attc-payments" class="attc-payments">
            <h3>Thanh toán</h3>
            <p>Vui lòng chuyển khoản theo một trong các phương thức dưới đây và ghi đúng nội dung chuyển khoản để hệ thống tự động đối soát.</p>

            <div class="attc-pay-grid" data-price-per-min="<?php echo (int)$price_per_min; ?>">
                <div class="attc-pay-box is-disabled" id="attc-pay-momo">
                    <div class="attc-pay-title">MoMo</div>
                    <?php if (!empty($momo['qr_img'])): ?>
                        <img class="attc-qr" src="<?php echo esc_url($momo['qr_img']); ?>" alt="QR MoMo">
                    <?php endif; ?>
                    <div class="attc-pay-line"><span>SĐT:</span> <strong><?php echo esc_html($momo['phone']); ?></strong></div>
                    <div class="attc-pay-line"><span>Tên ví:</span> <strong><?php echo esc_html($momo['name']); ?></strong></div>
                    <div class="attc-pay-line"><span>Số tiền:</span> <code id="attc-amount-display">0</code> <button class="attc-copy" data-target="#attc-amount-display">Copy</button></div>
                    <div class="attc-pay-line"><span>Nội dung:</span> <code id="attc-momo-note"><?php echo esc_html($momo['note']); ?></code> <button class="attc-copy" data-target="#attc-momo-note">Copy</button></div>
                    <div class="attc-pay-line"><a id="attc-momo-paylink" class="attc-pay-btn is-disabled" href="#" target="_blank" rel="noopener" aria-disabled="true">Mở MoMo để thanh toán</a></div>
                </div>

                <?php foreach ($banks as $i => $b): ?>
                <div class="attc-pay-box is-disabled" id="attc-pay-bank-<?php echo $i; ?>">
                    <div class="attc-pay-title"><?php echo esc_html($b['bank_name'] ?: $b['bank_code']); ?></div>
                    <?php
                        $qr_src = $b['qr_img'];
                        if (empty($qr_src)) {
                            $qr_src = strtr($vietqr_pattern, [
                                '{bank_code}' => urlencode($b['bank_code']),
                                '{account_no}' => urlencode($b['account_no']),
                                '{amount}' => '0', // sẽ cập nhật bằng JS khi chọn gói
                                '{note}' => rawurlencode($b['note']),
                                '{account_name}' => rawurlencode($b['account_name']),
                            ]);
                        }
                    ?>
                    <img class="attc-qr" data-base="<?php echo esc_attr($vietqr_pattern); ?>" data-bank="<?php echo esc_attr($b['bank_code']); ?>" data-acc="<?php echo esc_attr($b['account_no']); ?>" data-name="<?php echo esc_attr($b['account_name']); ?>" data-note="<?php echo esc_attr($b['note']); ?>" src="<?php echo esc_url($qr_src); ?>" alt="VietQR">
                    <div class="attc-pay-line"><span>Số TK:</span> <strong><?php echo esc_html($b['account_no']); ?></strong></div>
                    <div class="attc-pay-line"><span>Chủ TK:</span> <strong><?php echo esc_html($b['account_name']); ?></strong></div>
                    <div class="attc-pay-line"><span>Nội dung:</span> <code id="attc-bank-note-<?php echo $i; ?>"><?php echo esc_html($b['note']); ?></code> <button class="attc-copy" data-target="#attc-bank-note-<?php echo $i; ?>">Copy</button></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="attc-pay-help">
                <p>Sau khi thanh toán, hệ thống sẽ kích hoạt trong vài phút nếu nội dung chuyển khoản khớp. Nếu cần hỗ trợ nhanh, vui lòng liên hệ quản trị viên và cung cấp ảnh biên lai.</p>
            </div>
        </div>
    </div>

    <style>
        .attc-upgrade{max-width:920px;margin:0 auto;padding:20px}
        .attc-upgrade-sub{color:#4b5563;margin:6px 0 18px}
        .attc-pricing{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
        .attc-card{border:1px solid #e5e7eb;border-radius:10px;padding:16px;background:#fff}
        .attc-card.is-hot{border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.12)}
        .attc-card-title{font-weight:700;margin-bottom:8px}
        .attc-card-price{font-size:20px;margin:6px 0 10px}
        .attc-card-price strong{font-size:24px;color:#111827}
        .attc-card-price span{color:#6b7280;font-size:13px;margin-left:4px}
        .attc-card-feats{list-style:none;padding:0;margin:0 0 12px}
        .attc-card-feats li{padding-left:18px;position:relative;margin:6px 0}
        .attc-card-feats li:before{content:'✓';position:absolute;left:0;color:#16a34a}
        .attc-plan-select, .attc-pay-btn{display:inline-block;background:#2271b1;color:#fff !important;padding:10px 12px;border-radius:6px;text-decoration:none;font-weight:600}
        .attc-plan-select:hover, .attc-pay-btn:hover{background:#1b5c8f}
        .attc-payments{margin-top:22px}
        .attc-pay-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:12px}
        .attc-pay-box{border:1px solid #e5e7eb;border-radius:10px;padding:14px;background:#fff; position:relative}
        .attc-pay-box.is-disabled{opacity:.6}
        .attc-pay-btn.is-disabled{pointer-events:none; opacity:.7}
        .attc-pay-title{font-weight:700;margin-bottom:8px}
        .attc-qr{width:100%;max-width:220px;border-radius:8px;border:1px solid #e5e7eb}
        .attc-pay-line{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:6px 0}
        .attc-pay-line span{color:#374151;min-width:80px}
        .attc-copy{background:#f3f4f6;border:1px solid #e5e7eb;border-radius:6px;padding:4px 8px;cursor:pointer}
        .attc-copy:hover{background:#e5e7eb}
        code{background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;padding:2px 4px}
        .attc-pay-help{margin-top:10px;color:#4b5563}
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        var payGrid = document.querySelector('.attc-pay-grid');
        var pricePerMin = 0;
        if (payGrid && payGrid.getAttribute('data-price-per-min')) {
            pricePerMin = parseInt(payGrid.getAttribute('data-price-per-min') || '0', 10) || 0;
        }
        var momoLinkPattern = <?php echo wp_json_encode($momo_link_pattern); ?>;
        var amountDisplay = document.getElementById('attc-amount-display');
        var momoNoteEl = document.getElementById('attc-momo-note');
        var momoPayLink = document.getElementById('attc-momo-paylink');

        function updateAmount(amount){
            if (amountDisplay){ amountDisplay.textContent = (amount||0).toLocaleString('vi-VN'); }
            var hasAmount = (amount||0) > 0;
            // Update MoMo link
            if (momoPayLink){
                var note = momoNoteEl ? (momoNoteEl.textContent || '') : '';
                if (momoLinkPattern){
                    var href = momoLinkPattern.replace('{amount}', String(amount||0)).replace('{note}', encodeURIComponent(note||''));
                    momoPayLink.setAttribute('href', href);
                } else {
                    momoPayLink.setAttribute('href', '#');
                }
                if (hasAmount){ momoPayLink.classList.remove('is-disabled'); momoPayLink.removeAttribute('aria-disabled'); }
                else { momoPayLink.classList.add('is-disabled'); momoPayLink.setAttribute('aria-disabled','true'); }
            }
            // Update VietQR images
            var imgs = document.querySelectorAll('.attc-qr[data-base]');
            for (var i=0;i<imgs.length;i++){
                var img = imgs[i];
                var base = img.getAttribute('data-base');
                var bank = img.getAttribute('data-bank');
                var acc  = img.getAttribute('data-acc');
                var name = img.getAttribute('data-name');
                var note = img.getAttribute('data-note');
                if (!base || !bank || !acc) continue;
                var url = base
                    .replace('{bank_code}', encodeURIComponent(bank))
                    .replace('{account_no}', encodeURIComponent(acc))
                    .replace('{amount}', String(amount||0))
                    .replace('{note}', encodeURIComponent(note||''))
                    .replace('{account_name}', encodeURIComponent(name||''));
                img.setAttribute('src', url);
            }
            // Enable/disable pay boxes
            var momoBox = document.getElementById('attc-pay-momo');
            if (momoBox){ if (hasAmount) momoBox.classList.remove('is-disabled'); else momoBox.classList.add('is-disabled'); }
            var bankBoxes = document.querySelectorAll('[id^="attc-pay-bank-"]');
            for (var b=0;b<bankBoxes.length;b++){
                if (hasAmount) bankBoxes[b].classList.remove('is-disabled'); else bankBoxes[b].classList.add('is-disabled');
            }
        }

        var planButtons = document.querySelectorAll('.attc-plan-select[data-amount]');
        for (var j=0;j<planButtons.length;j++){
            planButtons[j].addEventListener('click', function(){
                var id = this.getAttribute('data-plan');
                var amt = parseInt(this.getAttribute('data-amount')||'0',10) || 0;
                try { sessionStorage.setItem('attc_chosen_plan', id || ''); } catch(e){}
                try { sessionStorage.setItem('attc_chosen_amount', String(amt)); } catch(e){}
                updateAmount(amt);
            });
        }
        // Nếu trước đó đã chọn plan, khôi phục
        var prevAmt = 0;
        try { prevAmt = parseInt(sessionStorage.getItem('attc_chosen_amount')||'0',10) || 0; } catch(e){ prevAmt = 0; }
        if (prevAmt>0) updateAmount(prevAmt);

        var copyBtns = document.querySelectorAll('.attc-copy');
        for (var k=0;k<copyBtns.length;k++){
            copyBtns[k].addEventListener('click', function(){
                var sel = this.getAttribute('data-target');
                var el = document.querySelector(sel);
                if (!el) return;
                var text = el.textContent || el.innerText || '';
                navigator.clipboard.writeText(text).then(()=>{
                    this.textContent = 'Đã copy';
                    setTimeout(()=>{ this.textContent = 'Copy'; }, 1500);
                });
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}

// ===== Khởi tạo plugin =====
function attc_init() {
    add_shortcode('audio_to_text_form', 'attc_display_form');
    // Shortcodes front-end đăng nhập/đăng ký
    add_shortcode('attc_login_form', 'attc_login_form');
    add_shortcode('attc_register_form', 'attc_register_form');
    // Shortcode lịch sử chuyển đổi
    add_shortcode('attc_history', 'attc_history_shortcode');
    // Shortcode quên mật khẩu
    add_shortcode('attc_forgot_form', 'attc_forgot_form');
    // Shortcode: đặt lại mật khẩu front-end
    add_shortcode('attc_reset_password_form', 'attc_reset_password_form');
    // Shortcode trang nâng cấp
    add_shortcode('attc_upgrade', 'attc_upgrade_shortcode');

    // Xử lý submit (frontend)
    add_action('admin_post_attc_process_audio', 'attc_process_audio');
    add_action('admin_post_nopriv_attc_process_audio', 'attc_process_audio');

    // Endpoint tải file Word (.doc)
    add_action('admin_post_attc_download_doc', 'attc_download_doc');
    add_action('admin_post_nopriv_attc_download_doc', 'attc_download_doc');

    // Handlers cho login/đăng ký front-end
    add_action('admin_post_nopriv_attc_do_login', 'attc_do_login');
    add_action('admin_post_attc_do_login', 'attc_do_login');
    add_action('admin_post_nopriv_attc_do_register', 'attc_do_register');
    // Handler quên mật khẩu
    add_action('admin_post_nopriv_attc_do_forgot', 'attc_do_forgot');
    // Handler đặt lại mật khẩu front-end
    add_action('admin_post_nopriv_attc_do_reset', 'attc_do_reset');
    add_action('admin_post_attc_do_reset', 'attc_do_reset');

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
    if (!get_page_by_path('quen-mat-khau')) {
        wp_insert_post([
            'post_title'   => 'Quên mật khẩu',
            'post_name'    => 'quen-mat-khau',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[attc_forgot_form]'
        ]);
        $created = true;
    }
    if (!get_page_by_path('dat-lai-mat-khau')) {
        wp_insert_post([
            'post_title'   => 'Đặt lại mật khẩu',
            'post_name'    => 'dat-lai-mat-khau',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[attc_reset_password_form]'
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
    // Trang nâng cấp
    if (!get_page_by_path('nang-cap')) {
        wp_insert_post([
            'post_title'   => 'Nâng cấp tài khoản',
            'post_name'    => 'nang-cap',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[attc_upgrade]'
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
        // Banner tài khoản: nếu đã đăng nhập, hiển thị email nổi bật trên đầu trang chuyển đổi
        if (is_user_logged_in()) {
            $cu = wp_get_current_user();
            if (!empty($cu->user_email)) {
                echo '<div class="attc-account-banner">Tài khoản: <strong>' . esc_html($cu->user_email) . '</strong></div>';
            }
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

        <?php
        // Hiển thị số lượt còn lại trong ngày cho tài khoản miễn phí
        if (is_user_logged_in()) {
            $uid = get_current_user_id();
            $quota_info = attc_get_daily_remaining($uid);
            if (!$quota_info['unlimited']) {
                if (intval($quota_info['remaining']) <= 0) {
                    // Hết lượt => thông báo + nút nâng cấp và ẩn form
                    echo '<div class="attc-alert attc-alert-error">Tài khoản miễn phí chỉ được tải lên 1 file ghi âm 2 phút mỗi ngày. Vui lòng thử lại vào ngày mai, hoặc Nâng Cấp Tài Khoản ngay để chuyển đổi không giới hạn!</div>';
                    $upgrade_url = apply_filters('attc_upgrade_url', home_url('/nang-cap/'), $uid);
                    echo '<p><a class="attc-login-btn" href="' . esc_url($upgrade_url) . '">Nâng cấp tài khoản</a></p>';
                    // Dừng render form
                    echo '</div>';
                    return ob_get_clean();
                } else {
                    //echo '<div class="attc-quota">Bạn còn ' . intval($quota_info['remaining']) . '/' . intval($quota_info['limit']) . ' lượt tải miễn phí hôm nay.</div>';
                    echo '<div class="attc-quota">Bạn còn ' . intval($quota_info['remaining']).' lượt tải miễn phí hôm nay.</div>';

                }
            }
        }
        ?>

<a class="attc-upgrade-btn" href="<?php echo esc_url(apply_filters('attc_upgrade_url', '#', get_current_user_id())); ?>">Nâng cấp tài khoản chuyển đổi không giới hạn</a>


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

        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="attc_process_audio">
            <?php wp_nonce_field('attc_upload_nonce', 'attc_nonce'); ?>

            <div class="form-group">
                <label for="audio_file">Chọn file ghi âm (MP3, WAV, M4A; miễn phí tối đa 2 phút):</label>
                <div class="attc-dropzone" id="attcDropzone">
                    <div class="attc-dropzone__inner">
                        <div class="attc-dropzone__icon" aria-hidden="true">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none"><path d="M19 15v4a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-4" stroke="#2271b1" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"></path><path d="M12 3v12" stroke="#2271b1" stroke-width="1.6" stroke-linecap="round"></path><path d="M8 9l4-4 4 4" stroke="#2271b1" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                        </div>
                        <div class="attc-dropzone__text"><strong>Kéo & Thả</strong> file vào đây hoặc <span class="attc-link">chọn file</span></div>
                        <div class="attc-dropzone__hint">Hỗ trợ: MP3, WAV, M4A · Tối đa 2 phút</div>
                    </div>
                    <input class="attc-dropzone__input" type="file" name="audio_file" id="audio_file" accept="audio/mp3,audio/mpeg,audio/wav,audio/x-wav,audio/m4a,audio/x-m4a,audio/mp4" required>
                </div>
                <div class="attc-file-info" id="attcFileInfo"></div>
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
        .form-actions {margin-top: 12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap}
        .button.button-primary {background: #2271b1; color: #fff; border: none; padding: 10px 16px; border-radius: 4px; cursor: pointer;}
        .button.button-primary:disabled {opacity: .7; cursor: not-allowed;}
        /* Nút tải .doc kiểu button download */
        .attc-download-btn {display:inline-flex; align-items:center; gap:8px; background:#28a745; border:1px solid #1e7e34; padding:10px 14px; border-radius:4px; color:#fff !important; text-decoration:none; font-weight:600;}
        .attc-download-btn:hover {background:#218838; border-color:#1c7430;}
        .attc-download-btn svg {width:16px; height:16px; fill: currentColor;}
        .attc-top-nav{display:flex; gap:12px; margin:8px 0 16px}
        .attc-nav-link{display:inline-block; padding:8px 12px; border:1px solid #cfd4d9; border-radius:6px; text-decoration:none; color:#2271b1}
        .attc-nav-link:hover{background:#f3f6f9}
        /* Nút nâng cấp */
        .attc-upgrade-btn{display: inline-block;background: #f59e0b;color: #1f2937 !important;padding: 8px;border-radius: 4px;text-decoration: none;font-weight: 700;border: 1px solid #d97706;font-size: 16px;/* height: 36px; */margin-bottom: 20px;text-transform: uppercase;font-weight: bold;width: 100%;text-align: center;}
        .attc-upgrade-btn:hover{background:#f59e0bdd}
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        const wrapper = document.querySelector('.audio-to-text-converter');
        const form = wrapper ? wrapper.querySelector('form') : null;
        if (!form) return;

        const fileInput = document.getElementById('audio_file');
        const dropzone = document.getElementById('attcDropzone');
        const fileInfo = document.getElementById('attcFileInfo');

        const formatBytes = (bytes) => {
            if (bytes === 0) return '0 B';
            const k = 1024, sizes = ['B','KB','MB','GB'];
            const i = Math.floor(Math.log(bytes)/Math.log(k));
            return parseFloat((bytes/Math.pow(k,i)).toFixed(2)) + ' ' + sizes[i];
        };
        const renderFileInfo = (file) => {
            if (!file) { fileInfo.textContent = ''; return; }
            fileInfo.innerHTML = '<strong>Đã chọn:</strong> ' +
                '<span>' + (file.name || '') + '</span>' +
                ' · <span>' + formatBytes(file.size || 0) + '</span>';
        };

        if (dropzone) {
            const onBrowse = () => fileInput && fileInput.click();
            dropzone.addEventListener('click', (e) => {
                const isLink = e.target && e.target.classList && e.target.classList.contains('attc-link');
                if (isLink || e.target === dropzone || dropzone.contains(e.target)) {
                    onBrowse();
                }
            });
            ['dragenter','dragover'].forEach(ev => dropzone.addEventListener(ev, (e)=>{
                e.preventDefault(); e.stopPropagation();
                dropzone.classList.add('is-dragover');
            }));
            ['dragleave','dragend','drop'].forEach(ev => dropzone.addEventListener(ev, ()=>{
                dropzone.classList.remove('is-dragover');
            }));
            dropzone.addEventListener('drop', (e)=>{
                e.preventDefault(); e.stopPropagation();
                if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
                const file = e.dataTransfer.files[0];
                fileInput.files = e.dataTransfer.files; // giữ nguyên flow backend
                renderFileInfo(file);
            });
        }

        fileInput.addEventListener('change', function(){
            const f = this.files && this.files[0];
            renderFileInfo(f);
        });

        // Bỏ kiểm tra thời lượng tại script này để tránh trùng lặp cảnh báo.
        // Kiểm tra thời lượng 120s được thực hiện trong script phía dưới.
    });
    </script>

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

            // Không kiểm tra thời lượng ở client nữa để server quyết định theo loại tài khoản (miễn phí/paid)
            // Tránh chặn các trường hợp tài khoản trả phí muốn upload > 2 phút.
            submitBtn.textContent = 'Đang gửi...';
            // Cho submit bình thường, server sẽ kiểm tra giới hạn phù hợp
            
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

// Chuẩn hoá xuống dòng: gộp nhiều newline liên tiếp thành 1 để tránh nhiều <br> khi hiển thị
if (!function_exists('attc_normalize_text')) {
function attc_normalize_text($text) {
    // Chuẩn hoá line ending sang \n trước
    $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
    // Gộp 2+ dòng trống liên tiếp thành 1 xuống dòng
    $text = preg_replace('/\n{2,}/', "\n", $text);
    // Trim khoảng trắng thừa ở đầu/cuối
    return trim($text);
}
}

// ===== Xử lý upload và gọi API =====
function attc_process_audio() {
    if (!isset($_POST['attc_nonce']) || !wp_verify_nonce($_POST['attc_nonce'], 'attc_upload_nonce')) {
        $back = attc_safe_referer_url();
        wp_redirect(add_query_arg('attc_error', urlencode('Lỗi bảo mật. Vui lòng thử lại.'), $back));
        exit;
    }

    // Chặn phía server nếu chưa đăng nhập
    if (!is_user_logged_in()) {
        $back = attc_safe_referer_url();
        wp_redirect(add_query_arg('attc_error', urlencode('Vui lòng đăng nhập để tải lên và chuyển đổi.'), $back));
        exit;
    }

    // Giới hạn: mỗi user miễn phí chỉ được upload 1 file/ngày (Paid sẽ bypass trong hàm)
    $user_id = get_current_user_id();
    $quota = attc_check_and_consume_daily_upload($user_id);
    if (is_wp_error($quota)) {
        $back = attc_safe_referer_url();
        wp_redirect(add_query_arg('attc_error', urlencode($quota->get_error_message()), $back));
        exit;
    }

    if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
        $back = attc_safe_referer_url();
        wp_redirect(add_query_arg('attc_error', urlencode('Có lỗi khi tải lên file. Vui lòng thử lại.'), $back));
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
    // Áp dụng giới hạn theo loại tài khoản
    $is_paid = attc_is_paid_user($user_id);
    if (!$is_paid) {
        // Miễn phí: giữ giới hạn 2 phút
        if ($duration > 120) {
            @unlink($target);
            $back = attc_safe_referer_url();
            wp_redirect(add_query_arg('attc_error', urlencode('File ghi âm vượt quá giới hạn 2 phút đối với tài khoản miễn phí.'), $back));
            exit;
        }
    } else {
        // Trả phí: bỏ giới hạn 2 phút, áp trần tối đa theo cấu hình (mặc định 30 phút)
        $paid_max_minutes = attc_get_paid_max_minutes();
        $max_seconds = max(1, intval($paid_max_minutes)) * 60;
        if ($duration > $max_seconds) {
            @unlink($target);
            $back = attc_safe_referer_url();
            wp_redirect(add_query_arg('attc_error', urlencode('File vượt quá giới hạn tối đa ' . intval($paid_max_minutes) . ' phút cho mỗi lần tải.'), $back));
            exit;
        }
    }

    // Tính chi phí (nếu trả phí) theo phút, làm tròn lên phút
    $billed_minutes = (int) ceil($duration / 60);
    $price_per_min = (int) attc_get_price_per_minute();
    $cost_vnd = $is_paid ? ($billed_minutes * $price_per_min) : 0;
    if ($is_paid) {
        $balance = attc_get_wallet_balance($user_id);
        if ($balance < $cost_vnd) {
            @unlink($target);
            $back = attc_safe_referer_url();
            $msg = 'Số dư ví không đủ để xử lý. Cần ' . number_format($cost_vnd, 0, ',', '.') . 'đ, số dư hiện tại ' . number_format($balance, 0, ',', '.') . 'đ. Vui lòng nạp thêm tại trang Nâng cấp.';
            wp_redirect(add_query_arg('attc_error', urlencode($msg), $back));
            exit;
        }
    }

    // Gọi OpenAI Whisper
    $transcription = attc_transcribe_audio($target);

    // Xoá file tạm
    @unlink($target);

    if (is_wp_error($transcription)) {
        $back = attc_safe_referer_url();
        wp_redirect(add_query_arg('attc_error', urlencode('Lỗi khi chuyển đổi: ' . $transcription->get_error_message()), $back));
        exit;
    }

    // Nếu là tài khoản trả phí và chuyển đổi thành công => trừ ví
    if ($is_paid && $cost_vnd > 0) {
        $charge = attc_charge_wallet($user_id, $cost_vnd, [
            'reason'   => 'transcription',
            'seconds'  => (int)$duration,
            'minutes'  => (int)$billed_minutes,
            'filename' => isset($file['name']) ? sanitize_file_name($file['name']) : '',
        ]);
        if (is_wp_error($charge)) {
            // Lỗi khi trừ ví (hiếm). Không huỷ kết quả nhưng cảnh báo.
            // Có thể gửi admin_email để xử lý thủ công.
        }
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

    $back = attc_safe_referer_url();
    $redirect_url = add_query_arg([
        'attc_result' => urlencode($final_text),
        'attc_token'  => $token,
    ], $back);

    wp_redirect($redirect_url);
    exit;
}

// Trả về URL referrer an toàn để redirect về trang frontend thay vì admin-post.php
function attc_safe_referer_url() {
    $fallback = home_url('/chuyen-doi-giong-noi/');
    $ref = wp_get_referer();
    if (empty($ref)) return $fallback;
    // Tránh quay lại admin-post.php
    if (false !== strpos($ref, 'admin-post.php')) return $fallback;
    return $ref;
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
    // Chuẩn hoá văn bản: gộp nhiều dòng trống liên tiếp thành 1
    if (!function_exists('attc_normalize_text')) {
        // no-op nếu helper chưa sẵn (sẽ được định nghĩa bên dưới)
    }
    $normalized = function_exists('attc_normalize_text') ? attc_normalize_text($text) : $text;
    $history[] = [
        'time' => time(),
        'text' => $normalized,
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
        <?php 
        $current_user  = wp_get_current_user();
        $current_email = isset($current_user->user_email) ? $current_user->user_email : '';
        ?>
        <?php if (!empty($current_email)) : ?>
        <div class="attc-account-banner">Tài khoản: <strong><?php echo esc_html($current_email); ?></strong></div>
        <?php endif; ?>
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
                    <span class="attc-history-time"><?php echo esc_html(wp_date('d/m/Y H:i', $ts)); ?></span>
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
        /* Tăng khoảng cách và style cho hai nút điều hướng trên cùng */
        .attc-top-nav{display:flex; gap:16px; margin:8px 0 16px; flex-wrap:wrap}
        .attc-nav-link{display:inline-block; padding:8px 12px; border:1px solid #cfd4d9; border-radius:6px; text-decoration:none; color:#2271b1}
        .attc-nav-link:hover{background:#f3f6f9}
        /* Banner tài khoản đang đăng nhập */
        .attc-account-banner{background:#fff7ed; border:1px solid #fed7aa; color:#7c2d12; padding:10px 12px; border-radius:6px; margin:8px 0 12px; font-weight:600}
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

    $filename = 'chuyen-doi-' . wp_date('Ymd-His') . '.doc';
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
         <?php if (isset($_GET['attc_notice'])): ?>
             <div class="attc-alert attc-alert-success"><?php echo esc_html(urldecode(sanitize_text_field($_GET['attc_notice']))); ?></div>
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
         <p class="attc-card__footer">Chưa có tài khoản? <a href="<?php echo esc_url(home_url('/dang-ky/')); ?>">Đăng ký</a>   <a style="margin-left:10px;" href="<?php echo esc_url(home_url('/quen-mat-khau/')); ?>">Quên mật khẩu?</a></p>
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
        $fallback = attc_login_fallback_url();
        wp_redirect(add_query_arg('attc_error', urlencode('Yêu cầu không hợp lệ.'), $fallback));
        exit;
    }
    // reCAPTCHA (nếu được cấu hình)
    if (!attc_verify_recaptcha_if_configured()) {
        $fallback = attc_login_fallback_url();
        wp_redirect(add_query_arg('attc_error', urlencode('Vui lòng xác nhận CAPTCHA.'), $fallback));
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
        $fallback = attc_login_fallback_url();
        wp_redirect(add_query_arg('attc_error', urlencode($user->get_error_message()), $fallback));
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

// ===== Handler: Quên mật khẩu =====
function attc_do_forgot() {
    if (!isset($_POST['attc_forgot']) || !wp_verify_nonce($_POST['attc_forgot'], 'attc_forgot_nonce')) {
        wp_redirect(add_query_arg('attc_error', urlencode('Yêu cầu không hợp lệ.'), wp_get_referer()));
        exit;
    }
    // reCAPTCHA (nếu được cấu hình)
    if (!attc_verify_recaptcha_if_configured()) {
        wp_redirect(add_query_arg('attc_error', urlencode('Vui lòng xác nhận CAPTCHA.'), wp_get_referer()));
        exit;
    }
    $login = sanitize_text_field($_POST['user_login'] ?? '');
    if (empty($login)) {
        wp_redirect(add_query_arg('attc_error', urlencode('Vui lòng nhập tên đăng nhập hoặc email.'), wp_get_referer()));
        exit;
    }
    // Sử dụng hàm WP để gửi email đặt lại mật khẩu
    $errors = retrieve_password($login);
    if ($errors === true) {
        $notice = 'Nếu thông tin hợp lệ, email đặt lại mật khẩu đã được gửi. Vui lòng kiểm tra hộp thư.';
        $redirect = !empty($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : wp_get_referer();
        wp_redirect(add_query_arg('attc_notice', urlencode($notice), $redirect));
        exit;
    } else {
        $msg = is_wp_error($errors) ? $errors->get_error_message() : 'Không thể gửi email đặt lại mật khẩu.';
        wp_redirect(add_query_arg('attc_error', urlencode($msg), wp_get_referer()));
        exit;
    }
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

// ===== Shortcode: Form quên mật khẩu =====
function attc_forgot_form() {
    if (is_user_logged_in()) {
        return '<div class="attc-alert attc-alert-success">Bạn đã đăng nhập.</div>';
    }
    $site_key = get_option('attc_recaptcha_site_key', '');
    $redirect = isset($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : home_url('/dang-nhap/');
    ob_start();
    ?>
    <div class="attc-auth-form attc-card">
        <h2 class="attc-card__title">Quên mật khẩu</h2>
        <?php if (isset($_GET['attc_error'])): ?>
            <div class="attc-alert attc-alert-error"><?php echo esc_html(urldecode(sanitize_text_field($_GET['attc_error']))); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['attc_notice'])): ?>
            <div class="attc-alert attc-alert-success"><?php echo esc_html(urldecode(sanitize_text_field($_GET['attc_notice']))); ?></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="attc_do_forgot">
            <?php wp_nonce_field('attc_forgot_nonce', 'attc_forgot'); ?>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect); ?>">
            <p class="attc-field"><label>Tên đăng nhập hoặc Email</label><input class="attc-input" type="text" name="user_login" required></p>
            <?php if (!empty($site_key)) : ?>
                <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($site_key); ?>"></div>
            <?php endif; ?>
            <p><button type="submit" class="button button-primary attc-btn-wide">Gửi liên kết đặt lại mật khẩu</button></p>
        </form>
        <p class="attc-card__footer"><a href="<?php echo esc_url(home_url('/dang-nhap/')); ?>">Quay lại đăng nhập</a></p>
    </div>
    <?php if (!empty($site_key)) : ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <style>
        .attc-card{max-width:520px;margin:24px auto;padding:24px 20px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
        .attc-card__title{margin:0 0 12px;font-size:20px}
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

// ===== Front-end: Form đặt lại mật khẩu =====
function attc_reset_password_form() {
    // Lấy tham số từ email
    $key   = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
    $login = isset($_GET['login']) ? sanitize_text_field(wp_unslash($_GET['login'])) : '';

    // Kiểm tra key hợp lệ để hiển thị form
    $user = false;
    if (!empty($key) && !empty($login)) {
        $user = check_password_reset_key($key, $login);
    }

    ob_start();
    ?>
    <div class="attc-auth-form attc-card">
        <h2 class="attc-card__title">Đặt lại mật khẩu</h2>
        <?php if (isset($_GET['attc_error'])): ?>
            <div class="attc-alert attc-alert-error"><?php echo esc_html(urldecode(sanitize_text_field($_GET['attc_error']))); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['attc_notice'])): ?>
            <div class="attc-alert attc-alert-success"><?php echo esc_html(urldecode(sanitize_text_field($_GET['attc_notice']))); ?></div>
        <?php endif; ?>

        <?php if (is_wp_error($user)): ?>
            <div class="attc-alert attc-alert-error">Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn. Vui lòng yêu cầu lại liên kết.</div>
            <p class="attc-card__footer"><a href="<?php echo esc_url(home_url('/quen-mat-khau/')); ?>">Quay lại quên mật khẩu</a></p>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="attc_do_reset">
                <?php wp_nonce_field('attc_reset_nonce', 'attc_reset'); ?>
                <input type="hidden" name="key" value="<?php echo esc_attr($key); ?>">
                <input type="hidden" name="login" value="<?php echo esc_attr($login); ?>">
                <p class="attc-field"><label>Mật khẩu mới</label><input class="attc-input" type="password" name="pass1" required minlength="8"></p>
                <p class="attc-field"><label>Nhập lại mật khẩu mới</label><input class="attc-input" type="password" name="pass2" required minlength="8"></p>
                <?php $site_key = get_option('attc_recaptcha_site_key', ''); if (!empty($site_key)) : ?>
                    <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($site_key); ?>"></div>
                <?php endif; ?>
                <p><button type="submit" class="button button-primary attc-btn-wide">Cập nhật mật khẩu</button></p>
            </form>
            <p class="attc-card__footer"><a href="<?php echo esc_url(home_url('/dang-nhap/')); ?>">Quay lại đăng nhập</a></p>
        <?php endif; ?>
    </div>
    <?php if (!empty(get_option('attc_recaptcha_site_key', ''))) : ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <style>
        .attc-card{max-width:520px;margin:24px auto;padding:24px 20px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
        .attc-card__title{margin:0 0 12px;font-size:20px}
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

// Xử lý submit đặt lại mật khẩu
function attc_do_reset() {
    if (!isset($_POST['attc_reset']) || !wp_verify_nonce($_POST['attc_reset'], 'attc_reset_nonce')) {
        wp_redirect(add_query_arg('attc_error', urlencode('Yêu cầu không hợp lệ.'), wp_get_referer()));
        exit;
    }
    if (!attc_verify_recaptcha_if_configured()) {
        wp_redirect(add_query_arg('attc_error', urlencode('Vui lòng xác nhận CAPTCHA.'), wp_get_referer()));
        exit;
    }
    $key   = sanitize_text_field($_POST['key'] ?? '');
    $login = sanitize_text_field($_POST['login'] ?? '');
    $pass1 = (string)($_POST['pass1'] ?? '');
    $pass2 = (string)($_POST['pass2'] ?? '');

    if (empty($key) || empty($login)) {
        wp_redirect(add_query_arg('attc_error', urlencode('Thiếu thông tin xác thực.'), wp_get_referer()));
        exit;
    }
    if ($pass1 !== $pass2) {
        wp_redirect(add_query_arg('attc_error', urlencode('Hai mật khẩu không khớp.'), wp_get_referer()));
        exit;
    }
    if (strlen($pass1) < 8) {
        wp_redirect(add_query_arg('attc_error', urlencode('Mật khẩu phải có ít nhất 8 ký tự.'), wp_get_referer()));
        exit;
    }

    $user = check_password_reset_key($key, $login);
    if (is_wp_error($user)) {
        wp_redirect(add_query_arg('attc_error', urlencode('Liên kết không hợp lệ hoặc đã hết hạn.'), home_url('/quen-mat-khau/')));
        exit;
    }

    reset_password($user, $pass1);

    $notice_url = add_query_arg('attc_notice', urlencode('Mật khẩu của bạn đã được cập nhật. Vui lòng đăng nhập.'), home_url('/dang-nhap/'));
    wp_redirect($notice_url);
    exit;
}

// ===== Việt hoá email khôi phục mật khẩu (HTML) =====
add_filter('retrieve_password_title', 'attc_retrieve_password_title', 10, 2);
function attc_retrieve_password_title($title, $user_login) {
    $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    return 'Yêu cầu đặt lại mật khẩu - ' . $site_name;
}

add_filter('retrieve_password_message', 'attc_retrieve_password_message', 10, 4);
function attc_retrieve_password_message($message, $key, $user_login, $user_data) {
    // Link đặt lại mật khẩu front-end
    $reset_url = add_query_arg([
        'key'   => rawurlencode($key),
        'login' => rawurlencode($user_login),
    ], home_url('/dat-lai-mat-khau/'));
    $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

    // Bật HTML cho riêng email này
    $unset = false;
    if (!has_filter('wp_mail_content_type', 'attc_set_mail_html')) {
        add_filter('wp_mail_content_type', 'attc_set_mail_html');
        $unset = true;
    }

    $message  = '';
    $message .= '<div style="font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.7; color:#111">';
    $message .= '<p>Xin chào <strong>' . esc_html($user_login) . '</strong>,</p>';
    $message .= '<p>Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản của bạn tại <strong>' . esc_html($site_name) . '</strong>.</p>';
    $message .= '<p>Để đặt mật khẩu mới, vui lòng bấm nút bên dưới (hoặc mở đường dẫn kế bên trong trình duyệt):</p>';
    $message .= '<p><a href="' . esc_url($reset_url) . '" style="display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:10px 16px;border-radius:4px">Đặt lại mật khẩu</a></p>';
    $message .= '<p style="word-break:break-all; margin-top:8px"><small>' . esc_html($reset_url) . '</small></p>';
    $message .= '<p>Nếu bạn không yêu cầu thao tác này, vui lòng bỏ qua email.</p>';
    $message .= '<p>Trân trọng,<br>' . esc_html($site_name) . '</p>';
    $message .= '</div>';

    // Hủy thiết lập content-type nếu chúng ta vừa thêm ở trên
    if ($unset) {
        add_action('phpmailer_init', function($phpmailer){ /* no-op, giữ tương thích */ });
    }

    return $message;
}

function attc_set_mail_html() {
    return 'text/html';
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
    // Trang nạp ví thủ công cho Admin
    add_submenu_page(
        'options-general.php',
        'Ví người dùng (Audio to Text)',
        'Ví người dùng',
        'manage_options',
        'attc-wallet-admin',
        'attc_wallet_admin_page'
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
        update_option('attc_webhook_momo_token', sanitize_text_field($_POST['attc_webhook_momo_token'] ?? ''));
        update_option('attc_webhook_bank_token', sanitize_text_field($_POST['attc_webhook_bank_token'] ?? ''));
        // MoMo & Bank display/payment options
        update_option('attc_momo_phone', sanitize_text_field($_POST['attc_momo_phone'] ?? ''));
        update_option('attc_momo_name', sanitize_text_field($_POST['attc_momo_name'] ?? ''));
        update_option('attc_momo_link_pattern', esc_url_raw($_POST['attc_momo_link_pattern'] ?? ''));
        update_option('attc_bank_acb_account_no', sanitize_text_field($_POST['attc_bank_acb_account_no'] ?? ''));
        update_option('attc_bank_acb_account_name', sanitize_text_field($_POST['attc_bank_acb_account_name'] ?? ''));
        update_option('attc_vietqr_image_pattern', esc_url_raw($_POST['attc_vietqr_image_pattern'] ?? ''));
        // Optional HMAC verification for webhooks
        update_option('attc_webhook_momo_hmac_secret', sanitize_text_field($_POST['attc_webhook_momo_hmac_secret'] ?? ''));
        update_option('attc_webhook_momo_hmac_header', sanitize_text_field($_POST['attc_webhook_momo_hmac_header'] ?? ''));
        update_option('attc_webhook_bank_hmac_secret', sanitize_text_field($_POST['attc_webhook_bank_hmac_secret'] ?? ''));
        update_option('attc_webhook_bank_hmac_header', sanitize_text_field($_POST['attc_webhook_bank_hmac_header'] ?? ''));
        add_settings_error('attc_messages', 'attc_saved', 'Cài đặt đã được lưu.', 'updated');
    }

    $openai_api_key = get_option('attc_openai_api_key', '');
    $recaptcha_site_key = get_option('attc_recaptcha_site_key', '');
    $recaptcha_secret_key = get_option('attc_recaptcha_secret_key', '');
    $webhook_momo_token = get_option('attc_webhook_momo_token', '');
    $webhook_bank_token = get_option('attc_webhook_bank_token', '');
    $webhook_momo_url = add_query_arg([ 'action' => 'attc_webhook_momo', 'token' => rawurlencode($webhook_momo_token) ], admin_url('admin-post.php'));
    $webhook_bank_url = add_query_arg([ 'action' => 'attc_webhook_bank', 'token' => rawurlencode($webhook_bank_token) ], admin_url('admin-post.php'));
    // Read MoMo/Bank display/payment options
    $o_momo_phone = get_option('attc_momo_phone', '');
    $o_momo_name = get_option('attc_momo_name', '');
    $o_momo_link = get_option('attc_momo_link_pattern', '');
    $o_acb_no = get_option('attc_bank_acb_account_no', '');
    $o_acb_name = get_option('attc_bank_acb_account_name', '');
    $o_vietqr_pattern = get_option('attc_vietqr_image_pattern', 'https://img.vietqr.io/image/{bank_code}-{account_no}-compact.png?amount={amount}&addInfo={note}&accountName={account_name}');
    // HMAC options
    $o_momo_hmac_secret = get_option('attc_webhook_momo_hmac_secret', '');
    $o_momo_hmac_header = get_option('attc_webhook_momo_hmac_header', '');
    $o_bank_hmac_secret = get_option('attc_webhook_bank_hmac_secret', '');
    $o_bank_hmac_header = get_option('attc_webhook_bank_hmac_header', '');
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
                <tr>
                    <th scope="row"><label for="attc_webhook_momo_token">MoMo Webhook Token</label></th>
                    <td>
                        <input type="text" id="attc_webhook_momo_token" name="attc_webhook_momo_token" class="regular-text" value="<?php echo esc_attr($webhook_momo_token); ?>">
                        <p class="description">Bí mật dùng để xác thực webhook từ MoMo (tham số token).</p>
                        <?php if (!empty($webhook_momo_token)): ?>
                        <p><code><?php echo esc_html($webhook_momo_url); ?></code></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_webhook_bank_token">VietQR/Webhook Bank Token</label></th>
                    <td>
                        <input type="text" id="attc_webhook_bank_token" name="attc_webhook_bank_token" class="regular-text" value="<?php echo esc_attr($webhook_bank_token); ?>">
                        <p class="description">Bí mật dùng để xác thực webhook từ dịch vụ sao kê (tham số token).</p>
                        <?php if (!empty($webhook_bank_token)): ?>
                        <p><code><?php echo esc_html($webhook_bank_url); ?></code></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><th colspan="2"><h3>Hiển thị thanh toán (MoMo/Ngân hàng)</h3></th></tr>
                <tr>
                    <th scope="row"><label for="attc_momo_phone">SĐT MoMo nhận</label></th>
                    <td><input type="text" id="attc_momo_phone" name="attc_momo_phone" class="regular-text" value="<?php echo esc_attr($o_momo_phone); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_momo_name">Tên chủ ví MoMo</label></th>
                    <td><input type="text" id="attc_momo_name" name="attc_momo_name" class="regular-text" value="<?php echo esc_attr($o_momo_name); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_momo_link_pattern">Mẫu link MoMo</label></th>
                    <td>
                        <input type="text" id="attc_momo_link_pattern" name="attc_momo_link_pattern" class="regular-text" value="<?php echo esc_attr($o_momo_link); ?>">
                        <p class="description">Dùng {amount} và {note}. Ví dụ: https://nhantien.momo.vn/0772729789?amount={amount}&message={note}</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_bank_acb_account_no">Số tài khoản ACB</label></th>
                    <td><input type="text" id="attc_bank_acb_account_no" name="attc_bank_acb_account_no" class="regular-text" value="<?php echo esc_attr($o_acb_no); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_bank_acb_account_name">Chủ tài khoản ACB</label></th>
                    <td><input type="text" id="attc_bank_acb_account_name" name="attc_bank_acb_account_name" class="regular-text" value="<?php echo esc_attr($o_acb_name); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_vietqr_image_pattern">Mẫu ảnh VietQR</label></th>
                    <td>
                        <input type="text" id="attc_vietqr_image_pattern" name="attc_vietqr_image_pattern" class="regular-text" value="<?php echo esc_attr($o_vietqr_pattern); ?>">
                        <p class="description">Dùng {bank_code} {account_no} {amount} {note} {account_name}. Mặc định dùng img.vietqr.io.</p>
                    </td>
                </tr>
                <tr><th colspan="2"><h3>Xác thực chữ ký webhook (tuỳ chọn)</h3></th></tr>
                <tr>
                    <th scope="row"><label for="attc_webhook_momo_hmac_header">MoMo HMAC Header</label></th>
                    <td><input type="text" id="attc_webhook_momo_hmac_header" name="attc_webhook_momo_hmac_header" class="regular-text" value="<?php echo esc_attr($o_momo_hmac_header); ?>">
                        <p class="description">Tên header chứa chữ ký HMAC (do hệ thống webhook MoMo gửi).</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_webhook_momo_hmac_secret">MoMo HMAC Secret</label></th>
                    <td><input type="text" id="attc_webhook_momo_hmac_secret" name="attc_webhook_momo_hmac_secret" class="regular-text" value="<?php echo esc_attr($o_momo_hmac_secret); ?>">
                        <p class="description">Khóa bí mật để tính HMAC (theo quy định tích hợp MoMo của bạn).</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_webhook_bank_hmac_header">Bank HMAC Header</label></th>
                    <td><input type="text" id="attc_webhook_bank_hmac_header" name="attc_webhook_bank_hmac_header" class="regular-text" value="<?php echo esc_attr($o_bank_hmac_header); ?>">
                        <p class="description">Tên header chứa chữ ký HMAC từ provider VietQR/sao kê.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="attc_webhook_bank_hmac_secret">Bank HMAC Secret</label></th>
                    <td><input type="text" id="attc_webhook_bank_hmac_secret" name="attc_webhook_bank_hmac_secret" class="regular-text" value="<?php echo esc_attr($o_bank_hmac_secret); ?>">
                        <p class="description">Khóa bí mật để tính HMAC của provider VietQR/sao kê.</p></td>
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
    if (!get_page_by_path('quen-mat-khau')) {
        wp_insert_post([
            'post_title'   => 'Quên mật khẩu',
            'post_name'    => 'quen-mat-khau',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[attc_forgot_form]'
        ]);
    }
    if (!get_page_by_path('dat-lai-mat-khau')) {
        wp_insert_post([
            'post_title'   => 'Đặt lại mật khẩu',
            'post_name'    => 'dat-lai-mat-khau',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[attc_reset_password_form]'
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

// ===== Giới hạn upload theo ngày cho tài khoản miễn phí =====
function attc_check_and_consume_daily_upload($user_id) {
    // Bỏ giới hạn cho admin hoặc nếu site muốn bypass qua filter
    if (user_can($user_id, 'manage_options')) {
        return true;
    }
    $bypass = apply_filters('attc_should_bypass_quota', false, $user_id);
    if ($bypass) return true;
    // Nếu là tài khoản trả phí (ví > 0 hoặc có gói), bỏ giới hạn miễn phí
    if (attc_is_paid_user($user_id)) {
        return true;
    }

    $meta_key = 'attc_daily_upload_quota';
    $today = current_time('Y-m-d'); // theo timezone của WP
    $data = get_user_meta($user_id, $meta_key, true);
    if (!is_array($data)) {
        $data = ['date' => $today, 'count' => 0];
    }

    // Reset nếu sang ngày mới
    if (!isset($data['date']) || $data['date'] !== $today) {
        $data = ['date' => $today, 'count' => 0];
    }

    // Giới hạn 1 lần/ngày
    if ((int)$data['count'] >= 1) {
        return new WP_Error('daily_quota_exceeded', 'Tài khoản miễn phí chỉ được tải lên 1 file ghi âm 2 phút mỗi ngày. Vui lòng thử lại vào ngày mai, hoặc Nâng Cấp Tài Khoản ngay để chuyển đổi không giới hạn!');
    }

    // Tiêu thụ 1 lượt
    $data['count'] = (int)$data['count'] + 1;
    update_user_meta($user_id, $meta_key, $data);
    return true;
}

// Helper: lấy số lượt còn lại trong ngày (không tiêu thụ)
function attc_get_daily_remaining($user_id) {
    $limit = 1;
    // Admin hoặc bypass thì không giới hạn
    if (user_can($user_id, 'manage_options') || apply_filters('attc_should_bypass_quota', false, $user_id)) {
        return [
            'limit' => $limit,
            'used' => 0,
            'remaining' => $limit,
            'date' => current_time('Y-m-d'),
            'unlimited' => true,
        ];
    }

    $meta_key = 'attc_daily_upload_quota';
    $today = current_time('Y-m-d');
    $data = get_user_meta($user_id, $meta_key, true);
    if (!is_array($data)) {
        $data = ['date' => $today, 'count' => 0];
    }
    if (!isset($data['date']) || $data['date'] !== $today) {
        $data = ['date' => $today, 'count' => 0];
    }
    $used = (int) ($data['count'] ?? 0);
    $remaining = max(0, $limit - $used);
    return [
        'limit' => $limit,
        'used' => $used,
        'remaining' => $remaining,
        'date' => $today,
        'unlimited' => false,
    ];
}

// Fallback URL an toàn cho trang đăng nhập khi referrer không hợp lệ
function attc_login_fallback_url() {
    $login_page = get_page_by_path('dang-nhap');
    if ($login_page) {
        return get_permalink($login_page);
    }
    return home_url('/dang-nhap/');
}

// ===== Ví tài khoản & cấu hình giá =====
function attc_get_price_per_minute() {
    // Giá mặc định 500 VND/phút, có thể thay bằng filter
    $price = 500;
    return (int) apply_filters('attc_price_per_minute', $price);
}

function attc_get_paid_max_minutes() {
    // Trần thời lượng mặc định: 30 phút/file
    $max = 30;
    return (int) apply_filters('attc_paid_max_minutes', $max);
}

function attc_get_wallet_balance($user_id) {
    $balance = get_user_meta($user_id, 'attc_wallet_balance', true);
    if ($balance === '' || $balance === null) $balance = 0;
    return (int) $balance;
}

function attc_set_wallet_balance($user_id, $amount) {
    update_user_meta($user_id, 'attc_wallet_balance', max(0, (int)$amount));
}

function attc_add_wallet_history($user_id, $entry) {
    $key = 'attc_wallet_history';
    $hist = get_user_meta($user_id, $key, true);
    if (!is_array($hist)) $hist = [];
    $entry['time'] = time();
    $hist[] = $entry;
    // Giới hạn 200 mục gần nhất
    if (count($hist) > 200) {
        $hist = array_slice($hist, -200);
    }
    update_user_meta($user_id, $key, $hist);
}

function attc_charge_wallet($user_id, $amount, $meta = []) {
    $amount = (int) $amount;
    if ($amount <= 0) return true;
    $bal = attc_get_wallet_balance($user_id);
    if ($bal < $amount) {
        return new WP_Error('insufficient_funds', 'Số dư ví không đủ.');
    }
    attc_set_wallet_balance($user_id, $bal - $amount);
    attc_add_wallet_history($user_id, [
        'type' => 'debit',
        'amount' => $amount,
        'meta' => $meta,
    ]);
    return true;
}

function attc_credit_wallet($user_id, $amount, $meta = []) {
    $amount = (int) $amount;
    if ($amount <= 0) return true;
    $bal = attc_get_wallet_balance($user_id);
    attc_set_wallet_balance($user_id, $bal + $amount);
    attc_add_wallet_history($user_id, [
        'type' => 'credit',
        'amount' => $amount,
        'meta' => $meta,
    ]);
    return true;
}

function attc_is_paid_user($user_id) {
    $paid = attc_get_wallet_balance($user_id) > 0;
    // Cho phép override qua filter (vd: có gói tháng không cần ví)
    return (bool) apply_filters('attc_is_paid_user', $paid, $user_id);
}

// Thêm tiền tố (prefix) vào tham số version khi enqueue CSS để cache-busting rõ ràng, tránh trình duyệt giữ bản cũ.
function attc_enqueue_frontend_assets() {
    if (is_admin()) return;
    $handle = 'attc-frontend';
    $src    = plugins_url('assets/css/frontend.css', __FILE__);
    $path   = plugin_dir_path(__FILE__) . 'assets/css/frontend.css';
    $verRaw = file_exists($path) ? filemtime($path) : time();
    $ver    = 'attc-' . $verRaw;
    wp_enqueue_style($handle, $src, [], $ver);
}
add_action('wp_enqueue_scripts', 'attc_enqueue_frontend_assets');
