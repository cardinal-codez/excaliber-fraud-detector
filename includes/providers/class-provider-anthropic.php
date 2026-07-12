<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_Provider_Anthropic extends AIFD_Provider_Base {

    protected $name = 'anthropic';

    public function call( $prompt ) {

        $api_key = get_option( 'aifd_anthropic_key', '' );
        $model   = get_option( 'aifd_anthropic_model', 'claude-haiku-4-5-20251001' );

        if ( empty( $api_key ) ) {
            return $this->fallback( 'API key not configured' );
        }

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'timeout' => 30,
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ),
            'body' => json_encode( array(
                'model'      => $model,
                'max_tokens' => 200,
                'system'     => 'You are a fraud detection expert. Always respond with valid JSON only.',
                'messages'   => array(
                    array(
                        'role'    => 'user',
                        'content' => $prompt
                    ),
                ),
            )),
        ));

        if ( is_wp_error( $response ) ) {
            return $this->fallback( 'Request failed' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $api_error = $data['error']['message'] ?? 'HTTP ' . $code;
            return $this->fallback( 'API error: ' . $api_error );
        }

        $content = $data['content'][0]['text'] ?? '';

        return $this->parse_response( $content );
    }
}