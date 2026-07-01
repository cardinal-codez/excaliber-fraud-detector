<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_Embeddings {

    /**
     * Embedding model map per provider
     */
    private $models = array(
        'openai'    => array(
            'model'     => 'text-embedding-3-small',
            'dimension' => 1536,
            'index_key' => 'aifd_pinecone_index_openai',
        ),
        'anthropic' => array(
            'model'     => 'voyage-3',
            'dimension' => 1024,
            'index_key' => 'aifd_pinecone_index_anthropic',
        ),
        'gemini'    => array(
            'model'     => 'text-embedding-004',
            'dimension' => 768,
            'index_key' => 'aifd_pinecone_index_768',
        ),
        'ollama'    => array(
            'model'     => 'nomic-embed-text',
            'dimension' => 768,
            'index_key' => 'aifd_pinecone_index_768',
        ),
    );

    /**
     * Get embedding config for current provider
     */
    public function get_config( $provider = null ) {
        if ( ! $provider ) {
            $provider = get_option( 'aifd_ai_provider', 'openai' );
        }
        return $this->models[ $provider ] ?? $this->models['openai'];
    }

    /**
     * Get Pinecone index name for current provider
     */
    public function get_index_name( $provider = null ) {
        $config = $this->get_config( $provider );
        return get_option( $config['index_key'], $config['index_key'] );
    }

    /**
     * Get namespace for merchant + provider
     */
    public function get_namespace( $provider = null ) {
        if ( ! $provider ) {
            $provider = get_option( 'aifd_ai_provider', 'openai' );
        }
        $merchant_id = get_current_blog_id();
        return 'merchant_' . $merchant_id . '_' . $provider;
    }

    /**
     * Embed text using the correct provider's embedding model
     */
    public function embed( $text, $provider = null ) {

        if ( ! $provider ) {
            $provider = get_option( 'aifd_ai_provider', 'openai' );
        }

        switch ( $provider ) {
            case 'anthropic':
                return $this->embed_voyage( $text );
            case 'gemini':
                return $this->embed_gemini( $text );
            case 'ollama':
                return $this->embed_ollama( $text );
            case 'openai':
            default:
                return $this->embed_openai( $text );
        }

    }

    /**
     * OpenAI embeddings — text-embedding-3-small
     */
    private function embed_openai( $text ) {

        $api_key = get_option( 'aifd_openai_key', '' );

        if ( empty( $api_key ) ) {
            return false;
        }

        $response = wp_remote_post( 'https://api.openai.com/v1/embeddings', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode( array(
                'input' => $text,
                'model' => 'text-embedding-3-small',
            )),
        ));

        if ( is_wp_error( $response ) ) {
            return false;
        }


        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['data'][0]['embedding'] ?? false;

    }

    /**
     * Voyage AI embeddings — for Anthropic users
     */
    private function embed_voyage( $text ) {

        $api_key = get_option( 'aifd_voyage_key', '' );

        if ( empty( $api_key ) ) {
            // Fallback to OpenAI if no Voyage key
            return $this->embed_openai( $text );
        }

        $response = wp_remote_post( 'https://api.voyageai.com/v1/embeddings', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode( array(
                'input' => $text,
                'model' => 'voyage-3',
            )),
        ));

        if ( is_wp_error( $response ) ) {
            return $this->embed_openai( $text );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['data'][0]['embedding'] ?? false;

    }

    /**
     * Gemini embeddings — text-embedding-004
     */
    private function embed_gemini( $text ) {

        $api_key = get_option( 'aifd_gemini_key', '' );

        if ( empty( $api_key ) ) {
            return false;
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent?key=' . $api_key;

        $response = wp_remote_post( $url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( array(
                'model'   => 'models/text-embedding-004',
                'content' => array(
                    'parts' => array(
                        array( 'text' => $text )
                    )
                ),
            )),
        ));

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['embedding']['values'] ?? false;

    }

    /**
     * Ollama embeddings — nomic-embed-text
     */
    private function embed_ollama( $text ) {

        $host  = get_option( 'aifd_ollama_host', 'http://localhost:11434' );
        $model = 'nomic-embed-text';

        $response = wp_remote_post( rtrim( $host, '/' ) . '/api/embeddings', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( array(
                'model'  => $model,
                'prompt' => $text,
            )),
        ));

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['embedding'] ?? false;

    }

}