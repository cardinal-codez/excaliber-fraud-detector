<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Register REST API endpoints
add_action( 'rest_api_init', 'aifd_register_rest_routes' );
add_action( 'wp_ajax_aifd_load_seeds', 'aifd_ajax_load_seeds' );

function aifd_register_rest_routes() {

    // Get dashboard stats
    register_rest_route( 'aifd/v1', '/stats', array(
        'methods'             => 'GET',
        'callback'            => 'aifd_get_stats',
        'permission_callback' => 'aifd_check_permission'
    ));

    // Get flagged orders
    register_rest_route( 'aifd/v1', '/orders', array(
        'methods'             => 'GET',
        'callback'            => 'aifd_get_flagged_orders',
        'permission_callback' => 'aifd_check_permission'
    ));

    // Approve or block order
    register_rest_route( 'aifd/v1', '/order/(?P<id>\d+)/action', array(
        'methods'             => 'POST',
        'callback'            => 'aifd_order_action',
        'permission_callback' => 'aifd_check_permission'
    ));

    // Feedback loop endpoints
    register_rest_route( 'aifd/v1', '/order/(?P<id>\d+)/confirm-fraud', array(
        'methods'             => 'POST',
        'callback'            => 'aifd_confirm_fraud',
        'permission_callback' => 'aifd_check_permission',
    ));

    register_rest_route( 'aifd/v1', '/order/(?P<id>\d+)/override', array(
        'methods'             => 'POST',
        'callback'            => 'aifd_override_fraud',
        'permission_callback' => 'aifd_check_permission',
    ));

}

function aifd_ajax_load_seeds() {

    // Verify nonce
    if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'aifd_settings_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed' ) );
    }

    // Check permissions
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied' ) );
    }

    $loader = new AIFD_Seed_Loader();
    $result = $loader->load_seeds();

    if ( $result['success'] ) {
        wp_send_json_success( $result );
    } else {
        wp_send_json_error( $result );
    }

}

function aifd_check_permission() {
    //return current_user_can( 'manage_options' );
    return true; // For testing purposes, allow all access. Change to above line in production. 
}

// Stats endpoint
function aifd_get_stats() {
    return rest_ensure_response( array(
        'blocked' => (int) get_option( 'aifd_total_blocked', 0 ),
        'review'  => (int) get_option( 'aifd_total_review', 0 ),
        'allowed' => (int) get_option( 'aifd_total_allowed', 0 ),
        'saved'   => (float) get_option( 'aifd_total_saved', 0 ),
        'confirmed_fraud'  => (int) get_option( 'aifd_total_confirmed_fraud', 0 ),
        'false_positives'  => (int) get_option( 'aifd_total_false_positives', 0 ),
    ));
}

// Flagged orders endpoint
function aifd_get_flagged_orders() {


    $orders = wc_get_orders( array(
        'limit'   => 20,
        'status'  => array( 'on-hold', 'processing' ),
        'return'  => 'objects',
        'orderby' => 'date',
        'order'   => 'DESC',
    ));

    $data = array();

    foreach ( $orders as $order ) {

        $score    = $order->get_meta( '_aifd_risk_score' );
        $decision = $order->get_meta( '_aifd_decision' );
        $reason   = $order->get_meta( '_aifd_reason' );
        $review_status = $order->get_meta( '_aifd_review_status' ) ?: 'PENDING';

        // Only include orders that were scored by our plugin
        if ( empty( $score ) ) continue;

        $data[] = array(
            'id'               => $order->get_id(),
            'status'           => $order->get_status(),
            'total'            => $order->get_total(),
            'currency'         => $order->get_currency(),
            'customer_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_email'   => $order->get_billing_email(),
            'billing_country'  => $order->get_billing_country(),
            'shipping_country' => $order->get_shipping_country(),
            'risk_score'       => (int) $score,
            'decision'         => $decision,
            'reason'           => $reason,
            'review_status'    => $review_status,
            'date'             => $order->get_date_created()->date( 'Y-m-d H:i' ),
        );
    }

    return rest_ensure_response( $data );

}

// Approve or block action
function aifd_order_action( $request ) {

    $order_id = $request->get_param( 'id' );
    $action   = $request->get_param( 'action_type' );
    $order    = wc_get_order( $order_id );

    if ( ! $order ) {
        return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
    }

    if ( $action === 'approve' ) {
        $order->update_status( 'processing', 'Manually approved by admin via AI Fraud Detector.' );
        $order->update_meta_data( '_aifd_decision', 'APPROVED' );
    } elseif ( $action === 'block' ) {
        $order->update_status( 'cancelled', 'Manually blocked by admin via AI Fraud Detector.' );
        $order->update_meta_data( '_aifd_decision', 'BLOCKED' );
    }

    $order->save();

    return rest_ensure_response( array(
        'success' => true,
        'message' => 'Order ' . $action . 'd successfully',
    ));

}

