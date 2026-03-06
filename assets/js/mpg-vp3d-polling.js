/**
 * V-Processor 3D - Webhook Polling
 *
 * Polls the server every 3 seconds after a charge is created,
 * waiting for VSafe's webhook to arrive with either:
 * - A 3DS redirectUrl (redirect customer to challenge)
 * - An approved status (redirect to thank you page)
 * - A declined status (redirect back to checkout)
 */

(function($) {
    'use strict';

    var MPGPolling = {
        attempts: 0,
        maxAttempts: 30,
        timer: null,
        startTime: null,

        init: function() {
            if (typeof mpg_vp3d_polling === 'undefined') {
                return;
            }

            this.startTime = Date.now();
            this.maxAttempts = Math.ceil((mpg_vp3d_polling.timeout || 90) / (mpg_vp3d_polling.interval / 1000 || 3));
            this.poll();
        },

        poll: function() {
            var self = this;
            self.attempts++;

            // Update progress bar
            var progress = Math.min((self.attempts / self.maxAttempts) * 100, 100);
            $('#mpg-vp3d-progress-bar').css('width', progress + '%');

            $.ajax({
                url: mpg_vp3d_polling.ajax_url,
                type: 'POST',
                data: {
                    action: 'mpg_vp3d_poll_status',
                    nonce: mpg_vp3d_polling.nonce,
                    order_id: mpg_vp3d_polling.order_id,
                    order_key: mpg_vp3d_polling.order_key
                },
                success: function(response) {
                    if (!response.success) {
                        self.scheduleNext();
                        return;
                    }

                    var data = response.data;

                    switch (data.status) {
                        case 'redirect_3ds':
                            self.showMessage('Redirecting to secure verification...', 'Redirecting...');
                            setTimeout(function() {
                                window.location.href = data.redirect_url;
                            }, 500);
                            break;

                        case 'approved':
                            self.showSuccess('Payment approved!');
                            setTimeout(function() {
                                window.location.href = data.redirect_url;
                            }, 1000);
                            break;

                        case 'failed':
                            self.showError(data.message || 'Payment was declined.');
                            setTimeout(function() {
                                window.location.href = data.redirect_url || mpg_vp3d_polling.checkout_url;
                            }, 2000);
                            break;

                        case 'waiting':
                            self.scheduleNext();
                            break;

                        default:
                            self.scheduleNext();
                    }
                },
                error: function() {
                    self.scheduleNext();
                }
            });
        },

        scheduleNext: function() {
            var self = this;

            if (self.attempts >= self.maxAttempts) {
                self.showTimeout();
                return;
            }

            self.timer = setTimeout(function() {
                self.poll();
            }, mpg_vp3d_polling.interval || 3000);
        },

        showMessage: function(message, title) {
            if (title) {
                $('#mpg-vp3d-title').text(title);
            }
            $('#mpg-vp3d-message').text(message);
        },

        showSuccess: function(message) {
            $('#mpg-vp3d-spinner').html(
                '<svg width="48" height="48" viewBox="0 0 48 48">' +
                '<circle cx="24" cy="24" r="22" fill="#10b981"/>' +
                '<path d="M14 24l7 7 13-13" stroke="white" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>'
            );
            $('#mpg-vp3d-title').text('Payment Successful').css('color', '#059669');
            $('#mpg-vp3d-message').text(message);
            $('#mpg-vp3d-subtitle').hide();
            $('#mpg-vp3d-progress').hide();
        },

        showError: function(message) {
            $('#mpg-vp3d-spinner').html(
                '<svg width="48" height="48" viewBox="0 0 48 48">' +
                '<circle cx="24" cy="24" r="22" fill="#ef4444"/>' +
                '<path d="M16 16l16 16M32 16l-16 16" stroke="white" stroke-width="3" fill="none" stroke-linecap="round"/>' +
                '</svg>'
            );
            $('#mpg-vp3d-title').text('Payment Failed').css('color', '#dc2626');
            $('#mpg-vp3d-message').text(message);
            $('#mpg-vp3d-subtitle').text('Redirecting to checkout...');
            $('#mpg-vp3d-progress').hide();
        },

        showTimeout: function() {
            $('#mpg-vp3d-spinner').html(
                '<svg width="48" height="48" viewBox="0 0 48 48">' +
                '<circle cx="24" cy="24" r="22" fill="none" stroke="#f59e0b" stroke-width="3"/>' +
                '<path d="M24 14v12l8 4" stroke="#f59e0b" stroke-width="3" fill="none" stroke-linecap="round"/>' +
                '</svg>'
            );
            $('#mpg-vp3d-title').text('Still Processing').css('color', '#d97706');
            $('#mpg-vp3d-message').text('Your payment is taking longer than expected to process.');
            $('#mpg-vp3d-subtitle').hide();
            $('#mpg-vp3d-progress').hide();
            $('#mpg-vp3d-timeout').show();
        }
    };

    $(document).ready(function() {
        MPGPolling.init();
    });

})(jQuery);
