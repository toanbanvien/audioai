document.addEventListener('DOMContentLoaded', function() {
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
        const bankCodeEl = document.getElementById('bank-name');
        const accNumberEl = document.getElementById('account-number');
        const bankCode = (bankCodeEl ? bankCodeEl.textContent.trim() : 'ACB');
        const accNumber = (accNumberEl ? accNumberEl.textContent.trim() : '');
        const qrString = `https://api.vietqr.io/image/${encodeURIComponent(bankCode)}-${encodeURIComponent(accNumber)}-print.png?amount=${encodeURIComponent(amount)}&addInfo=${encodeURIComponent(memo)}`;
        return qrString;
    }

    function generateMomoQR(amount, memo) {
        // MoMo cá nhân: dùng trang me.momo.vn với số điện thoại làm định danh
        const phoneEl = document.getElementById('momo-phone');
        const phone = (phoneEl ? phoneEl.textContent.trim() : '');
        const qrString = `https://me.momo.vn/${encodeURIComponent(phone)}?amount=${encodeURIComponent(amount)}&note=${encodeURIComponent(memo)}`;
        return qrString;
    }

    function startPaymentCheck() {
        if (isChecking) return;
        isChecking = true;
        // Ưu tiên dùng SSE nếu có
        let sse; 
        if (window.EventSource && attcPaymentData.stream_url) {
            try {
                sse = new EventSource(attcPaymentData.stream_url);
                sse.addEventListener('payment', (evt) => {
                    handlePaymentSuccess(JSON.parse(evt.data));
                    sse.close();
                });
                sse.onerror = () => {
                    // Fallback sang polling nếu SSE lỗi
                    sse.close();
                    startPollingFallback();
                };
            } catch (e) {
                startPollingFallback();
            }
        } else {
            startPollingFallback();
        }
        
        function startPollingFallback() {
            paymentCheckInterval = setInterval(() => {
                fetch(attcPaymentData.rest_url)
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            clearInterval(paymentCheckInterval);
                            handlePaymentSuccess(data);
                        }
                    })
                    .catch(err => {
                        console.error('Lỗi khi kiểm tra thanh toán:', err);
                        clearInterval(paymentCheckInterval);
                        isChecking = false;
                    });
            }, 3000);
        }
    }

    function handlePaymentSuccess(payload) {
        const notice = document.getElementById('attc-success-notice');
        const message = document.getElementById('attc-success-message');
        const amount = (payload && payload.data && payload.data.amount) ? payload.data.amount : 0;
        const pricePerMin = parseInt(attcPaymentData.price_per_min, 10) || 0;
        const minutes = pricePerMin > 0 ? Math.floor(amount / pricePerMin) : 0;
        if (message) {
            message.textContent = `Bạn vừa nạp thành công ${amount.toLocaleString('vi-VN')}đ (tương đương ${minutes} phút chuyển đổi).`;
        }
        if (notice) {
            notice.classList.remove('is-hidden');
        }
        setTimeout(() => { window.location.href = '/chuyen-doi-giong-noi/'; }, 2500);
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

            // Cập nhật thông tin MoMo
            const momoAmountEl = document.getElementById('momo-amount');
            const momoMemoEl = document.getElementById('momo-memo');
            if (momoAmountEl) momoAmountEl.textContent = `${parseInt(amount).toLocaleString('vi-VN')}đ`;
            if (momoMemoEl) momoMemoEl.textContent = memo;
            document.getElementById('attc-momo-qr').innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(generateMomoQR(amount, memo))}" alt="MoMo QR Code">`;

            pricingPlans.classList.add('is-hidden');
            paymentDetails.classList.remove('is-hidden');
            
            setTimeout(startPaymentCheck, 2000);
        });
    });

    backButton.addEventListener('click', function() {
        pricingPlans.classList.remove('is-hidden');
        paymentDetails.classList.add('is-hidden');
        clearInterval(paymentCheckInterval);
        isChecking = false;
    });

});
