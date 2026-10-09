<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_Provider_Ollama extends AIFD_Provider_Base {

    protected $name = 'ollama';

    public function call( $prompt ) {

        $host  = get_option( 'aifd_ollama_host', 'http://localhost:11434' );
        $model = get_option( 'aifd_ollama_model', 'llama3' );

        $response = wp_remote_post( rtrim( $host, '/' ) . '/api/chat', array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'    => $model,
                'stream'   => false,
                'messages' => array(
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
            return $this->fallback( 'Request failed — is Ollama running?' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            return $this->fallback( 'Ollama error: HTTP ' . $code );
        }

        $content = $data['message']['content'] ?? '';

        return $this->parse_response( $content );
    }
}