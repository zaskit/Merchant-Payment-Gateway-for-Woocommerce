<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MPG_VProcessor_2D extends WC_Payment_Gateway {

    use MPG_Descriptor_Trait;
    use MPG_Percentage_Fee_Trait;

    private $logger;

    public function __construct() {
        $this->id                 = 'mpg_vprocessor_2d';
        $this->method_title       = 'V-Processor 2D';
        $this->method_description = 'vSafe 2D direct card payment processing (Mastercard only).';
        $this->has_fields         = true;
        $this->supports           = array( 'products', 'refunds' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        // Icon: Mastercard only
        $this->icon = MPG_PLUGIN_URL . 'assets/img/mastercard.svg';

        // Credentials
        $this->merchant_id = $this->get_option( 'merchant_id' );
        $this->api_key     = $this->get_option( 'api_key' );
        $this->environment = $this->get_option( 'environment', 'sandbox' );
        $this->debug       = 'yes' === $this->get_option( 'debug' );

        $this->logger = new MPG_Logger( $this->debug, 'mpg-vprocessor-2d' );

        // Save settings
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        // Enqueue assets
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Descriptor hooks (thank-you + emails)
        $this->init_descriptor_hooks();

        // Percentage fee hooks
        $this->init_percentage_fee_hooks();
    }

    public function init_form_fields() {
        $fields = array(
            'enabled' => array(
                'title'   => 'Enable',
                'type'    => 'checkbox',
                'default' => 'no',
            ),
            'title' => array(
                'title'   => 'Title',
                'type'    => 'text',
                'default' => 'ONLY USE M A S T E R C A R D | DO NOT USE V I S A',
            ),
            'description' => array(
                'title'   => 'Description',
                'type'    => 'textarea',
                'default' => 'Pay securely using your Mastercard.',
            ),
            'merchant_id' => array(
                'title' => 'Merchant ID',
                'type'  => 'text',
            ),
            'api_key' => array(
                'title' => 'API Token',
                'type'  => 'password',
            ),
            'environment' => array(
                'title'   => 'Environment',
                'type'    => 'select',
                'options' => array( 'sandbox' => 'Sandbox', 'live' => 'Live' ),
                'default' => 'sandbox',
            ),
            'debug' => array(
                'title'   => 'Debug Log',
                'type'    => 'checkbox',
                'label'   => 'Enable logging',
                'default' => 'yes',
            ),
        );

        $fields = array_merge( $fields, $this->get_descriptor_form_fields( 'Antone' ) );
        $fields = array_merge( $fields, $this->get_percentage_fee_form_fields() );

        $this->form_fields = $fields;
    }

    public function payment_fields() {
        echo '<div class="mpg-card-form" id="mpg-vp2d-form">
            <div class="mpg-field">
                <label>Cardholder Name</label>
                <input type="text" name="mpg_vp2d_card_name" placeholder="Name on card" autocomplete="cc-name" required>
            </div>
            <div class="mpg-field">
                <label>Card Number</label>
                <input type="text" name="mpg_vp2d_card_number" inputmode="numeric" maxlength="23" placeholder="0000 0000 0000 0000" autocomplete="cc-number" required>
            </div>
            <div class="mpg-row">
                <div class="mpg-field">
                    <label>Expiry</label>
                    <input type="text" name="mpg_vp2d_expiry" maxlength="7" inputmode="numeric" placeholder="MM / YY" autocomplete="cc-exp" required>
                </div>
                <div class="mpg-field">
                    <label>CVC</label>
                    <input type="text" name="mpg_vp2d_cvv" maxlength="3" inputmode="numeric" placeholder="&bull;&bull;&bull;" autocomplete="cc-csc" required>
                </div>
            </div>
            <div class="mpg-secure-badge">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Secured with 256-bit encryption</span>
            </div>
        </div>';
    }

    public function validate_fields() {
        $errors = array();

        $card_name   = sanitize_text_field( $_POST['mpg_vp2d_card_name'] ?? '' );
        $card_number = preg_replace( '/\D/', '', sanitize_text_field( $_POST['mpg_vp2d_card_number'] ?? '' ) );
        $expiry      = preg_replace( '/\D/', '', sanitize_text_field( $_POST['mpg_vp2d_expiry'] ?? '' ) );
        $cvv         = preg_replace( '/\D/', '', sanitize_text_field( $_POST['mpg_vp2d_cvv'] ?? '' ) );

        if ( empty( $card_name ) ) {
            $errors[] = 'Cardholder name is required.';
        }
        if ( empty( $card_number ) ) {
            $errors[] = 'Card number is required.';
        } elseif ( strlen( $card_number ) < 13 || strlen( $card_number ) > 19 ) {
            $errors[] = 'Please enter a valid card number.';
        }
        if ( strlen( $expiry ) !== 4 ) {
            $errors[] = 'Please enter a valid expiry date (MM/YY).';
        } else {
            $month = (int) substr( $expiry, 0, 2 );
            if ( $month < 1 || $month > 12 ) {
                $errors[] = 'Please enter a valid expiry month (01-12).';
            }
        }
        if ( empty( $cvv ) || strlen( $cvv ) < 3 || strlen( $cvv ) > 4 ) {
            $errors[] = 'Please enter a valid CVC (3 or 4 digits).';
        }

        foreach ( $errors as $err ) {
            wc_add_notice( $err, 'error' );
        }

        return empty( $errors );
    }

    public function enqueue_assets() {
        if ( ! is_checkout() ) return;
        wp_enqueue_style( 'mpg-checkout-style', MPG_PLUGIN_URL . 'assets/css/mpg-checkout.css', array(), MPG_VERSION );
        wp_enqueue_script( 'mpg-card-formatting', MPG_PLUGIN_URL . 'assets/js/mpg-card-formatting.js', array(), MPG_VERSION, true );
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        $this->logger->log( '=== VP2D PAYMENT START === Order #' . $order_id );
        $this->logger->log( 'Environment: ' . $this->environment );
        $this->logger->log( 'Merchant ID: ' . $this->merchant_id );

        $card   = preg_replace( '/\D/', '', sanitize_text_field( $_POST['mpg_vp2d_card_number'] ?? '' ) );
        $expiry = preg_replace( '/\D/', '', sanitize_text_field( $_POST['mpg_vp2d_expiry'] ?? '' ) );
        $cvv    = preg_replace( '/\D/', '', sanitize_text_field( $_POST['mpg_vp2d_cvv'] ?? '' ) );
        $name   = sanitize_text_field( $_POST['mpg_vp2d_card_name'] ?? '' );

        $month = (int) substr( $expiry, 0, 2 );
        $year  = (int) substr( $expiry, 2, 2 );

        $this->logger->log( 'Card (masked): ' . substr( $card, 0, 6 ) . '******' . substr( $card, -4 ) );
        $this->logger->log( 'Expiry: ' . $month . '/' . $year );
        $this->logger->log( 'CVV length: ' . strlen( $cvv ) );

        $endpoint = MPG_VProcessor_API::endpoint( $this->environment, 'charges' );

        // Unique reference per attempt to avoid processor duplicate detection
        $attempt_ref = $order_id . '-' . substr( md5( wp_generate_password( 12, false ) ), 0, 6 );
        $order->update_meta_data( '_mpg_vp2d_external_ref', $attempt_ref );
        $order->save();

        $body = array(
            'serviceSecurity' => array( 'merchantId' => (int) $this->merchant_id ),
            'transactionDetails' => array(
                'amount'            => (float) $order->get_total(),
                'currency'          => strtoupper( $order->get_currency() ),
                'externalReference' => $attempt_ref,
                'custom1'           => 'WooCommerce',
            ),
            'cardDetails' => array(
                'cardHolderName'  => $name,
                'cardNumber'      => $card,
                'cvv'             => $cvv,
                'expirationMonth' => $month,
                'expirationYear'  => $year,
            ),
            'payerDetails' => array(
                'username'  => sanitize_user( $order->get_billing_email(), true ),
                'firstName' => $order->get_billing_first_name(),
                'lastName'  => $order->get_billing_last_name(),
                'email'     => $order->get_billing_email(),
                'phone'     => preg_replace( '/\D/', '', $order->get_billing_phone() ),
                'address'   => array(
                    'street'  => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
                    'city'    => $order->get_billing_city(),
                    'state'   => $order->get_billing_state(),
                    'country' => $order->get_billing_country(),
                    'zipCode' => substr( $order->get_billing_postcode(), 0, 9 ),
                ),
            ),
        );

        // Log request without sensitive card data
        $this->logger->log( 'Request (safe): ' . wp_json_encode( array(
            'serviceSecurity'    => $body['serviceSecurity'],
            'transactionDetails' => $body['transactionDetails'],
            'payerDetails'       => $body['payerDetails'],
        ) ) );

        $this->logger->log( 'API URL: ' . $endpoint );

        $json     = wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
        $sig      = hash( 'sha256', $this->api_key . $json . $this->api_key );
        $response = wp_remote_post( $endpoint, array(
            'headers' => array( 'Content-Type' => 'application/json', 'Signature' => $sig ),
            'body'    => $json,
            'timeout' => 60,
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

        if ( ! is_array( $result ) || ! isset( $result['result']['status'] ) ) {
            $this->logger->log( 'ERROR: Invalid or empty response from API' );
            wc_add_notice( 'Payment gateway error. Please try again.', 'error' );
            return array( 'result' => 'failure' );
        }

        $this->logger->log( 'Result Status: ' . $result['result']['status'] );
        $this->logger->log( 'Transaction ID: ' . ( $result['transactionId'] ?? 'N/A' ) );

        if ( $result['result']['status'] === 'approved' ) {
            $order->payment_complete( $result['transactionId'] );
            $order->update_meta_data( '_mpg_vp2d_tx', $result['transactionId'] );
            $pct = floatval( $this->get_option( 'percentage_on_top', '' ) );
            if ( $pct > 0 ) {
                $order->update_meta_data( '_mpg_vp2d_fee_pct', $pct );
            }
            $order->add_order_note( 'V-Processor 2D payment approved. TX: ' . $result['transactionId'] );
            $order->save();

            $this->logger->log( '=== VP2D PAYMENT SUCCESS ===' );
            return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
        }

        $error_msg  = $result['result']['errorDetail'] ?? 'Payment failed.';
        $error_code = $result['result']['errorCode'] ?? '';
        $this->logger->log( 'Payment failed. Code: ' . $error_code . ' Detail: ' . $error_msg );

        $order->update_status( 'failed', 'VP2D: [' . $error_code . '] ' . $error_msg );
        wc_add_notice( self::friendly_error( $error_code ), 'error' );
        return array( 'result' => 'failure' );
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        $tx    = $order->get_meta( '_mpg_vp2d_tx' );

        $this->logger->log( '=== VP2D REFUND START === Order #' . $order_id . ' Amount: ' . $amount );

        if ( ! $tx ) {
            $this->logger->log( 'ERROR: No transaction ID found for refund' );
            return new WP_Error( 'no_tx', 'No transaction ID found.' );
        }

        $endpoint = MPG_VProcessor_API::endpoint( $this->environment, 'refunds' );

        $body = array(
            'serviceSecurity' => array( 'merchantId' => (int) $this->merchant_id ),
            'transactionDetails' => array(
                'amount'        => (float) $amount,
                'currency'      => strtoupper( $order->get_currency() ),
                'transactionId' => $tx,
                'commentaries'  => (string) $reason,
            ),
        );

        $this->logger->log( 'Refund request: ' . wp_json_encode( $body ) );

        $json = wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
        $sig  = hash( 'sha256', $this->api_key . $json . $this->api_key );

        $response = wp_remote_post( $endpoint, array(
            'headers' => array( 'Content-Type' => 'application/json', 'Signature' => $sig ),
            'body'    => $json,
            'timeout' => 60,
        ));

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'HTTP Error: ' . $response->get_error_message() );
            return new WP_Error( 'http_error', $response->get_error_message() );
        }

        $response_body = wp_remote_retrieve_body( $response );
        $this->logger->log( 'Refund response: ' . $response_body );

        $result = json_decode( $response_body, true );

        if ( isset( $result['result']['status'] ) && $result['result']['status'] === 'approved' ) {
            $this->logger->log( '=== VP2D REFUND SUCCESS ===' );
            $order->add_order_note( 'V-Processor 2D refund approved. TX: ' . $tx );
            return true;
        }

        $error = $result['result']['errorDetail'] ?? 'Refund rejected.';
        $this->logger->log( 'Refund failed: ' . $error );
        return new WP_Error( 'refund_failed', $error );
    }

    /**
     * Map API error codes to user-friendly messages.
     * Raw details are kept in order notes for merchant debugging.
     */
    private static function friendly_error( $code ) {
        $code = (string) $code;

        // --- Exact code map (common / important codes) ---
        $map = array(
            // Signature / auth
            '1050' => 'A security error occurred. Please try again or contact support.',
            '1051' => 'A security error occurred. Please try again or contact support.',
            '1060' => 'A security error occurred. Please try again or contact support.',

            // Merchant / config (customer can't fix)
            '1052' => 'This payment method is temporarily unavailable. Please try another method or contact support.',
            '1053' => 'This payment method is temporarily unavailable. Please try another method or contact support.',
            '1055' => 'This payment method is temporarily unavailable. Please try another method or contact support.',
            '1061' => 'This payment method is temporarily unavailable. Please try another method or contact support.',
            '1062' => 'This payment method is temporarily unavailable. Please try another method or contact support.',
            '1065' => 'This payment method is temporarily unavailable. Please try another method or contact support.',
            '1067' => 'This payment method is temporarily unavailable. Please try another method or contact support.',
            '1068' => 'This payment method is temporarily unavailable. Please try another method or contact support.',
            '1093' => 'This payment method is temporarily unavailable. Please try another method or contact support.',
            '1102' => 'This payment method is temporarily unavailable. Please try another method or contact support.',
            '1103' => 'This payment method is temporarily unavailable. Please try another method or contact support.',

            // Format / request
            '1054' => 'Your payment could not be processed. Please try again.',
            '1056' => 'Your payment could not be processed. Please verify your details and try again.',
            '1059' => 'Your payment could not be processed. Please try again.',
            '1066' => 'Your payment could not be processed. Please try again.',

            // Timeout / unavailable
            '1057' => 'The payment processor is not responding. Please wait a moment and try again.',
            '1058' => 'The payment processor is not responding. Please wait a moment and try again.',
            '9827' => 'The payment processor is not responding. Please wait a moment and try again.',
            '9831' => 'The payment processor is not responding. Please wait a moment and try again.',

            // Maintenance
            '1074' => 'The payment system is under maintenance. Please try again later.',
            '1075' => 'The payment system is under maintenance. Please try again later.',
            '1076' => 'The payment system is under maintenance. Please try again later.',
            '1077' => 'The payment system is under maintenance. Please try again later.',
            '1078' => 'The payment system is under maintenance. Please try again later.',
            '1091' => 'The payment system is under maintenance. Please try again later.',

            // Card validation
            '1080' => 'Please check the cardholder name and try again.',
            '1508' => 'The card number is invalid. Please check and try again.',
            '1514' => 'The expiration date is invalid. Please check and try again.',
            '1562' => 'Your card information could not be processed. Please check your details and try again.',
            '9084' => 'The security code (CVV) is invalid. Please check and try again.',
            '9779' => 'The security code (CVV) is invalid. Please check and try again.',
            '9836' => 'The security code (CVV) is invalid. Please check and try again.',
            '9573' => 'The card number is invalid. Please check and try again.',
            '9563' => 'This card brand is not supported. Please use a different card.',
            '9075' => 'This card brand is not supported. Please use a different card.',

            // Amount
            '1509' => 'The order amount is below the minimum allowed. Please contact support.',
            '1511' => 'There was a problem with the payment amount. Please contact support.',
            '9617' => 'There was a problem with the payment amount. Please contact support.',

            // Currency
            '1512' => 'There was a currency error. Please contact support.',
            '1513' => 'There was a currency error. Please contact support.',
            '9566' => 'This currency is not supported. Please contact support.',

            // Payer details
            '1082' => 'Please check your email address and try again.',
            '1083' => 'Please enter a valid email address.',
            '1084' => 'Please enter a valid phone number (digits only).',
            '1085' => 'The phone number is too long. Please check and try again.',
            '1086' => 'Please check your zip/postal code and try again.',
            '1088' => 'Please check your zip/postal code and try again.',
            '1089' => 'Please check your phone number and try again.',
            '1563' => 'First name is required.',
            '1564' => 'Last name is required.',
            '1565' => 'Please verify your billing information and try again.',

            // Reference
            '1081' => 'A processing error occurred. Please try again.',

            // Duplicate
            '9037' => 'This transaction appears to be a duplicate. Please wait a moment before trying again.',
            '9042' => 'Your payment was declined. Please try a different card or contact your bank.',

            // Card declined by bank
            '1507' => 'Your bank does not support this transaction. Please try a different card.',
            '9832' => 'Your card was declined by the bank. Please try a different card or contact your bank.',
            '9833' => 'Insufficient funds. Please try a different card or contact your bank.',
            '9834' => 'Bank authorization is required. Please contact your bank and try again.',
            '9840' => 'Your card has expired. Please use a different card.',
            '9837' => 'Your card was declined. Please use a different card or contact your bank.',
            '9841' => 'Your card was declined. Please use a different card or contact your bank.',
            '9839' => 'Your card was declined. Please contact your bank.',
            '9663' => 'Your card is not active. Please use a different card or contact your bank.',
            '9821' => 'Your card is blocked. Please contact your bank.',
            '9824' => 'Your card is blocked. Please contact your bank.',
            '9618' => 'Your card is blocked. Please contact your bank.',
            '9546' => 'Please contact your card issuer.',
            '9867' => 'Your card issuer is unavailable. Please try again later.',
            '9559' => 'Your card issuer is unavailable. Please try again later.',
            '9586' => 'Your card issuer is unavailable. Please try again later.',
            '9523' => 'Card not recognized. Please check the card number or use a different card.',
            '9544' => 'Invalid card account. Please check your details or use a different card.',
            '9666' => 'This transaction is not permitted. Please try a different card.',
            '9561' => 'This transaction is not permitted. Please try a different card.',
            '9547' => 'This transaction cannot be completed. Please contact your bank.',

            // Fraud / risk
            '9079' => 'Your payment was declined. Please try a different card.',
            '9081' => 'Your payment was declined. Please try a different card.',
            '9083' => 'Your payment was declined. Please try a different card.',
            '9085' => 'Your payment was declined. Please try a different card.',
            '9835' => 'Your payment was declined. Please try a different card.',
            '9838' => 'Your payment was declined. Please try a different card.',
            '9777' => 'Your payment was declined. Please try a different card.',
            '9537' => 'Your payment was declined. Please try a different card.',
            '9539' => 'Your payment was declined. Please try a different card.',

            // 3DS
            '1517' => 'Payment verification failed. Please try again.',
            '1518' => 'Payment verification failed. Please try again.',
            '9549' => 'Additional verification is required. Please try a different card or lower amount.',
            '9556' => 'Additional verification is required. Please use a different card.',
            '9849' => 'Card verification failed. Please try again or use a different card.',

            // Velocity / limits
            '1545' => 'Daily spending limit exceeded. Please try again tomorrow or use a different card.',
            '9538' => 'Weekly transaction limit reached. Please try again later or contact support.',
            '9540' => 'Daily transaction limit reached. Please try again tomorrow or contact support.',
            '9845' => 'Daily transaction limit reached. Please try again tomorrow.',
            '9847' => 'Weekly transaction limit reached. Please try again later.',
            '9623' => 'Too many attempts. Please try a different card.',

            // System errors
            '1079' => 'A system error occurred. Please try again later.',
            '9550' => 'A system error occurred. Please try again later.',
            '9605' => 'A system error occurred. Please try again later.',
            '9607' => 'A system error occurred. Please try again later.',
            '9776' => 'A system error occurred. Please try again later.',
            '9613' => 'A system error occurred. Please try again later.',
            '9614' => 'A system error occurred. Please try again later.',
            '9862' => 'A system error occurred. Please try again later.',
            '9825' => 'A processing error occurred. Please try again later.',

            // Session
            '1552' => 'Your session has expired. Please refresh and try again.',
            '9635' => 'Your session has expired. Please refresh and try again.',
        );

        if ( isset( $map[ $code ] ) ) {
            return $map[ $code ];
        }

        // --- Range-based fallbacks for unmapped codes ---
        $num = (int) $code;

        // 1050-1103: config/security issues
        if ( $num >= 1050 && $num <= 1103 ) {
            return 'This payment method is temporarily unavailable. Please try another method or contact support.';
        }
        // 1507-1569: card/transaction validation
        if ( $num >= 1507 && $num <= 1569 ) {
            return 'Your payment was declined. Please check your card details and try again.';
        }
        // 1700-1796: installment/acquirer errors
        if ( $num >= 1700 && $num <= 1796 ) {
            return 'Your payment could not be processed. Please try again or use a different card.';
        }
        // 9000-9099: processor/validation errors
        if ( $num >= 9000 && $num <= 9099 ) {
            return 'Your payment was declined. Please try a different card or contact your bank.';
        }
        // 9500-9900+: processor declines, state errors, misc
        if ( $num >= 9500 ) {
            return 'Your payment was declined. Please try a different card or contact your bank.';
        }

        return 'Your payment could not be processed. Please try again or contact support.';
    }
}
