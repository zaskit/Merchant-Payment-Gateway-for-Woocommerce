<?php
/**
 * V-Processor 3D Webhook Handler
 *
 * Handles incoming webhook notifications from VSafe for payment, refund and void events.
 * Returns JSON response as required by VSafe documentation:
 * { "status": "OK|ERROR", "description": "...", "transactionId": "...", "merchantTransactionId": "..." }
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MPG_VProcessor_3D_Webhook {

    /**
     * Handle incoming webhook.
     */
    public static function handle() {
        $raw_post = file_get_contents( 'php://input' );
        $data     = json_decode( $raw_post, true );

        // Get settings by reading the gateway settings directly
        $settings  = get_option( 'woocommerce_mpg_vprocessor_3d_settings', array() );
        $testmode  = ( $settings['testmode'] ?? 'yes' ) === 'yes';
        $api_token = $testmode ? ( $settings['test_api_token'] ?? '' ) : ( $settings['live_api_token'] ?? '' );
        $debug     = ( $settings['debug'] ?? 'yes' ) === 'yes';
        $logger    = $debug ? wc_get_logger() : null;
        $ctx       = array( 'source' => 'mpg-vprocessor-3d' );

        if ( $debug ) {
            $logger->debug( '=== WEBHOOK RECEIVED ===', $ctx );
            $logger->debug( 'Raw webhook data: ' . $raw_post, $ctx );
            $logger->debug( 'Headers: ' . wp_json_encode( function_exists( 'getallheaders' ) ? getallheaders() : array() ), $ctx );
            $logger->debug( 'Signature from header: ' . ( $_SERVER['HTTP_SIGNATURE'] ?? 'NOT PROVIDED' ), $ctx );
        }

        // Validate JSON
        if ( empty( $data ) || ! is_array( $data ) ) {
            if ( $debug ) $logger->debug( 'ERROR: Invalid or empty JSON body', $ctx );
            self::send_response( 'ERROR', 'Invalid JSON body', '', '', 400 );
        }

        // Validate signature: SHA256(api_token + raw_body + api_token)
        $signature = $_SERVER['HTTP_SIGNATURE'] ?? '';
        $expected  = hash( 'sha256', $api_token . $raw_post . $api_token );

        if ( ! hash_equals( $expected, $signature ) ) {
            if ( $debug ) {
                $logger->debug( 'ERROR: Invalid webhook signature', $ctx );
                $logger->debug( 'Expected: ' . $expected, $ctx );
                $logger->debug( 'Received: ' . $signature, $ctx );
            }
            self::send_response( 'ERROR', 'Invalid signature', '', '', 400 );
        }

        if ( $debug ) $logger->debug( 'Signature validated successfully', $ctx );

        // Validate transaction type
        if ( ! isset( $data['transactionType'] ) ) {
            if ( $debug ) $logger->debug( 'ERROR: Missing transaction type', $ctx );
            self::send_response( 'ERROR', 'Missing transaction type', '', '', 400 );
        }

        $transaction_id     = $data['transactionId'] ?? '';
        $external_reference = $data['externalReference'] ?? '';

        if ( $debug ) {
            $logger->debug( 'Transaction Type: ' . $data['transactionType'], $ctx );
            $logger->debug( 'Transaction ID: ' . $transaction_id, $ctx );
            $logger->debug( 'External Reference: ' . $external_reference, $ctx );
        }

        $result = false;

        switch ( $data['transactionType'] ) {
            case 'payment':
            case 'deposit':
                $result = self::process_payment_webhook( $data, $debug, $logger, $ctx );
                break;
            case 'refund':
                $result = self::process_refund_webhook( $data, $debug, $logger, $ctx );
                break;
            case 'void':
                $result = self::process_void_webhook( $data, $debug, $logger, $ctx );
                break;
            default:
                if ( $debug ) $logger->debug( 'ERROR: Unknown transaction type: ' . $data['transactionType'], $ctx );
                self::send_response( 'ERROR', 'Unknown transaction type', $transaction_id, $external_reference, 400 );
        }

        if ( $result === false ) {
            if ( $debug ) $logger->debug( 'Webhook processing failed, sending ERROR response', $ctx );
            self::send_response( 'ERROR', 'Processing failed', $transaction_id, $external_reference, 200 );
        }

        if ( $debug ) {
            $logger->debug( 'Webhook processed successfully, sending OK response', $ctx );
            $logger->debug( '=== WEBHOOK COMPLETE ===', $ctx );
        }

        self::send_response( 'OK', 'Transaction Updated', $transaction_id, $external_reference, 200 );
    }

    /**
     * Send JSON response to VSafe and exit.
     */
    private static function send_response( $status, $description, $transaction_id, $merchant_transaction_id, $http_code = 200 ) {
        status_header( $http_code );
        header( 'Content-Type: application/json' );
        echo wp_json_encode( array(
            'status'                => $status,
            'description'           => $description,
            'transactionId'         => $transaction_id,
            'merchantTransactionId' => $merchant_transaction_id,
        ) );
        exit;
    }

    /**
     * Process payment webhook.
     */
    private static function process_payment_webhook( $data, $debug, $logger, $ctx ) {
        if ( $debug ) $logger->debug( '=== PROCESSING PAYMENT WEBHOOK ===', $ctx );

        if ( ! isset( $data['externalReference'] ) ) {
            if ( $debug ) $logger->debug( 'ERROR: Missing external reference', $ctx );
            return false;
        }

        // externalReference format: "order_id" or "order_id-attempt_hash"
        $ext_ref  = $data['externalReference'];
        $order_id = intval( explode( '-', $ext_ref )[0] );
        if ( $debug ) $logger->debug( 'Order ID from webhook: ' . $order_id . ' (ref: ' . $ext_ref . ')', $ctx );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            if ( $debug ) $logger->debug( 'ERROR: Order not found: ' . $order_id, $ctx );
            return false;
        }

        // Already completed — still return OK to VSafe
        if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
            if ( $debug ) $logger->debug( 'Order already completed, skipping', $ctx );
            return true;
        }

        $status         = $data['result']['status'] ?? '';
        $transaction_id = $data['transactionId'] ?? '';
        $error_detail   = $data['result']['errorDetail'] ?? '';
        $error_code     = $data['result']['errorCode'] ?? '';

        if ( $debug ) {
            $logger->debug( 'Transaction Status: ' . $status, $ctx );
            $logger->debug( 'Transaction ID: ' . $transaction_id, $ctx );
            $logger->debug( 'Error Code: ' . $error_code, $ctx );
            $logger->debug( 'Error Detail: ' . $error_detail, $ctx );
        }

        // Store card meta data
        foreach ( array(
            'cardBrand'       => '_mpg_vp3d_card_brand',
            'lastFour'        => '_mpg_vp3d_last_four',
            'bin'             => '_mpg_vp3d_bin',
            'transactionDate' => '_mpg_vp3d_transaction_date',
        ) as $key => $meta ) {
            if ( ! empty( $data[ $key ] ) ) {
                $order->update_meta_data( $meta, sanitize_text_field( $data[ $key ] ) );
            }
        }
        if ( isset( $data['livemode'] ) ) {
            $order->update_meta_data( '_mpg_vp3d_livemode', $data['livemode'] ? 'yes' : 'no' );
        }

        // Check for 3DS redirect URL in webhook — check both inside result and at top level
        $redirect_url = '';
        if ( ! empty( $data['result']['redirectUrl'] ) ) {
            $redirect_url = $data['result']['redirectUrl'];
        } elseif ( ! empty( $data['redirectUrl'] ) ) {
            $redirect_url = $data['redirectUrl'];
        }

        if ( ! empty( $redirect_url ) ) {
            if ( $debug ) $logger->debug( '3DS Redirect URL received via webhook: ' . $redirect_url, $ctx );
            $order->update_meta_data( '_mpg_vp3d_3ds_redirect_url', esc_url_raw( $redirect_url ) );
            $order->update_meta_data( '_mpg_vp3d_webhook_status', 'redirect_3ds' );
            $order->add_order_note( 'VP3D: 3DS challenge URL received via webhook. TX: ' . $transaction_id );
            $order->save();
            return true;
        }

        $order->save();

        // Update order based on status
        switch ( $status ) {
            case 'pending':
                if ( $debug ) $logger->debug( 'Webhook pending (no redirectUrl) for order #' . $order_id, $ctx );
                $order->add_order_note( 'VP3D webhook: status=pending. TX: ' . $transaction_id );
                return true;

            case 'approved':
                if ( $debug ) $logger->debug( 'Approving payment for order #' . $order_id, $ctx );
                $order->update_meta_data( '_mpg_vp3d_webhook_status', 'approved' );
                $order->save();
                $order->payment_complete( $transaction_id );
                wc_reduce_stock_levels( $order_id );
                $order->add_order_note( 'VP3D payment approved via webhook. TX: ' . $transaction_id );
                if ( $debug ) $logger->debug( 'Payment approved successfully', $ctx );
                return true;

            case 'declined':
                if ( $debug ) $logger->debug( 'Declining payment for order #' . $order_id . ': ' . $error_detail, $ctx );
                $order->update_meta_data( '_mpg_vp3d_webhook_status', 'declined' );
                $order->save();
                $order->update_status( 'failed', 'Payment declined: ' . $error_detail );
                return true;

            case 'error':
                if ( $debug ) $logger->debug( 'Payment error for order #' . $order_id . ': ' . $error_detail, $ctx );
                $order->update_meta_data( '_mpg_vp3d_webhook_status', 'error' );
                $order->save();
                $order->update_status( 'failed', 'Payment error: ' . $error_detail );
                return true;

            default:
                if ( $debug ) $logger->debug( 'WARNING: Unknown payment status: ' . $status, $ctx );
                return false;
        }
    }

    /**
     * Process refund webhook.
     */
    private static function process_refund_webhook( $data, $debug, $logger, $ctx ) {
        if ( $debug ) $logger->debug( '=== PROCESSING REFUND WEBHOOK ===', $ctx );

        if ( ! isset( $data['refenceTransactionId'] ) ) {
            if ( $debug ) $logger->debug( 'ERROR: Missing refenceTransactionId', $ctx );
            return false;
        }

        $orders = wc_get_orders( array(
            'limit'      => 1,
            'meta_key'   => '_mpg_vp3d_transaction_id',
            'meta_value' => $data['refenceTransactionId'],
        ) );

        if ( empty( $orders ) ) {
            if ( $debug ) $logger->debug( 'ERROR: Order not found for refund tx: ' . $data['refenceTransactionId'], $ctx );
            return false;
        }

        $order          = $orders[0];
        $status         = $data['result']['status'] ?? '';
        $transaction_id = $data['transactionId'] ?? '';
        $amount         = $data['amount'] ?? 0;

        if ( $debug ) {
            $logger->debug( 'Refund status: ' . $status . ' for order #' . $order->get_id(), $ctx );
            $logger->debug( 'Refund amount: ' . $amount, $ctx );
        }

        if ( $status === 'approved' ) {
            $order->add_order_note( sprintf(
                'VP3D refund approved via webhook. Amount: %s. TX: %s',
                wc_price( $amount ),
                $transaction_id
            ) );
            return true;
        }

        if ( $debug ) $logger->debug( 'Refund not approved. Status: ' . $status, $ctx );
        return true; // Still acknowledge to VSafe
    }

    /**
     * Process void webhook.
     */
    private static function process_void_webhook( $data, $debug, $logger, $ctx ) {
        if ( $debug ) $logger->debug( '=== PROCESSING VOID WEBHOOK ===', $ctx );

        if ( ! isset( $data['refenceTransactionId'] ) ) {
            if ( $debug ) $logger->debug( 'ERROR: Missing refenceTransactionId', $ctx );
            return false;
        }

        $orders = wc_get_orders( array(
            'limit'      => 1,
            'meta_key'   => '_mpg_vp3d_transaction_id',
            'meta_value' => $data['refenceTransactionId'],
        ) );

        if ( empty( $orders ) ) {
            if ( $debug ) $logger->debug( 'ERROR: Order not found for void tx: ' . $data['refenceTransactionId'], $ctx );
            return false;
        }

        $order          = $orders[0];
        $status         = $data['result']['status'] ?? '';
        $transaction_id = $data['transactionId'] ?? '';

        if ( $debug ) $logger->debug( 'Void status: ' . $status . ' for order #' . $order->get_id(), $ctx );

        if ( $status === 'approved' ) {
            $order->update_status( 'cancelled', 'Payment voided via webhook. TX: ' . $transaction_id );
            return true;
        }

        if ( $debug ) $logger->debug( 'Void not approved. Status: ' . $status, $ctx );
        return true;
    }

    /**
     * Handle 3DS return (customer redirected back after 3DS challenge).
     */
    public static function handle_3ds_return() {
        $settings = get_option( 'woocommerce_mpg_vprocessor_3d_settings', array() );
        $debug    = ( $settings['debug'] ?? 'yes' ) === 'yes';
        $logger   = $debug ? wc_get_logger() : null;
        $ctx      = array( 'source' => 'mpg-vprocessor-3d' );

        if ( $debug ) $logger->debug( '=== 3DS RETURN RECEIVED ===', $ctx );
        if ( $debug ) $logger->debug( 'GET params: ' . wp_json_encode( $_GET ), $ctx );
        if ( $debug ) $logger->debug( 'POST params: ' . wp_json_encode( $_POST ), $ctx );

        $order_id       = 0;
        $transaction_id = '';

        // Try to get order_id from GET or POST params
        // externalReference format: "order_id" or "order_id-attempt_hash"
        if ( isset( $_GET['order_id'] ) ) {
            $order_id = absint( $_GET['order_id'] );
        } elseif ( isset( $_GET['externalReference'] ) ) {
            $order_id = absint( explode( '-', $_GET['externalReference'] )[0] );
        } elseif ( isset( $_POST['externalReference'] ) ) {
            $order_id = absint( explode( '-', $_POST['externalReference'] )[0] );
        }

        // VSafe sends a base64-encoded 'result' parameter after 3DS challenge
        $threed_result = null;
        if ( isset( $_GET['result'] ) ) {
            $result_data = json_decode( base64_decode( $_GET['result'] ), true );
            if ( $debug ) $logger->debug( 'Decoded 3DS result: ' . wp_json_encode( $result_data ), $ctx );

            if ( is_array( $result_data ) ) {
                $threed_result = $result_data;

                // Get VSafe transaction ID from the 'reference' field
                if ( ! $order_id && isset( $result_data['reference'] ) ) {
                    $transaction_id = sanitize_text_field( $result_data['reference'] );
                    if ( $debug ) $logger->debug( 'Transaction ID from 3DS result: ' . $transaction_id, $ctx );

                    // Find order by VSafe transaction ID
                    $orders = wc_get_orders( array(
                        'limit'      => 1,
                        'meta_key'   => '_mpg_vp3d_transaction_id',
                        'meta_value' => $transaction_id,
                    ) );

                    if ( ! empty( $orders ) ) {
                        $order_id = $orders[0]->get_id();
                        if ( $debug ) $logger->debug( 'Order found by transaction ID: ' . $order_id, $ctx );
                    }
                }
            }
        }

        if ( $debug ) $logger->debug( 'Order ID resolved: ' . $order_id, $ctx );

        if ( ! $order_id ) {
            if ( $debug ) $logger->debug( 'ERROR: No order ID found in 3DS return', $ctx );
            wp_redirect( wc_get_checkout_url() );
            exit;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            if ( $debug ) $logger->debug( 'ERROR: Order not found: ' . $order_id, $ctx );
            wp_redirect( wc_get_checkout_url() );
            exit;
        }

        // Verify this order belongs to our payment method
        if ( $order->get_payment_method() !== 'mpg_vprocessor_3d' ) {
            if ( $debug ) $logger->debug( 'ERROR: Order payment method mismatch', $ctx );
            wp_redirect( wc_get_checkout_url() );
            exit;
        }

        if ( $debug ) $logger->debug( 'Order status: ' . $order->get_status(), $ctx );

        // Already completed — webhook arrived first
        if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
            if ( $debug ) $logger->debug( 'Order already completed, redirecting to thank you', $ctx );
            wp_redirect( $order->get_checkout_order_received_url() );
            exit;
        }

        // Failed
        if ( $order->has_status( 'failed' ) ) {
            if ( $debug ) $logger->debug( 'Order is failed, redirecting to checkout', $ctx );
            wc_add_notice( 'Payment failed. Please try again.', 'error' );
            wp_redirect( wc_get_checkout_url() );
            exit;
        }

        // Check the 3DS result from the base64-encoded result parameter
        if ( $threed_result && isset( $threed_result['status'] ) ) {
            $threed_status = strtoupper( $threed_result['status'] );
            if ( $debug ) $logger->debug( '3DS result status: ' . $threed_status, $ctx );

            if ( $threed_status === 'APPROVED' ) {
                $auth_number      = isset( $threed_result['authorizationNumber'] ) ? sanitize_text_field( $threed_result['authorizationNumber'] ) : '';
                $response_message = isset( $threed_result['responseMessage'] ) ? sanitize_text_field( $threed_result['responseMessage'] ) : '';

                $order->payment_complete( $transaction_id );
                wc_reduce_stock_levels( $order_id );
                $order->add_order_note( sprintf(
                    'VP3D payment approved after 3DS challenge. Auth: %s. Response: %s',
                    $auth_number,
                    $response_message
                ) );

                if ( $auth_number ) {
                    $order->update_meta_data( '_mpg_vp3d_authorization_number', $auth_number );
                }
                $order->update_meta_data( '_mpg_vp3d_3ds_status', 'APPROVED' );
                $order->save();

                if ( $debug ) $logger->debug( 'Order completed via 3DS result (APPROVED). Auth: ' . $auth_number, $ctx );

                wp_redirect( $order->get_checkout_order_received_url() );
                exit;
            }

            // For DECLINED/REJECTED/FAILED: do NOT trust the 3DS return result.
            // The 3DS return URL is just a customer redirect — not the source of truth.
            // The definitive status comes via webhook. Log it and fall through to polling.
            if ( in_array( $threed_status, array( 'DECLINED', 'REJECTED', 'FAILED' ), true ) ) {
                if ( $debug ) $logger->debug( '3DS return says ' . $threed_status . ' — ignoring, will wait for webhook as source of truth', $ctx );
                $order->add_order_note( 'VP3D: 3DS return status: ' . $threed_status . '. Awaiting webhook for definitive result.' );
                $order->update_meta_data( '_mpg_vp3d_3ds_return_status', $threed_status );
                $order->save();
            }
        }

        // Set order to on-hold and redirect to thank-you page with polling overlay
        // The webhook will deliver the final approved/declined status
        if ( $order->has_status( 'pending' ) ) {
            $order->update_status( 'on-hold', 'Customer returned from 3DS challenge. Awaiting webhook confirmation.' );
            if ( $debug ) $logger->debug( 'Order set to on-hold, awaiting webhook after 3DS return', $ctx );
        }

        // Redirect to thank-you page with polling so customer waits for webhook
        $polling_url = add_query_arg( array(
            'mpg_vp3d_poll' => '1',
            'order_id'      => $order_id,
            'key'           => $order->get_order_key(),
        ), $order->get_checkout_order_received_url() );

        if ( $debug ) $logger->debug( 'Redirecting to polling page: ' . $polling_url, $ctx );

        wp_redirect( $polling_url );
        exit;
    }
}
