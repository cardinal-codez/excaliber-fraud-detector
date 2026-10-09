<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_Seed_Loader {

    /**
     * Patterns handled per request. Each one costs an embedding call and a
     * Pinecone write, so loading all of them in one go can outlast the PHP or
     * proxy timeout.
     */
    const BATCH_SIZE = 10;

    private $pinecone;
    private $embeddings;

    public function __construct() {
        $this->pinecone   = new AIFD_Pinecone();
        $this->embeddings = new AIFD_Embeddings();
    }

    /**
     * Load seeds using current provider + namespace
     *
     * @param int $offset First pattern to load.
     * @param int $limit  How many to load from there; 0 loads the rest.
     */
    public function load_seeds( $offset = 0, $limit = 0 ) {
        $provider  = get_option( 'aifd_ai_provider', 'openai' );
        $namespace = $this->embeddings->get_namespace( $provider );

        return $this->load_seeds_to_namespace( $namespace, $provider, $offset, $limit );
    }

    /**
     * Load seeds to a specific namespace and provider
     */
    public function load_seeds_to_namespace( $namespace, $provider, $offset = 0, $limit = 0 ) {

        if ( ! $this->pinecone->is_configured() ) {
            return array(
                'success' => false,
                'message' => __( 'Pinecone not configured — add API key and host URL first', 'hifqa-fraud-detector' ),
            );
        }

        // Say why up front rather than failing 65 times — and never embed with another provider instead.
        $problem = $this->embeddings->get_setup_problem( $provider );

        if ( '' !== $problem ) {
            return array(
                'success' => false,
                'message' => $problem,
            );
        }

        // Load seed patterns from JSON file
        $seed_file = AIFD_PATH . 'data/seed-patterns.json';

        if ( ! is_readable( $seed_file ) ) {
            return array(
                'success' => false,
                'message' => __( 'seed-patterns.json not found in data/ folder', 'hifqa-fraud-detector' )
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a file bundled with the plugin.
        $patterns = json_decode( file_get_contents( $seed_file ), true );

        if ( empty( $patterns ) || ! is_array( $patterns ) ) {
            return array(
                'success' => false,
                'message' => __( 'No patterns found in seed-patterns.json', 'hifqa-fraud-detector' )
            );
        }

        $total  = count( $patterns );
        $offset = max( 0, (int) $offset );
        $limit  = (int) $limit;
        $batch  = array_slice( $patterns, $offset, $limit > 0 ? $limit : null );

        $loaded     = 0;
        $failed     = 0;
        $last_error = '';

        foreach ( $batch as $pattern ) {

            if ( empty( $pattern['id'] ) || empty( $pattern['pattern'] ) ) {
                $failed++;
                continue;
            }

            // Embed the pattern text
            $embedding = $this->embeddings->embed( $pattern['pattern'], $provider );

            if ( ! $embedding ) {
                $failed++;
                $last_error = $this->embeddings->get_last_error_message();
                continue;
            }

            // Store in Pinecone
            $success = $this->pinecone->upsert(
                $pattern['id'],
                $embedding,
                array(
                    'pattern'     => $pattern['pattern'],
                    'fraud_type'  => $pattern['fraud_type'] ?? 'unknown',
                    'confidence'  => $pattern['confidence'] ?? 0,
                    'times_seen'  => $pattern['times_seen'] ?? 0,
                    'source'      => $pattern['source'] ?? 'seed',
                    'description' => $pattern['description'] ?? '',
                    'provider'    => $provider,
                ),
                $namespace
            );

            if ( $success ) {
                $loaded++;
            } else {
                $failed++;
                $error      = $this->pinecone->get_last_error();
                $last_error = $error ? $error['message'] : $last_error;
            }

            // Small delay to avoid rate limiting
            usleep( 200000 ); // 0.2 seconds

        }

        $next_offset = $offset + count( $batch );

        $result = array(
            'loaded'      => $loaded,
            'failed'      => $failed,
            'total'       => $total,
            'next_offset' => $next_offset,
            'done'        => $next_offset >= $total,
            'namespace'   => $namespace,
            'provider'    => $provider,
        );

        // Nothing got through: report the reason instead of a "success" with zero loaded.
        if ( 0 === $loaded && $failed > 0 ) {
            $result['success'] = false;
            $result['done']    = true;
            $result['message'] = $last_error ?: __( 'No patterns could be loaded.', 'hifqa-fraud-detector' );
            return $result;
        }

        $result['success'] = true;
        /* translators: 1: number of patterns loaded, 2: total patterns, 3: Pinecone namespace, 4: number of failures */
        $result['message'] = sprintf( __( 'Loaded %1$d/%2$d patterns in namespace: %3$s. Failed: %4$d', 'hifqa-fraud-detector' ), $loaded, $total, $namespace, $failed );

        return $result;

    }

}
