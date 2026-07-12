<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_Provider_OpenAI extends AIFD_Provider_Base {

    protected $name = 'openai';

    public function call( $prompt ) {

        $api_key = get_option( 'aifd_openai_key', '' );
        $model   = get_option( 'aifd_openai_model', 'gpt-4o-mini' );

        if ( empty( $api_key ) ) {
            return $this->fallback( 'API key not configured' );
        }

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode( array(
                'model'      => $model,
                'max_tokens' => 200,
                'messages'   => array(
                    array(
                        'role'    => 'system',
                        'content' => 'You are a fraud detection expert. Always respond with valid JSON only.'
                    ),
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

        $content = $data['choices'][0]['message']['content'] ?? '';

        return $this->parse_response( $content );
    }
}