<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Trait MPG_Percentage_Fee_Trait
 * Adds a configurable percentage fee on top of the cart total.
 */
trait MPG_Percentage_Fee_Trait {

    protected function get_percentage_fee_form_fields() {
        return array(
            'percentage_on_top' => array(
                'title'       => __( 'Percentage on Top (%)', 'merchant-payment-gateway' ),
                'type'        => 'number',
                'description' => __( 'Additional percentage fee when this payment method is selected. Leave empty for no fee.', 'merchant-payment-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
                'css'         => 'width:100px;',
                'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
            ),
            'fee_label' => array(
                'title'       => __( 'Fee Label', 'merchant-payment-gateway' ),
                'type'        => 'text',
                'description' => __( 'Label for the percentage fee shown at checkout.', 'merchant-payment-gateway' ),
                'default'     => 'Transaction Fee',
                'desc_tip'    => true,
            ),
        );
    }

    protected function init_percentage_fee_hooks() {
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'mpg_add_percentage_fee' ) );
        add_action( 'wp_footer', array( $this, 'mpg_checkout_refresh_script' ) );
    }

    public function mpg_add_percentage_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! $cart ) return;

        $pct = floatval( $this->get_option( 'percentage_on_top', '' ) );
        if ( $pct <= 0 ) return;

        $chosen = WC()->session->get( 'chosen_payment_method' );
        if ( $chosen !== $this->id ) return;

        $total = $cart->get_cart_contents_total() + $cart->get_shipping_total();
        $fee   = round( $total * ( $pct / 100 ), 2 );
        if ( $fee > 0 ) {
            $label = $this->get_option( 'fee_label', 'Transaction Fee' );
            $cart->add_fee( sprintf( '%s (%s%%)', $label, $pct ), $fee, true );
        }
    }

    public function mpg_checkout_refresh_script() {
        if ( ! is_checkout() ) return;
        $pct = floatval( $this->get_option( 'percentage_on_top', '' ) );
        if ( $pct <= 0 ) return;
        ?>
        <script>
        jQuery(function($){
            $('form.checkout').on('change','input[name="payment_method"]',function(){
                $('body').trigger('update_checkout');
            });
        });
        </script>
        <?php
    }
}
