<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MPG_VProcessor_API {

    public static function endpoint( $env, $type, $version = '1' ) {
        $base = ( $env === 'live' ) ? 'https://vsafe.tech' : 'https://sandbox.vsafe.tech';
        return $base . '/api/v' . $version . '/' . $type . '/';
    }

    public static function sign( $key, $json ) {
        return hash( 'sha256', $key . $json . $key );
    }

    public static function post( $url, $key, $body, $timeout = 70 ) {
        $json = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return wp_remote_post( $url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Signature'    => self::sign( $key, $json ),
            ),
            'body'    => $json,
            'timeout' => $timeout,
        ));
    }

    /**
     * Map vSafe API error codes to user-friendly messages.
     * Raw details are kept in order notes for merchant debugging.
     */
    public static function friendly_error( $code ) {
        $code = (string) $code;

        $map = array(
            // Signature / auth
            '1050' => 'A security error occurred. Please try again or contact support.',
            '1051' => 'A security error occurred. Please try again or contact support.',
            '1060' => 'A security error occurred. Please try again or contact support.',

            // Merchant / config
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
            '9011' => 'Your card was declined by the bank. Please try a different card or contact your bank.',
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

        // Range-based fallbacks for unmapped codes
        $num = (int) $code;

        if ( $num >= 1050 && $num <= 1103 ) {
            return 'This payment method is temporarily unavailable. Please try another method or contact support.';
        }
        if ( $num >= 1507 && $num <= 1569 ) {
            return 'Your payment was declined. Please check your card details and try again.';
        }
        if ( $num >= 1700 && $num <= 1796 ) {
            return 'Your payment could not be processed. Please try again or use a different card.';
        }
        if ( $num >= 9000 && $num <= 9099 ) {
            return 'Your payment was declined. Please try a different card or contact your bank.';
        }
        if ( $num >= 9500 ) {
            return 'Your payment was declined. Please try a different card or contact your bank.';
        }

        return 'Your payment could not be processed. Please try again or contact support.';
    }
}
