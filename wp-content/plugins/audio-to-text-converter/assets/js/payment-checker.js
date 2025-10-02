document.addEventListener('DOMContentLoaded', function() {
    // attcPaymentData được truyền từ PHP qua wp_localize_script
    if (typeof attcPaymentData === 'undefined') {
        return;
    }

    var paymentCheckInterval = null;
    var isChecking = false;

    function startPaymentCheck() {
        if (isChecking) return;
        isChecking = true;

        paymentCheckInterval = setInterval(function() {
            fetch(attcPaymentData.rest_url)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        clearInterval(paymentCheckInterval);

                        var notice = document.getElementById('attc-success-notice');
                        var message = document.getElementById('attc-success-message');
                        var amount = data.data.amount || 0;
                        var pricePerMin = parseInt(attcPaymentData.price_per_min, 10) || 0;
                        var minutes = pricePerMin > 0 ? Math.floor(amount / pricePerMin) : 0;

                        if (message) {
                            message.textContent = 'Bạn vừa nạp thành công ' + amount.toLocaleString('vi-VN') + 'đ (tương đương ' + minutes + ' phút chuyển đổi).';
                        }
                        if (notice) {
                            notice.classList.remove('is-hidden');
                        }

                        // Tải lại trang sau 4 giây
                        setTimeout(function() {
                            window.location.reload();
                        }, 4000);
                    }
                })
                .catch(error => {
                    console.error('Lỗi khi kiểm tra trạng thái thanh toán:', error);
                    clearInterval(paymentCheckInterval);
                    isChecking = false;
                });
        }, 3000); // Kiểm tra mỗi 3 giây
    }

    // Chỉ bắt đầu kiểm tra khi người dùng chọn một gói thanh toán
    document.querySelectorAll('.attc-plan-select').forEach(function(button) {
        button.addEventListener('click', function() {
            // Đợi một chút để người dùng xem QR code rồi mới bắt đầu check
            setTimeout(startPaymentCheck, 2000);
        });
    });
});
