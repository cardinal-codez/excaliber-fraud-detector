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