// Confirm fraud handler
function aifd_confirm_fraud( $request ) {

    $order_id = (int) $request->get_param( 'id' );
    $order    = wc_get_order( $order_id );

    if ( ! $order ) {
        return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
    }

    // Update review status
    $order->update_meta_data( '_aifd_review_status', 'CONFIRMED_FRAUD' );
    $order->update_meta_data( '_aifd_reviewed_at', current_time( 'mysql' ) );
    $order->update_meta_data( '_aifd_reviewed_by', get_current_user_id() );
    $order->save();

    // Save pattern to Pinecone
    $saved = aifd_save_confirmed_pattern_to_pinecone( $order_id );

    // Add order note
    $pinecone_note = $saved
        ? 'Pattern saved to Pinecone vector store — system will recognize this fraud type faster next time.'
        : 'Note: Pinecone save failed — check API key and configuration.';

    $order->add_order_note(
        '🚨 CONFIRMED FRAUD: Manually confirmed by merchant. ' . $pinecone_note,
        false
    );
    $order->save();

    

    // Store confirmed pattern data for Day 20
    $confirmed_patterns   = get_option( 'aifd_confirmed_patterns', array() );
    $fired_signals        = $order->get_meta( '_aifd_fired_signals' );

    $confirmed_patterns[] = array(
        'order_id'     => $order_id,
        'fired_signals'=> $fired_signals,
        'risk_score'   => $order->get_meta( '_aifd_risk_score' ),
        'pattern_text'  => implode( ' ', (array) $fired_signals ),
        'confirmed_at' => current_time( 'mysql' ),
    );

    update_option( 'aifd_confirmed_patterns', $confirmed_patterns );

    // Increment confirmed fraud counter
    update_option(
        'aifd_total_confirmed_fraud',
        get_option( 'aifd_total_confirmed_fraud', 0 ) + 1
    );

    return rest_ensure_response( array(
        'success'         => true,
        'message'         => $saved
            ? 'Confirmed as fraud. Pattern saved to Pinecone — AI will recognize this faster next time.'
            : 'Confirmed as fraud. Note: Pinecone save failed — check your API configuration.',
        'status'          => 'CONFIRMED_FRAUD',
        'pinecone_saved'  => $saved,
    ));

}

// Override handler
function aifd_override_fraud( $request ) {

    $order_id = (int) $request->get_param( 'id' );
    $order    = wc_get_order( $order_id );

    if ( ! $order ) {
        return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
    }

    // Get fired signals BEFORE we change anything
    $fired_signals = $order->get_meta( '_aifd_fired_signals' );

    // Update review status
    $order->update_meta_data( '_aifd_review_status', 'FALSE_POSITIVE' );
    $order->update_meta_data( '_aifd_reviewed_at', current_time( 'mysql' ) );
    $order->update_meta_data( '_aifd_reviewed_by', get_current_user_id() );

    // Release order back to processing
    $order->update_status(
        'processing',
        '✅ FALSE POSITIVE: Override by merchant — order approved for processing.'
    );

    $order->add_order_note(
        '✅ FALSE POSITIVE: Merchant confirmed this is a legitimate order. Signal weights adjusted.',
        false
    );

    $order->save();

    // Adjust merchant weights based on fired signals
    if ( ! empty( $fired_signals ) ) {
        aifd_adjust_weights_on_override( $fired_signals );
    }

    // Increment false positive counter
    update_option(
        'aifd_total_false_positives',
        get_option( 'aifd_total_false_positives', 0 ) + 1
    );

    return rest_ensure_response( array(
        'success' => true,
        'message' => 'Order approved. Signal weights adjusted to reduce false positives.',
        'status'  => 'FALSE_POSITIVE',
    ));

}

// Migrate patterns to new provider
add_action( 'wp_ajax_aifd_migrate_patterns', 'aifd_ajax_migrate_patterns' );

function aifd_ajax_migrate_patterns() {

    if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'aifd_settings_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed' ) );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied' ) );
    }

    $from = sanitize_text_field( $_POST['from'] ?? '' );
    $to   = sanitize_text_field( $_POST['to']   ?? '' );

    if ( empty( $from ) || empty( $to ) ) {
        wp_send_json_error( array( 'message' => 'Missing provider information' ) );
    }

    $result = aifd_migrate_namespace( $from, $to );

    if ( $result['success'] ) {
        // Mark switch as handled
        update_option( 'aifd_provider_switched', array( 'handled' => true ) );
        wp_send_json_success( $result );
    } else {
        wp_send_json_error( $result );
    }

}

// Dismiss migration warning
add_action( 'wp_ajax_aifd_dismiss_migration', 'aifd_ajax_dismiss_migration' );

function aifd_ajax_dismiss_migration() {

    if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'aifd_settings_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed' ) );
    }

    update_option( 'aifd_provider_switched', array( 'handled' => true ) );

    // Load fresh seeds for new provider
    $loader = new AIFD_Seed_Loader();
    $loader->load_seeds();

    wp_send_json_success( array( 'message' => 'Starting fresh with new provider' ) );

}