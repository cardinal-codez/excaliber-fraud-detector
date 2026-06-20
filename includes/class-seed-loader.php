<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_Seed_Loader {

    private $pinecone;
    private $embeddings;

    public function __construct() {
        $this->pinecone   = new AIFD_Pinecone();
        $this->embeddings = new AIFD_Embeddings();
    }

    /**
     * Load seeds using current provider + namespace
     */
    public function load_seeds() {
        $provider  = get_option( 'aifd_ai_provider', 'openai' );
        $namespace = $this->embeddings->get_namespace( $provider );

        return $this->load_seeds_to_namespace( $namespace, $provider );
    }

    /**
     * Load seeds to a specific namespace and provider
     */
    public function load_seeds_to_namespace( $namespace, $provider ) {


        if ( ! $this->pinecone->is_configured() ) {
            return array(
                'success' => false,
                'message' => 'Pinecone not configured — add API key and host URL first',
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
            $embedding = $this->embeddings->embed( $pattern['pattern'], $provider );

            if ( ! $embedding ) {
                $failed++;
                error_log( 'AIFD Seed Loader: Failed to embed pattern ' . $pattern['id'] );
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
                    'provider'    => $provider,
                ),
                $namespace
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
            'message' => "Loaded {$loaded}/{$total} patterns in namespace: {$namespace}. Failed: {$failed}",
            'loaded'  => $loaded,
            'failed'  => $failed,
            'total'   => $total,
            'namespace' => $namespace,
            'provider'  => $provider,
        );

    }


}