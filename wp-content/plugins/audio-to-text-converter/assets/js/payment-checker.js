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
        const bankInfo = {
            bank: 'ACB',
            accountName: 'DINH CONG TOAN',
            accountNumber: '240306539'
        };
        const qrString = `https://api.vietqr.io/image/${bankInfo.bank}-${bankInfo.accountNumber}-print.png?amount=${amount}&addInfo=${memo}`;
        return qrString;
    }

    function generateMomoQR(amount, memo) {
        const momoInfo = {
            receiver: 'trantoan275',
            amount: amount,
            note: memo
        };
        const qrString = `https://me.momo.vn/${momoInfo.receiver}?amount=${momoInfo.amount}&note=${momoInfo.note}`;
        return qrString;
    }

    function startPaymentCheck() {
        if (isChecking) return;
        isChecking = true;
        paymentCheckInterval = setInterval(() => {
            fetch(attcPaymentData.rest_url)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        clearInterval(paymentCheckInterval);
                        const notice = document.getElementById('attc-success-notice');
                        const message = document.getElementById('attc-success-message');
                        const amount = data.data.amount || 0;
                        const pricePerMin = parseInt(attcPaymentData.price_per_min, 10) || 0;
                        const minutes = pricePerMin > 0 ? Math.floor(amount / pricePerMin) : 0;
                        if (message) {
                            message.textContent = `Bạn vừa nạp thành công ${amount.toLocaleString('vi-VN')}đ (tương đương ${minutes} phút chuyển đổi).`;
                        }
                        if (notice) {
                            notice.classList.remove('is-hidden');
                        }
                        setTimeout(() => window.location.reload(), 4000);
                    }
                })
                .catch(err => {
                    console.error('Lỗi khi kiểm tra thanh toán:', err);
                    clearInterval(paymentCheckInterval);
                    isChecking = false;
                });
        }, 3000);
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

            // Cập nhật thông tin Momo
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
