<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MPG_EProcessor_API {

    private static function format_phone( $phone ) {
        $phone  = trim( $phone );
        $prefix = strpos( $phone, '+' ) === 0 ? '+' : '';
        return $prefix . preg_replace( '/\D/', '', $phone );
    }

    const PROCESS_URL = 'https://ts.secure1gateway.com/api/v2/processTx';
    const REFUND_URL  = 'https://ts.secure1gateway.com/api/v2/processRefund';
    const STATUS_URL  = 'https://ts.secure1gateway.com/api/v2/processTxGetStatus';

    public static function sha_with_card( $passphrase, $amount, $account_id, $email, $card_number, $customer_ip ) {
        return hash( 'sha256', $passphrase . $amount . $account_id . $email . $card_number . $customer_ip );
    }

    public static function sha_without_card( $passphrase, $amount, $account_id, $email, $customer_ip ) {
        return hash( 'sha256', $passphrase . $amount . $account_id . $email . $customer_ip );
    }

    public static function sha_refund( $passphrase, $account_id, $transaction_id ) {
        return hash( 'sha256', $passphrase . $account_id . $transaction_id );
    }

    public static function verify_response_sha( $passphrase, $response ) {
        if ( ! isset( $response['resp_sha'] ) ) return false;
        $expected = hash( 'sha256',
            $passphrase .
            ( $response['resp_trans_id'] ?? '' ) .
            ( $response['resp_trans_amount'] ?? '' ) .
            ( $response['resp_trans_status'] ?? '' )
        );
        return hash_equals( $expected, $response['resp_sha'] );
    }

    public static function post( $url, $data, $timeout = 95 ) {
        return wp_remote_post( $url, array(
            'method'      => 'POST',
            'timeout'     => $timeout,
            'httpversion' => '1.1',
            'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'        => $data,
            'sslverify'   => true,
        ));
    }

    public static function parse_response( $response ) {
        if ( is_wp_error( $response ) ) return false;
        $body   = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) return false;
        if ( isset( $result['success'] ) ) {
            if ( $result['success'] === true && isset( $result['data'] ) ) return $result['data'];
            if ( $result['success'] === false ) return false;
        }
        return $result;
    }

    public static function get_order_items_string( $order ) {
        $items = array();
        foreach ( $order->get_items() as $item ) {
            $items[] = $item->get_name() . ' x ' . $item->get_quantity();
        }
        return implode( ', ', $items );
    }

    public static function build_base_data( $gateway, $order ) {
        // Idempotent payment ID: same on double-click, new on retry after failure
        $existing_pid = $order->get_meta( '_mpg_ep_merchant_payment_id' );
        if ( $existing_pid && $order->has_status( 'pending' ) ) {
            $payment_id = $existing_pid;
        } else {
            $attempt    = (int) $order->get_meta( '_mpg_ep_attempt' ) + 1;
            $payment_id = $gateway->transaction_prefix . $order->get_id() . '-' . $attempt;
            $order->update_meta_data( '_mpg_ep_attempt', $attempt );
            $order->update_meta_data( '_mpg_ep_merchant_payment_id', $payment_id );
            $order->save();
        }

        // Format amount as decimal with 2 places (API requires e.g. "10000.00", no commas)
        $amount = number_format( (float) $order->get_total(), 2, '.', '' );

        $data = array(
            'account_id'              => $gateway->account_id,
            'account_password'        => $gateway->account_password,
            'action_type'             => 'payment',
            'account_gateway'         => $gateway->account_gateway,
            'merchant_payment_id'     => $payment_id,
            'cust_email'              => $order->get_billing_email(),
            'cust_billing_last_name'  => $order->get_billing_last_name(),
            'cust_billing_first_name' => $order->get_billing_first_name(),
            'cust_billing_address'    => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
            'cust_billing_city'       => $order->get_billing_city(),
            'cust_billing_zipcode'    => $order->get_billing_postcode(),
            'cust_billing_state'      => $order->get_billing_state() ?: 'NA',
            'cust_billing_country'    => $order->get_billing_country(),
            'cust_billing_phone'      => self::format_phone( $order->get_billing_phone() ),
            'transac_products_name'   => self::get_order_items_string( $order ),
            'transac_amount'          => $amount,
            'transac_currency_code'   => $order->get_currency(),
            'customer_ip'             => $order->get_customer_ip_address(),
            'merchant_url_return'     => home_url( 'eupaymentz-return' ) . '?order_id=' . $order->get_id(),
            'merchant_url_callback'   => home_url( 'eupaymentz-callback' ),
            'merchant_data1'          => (string) $order->get_id(),
            'merchant_data2'          => substr( $order->get_order_key(), 0, 20 ),
            'option'                  => '',
        );

        if ( $order->has_shipping_address() ) {
            $data['cust_shipping_last_name']  = $order->get_shipping_last_name();
            $data['cust_shipping_first_name'] = $order->get_shipping_first_name();
            $data['cust_shipping_address']    = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
            $data['cust_shipping_city']       = $order->get_shipping_city();
            $data['cust_shipping_zipcode']    = $order->get_shipping_postcode();
            $data['cust_shipping_state']      = $order->get_shipping_state() ?: 'NA';
            $data['cust_shipping_country']    = $order->get_shipping_country();
            $data['cust_shipping_phone']      = self::format_phone( $order->get_billing_phone() );
        }

        return $data;
    }

    public static function build_redirect_url( $response ) {
        if ( empty( $response['UrlToRedirect'] ) ) return '';
        $url    = $response['UrlToRedirect'];
        $method = $response['UrlToRedirectMethod'] ?? 'GET';
        if ( $method === 'GET' && ! empty( $response['UrlToRedirecPostedParameters'] ) ) {
            $params = array();
            foreach ( $response['UrlToRedirecPostedParameters'] as $p ) {
                if ( isset( $p['key'], $p['value'] ) ) $params[ $p['key'] ] = $p['value'];
            }
            if ( ! empty( $params ) ) $url = add_query_arg( $params, $url );
        }
        return $url;
    }

    public static function parse_transaction_status( $response ) {
        $status = $response['resp_trans_status'] ?? '';
        return array(
            'status'         => $status,
            'transaction_id' => $response['resp_trans_id'] ?? '',
            'description'    => $response['resp_trans_description_status'] ?? '',
            'is_success'     => ( $status === '00000' ),
            'is_pending'     => ( $status === 'PEND' ),
            'is_failed'      => ( $status !== '00000' && $status !== 'PEND' ),
        );
    }
}
