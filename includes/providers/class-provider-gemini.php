<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_Provider_Gemini extends AIFD_Provider_Base {

    protected $name = 'gemini';

    public function call( $prompt ) {

        $api_key = get_option( 'aifd_gemini_key', '' );
        $model   = get_option( 'aifd_gemini_model', 'gemini-1.5-flash' );

        if ( empty( $api_key ) ) {
            return $this->fallback( 'API key not configured' );
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

        $response = wp_remote_post( $url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( array(
                'contents' => array(
                    array(
                        'parts' => array(
                            array(
                                'text' => 'You are a fraud detection expert. Always respond with valid JSON only. ' . $prompt
                            ),
                        ),
                    ),
                ),
                'generationConfig' => array(
                    'maxOutputTokens' => 200,
                ),
            )),
        ));

        if ( is_wp_error( $response ) ) {
            return $this->fallback( 'Request failed' );
        }

        $data    = json_decode( wp_remote_retrieve_body( $response ), true );
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return $this->parse_response( $content );
    }
}