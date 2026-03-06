<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MPG_VProcessor_3D extends WC_Payment_Gateway {

    use MPG_Descriptor_Trait;
    use MPG_Percentage_Fee_Trait;

    private $logger;

    public function __construct() {
        $this->id                 = 'mpg_vprocessor_3d';
        $this->method_title       = 'V-Processor 3D';
        $this->method_description = 'vSafe 3D-Secure card payment processing (Visa & Mastercard).';
        $this->has_fields         = true;
        $this->supports           = array( 'products', 'refunds' );

        // Icons: Visa + Mastercard
        $this->icon = '';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );
        $this->testmode    = 'yes' === $this->get_option( 'testmode' );
        $this->merchant_id = $this->testmode ? $this->get_option( 'test_merchant_id' ) : $this->get_option( 'live_merchant_id' );
        $this->api_token   = $this->testmode ? $this->get_option( 'test_api_token' ) : $this->get_option( 'live_api_token' );
        $this->debug       = 'yes' === $this->get_option( 'debug' );

        $this->logger = new MPG_Logger( $this->debug, 'mpg-vprocessor-3d' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        // Sync webhook/redirect URLs only when admin saves settings (not on every page load)
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'sync_webhook_urls' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

        // Descriptor + fee hooks
        $this->init_descriptor_hooks();
        $this->init_percentage_fee_hooks();

        // AJAX polling
        add_action( 'wp_ajax_mpg_vp3d_poll_status', array( $this, 'ajax_poll_status' ) );
        add_action( 'wp_ajax_nopriv_mpg_vp3d_poll_status', array( $this, 'ajax_poll_status' ) );

        // Polling overlay on thank-you page
        add_action( 'woocommerce_before_thankyou', array( $this, 'maybe_show_polling_overlay' ), 1 );

        // Block checkout support
        add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this, 'process_payment_for_block' ), 10, 2 );
    }

    /**
     * Sync webhook/redirect URLs in settings when admin saves.
     */
    public function sync_webhook_urls() {
        $this->settings['webhook_url']  = home_url( '/wc-api/vsafe_webhook' );
        $this->settings['redirect_url'] = home_url( '/wc-api/vsafe_3ds_return' );
        update_option( 'woocommerce_' . $this->id . '_settings', $this->settings );
    }

    public function get_icon() {
        $visa = MPG_PLUGIN_URL . 'assets/img/visa.svg';
        $mc   = MPG_PLUGIN_URL . 'assets/img/mastercard.svg';
        $html = '<img src="' . esc_url( $visa ) . '" alt="Visa" style="max-height:26px;display:inline-block;vertical-align:middle;margin-left:6px" />';
        $html .= '<img src="' . esc_url( $mc ) . '" alt="Mastercard" style="max-height:26px;display:inline-block;vertical-align:middle;margin-left:4px" />';
        return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
    }

    public function init_form_fields() {
        $fields = array(
            'enabled'  => array( 'title' => 'Enable', 'type' => 'checkbox', 'default' => 'no' ),
            'title'    => array( 'title' => 'Title', 'type' => 'text', 'default' => 'ONLY USE V I S A & M A S T E R C A R D  ONLY' ),
            'description' => array( 'title' => 'Description', 'type' => 'textarea', 'default' => 'Pay securely with your Visa or Mastercard (3D-Secure).' ),
            'testmode' => array( 'title' => 'Sandbox Mode', 'type' => 'checkbox', 'label' => 'Enable Sandbox', 'default' => 'yes' ),
            'test_merchant_id' => array( 'title' => 'Sandbox Merchant ID', 'type' => 'text' ),
            'test_api_token'   => array( 'title' => 'Sandbox API Token', 'type' => 'password' ),
            'live_merchant_id' => array( 'title' => 'Live Merchant ID', 'type' => 'text' ),
            'live_api_token'   => array( 'title' => 'Live API Token', 'type' => 'password' ),
            'webhook_url' => array(
                'title'       => 'Webhook URL',
                'type'        => 'text',
                'description' => 'Copy to your vSafe dashboard: <code>' . home_url( '/wc-api/vsafe_webhook' ) . '</code>',
                'default'     => home_url( '/wc-api/vsafe_webhook' ),
                'custom_attributes' => array( 'readonly' => 'readonly' ),
            ),
            'redirect_url' => array(
                'title'       => '3DS Redirect URL',
                'type'        => 'text',
                'description' => 'Default: <code>' . home_url( '/wc-api/vsafe_3ds_return' ) . '</code>',
                'default'     => home_url( '/wc-api/vsafe_3ds_return' ),
            ),
            'debug' => array( 'title' => 'Debug Log', 'type' => 'checkbox', 'label' => 'Enable logging', 'default' => 'yes' ),
        );

        $fields = array_merge( $fields, $this->get_descriptor_form_fields( 'GVM*FRM NEW TECH' ) );
        $fields = array_merge( $fields, $this->get_percentage_fee_form_fields() );

        $this->form_fields = $fields;
    }

    public function payment_scripts() {
        if ( 'no' === $this->enabled ) return;
        if ( is_cart() || is_checkout() || isset( $_GET['pay_for_order'] ) || is_wc_endpoint_url( 'order-received' ) ) {
            wp_enqueue_style( 'mpg-checkout-style', MPG_PLUGIN_URL . 'assets/css/mpg-checkout.css', array(), MPG_VERSION );
        }
        if ( is_cart() || is_checkout() || isset( $_GET['pay_for_order'] ) ) {
            wp_enqueue_script( 'mpg-card-formatting', MPG_PLUGIN_URL . 'assets/js/mpg-card-formatting.js', array(), MPG_VERSION, true );
        }
    }

    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }
        ?>
        <fieldset id="mpg-vp3d-form" class="mpg-card-form wc-credit-card-form wc-payment-form">
            <div class="mpg-field form-row form-row-wide">
                <label>Card Holder Name <span class="required">*</span></label>
                <input id="mpg_vp3d_card_holder_name" name="mpg_vp3d_card_holder_name" class="input-text" type="text" autocomplete="cc-name" placeholder="John Doe" />
            </div>
            <div class="mpg-field form-row form-row-wide">
                <label>Card Number <span class="required">*</span></label>
                <input id="mpg_vp3d_card_number" name="mpg_vp3d_card_number" class="input-text" inputmode="numeric" autocomplete="cc-number" type="text" placeholder="•••• •••• •••• ••••" maxlength="19" />
            </div>
            <div class="mpg-row">
                <div class="mpg-field">
                    <label>Expiry Month <span class="required">*</span></label>
                    <input id="mpg_vp3d_card_expiry_month" name="mpg_vp3d_card_expiry_month" class="input-text" inputmode="numeric" autocomplete="cc-exp-month" type="text" placeholder="MM" maxlength="2" />
                </div>
                <div class="mpg-field">
                    <label>Expiry Year <span class="required">*</span></label>
                    <input id="mpg_vp3d_card_expiry_year" name="mpg_vp3d_card_expiry_year" class="input-text" inputmode="numeric" autocomplete="cc-exp-year" type="text" placeholder="YY" maxlength="2" />
                </div>
            </div>
            <div class="mpg-field form-row form-row-wide">
                <label>CVV <span class="required">*</span></label>
                <input id="mpg_vp3d_card_cvv" name="mpg_vp3d_card_cvv" class="input-text" inputmode="numeric" autocomplete="cc-csc" type="text" placeholder="CVV" maxlength="4" />
            </div>
            <div class="mpg-secure-badge">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Secured with 256-bit encryption</span>
            </div>
        </fieldset>
        <?php
    }

    public function validate_fields() {
        $errors = array();

        $card_holder = isset( $_POST['mpg_vp3d_card_holder_name'] ) ? sanitize_text_field( $_POST['mpg_vp3d_card_holder_name'] ) : '';
        $card_number = isset( $_POST['mpg_vp3d_card_number'] ) ? str_replace( ' ', '', sanitize_text_field( $_POST['mpg_vp3d_card_number'] ) ) : '';
        $exp_month   = isset( $_POST['mpg_vp3d_card_expiry_month'] ) ? sanitize_text_field( $_POST['mpg_vp3d_card_expiry_month'] ) : '';
        $exp_year    = isset( $_POST['mpg_vp3d_card_expiry_year'] ) ? sanitize_text_field( $_POST['mpg_vp3d_card_expiry_year'] ) : '';
        $cvv         = isset( $_POST['mpg_vp3d_card_cvv'] ) ? sanitize_text_field( $_POST['mpg_vp3d_card_cvv'] ) : '';

        if ( empty( $card_holder ) ) {
            $errors[] = 'Card holder name is required.';
        }
        if ( empty( $card_number ) ) {
            $errors[] = 'Card number is required.';
        } elseif ( ! is_numeric( $card_number ) || strlen( $card_number ) < 13 || strlen( $card_number ) > 19 ) {
            $errors[] = 'Please enter a valid card number.';
        }
        if ( empty( $exp_month ) ) {
            $errors[] = 'Expiry month is required.';
        } elseif ( ! is_numeric( $exp_month ) || $exp_month < 1 || $exp_month > 12 ) {
            $errors[] = 'Please enter a valid expiry month (01-12).';
        }
        if ( empty( $exp_year ) ) {
            $errors[] = 'Expiry year is required.';
        } elseif ( ! is_numeric( $exp_year ) || strlen( $exp_year ) != 2 ) {
            $errors[] = 'Please enter a valid 2-digit expiry year.';
        }
        if ( empty( $cvv ) ) {
            $errors[] = 'CVV is required.';
        } elseif ( ! is_numeric( $cvv ) || strlen( $cvv ) < 3 || strlen( $cvv ) > 4 ) {
            $errors[] = 'Please enter a valid CVV (3 or 4 digits).';
        }

        foreach ( $errors as $err ) {
            wc_add_notice( $err, 'error' );
        }

        return empty( $errors );
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        $this->logger->log( '=== VP3D PAYMENT START === Order #' . $order_id );
        $this->logger->log( 'Test Mode: ' . ( $this->testmode ? 'YES' : 'NO' ) );
        $this->logger->log( 'Merchant ID: ' . $this->merchant_id );

        $card_holder = sanitize_text_field( $_POST['mpg_vp3d_card_holder_name'] ?? '' );
        $card_number = str_replace( ' ', '', sanitize_text_field( $_POST['mpg_vp3d_card_number'] ?? '' ) );
        $exp_month   = intval( $_POST['mpg_vp3d_card_expiry_month'] ?? 0 );
        $exp_year    = intval( $_POST['mpg_vp3d_card_expiry_year'] ?? 0 );
        $cvv         = sanitize_text_field( $_POST['mpg_vp3d_card_cvv'] ?? '' );

        $this->logger->log( 'Card Number (masked): ' . substr( $card_number, 0, 6 ) . '******' . substr( $card_number, -4 ) );
        $this->logger->log( 'Expiry: ' . $exp_month . '/' . $exp_year );
        $this->logger->log( 'CVV length: ' . strlen( $cvv ) );

        // Unique reference per attempt to avoid processor duplicate detection
        $attempt_ref = $order_id . '-' . substr( md5( wp_generate_password( 12, false ) ), 0, 6 );
        $order->update_meta_data( '_mpg_vp3d_external_ref', $attempt_ref );
        $order->save();

        // Build v2.0 request body
        $request_body = array(
            'serviceSecurity' => array( 'merchantId' => intval( $this->merchant_id ) ),
            'transactionDetails' => array(
                'amount'            => floatval( $order->get_total() ),
                'currency'          => $order->get_currency(),
                'externalReference' => $attempt_ref,
            ),
            'cardDetails' => array(
                'cardHolderName'  => $card_holder,
                'cardNumber'      => $card_number,
                'cvv'             => $cvv,
                'expirationMonth' => sprintf( '%02d', $exp_month ),
                'expirationYear'  => intval( $exp_year ),
            ),
            'payerDetails' => array(
                'firstName' => $order->get_billing_first_name(),
                'lastName'  => $order->get_billing_last_name(),
                'email'     => $order->get_billing_email(),
                'phone'     => preg_replace( '/\D/', '', $order->get_billing_phone() ),
                'address'   => array(
                    'street'  => $order->get_billing_address_1(),
                    'city'    => $order->get_billing_city(),
                    'state'   => $order->get_billing_state(),
                    'country' => $order->get_billing_country(),
                    'zipCode' => $order->get_billing_postcode(),
                ),
            ),
        );

        // Log the request (without sensitive card data)
        $this->logger->log( 'Request Body (safe): ' . wp_json_encode( array(
            'serviceSecurity'    => $request_body['serviceSecurity'],
            'transactionDetails' => $request_body['transactionDetails'],
            'payerDetails'       => $request_body['payerDetails'],
        ) ) );

        $env = $this->testmode ? 'sandbox' : 'live';
        $url = MPG_VProcessor_API::endpoint( $env, 'charges', '2' );

        $this->logger->log( 'API URL: ' . $url );

        $json = wp_json_encode( $request_body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $sig  = MPG_VProcessor_API::sign( $this->api_token, $json );

        $this->logger->log( 'Signature: ' . $sig );

        $response = wp_remote_post( $url, array(
            'headers' => array( 'Content-Type' => 'application/json', 'Signature' => $sig ),
            'body'    => $json,
            'timeout' => 70,
        ));

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'HTTP Error: ' . $response->get_error_message() );
            wc_add_notice( 'Connection error. Please try again.', 'error' );
            return array( 'result' => 'failure' );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        $this->logger->log( 'Response Code: ' . $response_code );
        $this->logger->log( 'Response Body: ' . $response_body );

        $result = json_decode( $response_body, true );

        // Log key response fields
        $this->logger->log( 'Transaction ID: ' . ( $result['transactionId'] ?? 'N/A' ) );
        $this->logger->log( 'Result Status: ' . ( $result['result']['status'] ?? 'N/A' ) );
        if ( ! empty( $result['result']['errorCode'] ) ) {
            $this->logger->log( 'Error: ' . ( $result['result']['errorCode'] ?? '' ) . ' - ' . ( $result['result']['errorDetail'] ?? '' ) );
        }
        if ( ! empty( $result['result']['redirectUrl'] ) || ! empty( $result['redirectUrl'] ) ) {
            $this->logger->log( 'Redirect URL present: yes' );
        }

        // Store transaction ID
        if ( isset( $result['transactionId'] ) ) {
            $order->update_meta_data( '_mpg_vp3d_transaction_id', $result['transactionId'] );
            $order->save();
        }

        if ( ! isset( $result['result']['status'] ) ) {
            wc_add_notice( 'Unexpected response.', 'error' );
            return array( 'result' => 'failure' );
        }

        $status = $result['result']['status'];

        if ( $status === 'pending' ) {
            // Check for 3DS redirect URL
            $redirect_url = '';
            if ( ! empty( $result['result']['redirectUrl'] ) ) {
                $redirect_url = $result['result']['redirectUrl'];
            } elseif ( ! empty( $result['redirectUrl'] ) ) {
                $redirect_url = $result['redirectUrl'];
            }

            if ( ! empty( $redirect_url ) ) {
                $this->logger->log( '3DS redirect: ' . $redirect_url );
                $order->update_status( 'pending', 'Awaiting 3DS authentication.' );
                WC()->cart->empty_cart();
                return array( 'result' => 'success', 'redirect' => $redirect_url );
            }

            // Check if webhook already arrived (race condition)
            if ( function_exists( 'wp_cache_delete' ) ) {
                wp_cache_delete( 'order-' . $order_id, 'orders' );
                wp_cache_delete( $order_id, 'posts' );
            }
            $fresh = wc_get_order( $order_id );
            if ( $fresh ) {
                $existing_redirect = $fresh->get_meta( '_mpg_vp3d_3ds_redirect_url' );
                if ( ! empty( $existing_redirect ) ) {
                    WC()->cart->empty_cart();
                    return array( 'result' => 'success', 'redirect' => $existing_redirect );
                }
                $existing_status = $fresh->get_meta( '_mpg_vp3d_webhook_status' );
                if ( $existing_status === 'approved' || $fresh->has_status( array( 'processing', 'completed' ) ) ) {
                    WC()->cart->empty_cart();
                    return array( 'result' => 'success', 'redirect' => $this->get_return_url( $fresh ) );
                }
            }

            // Set up polling — stock will be reduced when webhook confirms payment
            $order->update_status( 'pending', 'Awaiting VSafe webhook.' );
            $order->update_meta_data( '_mpg_vp3d_awaiting_webhook', 'yes' );
            $order->update_meta_data( '_mpg_vp3d_webhook_status', 'waiting' );
            $order->save();
            WC()->cart->empty_cart();

            $polling_url = add_query_arg( array(
                'mpg_vp3d_poll' => '1',
                'order_id'      => $order_id,
                'key'           => $order->get_order_key(),
            ), $order->get_checkout_order_received_url() );

            return array( 'result' => 'success', 'redirect' => $polling_url );

        } elseif ( $status === 'approved' ) {
            $order->payment_complete( $result['transactionId'] );
            $order->add_order_note( 'V-Processor 3D payment approved (direct). TX: ' . $result['transactionId'] );
            wc_reduce_stock_levels( $order_id );
            return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
        } else {
            $error_msg  = $result['result']['errorDetail'] ?? 'Payment failed';
            $error_code = $result['result']['errorCode'] ?? '';
            $this->logger->log( 'Payment failed. Code: ' . $error_code . ' Detail: ' . $error_msg );
            $order->update_status( 'failed', 'VP3D: [' . $error_code . '] ' . $error_msg );
            wc_add_notice( MPG_VProcessor_API::friendly_error( $error_code ), 'error' );
            return array( 'result' => 'failure' );
        }
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        $tx    = $order->get_meta( '_mpg_vp3d_transaction_id' );

        if ( ! $tx ) return new WP_Error( 'no_tx', 'No transaction ID found.' );

        $env = $this->testmode ? 'sandbox' : 'live';
        // Refunds always use v1
        $url = MPG_VProcessor_API::endpoint( $env, 'refunds', '1' );

        $body = array(
            'serviceSecurity'    => array( 'merchantId' => intval( $this->merchant_id ) ),
            'transactionDetails' => array(
                'amount'        => floatval( $amount ),
                'currency'      => $order->get_currency(),
                'transactionId' => $tx,
                'commentaries'  => $reason,
            ),
        );

        $json = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $sig  = MPG_VProcessor_API::sign( $this->api_token, $json );

        $response = wp_remote_post( $url, array(
            'headers' => array( 'Content-Type' => 'application/json', 'Signature' => $sig ),
            'body'    => $json,
            'timeout' => 60,
        ));

        if ( is_wp_error( $response ) ) return new WP_Error( 'http', $response->get_error_message() );

        $result = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $result['result']['status'] ) && $result['result']['status'] === 'approved' ) {
            $order->add_order_note( 'V-Processor 3D refund approved. TX: ' . $tx );
            return true;
        }

        return new WP_Error( 'refund_fail', $result['result']['errorDetail'] ?? 'Refund failed' );
    }

    /* ─── AJAX Polling ─── */
    public function ajax_poll_status() {
        check_ajax_referer( 'mpg_vp3d_poll_nonce', 'nonce' );

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

        $webhook_status = $order->get_meta( '_mpg_vp3d_webhook_status' );
        $redirect_url   = $order->get_meta( '_mpg_vp3d_3ds_redirect_url' );

        if ( ! empty( $redirect_url ) ) {
            wp_send_json_success( array( 'status' => 'redirect_3ds', 'redirect_url' => $redirect_url ) );
        }
        if ( $webhook_status === 'approved' || $order->has_status( array( 'processing', 'completed' ) ) ) {
            wp_send_json_success( array( 'status' => 'approved', 'redirect_url' => $this->get_return_url( $order ) ) );
        }
        if ( in_array( $webhook_status, array( 'declined', 'error' ) ) || $order->has_status( 'failed' ) ) {
            wp_send_json_success( array( 'status' => 'failed', 'redirect_url' => wc_get_checkout_url() ) );
        }

        wp_send_json_success( array( 'status' => 'waiting' ) );
    }

    /* ─── Polling overlay ─── */
    public function maybe_show_polling_overlay( $order_id ) {
        if ( ! isset( $_GET['mpg_vp3d_poll'] ) || $_GET['mpg_vp3d_poll'] !== '1' ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) return;

        $order_key = isset( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : $order->get_order_key();

        wp_enqueue_script( 'mpg-vp3d-polling', MPG_PLUGIN_URL . 'assets/js/mpg-vp3d-polling.js', array( 'jquery' ), MPG_VERSION, true );
        wp_localize_script( 'mpg-vp3d-polling', 'mpg_vp3d_polling', array(
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'mpg_vp3d_poll_nonce' ),
            'order_id'     => $order_id,
            'order_key'    => $order_key,
            'timeout'      => 90,
            'interval'     => 3000,
            'thankyou_url' => $this->get_return_url( $order ),
            'checkout_url' => wc_get_checkout_url(),
        ));

        ?>
        <div id="mpg-vp3d-polling-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.97);z-index:999999;display:flex;align-items:center;justify-content:center;flex-direction:column;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
            <div style="text-align:center;max-width:400px;padding:40px;">
                <div id="mpg-vp3d-spinner" style="margin:0 auto 24px;">
                    <svg width="48" height="48" viewBox="0 0 48 48" style="animation:mpg-spin 1s linear infinite;">
                        <circle cx="24" cy="24" r="20" fill="none" stroke="#e5e7eb" stroke-width="4"/>
                        <circle cx="24" cy="24" r="20" fill="none" stroke="#3b82f6" stroke-width="4" stroke-dasharray="80" stroke-dashoffset="60" stroke-linecap="round"/>
                    </svg>
                </div>
                <h2 id="mpg-vp3d-title" style="margin:0 0 8px;font-size:20px;font-weight:600;color:#111827;">Processing your payment</h2>
                <p id="mpg-vp3d-message" style="margin:0 0 24px;font-size:15px;color:#6b7280;line-height:1.5;">Please wait while we securely verify your payment. This may take up to a minute.</p>
                <p id="mpg-vp3d-subtitle" style="margin:0;font-size:13px;color:#9ca3af;">Do not close this window or press back.</p>
                <div id="mpg-vp3d-progress" style="margin-top:24px;width:100%;height:4px;background:#e5e7eb;border-radius:2px;overflow:hidden;">
                    <div id="mpg-vp3d-progress-bar" style="height:100%;width:0%;background:#3b82f6;border-radius:2px;transition:width 3s linear;"></div>
                </div>
                <div id="mpg-vp3d-timeout" style="display:none;margin-top:24px;">
                    <p style="font-size:14px;color:#f59e0b;margin:0 0 12px;">Your payment is still being processed.</p>
                    <p style="font-size:13px;color:#6b7280;margin:0;">You will receive an email confirmation once your payment is confirmed. You may safely close this page.</p>
                </div>
            </div>
        </div>
        <style>@keyframes mpg-spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }</style>
        <?php
    }

    /* ─── Block checkout bridge ─── */
    public function process_payment_for_block( $context, &$result ) {
        if ( $context->payment_method !== $this->id ) return;
        $pd = isset( $context->payment_data ) ? $context->payment_data : array();
        $map = array(
            'mpg_vp3d_card_holder_name'  => 'mpg_vp3d_card_holder_name',
            'mpg_vp3d_card_number'       => 'mpg_vp3d_card_number',
            'mpg_vp3d_card_expiry_month' => 'mpg_vp3d_card_expiry_month',
            'mpg_vp3d_card_expiry_year'  => 'mpg_vp3d_card_expiry_year',
            'mpg_vp3d_card_cvv'          => 'mpg_vp3d_card_cvv',
        );
        foreach ( $map as $k => $v ) {
            if ( isset( $pd[ $k ] ) ) $_POST[ $v ] = sanitize_text_field( $pd[ $k ] );
        }
    }
}
