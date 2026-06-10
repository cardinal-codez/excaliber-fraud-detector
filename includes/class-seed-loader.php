<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_Seed_Loader {

    private $pinecone;
    private $openai_key;

    public function __construct() {
        $this->pinecone   = new AIFD_Pinecone();
        $this->openai_key = get_option( 'aifd_openai_key', '' );
    }

    /**
     * Load all seed patterns into Pinecone
     */
    public function load_seeds() {

        if ( ! $this->pinecone->is_configured() ) {
            return array(
                'success' => false,
                'message' => 'Pinecone not configured — add API key and host URL first'
            );
        }

        if ( empty( $this->openai_key ) ) {
            return array(
                'success' => false,
                'message' => 'OpenAI API key not configured — needed for embeddings'
            );
        }

        // Load seed patterns from JSON file
        $seed_file = AIFD_PATH . 'data/seed-patterns.json';

        if ( ! file_exists( $seed_file ) ) {
            return array(
                'success' => false,
                'message' => 'seed-patterns.json not found in data/ folder'
            );
        }

        $patterns = json_decode( file_get_contents( $seed_file ), true );

        if ( empty( $patterns ) ) {
            return array(
                'success' => false,
                'message' => 'No patterns found in seed-patterns.json'
            );
        }

        $loaded  = 0;
        $failed  = 0;
        $skipped = 0;

        foreach ( $patterns as $pattern ) {

            // Embed the pattern text
            $embedding = $this->embed( $pattern['pattern'] );

            if ( ! $embedding ) {
                $failed++;
                continue;
            }

            // Store in Pinecone
            $success = $this->pinecone->upsert(
                $pattern['id'],
                $embedding,
                array(
                    'pattern'     => $pattern['pattern'],
                    'fraud_type'  => $pattern['fraud_type'],
                    'confidence'  => $pattern['confidence'],
                    'times_seen'  => $pattern['times_seen'],
                    'source'      => $pattern['source'],
                    'description' => $pattern['description'],
                )
            );

            if ( $success ) {
                $loaded++;
            } else {
                $failed++;
            }

            // Small delay to avoid rate limiting
            usleep( 200000 ); // 0.2 seconds

        }

        $total = count( $patterns );

        return array(
            'success' => true,
            'message' => "Loaded {$loaded}/{$total} patterns successfully. Failed: {$failed}",
            'loaded'  => $loaded,
            'failed'  => $failed,
            'total'   => $total,
        );

    }

    /**
     * Embed text using OpenAI text-embedding-3-small
     */
    public function embed( $text ) {

        $response = wp_remote_post( 'https://api.openai.com/v1/embeddings', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->openai_key,
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

}