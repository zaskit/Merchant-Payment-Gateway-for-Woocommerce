<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class MPG_Blocks_Integration extends AbstractPaymentMethodType {

    private $gateway_id;

    public function __construct( $gateway_id ) {
        $this->gateway_id = $gateway_id;
        $this->name       = $gateway_id;
    }

    public function initialize() {
        $this->settings = get_option( 'woocommerce_' . $this->gateway_id . '_settings', array() );
    }

    public function is_active() {
        return isset( $this->settings['enabled'] ) && $this->settings['enabled'] === 'yes';
    }

    public function get_payment_method_script_handles() {
        $handle = 'mpg-blocks-' . $this->gateway_id;

        wp_register_script(
            $handle,
            MPG_PLUGIN_URL . 'assets/js/mpg-blocks.js',
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ),
            MPG_VERSION,
            true
        );

        wp_localize_script( $handle, 'mpg_blocks_data_' . $this->gateway_id, $this->get_payment_method_data() );

        return array( $handle );
    }

    public function get_payment_method_data() {
        $data = array(
            'id'          => $this->gateway_id,
            'title'       => $this->settings['title'] ?? $this->get_default_title(),
            'description' => $this->settings['description'] ?? '',
            'supports'    => array( 'products' ),
            'icons'       => $this->get_icons(),
            'has_fields'  => $this->has_card_fields(),
        );

        return $data;
    }

    private function get_default_title() {
        $titles = array(
            'mpg_vprocessor_2d'   => 'ONLY USE M A S T E R C A R D | DO NOT USE V I S A',
            'mpg_vprocessor_3d'   => 'ONLY USE V I S A & M A S T E R C A R D  ONLY',
            'mpg_eprocessor_2d'   => 'ONLY USE V I S A & M A S T E R C A R D  ONLY',
            'mpg_eprocessor_3d'   => 'ONLY USE V I S A & M A S T E R C A R D  ONLY',
            'mpg_eprocessor_hosted' => 'ONLY USE V I S A & M A S T E R C A R D  ONLY',
        );
        return $titles[ $this->gateway_id ] ?? 'Pay by Card';
    }

    private function get_icons() {
        if ( $this->gateway_id === 'mpg_vprocessor_2d' ) {
            return array(
                array( 'id' => 'mastercard', 'src' => MPG_PLUGIN_URL . 'assets/img/mastercard.svg', 'alt' => 'Mastercard' ),
            );
        }
        return array(
            array( 'id' => 'visa', 'src' => MPG_PLUGIN_URL . 'assets/img/visa.svg', 'alt' => 'Visa' ),
            array( 'id' => 'mastercard', 'src' => MPG_PLUGIN_URL . 'assets/img/mastercard.svg', 'alt' => 'Mastercard' ),
        );
    }

    private function has_card_fields() {
        // Hosted page has no card fields
        return $this->gateway_id !== 'mpg_eprocessor_hosted';
    }
}
