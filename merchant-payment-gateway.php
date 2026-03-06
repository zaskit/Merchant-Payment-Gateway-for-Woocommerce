<?php
/**
 * Plugin Name: Merchant Payment Gateway for WooCommerce
 * Description: Unified payment gateway supporting V-Processor (2D/3D) and E-Processor (2D/3D/Hosted) with full block checkout support.
 * Version: 1.0.0
 * Author: Developer
 * Text Domain: merchant-payment-gateway
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.6
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MPG_VERSION', '1.0.0' );
define( 'MPG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MPG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MPG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/* ─── HPOS + Block Checkout compat ─── */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
});

/* ─── WooCommerce check ─── */
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="error"><p><strong>Merchant Payment Gateway</strong> requires WooCommerce.</p></div>';
    });
    return;
}

/* ─── Load gateways ─── */
add_action( 'plugins_loaded', 'mpg_init_gateways', 11 );
function mpg_init_gateways() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    require_once MPG_PLUGIN_DIR . 'includes/class-mpg-logger.php';
    require_once MPG_PLUGIN_DIR . 'includes/trait-mpg-descriptor.php';
    require_once MPG_PLUGIN_DIR . 'includes/trait-mpg-percentage-fee.php';
    require_once MPG_PLUGIN_DIR . 'includes/class-mpg-vprocessor-api.php';
    require_once MPG_PLUGIN_DIR . 'includes/class-mpg-vprocessor-2d.php';
    require_once MPG_PLUGIN_DIR . 'includes/class-mpg-vprocessor-3d.php';
    require_once MPG_PLUGIN_DIR . 'includes/class-mpg-vprocessor-3d-webhook.php';
    require_once MPG_PLUGIN_DIR . 'includes/class-mpg-eprocessor-api.php';
    require_once MPG_PLUGIN_DIR . 'includes/class-mpg-eprocessor-2d.php';
    require_once MPG_PLUGIN_DIR . 'includes/class-mpg-eprocessor-3d.php';
    require_once MPG_PLUGIN_DIR . 'includes/class-mpg-eprocessor-hosted.php';

    add_filter( 'woocommerce_payment_gateways', function ( $gw ) {
        $gw[] = 'MPG_VProcessor_2D';
        $gw[] = 'MPG_VProcessor_3D';
        $gw[] = 'MPG_EProcessor_2D';
        $gw[] = 'MPG_EProcessor_3D';
        $gw[] = 'MPG_EProcessor_Hosted';
        return $gw;
    });
}

/* ─── Block checkout integrations ─── */
add_action( 'woocommerce_blocks_loaded', function () {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) return;
    require_once MPG_PLUGIN_DIR . 'includes/class-mpg-blocks-integration.php';

    add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
        foreach ( array( 'mpg_vprocessor_2d', 'mpg_vprocessor_3d', 'mpg_eprocessor_2d', 'mpg_eprocessor_3d', 'mpg_eprocessor_hosted' ) as $id ) {
            $registry->register( new MPG_Blocks_Integration( $id ) );
        }
    });
});

/* ─── Frontend CSS ─── */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'is_checkout' ) ) return;
    if ( ! is_checkout() && ! is_cart() && ! is_wc_endpoint_url( 'order-received' ) ) return;
    wp_enqueue_style( 'mpg-blocks-style', MPG_PLUGIN_URL . 'assets/css/mpg-blocks.css', [], MPG_VERSION );
});

/* ─── Phone field required ─── */
add_filter( 'woocommerce_billing_fields', function ( $f ) {
    if ( isset( $f['billing_phone'] ) ) $f['billing_phone']['required'] = true;
    return $f;
}, 20 );

add_filter( 'woocommerce_get_country_locale_default', function ( $locale ) {
    $locale['phone'] = array( 'required' => true );
    return $locale;
});

add_action( 'woocommerce_store_api_checkout_update_order_from_request', function ( $order, $request ) {
    if ( empty( $order->get_billing_phone() ) ) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
            'missing_phone', 'Phone number is required for payment processing.', 400
        );
    }
}, 10, 2 );

