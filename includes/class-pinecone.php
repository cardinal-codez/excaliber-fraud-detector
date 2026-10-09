<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_Pinecone {

    private $api_key;
    private $host;
    private $embeddings;

    /**
     * Why the last call failed: array( 'code' => ..., 'message' => ... ), or null.
     */
    private $last_error = null;

    public function __construct() {
        $this->api_key = get_option( 'aifd_pinecone_key', '' );
        $this->host    = rtrim( get_option( 'aifd_pinecone_host', '' ), '/' );
        $this->embeddings = new AIFD_Embeddings();
    }

    /**
     * Why the last upsert, query or search failed, or null if it did not.
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * POST to the index and return the decoded reply, or false on any failure.
     */
    private function post( $path, $body ) {

        $this->last_error = null;

        $response = wp_remote_post( $this->host . $path, array(
            'timeout' => 30,
            'headers' => array(
                'Api-Key'      => $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
        ));

        if ( is_wp_error( $response ) ) {
            $this->last_error = array(
                'code'    => 'request_failed',
                'message' => __( 'Could not reach Pinecone. Check the index host URL.', 'hifqa-fraud-detector' ),
            );
            return false;
        }

        // A wrong key or host answers 4xx — don't report that as success.
        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( $code < 200 || $code >= 300 ) {
            $this->last_error = array(
                'code'    => 'http_error',
                /* translators: %d: HTTP status code */
                'message' => sprintf( __( 'Pinecone rejected the request (HTTP %d). Check the API key and index host.', 'hifqa-fraud-detector' ), $code ),
            );
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return is_array( $data ) ? $data : array();

    }

    /**
     * Check if Pinecone is configured
     */
    public function is_configured() {
        return ! empty( $this->api_key ) && ! empty( $this->host );
    }

    /**
     * Upsert a vector into Pinecone
     * Upsert vector — now uses namespace.
     */
    public function upsert( $id, $vector, $metadata, $namespace = '' ) {

        if ( ! $this->is_configured() ) {
            return false;
        }

        $body = array(
            'vectors' => array(
                array(
                    'id'       => $id,
                    'values'   => $vector,
                    'metadata' => $metadata,
                ),
            ),
        );

        if ( ! empty( $namespace ) ) {
            $body['namespace'] = $namespace;
        }

        return false !== $this->post( '/vectors/upsert', $body );

    }

    /**
     * Query Pinecone for similar patterns
     * Query vector — now supports namespace and filtering by metadata.
     */
    public function query( $vector, $top_k = 5, $namespace = '' ) {

        if ( ! $this->is_configured() ) return array();

        $body = array(
            'vector'          => $vector,
            'topK'            => $top_k,
            'includeMetadata' => true,
        );

        if ( ! empty( $namespace ) ) {
            $body['namespace'] = $namespace;
        }

        $data = $this->post( '/query', $body );

        if ( false === $data || ! is_array( $data['matches'] ?? null ) ) {
            return array();
        }

        return $data['matches'];

    }

    /**
     * Search similar patterns using correct provider embeddings + namespace
     */
    public function search_similar_patterns( $signals, $top_k = 5 ) {

        if ( ! $this->is_configured() ) {
            return array();
        }

        $pattern_text = $this->build_pattern_text( $signals );

        if ( empty( $pattern_text ) ) {
            return array();
        }

        $this->last_error = null;

        $provider  = get_option( 'aifd_ai_provider', 'openai' );
        $embedding = $this->embeddings->embed( $pattern_text, $provider );

        if ( ! $embedding ) {
            // No embedding, no search — and no other provider is tried in its place.
            $this->last_error = $this->embeddings->get_last_error();
            return array();
        }

        $namespace = $this->embeddings->get_namespace( $provider );

        return $this->query( $embedding, $top_k, $namespace );

    }

    /**
     * Build pattern text from active signals
     */
    public function build_pattern_text( $signals ) {

        $active = array();

        $signal_keys = array(
            'card_testing_pattern', 'disposable_email',
            'high_velocity', 'address_mismatch',
            'freight_forwarder', 'chargeback_history',
            'first_order_high_value', 'ip_proxy_vpn',
            'risky_country_combo', 'mismatched_names',
            'no_phone_number', 'payment_method_risk',
            'multiple_addresses', 'order_total_anomaly',
            'odd_hours', 'guest_checkout', 'new_account',
        );

        foreach ( $signal_keys as $key ) {
            if ( ! empty( $signals[ $key ] ) ) {
                $active[] = $key;
            }
        }

        // Gateway id and country codes only — reduced to a fixed shape before they leave the site.
        $payment  = sanitize_key( (string) ( $signals['payment_method'] ?? '' ) );
        $billing  = strtoupper( sanitize_key( (string) ( $signals['billing_country'] ?? '' ) ) );
        $shipping = strtoupper( sanitize_key( (string) ( $signals['shipping_country'] ?? '' ) ) );

        if ( '' !== $payment ) {
            $active[] = $payment . '_payment';
        }

        if ( '' !== $billing && '' !== $shipping ) {
            $active[] = $billing . '_to_' . $shipping;
        }

        return implode( ' ', $active );

    }

    /**
     * Delete a vector by ID
     */
    public function delete( $id, $namespace = '' ) {

        if ( ! $this->is_configured() ) {
            return false;
        }

        $body = array( 'ids' => array( $id ) );

        if ( ! empty( $namespace ) ) {
            $body['namespace'] = $namespace;
        }

        return false !== $this->post( '/vectors/delete', $body );

    }

    /**
     * Migrate patterns from one provider namespace to another
     *
     * @param bool $load_seeds Load the seed patterns first. Pass false when the
     *                         caller has already loaded them in batches.
     */
    public function aifd_migrate_namespace( $from_provider, $to_provider, $load_seeds = true ) {

        $pinecone   = new AIFD_Pinecone();
        $embeddings = new AIFD_Embeddings();

        if ( ! $pinecone->is_configured() ) {
            return array( 'success' => false, 'message' => __( 'Pinecone not configured', 'hifqa-fraud-detector' ) );
        }

        $allowed = aifd_get_allowed_providers();

        if ( ! in_array( $from_provider, $allowed, true ) || ! in_array( $to_provider, $allowed, true ) ) {
            return array( 'success' => false, 'message' => __( 'Unknown AI provider', 'hifqa-fraud-detector' ) );
        }

        $problem = $embeddings->get_setup_problem( $to_provider );

        if ( '' !== $problem ) {
            return array( 'success' => false, 'message' => $problem );
        }

        $to_namespace = $embeddings->get_namespace( $to_provider );

        // First reload the seed patterns into the new namespace
        if ( $load_seeds ) {
            $loader = new AIFD_Seed_Loader();
            $loader->load_seeds_to_namespace( $to_namespace, $to_provider );
        }

        // Then migrate confirmed merchant patterns
        $confirmed = get_option( 'aifd_confirmed_patterns', array() );
        $migrated  = 0;
        $failed    = 0;

        foreach ( (array) $confirmed as $pattern ) {

            if ( empty( $pattern['pattern_text'] ) || empty( $pattern['order_id'] ) ) continue;

            $embedding = $embeddings->embed( $pattern['pattern_text'], $to_provider );

            if ( ! $embedding ) {
                $failed++;
                continue;
            }

            $saved = $pinecone->upsert(
                aifd_pattern_vector_id( $pattern['order_id'], $to_provider ),
                $embedding,
                array(
                    'pattern'     => $pattern['pattern_text'],
                    'fraud_type'  => 'confirmed_fraud',
                    'confidence'  => 1.0,
                    'times_seen'  => 1,
                    'source'      => 'merchant_confirmed',
                    'provider'    => $to_provider,
                    'description' => 'Merchant-confirmed fraud pattern',
                ),
                $to_namespace
            );

            if ( $saved ) {
                $migrated++;
            } else {
                $failed++;
            }

        }

        if ( $failed > 0 && 0 === $migrated ) {
            $error = $pinecone->get_last_error();
            return array(
                'success' => false,
                'message' => $embeddings->get_last_error_message() ?: ( $error ? $error['message'] : __( 'Migration failed.', 'hifqa-fraud-detector' ) ),
            );
        }

        return array(
            'success'  => true,
            /* translators: 1: number of migrated patterns, 2: new provider name */
            'message'  => sprintf( __( 'Migration complete. Seeds loaded + %1$d confirmed patterns migrated to %2$s namespace.', 'hifqa-fraud-detector' ), $migrated, $to_provider ),
            'migrated' => $migrated,
            'failed'   => $failed,
        );

    }

}
