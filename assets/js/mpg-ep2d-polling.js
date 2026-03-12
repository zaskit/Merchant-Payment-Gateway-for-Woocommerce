/**
 * E-Processor 2D - Callback Polling
 *
 * Polls the server every 3 seconds after a PEND response,
 * waiting for the EuPaymentz callback to arrive with either:
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
            if (typeof mpg_ep2d_polling === 'undefined') {
                return;
            }

            this.startTime = Date.now();
            this.maxAttempts = Math.ceil((mpg_ep2d_polling.timeout || 90) / (mpg_ep2d_polling.interval / 1000 || 3));
            this.poll();
        },

        poll: function() {
            var self = this;
            self.attempts++;

            // Update progress bar
            var progress = Math.min((self.attempts / self.maxAttempts) * 100, 100);
            $('#mpg-ep2d-progress-bar').css('width', progress + '%');

            $.ajax({
                url: mpg_ep2d_polling.ajax_url,
                type: 'POST',
                data: {
                    action: 'mpg_ep2d_poll_status',
                    nonce: mpg_ep2d_polling.nonce,
                    order_id: mpg_ep2d_polling.order_id,
                    order_key: mpg_ep2d_polling.order_key
                },
                success: function(response) {
                    if (!response.success) {
                        self.scheduleNext();
                        return;
                    }

                    var data = response.data;

                    switch (data.status) {
                        case 'approved':
                            self.showSuccess('Payment approved!');
                            setTimeout(function() {
                                window.location.href = data.redirect_url;
                            }, 1000);
                            break;

                        case 'failed':
                            self.showError(data.message || 'Payment was declined.');
                            setTimeout(function() {
                                window.location.href = data.redirect_url || mpg_ep2d_polling.checkout_url;
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
            }, mpg_ep2d_polling.interval || 3000);
        },

        showMessage: function(message, title) {
            if (title) {
                $('#mpg-ep2d-title').text(title);
            }
            $('#mpg-ep2d-message').text(message);
        },

        showSuccess: function(message) {
            $('#mpg-ep2d-spinner').html(
                '<svg width="48" height="48" viewBox="0 0 48 48">' +
                '<circle cx="24" cy="24" r="22" fill="#10b981"/>' +
                '<path d="M14 24l7 7 13-13" stroke="white" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>'
            );
            $('#mpg-ep2d-title').text('Payment Successful').css('color', '#059669');
            $('#mpg-ep2d-message').text(message);
            $('#mpg-ep2d-subtitle').hide();
            $('#mpg-ep2d-progress').hide();
        },

        showError: function(message) {
            $('#mpg-ep2d-spinner').html(
                '<svg width="48" height="48" viewBox="0 0 48 48">' +
                '<circle cx="24" cy="24" r="22" fill="#ef4444"/>' +
                '<path d="M16 16l16 16M32 16l-16 16" stroke="white" stroke-width="3" fill="none" stroke-linecap="round"/>' +
                '</svg>'
            );
            $('#mpg-ep2d-title').text('Payment Failed').css('color', '#dc2626');
            $('#mpg-ep2d-message').text(message);
            $('#mpg-ep2d-subtitle').text('Redirecting to checkout...');
            $('#mpg-ep2d-progress').hide();
        },

        showTimeout: function() {
            $('#mpg-ep2d-spinner').html(
                '<svg width="48" height="48" viewBox="0 0 48 48">' +
                '<circle cx="24" cy="24" r="22" fill="none" stroke="#f59e0b" stroke-width="3"/>' +
                '<path d="M24 14v12l8 4" stroke="#f59e0b" stroke-width="3" fill="none" stroke-linecap="round"/>' +
                '</svg>'
            );
            $('#mpg-ep2d-title').text('Still Processing').css('color', '#d97706');
            $('#mpg-ep2d-message').text('Your payment is taking longer than expected to process.');
            $('#mpg-ep2d-subtitle').hide();
            $('#mpg-ep2d-progress').hide();
            $('#mpg-ep2d-timeout').show();
        }
    };

    $(document).ready(function() {
        MPGPolling.init();
    });

})(jQuery);
