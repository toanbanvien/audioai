document.addEventListener('DOMContentLoaded', function() {
    // DEBUG: In ra User ID mà trang đang kiểm tra
    if (typeof attcPaymentData !== 'undefined' && attcPaymentData.user_id) {
        console.log(`[ATTC DEBUG] Payment checker is running for User ID: ${attcPaymentData.user_id}`);
    } else {
        console.log('[ATTC DEBUG] Payment checker is running for a logged-out user.');
    }

    if (typeof attcPaymentData === 'undefined') {
        return;
    }

    const pricingPlans = document.getElementById('attc-pricing-plans');
    const paymentDetails = document.getElementById('attc-payment-details');
    const backButton = document.getElementById('attc-back-to-plans');
    const planButtons = document.querySelectorAll('.attc-plan-select');
    let paymentCheckInterval = null;
    let isChecking = false;

    function generateVietQR(amount, memo) {
        const accNumberEl = document.getElementById('account-number');
        const bankNameEl = document.getElementById('bank-name'); // Sửa lại để lấy đúng element
        // Lưu ý: VietQR dùng mã BIN, không phải tên. Tạm hardcode cho ACB.
        const bankCode = 'ACB'; // Hardcode mã BIN của ACB
        // const bankCode = (bankNameEl ? bankNameEl.textContent.trim() : 'ACB'); // Tên ngân hàng không phải mã BIN
        const accNumber = (accNumberEl ? accNumberEl.textContent.trim() : '');
        const qrString = `https://api.vietqr.io/image/${encodeURIComponent(bankCode)}-${encodeURIComponent(accNumber)}-print.png?amount=${encodeURIComponent(amount)}&addInfo=${encodeURIComponent(memo)}`;
        return qrString;
    }

    // (Removed) MoMo QR generator

    function startPaymentCheck() {
        if (isChecking || document.hidden) return;
        isChecking = true;

        // Always run polling every 3s until success
        const startPolling = () => {
            if (paymentCheckInterval) clearInterval(paymentCheckInterval);
            let attempts = 0;
            let intervalMs = 3000;

            const tick = () => {
                attempts++;
                fetch(attcPaymentData.rest_url, {
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': attcPaymentData.nonce }
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.status === 'success') {
                        cleanupChecks();
                        handlePaymentSuccess(data);
                    } else {
                        // Sau ~1 phút, nới chu kỳ để giảm tải
                        if (attempts === 20) intervalMs = 10000;
                        paymentCheckInterval = setTimeout(tick, intervalMs);
                    }
                })
                .catch(() => {
                    // Tiếp tục thử lại với backoff nhẹ
                    if (attempts === 20) intervalMs = 10000;
                    paymentCheckInterval = setTimeout(tick, intervalMs);
                });
            };
            paymentCheckInterval = setTimeout(tick, intervalMs);
        };

        let sse;
        const startSSE = () => {
            if (!window.EventSource || !attcPaymentData.stream_url) return;
            try {
                const sseUrl = new URL(attcPaymentData.stream_url);
                sseUrl.searchParams.append('_wpnonce', attcPaymentData.nonce);
                sse = new EventSource(sseUrl.href);
                sse.addEventListener('payment', (evt) => {
                    cleanupChecks();
                    handlePaymentSuccess(JSON.parse(evt.data));
                });
                sse.onerror = () => {
                    // Keep polling; try to restart SSE later
                    if (sse) { try { sse.close(); } catch(_){} }
                    setTimeout(startSSE, 10000); // retry SSE in 10s
                };
            } catch (_) {
                // Ignore, polling covers it
            }
        };

        function cleanupChecks() {
            if (paymentCheckInterval) { clearTimeout(paymentCheckInterval); paymentCheckInterval = null; }
            if (sse) { try { sse.close(); } catch(_){} sse = null; }
            isChecking = false;
        }

        // Khi khởi động chủ động (user chọn gói), chạy cả polling + SSE
        startPolling();
        startSSE();
    }

    function ensureSuccessNotice() {
        let notice = document.getElementById('attc-success-notice');
        if (!notice) {
            notice = document.createElement('div');
            notice.id = 'attc-success-notice';
            notice.className = 'attc-notice';
            notice.style.display = 'none';
            notice.innerHTML = [
                '<p style="font-size:28px"><strong>Nạp tiền thành công!</strong></p>',
                '<p id="attc-success-message"></p>',
                '<button id="attc-confirm-payment" class="attc-btn-primary">Quay lại chuyển đổi giọng nói</button>'
            ].join('');
            document.body.appendChild(notice);
            // Minimal styles if shortcode CSS not present
            const styleId = 'attc-success-notice-style';
            if (!document.getElementById(styleId)) {
                const style = document.createElement('style');
                style.id = styleId;
                style.textContent = '.attc-notice{background:#fff;border-left:4px solid #4CAF50;padding:12px 20px;margin:20px;box-shadow:0 2px 4px rgba(0,0,0,.1)} .attc-btn-primary{background:#2271b1;color:#fff;border:none;padding:8px 12px;border-radius:6px;cursor:pointer}';
                document.head.appendChild(style);
            }
        }
        return notice;
    }

    function handlePaymentSuccess(payload) {
        const notice = ensureSuccessNotice();
        const message = document.getElementById('attc-success-message');
        const amount = (payload && payload.data && payload.data.amount) ? payload.data.amount : 0;
        const pricePerMin = parseInt(attcPaymentData.price_per_min, 10) || 0;
        const minutes = pricePerMin > 0 ? Math.floor(amount / pricePerMin) : 0;
        if (message) {
            message.textContent = `Bạn vừa nạp thành công ${amount.toLocaleString('vi-VN')}đ (tương đương ${minutes} phút chuyển đổi).`;
        }
        if (notice) {
            notice.style.display = 'block';
            notice.classList.remove('is-hidden');
        }
        const confirmBtn = document.getElementById('attc-confirm-payment');
        if (confirmBtn) {
            confirmBtn.onclick = () => { window.location.href = '/chuyen-doi-giong-noi/'; };
        }
    }

    planButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const amount = this.dataset.amount;
            const uid = (typeof attcPaymentData !== 'undefined' && attcPaymentData.user_id)
                ? attcPaymentData.user_id
                : (this.dataset.userId || '');
            const memo = `NANGCAP${uid}`;

            // Cập nhật thông tin Bank
            document.getElementById('bank-amount').textContent = `${parseInt(amount).toLocaleString('vi-VN')}đ`;
            document.getElementById('bank-memo').textContent = memo;
            document.getElementById('attc-bank-qr').innerHTML = `<img src="${generateVietQR(amount, memo)}" alt="QR Code">`;

            // (Removed) MoMo rendering

            pricingPlans.classList.add('is-hidden');
            paymentDetails.classList.remove('is-hidden');
            
            setTimeout(startPaymentCheck, 2000);
        });
    });

    // Auto-start kiểm tra đầy đủ khi trang sẵn sàng
    function tryStart() { try { startPaymentCheck(); } catch(e) { /* ignore */ } }
    if (!document.hidden) { tryStart(); }
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            // Pause checks
            if (paymentCheckInterval) { clearTimeout(paymentCheckInterval); paymentCheckInterval = null; }
        } else {
            // Resume checks
            if (!isChecking) { tryStart(); }
        }
    });

    backButton.addEventListener('click', function() {
        pricingPlans.classList.remove('is-hidden');
        paymentDetails.classList.add('is-hidden');
        clearInterval(paymentCheckInterval);
        isChecking = false;
    });

});
