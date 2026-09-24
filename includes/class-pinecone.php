<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_Pinecone {

    private $api_key;
    private $host;
    private $embeddings;

    public function __construct() {
        $this->api_key = get_option( 'aifd_pinecone_key', '' );
        $this->host    = rtrim( get_option( 'aifd_pinecone_host', '' ), '/' );
        $this->embeddings = new AIFD_Embeddings();
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

        $response = wp_remote_post( $this->host . '/vectors/upsert', array(
            'timeout' => 30,
            'headers' => array(
                'Api-Key'      => $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
        ));

        if ( is_wp_error( $response ) ) {
            return false;
        }

        // A wrong key or host answers 4xx — don't report that as saved.
        $code = wp_remote_retrieve_response_code( $response );

        return $code >= 200 && $code < 300;

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

        $response = wp_remote_post( $this->host . '/query', array(
            'timeout' => 30,
            'headers' => array(
                'Api-Key'      => $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
        ));

        if ( is_wp_error( $response ) ) {
            return array();
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );

        $data = json_decode( $body_raw, true );
        return $data['matches'] ?? array();

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

        $provider  = get_option( 'aifd_ai_provider', 'openai' );
        $embedding = $this->embeddings->embed( $pattern_text, $provider );

        if ( ! $embedding ) {
            return array();
        }

        $namespace = $this->embeddings->get_namespace( $provider );

        $matches = $this->query( $embedding, $top_k, $namespace );

        return $matches;

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

        if ( ! empty( $signals['payment_method'] ) ) {
            $active[] = $signals['payment_method'] . '_payment';
        }

        if ( ! empty( $signals['billing_country'] ) && ! empty( $signals['shipping_country'] ) ) {
            $active[] = $signals['billing_country'] . '_to_' . $signals['shipping_country'];
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

        wp_remote_post( $this->host . '/vectors/delete', array(
            'timeout' => 30,
            'headers' => array(
                'Api-Key'      => $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($body),
        ));

        return true;

    }

    /**
     * Migrate patterns from one provider namespace to another
     */
    public function aifd_migrate_namespace( $from_provider, $to_provider ) {

        $pinecone   = new AIFD_Pinecone();
        $embeddings = new AIFD_Embeddings();

        if ( ! $pinecone->is_configured() ) {
            return array( 'success' => false, 'message' => __( 'Pinecone not configured', 'disham-fraud-detector' ) );
        }

        $merchant_id    = get_current_blog_id();
        $from_namespace = 'merchant_' . $merchant_id . '_' . $from_provider;
        $to_namespace   = 'merchant_' . $merchant_id . '_' . $to_provider;

        // First reload all 65 seed patterns into new namespace
        $loader = new AIFD_Seed_Loader();
        $result = $loader->load_seeds_to_namespace( $to_namespace, $to_provider );

        // Then migrate confirmed merchant patterns
        $confirmed = get_option( 'aifd_confirmed_patterns', array() );
        $migrated  = 0;

        foreach ( $confirmed as $pattern ) {

            $embedding = $embeddings->embed( $pattern['pattern_text'], $to_provider );

            if ( ! $embedding ) continue;

            $pinecone->upsert(
                'confirmed_' . $pattern['order_id'] . '_' . $to_provider,
                $embedding,
                array(
                    'pattern'    => $pattern['pattern_text'],
                    'fraud_type' => 'confirmed_fraud',
                    'confidence' => 1.0,
                    'source'     => 'merchant_confirmed',
                    'provider'   => $to_provider,
                    'order_id'   => $pattern['order_id'],
                ),
                $to_namespace
            );

            $migrated++;
            usleep( 200000 );

        }

        return array(
            'success'  => true,
            /* translators: 1: number of migrated patterns, 2: new provider name */
            'message'  => sprintf( __( 'Migration complete. Seeds loaded + %1$d confirmed patterns migrated to %2$s namespace.', 'disham-fraud-detector' ), $migrated, $to_provider ),
            'migrated' => $migrated,
        );

    }


}