/* ─── E-Processor endpoints ─── */
add_action( 'init', function () {
    add_rewrite_endpoint( 'eupaymentz-callback', EP_ROOT );
    add_rewrite_endpoint( 'eupaymentz-return', EP_ROOT );
});

add_action( 'template_redirect', function () {
    global $wp_query;

    /* E-Processor callback — processor sends JSON body */
    if ( isset( $wp_query->query_vars['eupaymentz-callback'] ) ) {
        // Read JSON body (EuPaymentz sends application/json, not form-encoded)
        $raw  = file_get_contents( 'php://input' );
        $data = json_decode( $raw, true );

        // Fall back to POST if JSON decode fails (backwards compat)
        if ( empty( $data ) || ! is_array( $data ) ) {
            $data = $_POST;
        }

        if ( empty( $data ) ) {
            status_header( 200 );
            echo 'OK';
            exit;
        }

        // Route to the correct gateway based on the order's payment method
        $order_id = isset( $data['resp_merchant_data1'] ) ? intval( $data['resp_merchant_data1'] ) : 0;
        $routed   = false;

        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $payment_method = $order->get_payment_method();
                $gateways       = WC()->payment_gateways()->payment_gateways();
                if ( isset( $gateways[ $payment_method ] ) && method_exists( $gateways[ $payment_method ], 'process_callback' ) ) {
                    $gateways[ $payment_method ]->process_callback( $data );
                    $routed = true;
                }
            }
        }

        // Fallback: try first enabled EP gateway
        if ( ! $routed ) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            foreach ( array( 'mpg_eprocessor_2d', 'mpg_eprocessor_3d', 'mpg_eprocessor_hosted' ) as $id ) {
                if ( isset( $gateways[ $id ] ) && $gateways[ $id ]->enabled === 'yes' ) {
                    $gateways[ $id ]->process_callback( $data );
                    break;
                }
            }
        }

        status_header( 200 );
        echo 'OK';
        exit;
    }

    /* E-Processor return — processor redirects customer via GET */
    if ( isset( $wp_query->query_vars['eupaymentz-return'] ) ) {
        $data = $_REQUEST;

        // Route to the correct gateway based on the order's payment method
        $order_id = isset( $data['order_id'] ) ? intval( $data['order_id'] ) : 0;
        $routed   = false;

        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $payment_method = $order->get_payment_method();
                $gateways       = WC()->payment_gateways()->payment_gateways();
                if ( isset( $gateways[ $payment_method ] ) && method_exists( $gateways[ $payment_method ], 'process_return' ) ) {
                    $gateways[ $payment_method ]->process_return( $data );
                    $routed = true;
                }
            }
        }

        // Fallback: try first enabled EP gateway
        if ( ! $routed ) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            foreach ( array( 'mpg_eprocessor_2d', 'mpg_eprocessor_3d', 'mpg_eprocessor_hosted' ) as $id ) {
                if ( isset( $gateways[ $id ] ) && $gateways[ $id ]->enabled === 'yes' ) {
                    $gateways[ $id ]->process_return( $data );
                    break;
                }
            }
        }
        exit;
    }
});

/* ─── V-Processor 3D endpoints ─── */
add_action( 'woocommerce_api_vsafe_webhook', function () { MPG_VProcessor_3D_Webhook::handle(); });
add_action( 'woocommerce_api_vsafe_3ds_return', function () { MPG_VProcessor_3D_Webhook::handle_3ds_return(); });

/* ─── Activation / Deactivation ─── */
register_activation_hook( __FILE__, function () { flush_rewrite_rules(); });
register_deactivation_hook( __FILE__, function () { flush_rewrite_rules(); });

/* ─── Settings link ─── */
add_filter( 'plugin_action_links_' . MPG_PLUGIN_BASENAME, function ( $links ) {
    array_unshift( $links, '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mpg_vprocessor_2d' ) . '">Settings</a>' );
    return $links;
});
