<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_Provider_Gemini extends AIFD_Provider_Base {

    protected $name = 'gemini';

    public function call( $prompt ) {

        $api_key = get_option( 'aifd_gemini_key', '' );
        $model   = get_option( 'aifd_gemini_model', 'gemini-2.5-flash' );

        // Google retired the Gemini 1.5 and 2.0 models — remap any stored value
        // so existing installs keep working without re-saving settings.
        if ( strpos( $model, 'gemini-1.5' ) === 0 || strpos( $model, 'gemini-2.0' ) === 0 ) {
            $model = 'gemini-2.5-flash';
        }

        // The model name becomes part of the request path.
        if ( ! preg_match( '/^[a-z0-9.\-]+$/', $model ) ) {
            $model = 'gemini-2.5-flash';
        }

        if ( empty( $api_key ) ) {
            return $this->fallback( 'API key not configured' );
        }

        // The key travels in a header, never in the URL, so it cannot leak through logs or error text.
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $response = wp_remote_post( $url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $api_key,
            ),
            'body' => wp_json_encode( array(
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

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $api_error = $data['error']['message'] ?? 'HTTP ' . $code;
            return $this->fallback( 'API error: ' . $api_error );
        }

        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return $this->parse_response( $content );
    }
}