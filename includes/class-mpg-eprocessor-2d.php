<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MPG_EProcessor_2D extends WC_Payment_Gateway {

    use MPG_Descriptor_Trait;
    use MPG_Percentage_Fee_Trait;

    private $logger;
    public $account_id;
    public $account_password;
    public $account_passphrase;
    public $account_gateway;
    public $transaction_prefix;

    public function __construct() {
        $this->id                 = 'mpg_eprocessor_2d';
        $this->method_title       = 'E-Processor 2D';
        $this->method_description = 'EuPaymentz Direct 2D card payment (no 3DS).';
        $this->has_fields         = true;
        $this->supports           = array( 'products', 'refunds' );
        $this->icon               = '';

        $this->init_form_fields();
        $this->init_settings();

        $this->title              = $this->get_option( 'title' );
        $this->description        = $this->get_option( 'description' );
        $this->enabled            = $this->get_option( 'enabled' );
        $this->testmode           = 'yes' === $this->get_option( 'testmode' );
        $this->account_id         = $this->get_option( 'account_id' );
        $this->account_password   = $this->get_option( 'account_password' );
        $this->account_passphrase = $this->get_option( 'account_passphrase' );
        $this->account_gateway    = $this->get_option( 'account_gateway', '1' );
        $this->transaction_prefix = $this->get_option( 'transaction_prefix', 'WC-' );
        $this->debug              = 'yes' === $this->get_option( 'debug' );

        $this->logger = new MPG_Logger( $this->debug, 'mpg-eprocessor-2d' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        $this->init_descriptor_hooks();
        $this->init_percentage_fee_hooks();

        // Block checkout support
        add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this, 'process_payment_for_block' ), 10, 2 );

        // AJAX polling for PEND status
        add_action( 'wp_ajax_mpg_ep2d_poll_status', array( $this, 'ajax_poll_status' ) );
        add_action( 'wp_ajax_nopriv_mpg_ep2d_poll_status', array( $this, 'ajax_poll_status' ) );

        // Polling overlay on thank-you page
        add_action( 'woocommerce_before_thankyou', array( $this, 'maybe_show_polling_overlay' ), 1 );
    }

    public function get_icon() {
        $visa = MPG_PLUGIN_URL . 'assets/img/visa.svg';
        $mc   = MPG_PLUGIN_URL . 'assets/img/mastercard.svg';
        return apply_filters( 'woocommerce_gateway_icon',
            '<img src="' . esc_url( $visa ) . '" alt="Visa" style="max-height:26px;display:inline-block;vertical-align:middle;margin-left:6px" />' .
            '<img src="' . esc_url( $mc ) . '" alt="Mastercard" style="max-height:26px;display:inline-block;vertical-align:middle;margin-left:4px" />',
            $this->id
        );
    }

    public function init_form_fields() {
        $fields = array(
            'enabled'            => array( 'title' => 'Enable', 'type' => 'checkbox', 'default' => 'no' ),
            'title'              => array( 'title' => 'Title', 'type' => 'text', 'default' => 'ONLY USE V I S A & M A S T E R C A R D  ONLY' ),
            'description'        => array( 'title' => 'Description', 'type' => 'textarea', 'default' => 'Pay securely using your Visa or Mastercard (Direct).' ),
            'testmode'           => array( 'title' => 'Test Mode', 'type' => 'checkbox', 'label' => 'Enable Test Mode', 'default' => 'yes' ),
            'account_id'         => array( 'title' => 'Account ID', 'type' => 'text', 'description' => 'Your EuPaymentz account ID.', 'desc_tip' => true ),
            'account_password'   => array( 'title' => 'Account Password', 'type' => 'password', 'description' => 'Your EuPaymentz account password.', 'desc_tip' => true ),
            'account_passphrase' => array( 'title' => 'Account Passphrase', 'type' => 'password', 'description' => 'Your EuPaymentz passphrase for SHA256 hash generation.', 'desc_tip' => true ),
            'account_gateway'    => array( 'title' => 'Gateway Account', 'type' => 'text', 'description' => 'Always "1" unless specified by EuPaymentz.', 'default' => '1', 'desc_tip' => true ),
            'transaction_prefix' => array( 'title' => 'Transaction Prefix', 'type' => 'text', 'description' => 'Prefix for transaction IDs.', 'default' => 'WC-', 'desc_tip' => true ),
            'debug'              => array( 'title' => 'Debug Log', 'type' => 'checkbox', 'label' => 'Enable logging', 'default' => 'yes' ),
        );
        $fields = array_merge( $fields, $this->get_descriptor_form_fields( 'CHARMTRAIL SOUVENIRS VENTURES' ) );
        $fields = array_merge( $fields, $this->get_percentage_fee_form_fields() );
        $this->form_fields = $fields;
    }

    public function enqueue_assets() {
        if ( ! is_checkout() ) return;
        wp_enqueue_style( 'mpg-checkout-style', MPG_PLUGIN_URL . 'assets/css/mpg-checkout.css', array(), MPG_VERSION );
        wp_enqueue_script( 'mpg-card-formatting', MPG_PLUGIN_URL . 'assets/js/mpg-card-formatting.js', array(), MPG_VERSION, true );
    }

    public function payment_fields() {
        if ( $this->description ) echo wpautop( wp_kses_post( $this->description ) );
        ?>
        <fieldset id="mpg-ep2d-form" class="mpg-card-form wc-credit-card-form wc-payment-form">
            <div class="mpg-field">
                <label>Card Holder Name <span class="required">*</span></label>
                <input type="text" name="mpg_ep2d_card_name" autocomplete="cc-name" placeholder="John Doe" required />
            </div>
            <div class="mpg-field">
                <label>Card Number <span class="required">*</span></label>
                <input type="text" name="mpg_ep2d_card_number" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000" maxlength="23" required />
            </div>
            <div class="mpg-row">
                <div class="mpg-field">
                    <label>Expiry <span class="required">*</span></label>
                    <input type="text" name="mpg_ep2d_expiry" inputmode="numeric" autocomplete="cc-exp" placeholder="MM / YY" maxlength="7" required />
                </div>
                <div class="mpg-field">
                    <label>CVC <span class="required">*</span></label>
                    <input type="text" name="mpg_ep2d_cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="&bull;&bull;&bull;" maxlength="4" required />
                </div>
            </div>
            <div class="mpg-secure-badge">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Secured with 256-bit encryption</span>
            </div>
        </fieldset>
        <?php
    }

    /* ─── Block checkout bridge ─── */
    public function process_payment_for_block( $context, &$result ) {
        if ( $context->payment_method !== $this->id ) return;
        $pd = isset( $context->payment_data ) ? $context->payment_data : array();
        $map = array(
            'mpg_ep2d_card_name'   => 'mpg_ep2d_card_name',
            'mpg_ep2d_card_number' => 'mpg_ep2d_card_number',
            'mpg_ep2d_expiry'      => 'mpg_ep2d_expiry',
            'mpg_ep2d_cvv'         => 'mpg_ep2d_cvv',
        );
        foreach ( $map as $k => $v ) {
            if ( isset( $pd[ $k ] ) ) $_POST[ $v ] = sanitize_text_field( $pd[ $k ] );
        }
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        $this->logger->log( '=== EP2D PAYMENT START === Order #' . $order_id );

        // Get card data
        $card_number = str_replace( ' ', '', sanitize_text_field( $_POST['mpg_ep2d_card_number'] ?? '' ) );
        $expiry      = str_replace( ' ', '', sanitize_text_field( $_POST['mpg_ep2d_expiry'] ?? '' ) );
        $cvv         = sanitize_text_field( $_POST['mpg_ep2d_cvv'] ?? '' );

        $expiry_parts = explode( '/', $expiry );
        $exp_month    = trim( $expiry_parts[0] ?? '' );
        $exp_year     = trim( $expiry_parts[1] ?? '' );
        if ( strlen( $exp_year ) === 2 ) $exp_year = '20' . $exp_year;

        // Build base payment data
        $data = MPG_EProcessor_API::build_base_data( $this, $order );

        // Add card details
        $data['transac_cc_number'] = $card_number;
        $data['transac_cc_month']  = str_pad( $exp_month, 2, '0', STR_PAD_LEFT );
        $data['transac_cc_year']   = $exp_year;
        $data['transac_cc_cvc']    = $cvv;

        // Generate SHA WITH card number
        $data['account_sha'] = MPG_EProcessor_API::sha_with_card(
            $this->account_passphrase,
            $data['transac_amount'],
            $this->account_id,
            $data['cust_email'],
            $card_number,
            $data['customer_ip']
        );

        // Send to API
        $response = MPG_EProcessor_API::post( MPG_EProcessor_API::PROCESS_URL, $data );
        $result   = MPG_EProcessor_API::parse_response( $response );

        if ( ! $result ) {
            $this->logger->log( 'No valid response from API' );
            wc_add_notice( 'Payment gateway error. Please try again.', 'error' );
            return array( 'result' => 'failure' );
        }

        $this->logger->log( 'Response: ' . wp_json_encode( $result ) );

        // Check for redirect (shouldn't happen in direct mode, but handle it)
        if ( isset( $result['isDirectResult'] ) && $result['isDirectResult'] === false ) {
            $redirect_url = MPG_EProcessor_API::build_redirect_url( $result );
            if ( ! empty( $redirect_url ) ) {
                $order->update_meta_data( '_mpg_ep_transaction_id', $result['resp_trans_id'] ?? '' );
                $order->save();
                return array( 'result' => 'success', 'redirect' => $redirect_url );
            }
        }

        // Direct response
        if ( isset( $result['resp_trans_status'] ) ) {
            // Verify SHA
            if ( ! MPG_EProcessor_API::verify_response_sha( $this->account_passphrase, $result ) ) {
                $this->logger->log( 'SHA verification failed!' );
                wc_add_notice( 'Payment verification failed.', 'error' );
                return array( 'result' => 'failure' );
            }

            $parsed = MPG_EProcessor_API::parse_transaction_status( $result );
            $order->update_meta_data( '_mpg_ep_transaction_id', $parsed['transaction_id'] );
            $order->update_meta_data( '_mpg_ep_status', $parsed['status'] );

            if ( $parsed['is_success'] ) {
                $order->save();
                // Refresh to check if callback already completed
                clean_post_cache( $order_id );
                if ( function_exists( 'wp_cache_delete' ) ) {
                    wp_cache_delete( 'order-' . $order_id, 'orders' );
                    wp_cache_delete( $order_id, 'posts' );
                }
                $fresh = wc_get_order( $order_id );
                if ( ! $fresh || $fresh->has_status( array( 'processing', 'completed' ) ) ) {
                    return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
                }
                $order->payment_complete( $parsed['transaction_id'] );
                $order->add_order_note( 'E-Processor 2D payment completed. TX: ' . $parsed['transaction_id'] );
                return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
            }

            if ( $parsed['is_pending'] ) {
                $order->update_status( 'pending', 'Payment pending, awaiting callback. TX: ' . $parsed['transaction_id'] );
                $order->update_meta_data( '_mpg_ep2d_awaiting_callback', 'yes' );
                $order->update_meta_data( '_mpg_ep2d_callback_status', 'waiting' );
                $order->save();
                WC()->cart->empty_cart();

                $polling_url = add_query_arg( array(
                    'mpg_ep2d_poll' => '1',
                    'order_id'      => $order_id,
                    'key'           => $order->get_order_key(),
                ), $order->get_checkout_order_received_url() );

                return array( 'result' => 'success', 'redirect' => $polling_url );
            }

            // Failed
            $order->update_status( 'failed', $parsed['description'] );
            $order->save();
            wc_add_notice( $parsed['description'] ?: 'Payment failed.', 'error' );
            return array( 'result' => 'failure' );
        }

        wc_add_notice( 'Invalid gateway response.', 'error' );
        return array( 'result' => 'failure' );
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        $tx    = $order->get_meta( '_mpg_ep_transaction_id' );
        if ( ! $tx ) return new WP_Error( 'no_tx', 'No transaction ID found.' );

        $data = array(
            'account_id'       => $this->account_id,
            'account_password' => $this->account_password,
            'account_sha'      => MPG_EProcessor_API::sha_refund( $this->account_passphrase, $this->account_id, $tx ),
            'trans_id'         => $tx,
            'option'           => '',
        );
        if ( $amount !== null ) {
            $data['transac_amount'] = number_format( (float) $amount, 2, '.', '' );
        }

        $response = MPG_EProcessor_API::post( MPG_EProcessor_API::REFUND_URL, $data );
        $result   = MPG_EProcessor_API::parse_response( $response );

        if ( ! $result ) return new WP_Error( 'api_error', 'No response from payment gateway.' );

        if ( isset( $result['resp_trans_status'] ) && $result['resp_trans_status'] === '00000' ) {
            $order->add_order_note( 'E-Processor 2D refund approved. TX: ' . $tx );
            return true;
        }

        $desc = $result['resp_trans_description_status'] ?? 'Refund failed.';
        return new WP_Error( 'refund_fail', $desc );
    }

    /* ─── Callback handler (shared by all E-Processor types) ─── */
    public function process_callback( $data ) {
        $this->logger->log( 'EP Callback: ' . wp_json_encode( $data ) );

        $order_id = isset( $data['resp_merchant_data1'] ) ? intval( $data['resp_merchant_data1'] ) : 0;
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Verify order key (compare first 20 chars — API response truncates to varchar(20))
        if ( isset( $data['resp_merchant_data2'] ) && ! empty( $data['resp_merchant_data2'] ) ) {
            if ( substr( $order->get_order_key(), 0, 20 ) !== substr( $data['resp_merchant_data2'], 0, 20 ) ) {
                $this->logger->log( 'Order key mismatch in callback' );
                return;
            }
        }

        if ( $order->has_status( array( 'processing', 'completed' ) ) ) return;

        // Use the passphrase from the gateway that processed this order
        $passphrase = $this->get_passphrase_for_order( $order );

        // Verify SHA
        if ( ! MPG_EProcessor_API::verify_response_sha( $passphrase, $data ) ) {
            $this->logger->log( 'Callback SHA verification failed!' );
            return;
        }

        $parsed = MPG_EProcessor_API::parse_transaction_status( $data );
        $order->update_meta_data( '_mpg_ep_transaction_id', $parsed['transaction_id'] );
        $order->update_meta_data( '_mpg_ep_status', $parsed['status'] );

        if ( $parsed['is_success'] ) {
            // Refresh order from DB — callback may race with direct response
            clean_post_cache( $order_id );
            if ( function_exists( 'wp_cache_delete' ) ) {
                wp_cache_delete( 'order-' . $order_id, 'orders' );
                wp_cache_delete( $order_id, 'posts' );
            }
            $fresh = wc_get_order( $order_id );
            if ( $fresh && $fresh->has_status( array( 'processing', 'completed' ) ) ) return;

            $order->update_meta_data( '_mpg_ep2d_callback_status', 'approved' );
            $order->save();
            $order->payment_complete( $parsed['transaction_id'] );
            $order->add_order_note( 'E-Processor callback: approved. TX: ' . $parsed['transaction_id'] );
        } elseif ( $parsed['is_pending'] ) {
            $order->update_meta_data( '_mpg_ep2d_callback_status', 'waiting' );
            $order->update_status( 'on-hold', 'Payment pending via callback. TX: ' . $parsed['transaction_id'] );
            $order->save();
        } else {
            $order->update_meta_data( '_mpg_ep2d_callback_status', 'declined' );
            $order->update_status( 'failed', 'Payment failed via callback: ' . $parsed['description'] );
            $order->save();
        }
    }

    /**
     * Get the correct passphrase for an order's payment method.
     */
    private function get_passphrase_for_order( $order ) {
        $method = $order->get_payment_method();
        if ( $method === $this->id ) {
            return $this->account_passphrase;
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( isset( $gateways[ $method ] ) && isset( $gateways[ $method ]->account_passphrase ) ) {
            return $gateways[ $method ]->account_passphrase;
        }
        return $this->account_passphrase;
    }

    /* ─── Return handler (shared by all E-Processor types) ─── */
    public function process_return( $data ) {
        $this->logger->log( 'EP Return: ' . wp_json_encode( $data ) );

        $order_id = isset( $data['order_id'] ) ? intval( $data['order_id'] ) : 0;
        if ( ! $order_id ) { wp_redirect( wc_get_page_permalink( 'cart' ) ); exit; }

        $order = wc_get_order( $order_id );
        if ( ! $order ) { wp_redirect( wc_get_page_permalink( 'cart' ) ); exit; }

        // If order is already finalized (by direct response or callback), just redirect
        if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
            wp_redirect( $this->get_return_url( $order ) );
            exit;
        }

        // Process response data if present and order not yet finalized
        if ( isset( $data['resp_trans_status'] ) && ! $order->has_status( array( 'processing', 'completed' ) ) ) {
            $passphrase = $this->get_passphrase_for_order( $order );
            if ( MPG_EProcessor_API::verify_response_sha( $passphrase, $data ) ) {
                $parsed = MPG_EProcessor_API::parse_transaction_status( $data );
                $order->update_meta_data( '_mpg_ep_transaction_id', $parsed['transaction_id'] );
                if ( $parsed['is_success'] ) {
                    $order->save();
                    $order->payment_complete( $parsed['transaction_id'] );
                    $order->add_order_note( 'E-Processor return: approved. TX: ' . $parsed['transaction_id'] );
                } elseif ( $parsed['is_pending'] ) {
                    $order->update_status( 'on-hold', 'Pending via return. TX: ' . $parsed['transaction_id'] );
                    $order->save();
                } else {
                    $order->update_status( 'failed', $parsed['description'] );
                    $order->save();
                }
            }
        }

        if ( $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
            wp_redirect( $this->get_return_url( $order ) );
        } else {
            wc_add_notice( 'Payment was not completed. Please try again.', 'error' );
            wp_redirect( $order->get_cancel_order_url() );
        }
        exit;
    }

    /* ─── AJAX Polling ─── */
    public function ajax_poll_status() {
        check_ajax_referer( 'mpg_ep2d_poll_nonce', 'nonce' );

        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $order_key = sanitize_text_field( $_POST['order_key'] ?? '' );

        if ( ! $order_id || ! $order_key ) wp_send_json_error();

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_order_key() !== $order_key ) wp_send_json_error();

        // Clear cache for HPOS and legacy post storage
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( 'order-' . $order_id, 'orders' );
            wp_cache_delete( $order_id, 'posts' );
        }
        $order = wc_get_order( $order_id );

        $callback_status = $order->get_meta( '_mpg_ep2d_callback_status' );

        if ( $callback_status === 'approved' || $order->has_status( array( 'processing', 'completed' ) ) ) {
            wp_send_json_success( array( 'status' => 'approved', 'redirect_url' => $this->get_return_url( $order ) ) );
        }
        if ( in_array( $callback_status, array( 'declined', 'error' ) ) || $order->has_status( 'failed' ) ) {
            wp_send_json_success( array( 'status' => 'failed', 'redirect_url' => wc_get_checkout_url() ) );
        }

        wp_send_json_success( array( 'status' => 'waiting' ) );
    }

    /* ─── Polling overlay ─── */
    public function maybe_show_polling_overlay( $order_id ) {
        if ( ! isset( $_GET['mpg_ep2d_poll'] ) || $_GET['mpg_ep2d_poll'] !== '1' ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) return;

        $order_key = isset( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : $order->get_order_key();

        wp_enqueue_script( 'mpg-ep2d-polling', MPG_PLUGIN_URL . 'assets/js/mpg-ep2d-polling.js', array( 'jquery' ), MPG_VERSION, true );
        wp_localize_script( 'mpg-ep2d-polling', 'mpg_ep2d_polling', array(
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'mpg_ep2d_poll_nonce' ),
            'order_id'     => $order_id,
            'order_key'    => $order_key,
            'timeout'      => 90,
            'interval'     => 3000,
            'thankyou_url' => $this->get_return_url( $order ),
            'checkout_url' => wc_get_checkout_url(),
        ));

        ?>
        <div id="mpg-ep2d-polling-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.97);z-index:999999;display:flex;align-items:center;justify-content:center;flex-direction:column;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
            <div style="text-align:center;max-width:400px;padding:40px;">
                <div id="mpg-ep2d-spinner" style="margin:0 auto 24px;">
                    <svg width="48" height="48" viewBox="0 0 48 48" style="animation:mpg-spin 1s linear infinite;">
                        <circle cx="24" cy="24" r="20" fill="none" stroke="#e5e7eb" stroke-width="4"/>
                        <circle cx="24" cy="24" r="20" fill="none" stroke="#3b82f6" stroke-width="4" stroke-dasharray="80" stroke-dashoffset="60" stroke-linecap="round"/>
                    </svg>
                </div>
                <h2 id="mpg-ep2d-title" style="margin:0 0 8px;font-size:20px;font-weight:600;color:#111827;">Processing your payment</h2>
                <p id="mpg-ep2d-message" style="margin:0 0 24px;font-size:15px;color:#6b7280;line-height:1.5;">Please wait while we securely verify your payment. This may take up to a minute.</p>
                <p id="mpg-ep2d-subtitle" style="margin:0;font-size:13px;color:#9ca3af;">Do not close this window or press back.</p>
                <div id="mpg-ep2d-progress" style="margin-top:24px;width:100%;height:4px;background:#e5e7eb;border-radius:2px;overflow:hidden;">
                    <div id="mpg-ep2d-progress-bar" style="height:100%;width:0%;background:#3b82f6;border-radius:2px;transition:width 3s linear;"></div>
                </div>
                <div id="mpg-ep2d-timeout" style="display:none;margin-top:24px;">
                    <p style="font-size:14px;color:#f59e0b;margin:0 0 12px;">Your payment is still being processed.</p>
                    <p style="font-size:13px;color:#6b7280;margin:0;">You will receive an email confirmation once your payment is confirmed. You may safely close this page.</p>
                </div>
            </div>
        </div>
        <style>@keyframes mpg-spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }</style>
        <?php
    }
}
