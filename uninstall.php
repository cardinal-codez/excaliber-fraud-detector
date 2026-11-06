<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes what excaliber Fraud Detector itself stored: its settings, the saved API
 * keys, its counters and learned weights, and its transients.
 *
 * Deliberately left in place:
 * - WooCommerce orders and customers.
 * - The _aifd_* order meta (risk score, decision, reason, review status). It
 *   records why an order was held or released and belongs to the order's
 *   history; the order notes that refer to it would otherwise make no sense.
 * - Anything in the merchant's Pinecone index. That lives in the merchant's
 *   own Pinecone account and is removed there.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Delete this plugin's options and transients from the current site.
 */
function aifd_uninstall_site() {

    $options = array(
        // Provider selection and API keys
        'aifd_ai_provider',
        'aifd_openai_key',
        'aifd_anthropic_key',
        'aifd_gemini_key',
        'aifd_voyage_key',
        'aifd_pinecone_key',

        // Provider configuration
        'aifd_openai_model',
        'aifd_anthropic_model',
        'aifd_gemini_model',
        'aifd_ollama_host',
        'aifd_ollama_model',
        'aifd_pinecone_host',
        'aifd_pinecone_index',
        'aifd_pinecone_index_openai',
        'aifd_pinecone_index_anthropic',
        'aifd_pinecone_index_768',
        'aifd_provider_switched',

        // Thresholds and learned weights
        'aifd_block_threshold',
        'aifd_review_threshold',
        'aifd_merchant_weights',
        'aifd_signal_override_counts',
        'aifd_confirmed_patterns',

        // Cached store average
        'aifd_store_average',
        'aifd_average_calculated',

        // Dashboard counters
        'aifd_total_blocked',
        'aifd_total_review',
        'aifd_total_allowed',
        'aifd_total_saved',
        'aifd_total_confirmed_fraud',
        'aifd_total_false_positives',
    );

    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Circuit-breaker state
    $transients = array(
        'aifd_pinecone_fails',
        'aifd_pinecone_circuit_open',
        'aifd_provider_fails',
        'aifd_provider_circuit_open',
    );

    foreach ( $transients as $transient ) {
        delete_transient( $transient );
    }

}

if ( is_multisite() ) {

    $aifd_site_ids = get_sites( array(
        'fields' => 'ids',
        'number' => 0,
    ) );

    foreach ( $aifd_site_ids as $aifd_site_id ) {
        switch_to_blog( $aifd_site_id );
        aifd_uninstall_site();
        restore_current_blog();
    }

} else {
    aifd_uninstall_site();
}
