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
}
