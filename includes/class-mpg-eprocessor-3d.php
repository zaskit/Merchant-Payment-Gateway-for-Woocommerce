<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MPG_EProcessor_3D extends WC_Payment_Gateway {

    use MPG_Descriptor_Trait;
    use MPG_Percentage_Fee_Trait;

    private $logger;
    public $account_id;
    public $account_password;
    public $account_passphrase;
    public $account_gateway;
    public $transaction_prefix;

    public function __construct() {
        $this->id                 = 'mpg_eprocessor_3d';
        $this->method_title       = 'E-Processor 3D';
        $this->method_description = 'EuPaymentz 3D-Secure card payment processing.';
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

        $this->logger = new MPG_Logger( $this->debug, 'mpg-eprocessor-3d' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        $this->init_descriptor_hooks();
        $this->init_percentage_fee_hooks();
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
            'description'        => array( 'title' => 'Description', 'type' => 'textarea', 'default' => 'Pay securely using your Visa or Mastercard (3D-Secure).' ),
            'testmode'           => array( 'title' => 'Test Mode', 'type' => 'checkbox', 'label' => 'Enable Test Mode', 'default' => 'yes' ),
            'account_id'         => array( 'title' => 'Account ID', 'type' => 'text', 'desc_tip' => true ),
            'account_password'   => array( 'title' => 'Account Password', 'type' => 'password', 'desc_tip' => true ),
            'account_passphrase' => array( 'title' => 'Account Passphrase', 'type' => 'password', 'desc_tip' => true ),
            'account_gateway'    => array( 'title' => 'Gateway Account', 'type' => 'text', 'default' => '1', 'desc_tip' => true ),
            'transaction_prefix' => array( 'title' => 'Transaction Prefix', 'type' => 'text', 'default' => 'WC-', 'desc_tip' => true ),
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
        <fieldset id="mpg-ep3d-form" class="mpg-card-form wc-credit-card-form wc-payment-form">
            <div class="mpg-field">
                <label>Card Holder Name <span class="required">*</span></label>
                <input type="text" name="mpg_ep3d_card_name" autocomplete="cc-name" placeholder="John Doe" required />
            </div>
            <div class="mpg-field">
                <label>Card Number <span class="required">*</span></label>
                <input type="text" name="mpg_ep3d_card_number" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000" maxlength="23" required />
            </div>
            <div class="mpg-row">
                <div class="mpg-field">
                    <label>Expiry <span class="required">*</span></label>
                    <input type="text" name="mpg_ep3d_expiry" inputmode="numeric" autocomplete="cc-exp" placeholder="MM / YY" maxlength="7" required />
                </div>
                <div class="mpg-field">
                    <label>CVC <span class="required">*</span></label>
                    <input type="text" name="mpg_ep3d_cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="&bull;&bull;&bull;" maxlength="4" required />
                </div>
            </div>
            <div class="mpg-secure-badge">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Secured with 256-bit encryption &amp; 3D-Secure</span>
            </div>
        </fieldset>
        <?php
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        $this->logger->log( '=== EP3D PAYMENT START === Order #' . $order_id );

        $card_number = str_replace( ' ', '', sanitize_text_field( $_POST['mpg_ep3d_card_number'] ?? '' ) );
        $expiry      = str_replace( ' ', '', sanitize_text_field( $_POST['mpg_ep3d_expiry'] ?? '' ) );
        $cvv         = sanitize_text_field( $_POST['mpg_ep3d_cvv'] ?? '' );

        $expiry_parts = explode( '/', $expiry );
        $exp_month    = trim( $expiry_parts[0] ?? '' );
        $exp_year     = trim( $expiry_parts[1] ?? '' );
        if ( strlen( $exp_year ) === 2 ) $exp_year = '20' . $exp_year;

        $data = MPG_EProcessor_API::build_base_data( $this, $order );

        $data['transac_cc_number'] = $card_number;
        $data['transac_cc_month']  = str_pad( $exp_month, 2, '0', STR_PAD_LEFT );
        $data['transac_cc_year']   = $exp_year;
        $data['transac_cc_cvc']    = $cvv;

        $data['account_sha'] = MPG_EProcessor_API::sha_with_card(
            $this->account_passphrase, $data['transac_amount'], $this->account_id,
            $data['cust_email'], $card_number, $data['customer_ip']
        );

        $response = MPG_EProcessor_API::post( MPG_EProcessor_API::PROCESS_URL, $data );
        $result   = MPG_EProcessor_API::parse_response( $response );

        if ( ! $result ) {
            wc_add_notice( 'Payment gateway error. Please try again.', 'error' );
            return array( 'result' => 'failure' );
        }

        $this->logger->log( 'Response: ' . wp_json_encode( $result ) );
        $order->update_meta_data( '_mpg_ep_merchant_payment_id', $data['merchant_payment_id'] );

        // 3DS redirect
        if ( isset( $result['isDirectResult'] ) && $result['isDirectResult'] === false ) {
            $order->update_meta_data( '_mpg_ep_transaction_id', $result['resp_trans_id'] ?? '' );
            $order->save();
            $redirect_url = MPG_EProcessor_API::build_redirect_url( $result );
            if ( ! empty( $redirect_url ) ) {
                return array( 'result' => 'success', 'redirect' => $redirect_url );
            }
        }

        // Direct response
        if ( isset( $result['resp_trans_status'] ) ) {
            if ( ! MPG_EProcessor_API::verify_response_sha( $this->account_passphrase, $result ) ) {
                wc_add_notice( 'Payment verification failed.', 'error' );
                return array( 'result' => 'failure' );
            }

            $parsed = MPG_EProcessor_API::parse_transaction_status( $result );
            $order->update_meta_data( '_mpg_ep_transaction_id', $parsed['transaction_id'] );

            if ( $parsed['is_success'] ) {
                $order->save();
                $order->payment_complete( $parsed['transaction_id'] );
                $order->add_order_note( 'E-Processor 3D payment completed. TX: ' . $parsed['transaction_id'] );
                return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
            }
            if ( $parsed['is_pending'] ) {
                $order->update_status( 'on-hold', 'Pending. TX: ' . $parsed['transaction_id'] );
                $order->save();
                return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
            }

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
        if ( $amount !== null ) $data['transac_amount'] = $amount;

        $response = MPG_EProcessor_API::post( MPG_EProcessor_API::REFUND_URL, $data );
        $result   = MPG_EProcessor_API::parse_response( $response );

        if ( ! $result ) return new WP_Error( 'api_error', 'No response.' );

        if ( isset( $result['resp_trans_status'] ) && $result['resp_trans_status'] === '00000' ) {
            $order->add_order_note( 'E-Processor 3D refund approved. TX: ' . $tx );
            return true;
        }

        return new WP_Error( 'refund_fail', $result['resp_trans_description_status'] ?? 'Refund failed.' );
    }

    public function process_callback( $data ) {
        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( isset( $gateways['mpg_eprocessor_2d'] ) ) $gateways['mpg_eprocessor_2d']->process_callback( $data );
    }

    public function process_return( $data ) {
        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( isset( $gateways['mpg_eprocessor_2d'] ) ) $gateways['mpg_eprocessor_2d']->process_return( $data );
    }
}
