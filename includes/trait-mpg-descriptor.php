<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Trait MPG_Descriptor_Trait
 * Provides the Descriptor custom field, thank-you page and email messages.
 */
trait MPG_Descriptor_Trait {

    /**
     * Return the form fields for the Descriptor setting.
     */
    protected function get_descriptor_form_fields( $default = '' ) {
        return array(
            'descriptor' => array(
                'title'       => __( 'Descriptor', 'merchant-payment-gateway' ),
                'type'        => 'text',
                'description' => __( 'The value shown on the customer\'s bank statement and in order emails/thank-you page.', 'merchant-payment-gateway' ),
                'default'     => $default,
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Build the descriptor message string.
     */
    protected function get_descriptor_message() {
        $descriptor = $this->get_option( 'descriptor', '' );
        if ( empty( $descriptor ) ) return '';

        return sprintf(
            'Your payment has been processed securely. The charge will appear on your statement as "%s". If you have any questions regarding this transaction, please contact our support team. Please do not do chargebacks.',
            esc_html( $descriptor )
        );
    }

    /**
     * Hook the descriptor display into thank-you page and emails.
     * Call this from the gateway constructor.
     */
    protected function init_descriptor_hooks() {
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'show_descriptor_thankyou' ), 5 );
        add_action( 'woocommerce_thankyou', array( $this, 'show_descriptor_thankyou_fallback' ), 5 );
        // Email descriptor hooks are now registered as standalone functions in the main plugin file
    }

    public function show_descriptor_thankyou( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) return;
        $msg = $this->get_descriptor_message();
        if ( empty( $msg ) ) return;
        echo '<div class="mpg-descriptor-message" style="background:#f0f7ff;border-left:4px solid #6366f1;padding:14px 18px;margin:16px 0 24px;border-radius:4px;font-size:15px;line-height:1.6;color:#1d2327;">';
        echo wp_kses_post( nl2br( $msg ) );
        echo '</div>';
    }

    public function show_descriptor_thankyou_fallback( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) return;
        // Only fire if the specific hook didn't already fire (some themes skip it)
        if ( did_action( 'woocommerce_thankyou_' . $this->id ) ) return;
        $this->show_descriptor_thankyou( $order_id );
    }

    public function show_descriptor_email( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $order->get_payment_method() !== $this->id ) return;
        $msg = $this->get_descriptor_message();
        if ( empty( $msg ) ) return;

        if ( $plain_text ) {
            echo "\n" . wp_strip_all_tags( $msg ) . "\n\n";
        } else {
            echo '<div style="background:#f0f7ff;border-left:4px solid #6366f1;padding:14px 18px;margin:16px 0;font-size:15px;line-height:1.6;color:#1d2327;">';
            echo wp_kses_post( nl2br( $msg ) );
            echo '</div>';
        }
    }
}
