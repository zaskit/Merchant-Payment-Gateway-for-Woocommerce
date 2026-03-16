<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MPG_VProcessor_2D extends WC_Payment_Gateway {

    use MPG_Descriptor_Trait;
    use MPG_Percentage_Fee_Trait;

    private static function format_phone( $phone ) {
        $phone  = trim( $phone );
        $prefix = strpos( $phone, '+' ) === 0 ? '+' : '';
        return $prefix . preg_replace( '/\D/', '', $phone );
    }

    private function get_cardholder_billing_from_post( $prefix = 'mpg_vp2d' ) {
        $state_raw = sanitize_text_field( $_POST[ $prefix . '_billing_state' ] ?? '' );
        return array(
            'street'  => sanitize_text_field( $_POST[ $prefix . '_billing_street' ] ?? '' ),
            'city'    => sanitize_text_field( $_POST[ $prefix . '_billing_city' ] ?? '' ),
            'state'   => ! empty( $state_raw ) ? strtoupper( substr( $state_raw, 0, 2 ) ) : 'NA',
            'country' => sanitize_text_field( $_POST[ $prefix . '_billing_country' ] ?? '' ),
            'zipCode' => sanitize_text_field( $_POST[ $prefix . '_billing_zip' ] ?? '' ),
        );
    }

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

        // Block checkout support
        add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this, 'process_payment_for_block' ), 10, 2 );
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
            <div class="mpg-billing-heading">Cardholder Billing Address</div>
            <div class="mpg-field">
                <label>Street Address <span class="required">*</span></label>
                <input type="text" name="mpg_vp2d_billing_street" autocomplete="address-line1" placeholder="Street address" required>
            </div>
            <div class="mpg-row">
                <div class="mpg-field">
                    <label>City <span class="required">*</span></label>
                    <input type="text" name="mpg_vp2d_billing_city" autocomplete="address-level2" placeholder="City" required>
                </div>
                <div class="mpg-field">
                    <label>State / Province <span class="required">*</span></label>
                    <input type="text" name="mpg_vp2d_billing_state" autocomplete="address-level1" placeholder="e.g. MO, NY" maxlength="50" required>
                </div>
            </div>
            <div class="mpg-row">
                <div class="mpg-field">
                    <label>Country <span class="required">*</span></label>
                    <select name="mpg_vp2d_billing_country" autocomplete="country" required>
                        <option value="">Select country&hellip;</option>';
        foreach ( WC()->countries->get_countries() as $code => $name ) {
            $selected = ( WC()->customer && WC()->customer->get_billing_country() === $code ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $code ) . '"' . $selected . '>' . esc_html( $name ) . '</option>';
        }
        echo '  </select>
                </div>
                <div class="mpg-field">
                    <label>ZIP / Postal Code <span class="required">*</span></label>
                    <input type="text" name="mpg_vp2d_billing_zip" autocomplete="postal-code" placeholder="ZIP / Postal" maxlength="10" required>
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
        } else {
            $two  = (int) substr( $card_number, 0, 2 );
            $four = (int) substr( $card_number, 0, 4 );
            $is_mc = ( $two >= 51 && $two <= 55 ) || ( $four >= 2221 && $four <= 2720 );
            if ( ! $is_mc ) {
                $errors[] = 'Only Mastercard is accepted on this gateway. Please use a Mastercard.';
            }
        }
        if ( strlen( $expiry ) !== 4 ) {
            $errors[] = 'Please enter a valid expiry date (MM/YY).';
        } else {
            $month = (int) substr( $expiry, 0, 2 );
            $year  = (int) substr( $expiry, 2, 2 );
            if ( $month < 1 || $month > 12 ) {
                $errors[] = 'Please enter a valid expiry month (01-12).';
            } else {
                $now_month = (int) gmdate( 'n' );
                $now_year  = (int) gmdate( 'y' );
                if ( $year < $now_year || ( $year === $now_year && $month < $now_month ) ) {
                    $errors[] = 'Your card has expired. Please use a valid card.';
                }
            }
        }
        if ( empty( $cvv ) || strlen( $cvv ) < 3 || strlen( $cvv ) > 4 ) {
            $errors[] = 'Please enter a valid CVC (3 or 4 digits).';
        }

        $b_street  = sanitize_text_field( $_POST['mpg_vp2d_billing_street'] ?? '' );
        $b_city    = sanitize_text_field( $_POST['mpg_vp2d_billing_city'] ?? '' );
        $b_country = sanitize_text_field( $_POST['mpg_vp2d_billing_country'] ?? '' );
        $b_zip     = sanitize_text_field( $_POST['mpg_vp2d_billing_zip'] ?? '' );

        if ( empty( $b_street ) )  { $errors[] = 'Cardholder billing street address is required.'; }
        if ( empty( $b_city ) )    { $errors[] = 'Cardholder billing city is required.'; }
        if ( empty( $b_country ) || strlen( $b_country ) !== 2 ) { $errors[] = 'Please select a valid billing country.'; }
        if ( empty( $b_zip ) )     { $errors[] = 'Cardholder billing ZIP / postal code is required.'; }

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
                'phone'     => self::format_phone( $order->get_billing_phone() ),
                'address'   => $this->get_cardholder_billing_from_post( 'mpg_vp2d' ),
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
            $order->update_meta_data( '_mpg_vp2d_tx', $result['transactionId'] );
            $pct = floatval( $this->get_option( 'percentage_on_top', '' ) );
            if ( $pct > 0 ) {
                $order->update_meta_data( '_mpg_vp2d_fee_pct', $pct );
            }
            $order->add_order_note( 'V-Processor 2D payment approved. TX: ' . $result['transactionId'] );
            $order->save();
            $order->payment_complete( $result['transactionId'] );

            $this->logger->log( '=== VP2D PAYMENT SUCCESS ===' );
            return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
        }

        $error_msg  = $result['result']['errorDetail'] ?? 'Payment failed.';
        $error_code = $result['result']['errorCode'] ?? '';
        $this->logger->log( 'Payment failed. Code: ' . $error_code . ' Detail: ' . $error_msg );

        $order->update_status( 'failed', 'VP2D: [' . $error_code . '] ' . $error_msg );
        wc_add_notice( MPG_VProcessor_API::friendly_error( $error_code ), 'error' );
        return array( 'result' => 'failure' );
    }

    /* ─── Block checkout bridge ─── */
    public function process_payment_for_block( $context, &$result ) {
        if ( $context->payment_method !== $this->id ) return;
        $pd = isset( $context->payment_data ) ? $context->payment_data : array();
        $map = array(
            'mpg_vp2d_card_name'        => 'mpg_vp2d_card_name',
            'mpg_vp2d_card_number'      => 'mpg_vp2d_card_number',
            'mpg_vp2d_expiry'           => 'mpg_vp2d_expiry',
            'mpg_vp2d_cvv'              => 'mpg_vp2d_cvv',
            'mpg_vp2d_billing_street'   => 'mpg_vp2d_billing_street',
            'mpg_vp2d_billing_city'     => 'mpg_vp2d_billing_city',
            'mpg_vp2d_billing_state'    => 'mpg_vp2d_billing_state',
            'mpg_vp2d_billing_country'  => 'mpg_vp2d_billing_country',
            'mpg_vp2d_billing_zip'      => 'mpg_vp2d_billing_zip',
        );
        foreach ( $map as $k => $v ) {
            if ( isset( $pd[ $k ] ) ) $_POST[ $v ] = sanitize_text_field( $pd[ $k ] );
        }
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

}
