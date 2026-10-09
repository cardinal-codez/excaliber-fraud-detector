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
            'model'     => 'gemini-embedding-001',
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
     * Why the last embed() call returned false: array( 'code' => ..., 'message' => ... ).
     *
     * Code is 'not_configured' when a key or host is missing, otherwise the
     * request itself failed.
     */
    private $last_error = null;

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
     * Why the last embed() call failed, or null if it did not.
     */
    public function get_last_error() {
        return $this->last_error;
    }

    public function get_last_error_message() {
        return $this->last_error ? $this->last_error['message'] : '';
    }

    /**
     * The reason this provider cannot produce embeddings right now, or '' if it can.
     *
     * Each provider embeds with its own service and its own key. There is no
     * cross-provider fallback: text is only ever sent to the service the
     * merchant configured for the selected provider.
     */
    public function get_setup_problem( $provider = null ) {

        if ( ! $provider ) {
            $provider = get_option( 'aifd_ai_provider', 'openai' );
        }

        switch ( $provider ) {
            case 'anthropic':
                return get_option( 'aifd_voyage_key', '' )
                    ? ''
                    : __( 'Anthropic has no embedding model, so vector memory needs a Voyage AI key. Add one in Settings, or leave vector memory off — nothing is sent to another provider in its place.', 'hifqa-fraud-detector' );
            case 'gemini':
                return get_option( 'aifd_gemini_key', '' )
                    ? ''
                    : __( 'Vector memory needs your Google Gemini API key to create embeddings.', 'hifqa-fraud-detector' );
            case 'ollama':
                return get_option( 'aifd_ollama_host', '' )
                    ? ''
                    : __( 'Vector memory needs your Ollama host URL to create embeddings.', 'hifqa-fraud-detector' );
            case 'openai':
                return get_option( 'aifd_openai_key', '' )
                    ? ''
                    : __( 'Vector memory needs your OpenAI API key to create embeddings.', 'hifqa-fraud-detector' );
            default:
                return __( 'The selected AI provider is not recognised.', 'hifqa-fraud-detector' );
        }

    }

    /**
     * Embed text using the selected provider's own embedding service.
     *
     * @return array|false The vector, or false — see get_last_error() for why.
     */
    public function embed( $text, $provider = null ) {

        $this->last_error = null;

        if ( ! $provider ) {
            $provider = get_option( 'aifd_ai_provider', 'openai' );
        }

        $problem = $this->get_setup_problem( $provider );

        if ( '' !== $problem ) {
            return $this->fail( 'not_configured', $problem );
        }

        switch ( $provider ) {
            case 'anthropic':
                return $this->embed_voyage( $text );
            case 'gemini':
                return $this->embed_gemini( $text );
            case 'ollama':
                return $this->embed_ollama( $text );
            default:
                return $this->embed_openai( $text );
        }

    }

    private function fail( $code, $message ) {
        $this->last_error = array(
            'code'    => $code,
            'message' => $message,
        );
        return false;
    }

    /**
     * POST a JSON body and return the decoded reply, or false on any failure.
     *
     * Error text is built from the service name and status code only — never
     * from the request, so a key cannot end up in a message or a log.
     */
    private function post_json( $service, $url, $headers, $body ) {

        $response = wp_remote_post( $url, array(
            'timeout' => 30,
            'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
            'body'    => wp_json_encode( $body ),
        ));

        if ( is_wp_error( $response ) ) {
            /* translators: %s: name of the embedding service */
            return $this->fail( 'request_failed', sprintf( __( 'Could not reach %s for embeddings.', 'hifqa-fraud-detector' ), $service ) );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( $code < 200 || $code >= 300 ) {
            /* translators: 1: name of the embedding service, 2: HTTP status code */
            return $this->fail( 'http_error', sprintf( __( '%1$s rejected the embedding request (HTTP %2$d). Check the API key.', 'hifqa-fraud-detector' ), $service, $code ) );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $data ) ) {
            /* translators: %s: name of the embedding service */
            return $this->fail( 'bad_response', sprintf( __( '%s returned an unreadable embedding reply.', 'hifqa-fraud-detector' ), $service ) );
        }

        return $data;

    }

    /**
     * Pull the vector out of a reply, or record why there is none.
     */
    private function vector_or_fail( $service, $vector ) {

        if ( is_array( $vector ) && ! empty( $vector ) ) {
            return $vector;
        }

        /* translators: %s: name of the embedding service */
        return $this->fail( 'bad_response', sprintf( __( '%s returned no embedding.', 'hifqa-fraud-detector' ), $service ) );

    }

    /**
     * OpenAI embeddings — text-embedding-3-small
     */
    private function embed_openai( $text ) {

        $data = $this->post_json(
            'OpenAI',
            'https://api.openai.com/v1/embeddings',
            array( 'Authorization' => 'Bearer ' . get_option( 'aifd_openai_key', '' ) ),
            array(
                'input' => $text,
                'model' => 'text-embedding-3-small',
            )
        );

        return false === $data ? false : $this->vector_or_fail( 'OpenAI', $data['data'][0]['embedding'] ?? null );

    }

    /**
     * Voyage AI embeddings — for Anthropic users
     */
    private function embed_voyage( $text ) {

        $data = $this->post_json(
            'Voyage AI',
            'https://api.voyageai.com/v1/embeddings',
            array( 'Authorization' => 'Bearer ' . get_option( 'aifd_voyage_key', '' ) ),
            array(
                'input' => $text,
                'model' => 'voyage-3',
            )
        );

        return false === $data ? false : $this->vector_or_fail( 'Voyage AI', $data['data'][0]['embedding'] ?? null );

    }

    /**
     * Gemini embeddings — gemini-embedding-001, reduced to 768 dimensions
     */
    private function embed_gemini( $text ) {

        // The key travels in a header, never in the URL, so it cannot leak through logs or error text.
        $data = $this->post_json(
            'Google Gemini',
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent',
            array( 'x-goog-api-key' => get_option( 'aifd_gemini_key', '' ) ),
            array(
                'model'                => 'models/gemini-embedding-001',
                'content'              => array(
                    'parts' => array(
                        array( 'text' => $text )
                    )
                ),
                'outputDimensionality' => 768,
            )
        );

        return false === $data ? false : $this->vector_or_fail( 'Google Gemini', $data['embedding']['values'] ?? null );

    }

    /**
     * Ollama embeddings — nomic-embed-text
     */
    private function embed_ollama( $text ) {

        $host = get_option( 'aifd_ollama_host', 'http://localhost:11434' );

        $data = $this->post_json(
            'Ollama',
            rtrim( $host, '/' ) . '/api/embeddings',
            array(),
            array(
                'model'  => 'nomic-embed-text',
                'prompt' => $text,
            )
        );

        return false === $data ? false : $this->vector_or_fail( 'Ollama', $data['embedding'] ?? null );

    }

}
