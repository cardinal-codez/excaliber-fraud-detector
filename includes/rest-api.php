<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Register REST API endpoints
add_action( 'rest_api_init', 'aifd_register_rest_routes' );
add_action( 'wp_ajax_aifd_load_seeds', 'aifd_ajax_load_seeds' );

function aifd_register_rest_routes() {

    // Every order route takes the order id from the URL as a positive integer.
    $order_id_arg = array(
        'id' => array(
            'type'              => 'integer',
            'required'          => true,
            'minimum'           => 1,
            'sanitize_callback' => 'absint',
            'validate_callback' => 'rest_validate_request_arg',
        ),
    );

    // Get dashboard stats
    register_rest_route( 'aifd/v1', '/stats', array(
        'methods'             => 'GET',
        'callback'            => 'aifd_get_stats',
        'permission_callback' => 'aifd_check_permission',
        'args'                => array(),
    ));

    // Get flagged orders
    register_rest_route( 'aifd/v1', '/orders', array(
        'methods'             => 'GET',
        'callback'            => 'aifd_get_flagged_orders',
        'permission_callback' => 'aifd_check_permission',
        'args'                => array(),
    ));

    // Approve or block order
    register_rest_route( 'aifd/v1', '/order/(?P<id>\d+)/action', array(
        'methods'             => 'POST',
        'callback'            => 'aifd_order_action',
        'permission_callback' => 'aifd_check_permission',
        'args'                => $order_id_arg + array(
            // Not marked required here: that check would run before the
            // permission check and answer logged-out callers with a 400.
            'action_type' => array(
                'type'              => 'string',
                'enum'              => array( 'approve', 'block' ),
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'rest_validate_request_arg',
            ),
        ),
    ));

    // Feedback loop endpoints
    register_rest_route( 'aifd/v1', '/order/(?P<id>\d+)/confirm-fraud', array(
        'methods'             => 'POST',
        'callback'            => 'aifd_confirm_fraud',
        'permission_callback' => 'aifd_check_permission',
        'args'                => $order_id_arg,
    ));

    register_rest_route( 'aifd/v1', '/order/(?P<id>\d+)/override', array(
        'methods'             => 'POST',
        'callback'            => 'aifd_override_fraud',
        'permission_callback' => 'aifd_check_permission',
        'args'                => $order_id_arg,
    ));

    // Get current merchant weights
    register_rest_route( 'aifd/v1', '/weights', array(
        'methods'             => 'GET',
        'callback'            => 'aifd_get_weights',
        'permission_callback' => 'aifd_check_permission',
        'args'                => array(),
    ));

    // Reset weights to defaults
    register_rest_route( 'aifd/v1', '/weights/reset', array(
        'methods'             => 'POST',
        'callback'            => 'aifd_reset_weights',
        'permission_callback' => 'aifd_check_permission',
        'args'                => array(),
    ));

}

function aifd_ajax_load_seeds() {

    // Verify nonce
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'aifd_settings_nonce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Security check failed', 'hifqa-fraud-detector' ) ) );
    }

    // Check permissions
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied', 'hifqa-fraud-detector' ) ) );
    }

    // The settings page loads the patterns a batch at a time so that no single
    // request has to outlast the server's time limit.
    $loader = new AIFD_Seed_Loader();
    $result = isset( $_POST['offset'] )
        ? $loader->load_seeds( absint( wp_unslash( $_POST['offset'] ) ), AIFD_Seed_Loader::BATCH_SIZE )
        : $loader->load_seeds();

    if ( $result['success'] ) {
        wp_send_json_success( $result );
    } else {
        wp_send_json_error( $result );
    }

}

function aifd_check_permission() {
    return current_user_can( 'manage_options' );
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
        'tier1_active'    => aifd_tier1_ready(),
        'tier2_active'    => aifd_tier2_ready(),
    ));
}

// Flagged orders endpoint
function aifd_get_flagged_orders() {

    if ( ! function_exists( 'wc_get_orders' ) ) {
        return new WP_Error( 'woocommerce_inactive', __( 'WooCommerce must be active.', 'hifqa-fraud-detector' ), array( 'status' => 501 ) );
    }

    $orders = wc_get_orders( array(
        'type'    => 'shop_order', // exclude refunds, which lack order fields
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

        $created = $order->get_date_created();

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
            'date'             => $created ? $created->date( 'Y-m-d H:i' ) : '',
        );
    }

    return rest_ensure_response( $data );

}

// Approve or block action
function aifd_order_action( $request ) {

    $order_id = (int) $request->get_param( 'id' );
    $action   = $request->get_param( 'action_type' );
    $order    = wc_get_order( $order_id );

    if ( ! $order ) {
        return new WP_Error( 'not_found', __( 'Order not found', 'hifqa-fraud-detector' ), array( 'status' => 404 ) );
    }

    // Decision meta is saved before the status change so aifd_enforce_block_status
    // sees the merchant's verdict rather than the original BLOCK.
    if ( $action === 'approve' ) {
        $order->update_meta_data( '_aifd_decision', 'APPROVED' );
        $order->update_status( 'processing', __( 'Manually approved by admin via HIFQA Fraud Detector.', 'hifqa-fraud-detector' ) );
        $message = __( 'Order approved successfully', 'hifqa-fraud-detector' );
    } elseif ( $action === 'block' ) {
        $order->update_meta_data( '_aifd_decision', 'BLOCKED' );
        $order->update_status( 'cancelled', __( 'Manually blocked by admin via HIFQA Fraud Detector.', 'hifqa-fraud-detector' ) );
        $message = __( 'Order blocked successfully', 'hifqa-fraud-detector' );
    } else {
        // The route schema only lets 'approve' and 'block' through.
        return new WP_Error( 'aifd_invalid_action', __( 'Unknown action', 'hifqa-fraud-detector' ), array( 'status' => 400 ) );
    }

    $order->save();

    return rest_ensure_response( array(
        'success' => true,
        'message' => $message,
    ));

}

// Confirm fraud handler
function aifd_confirm_fraud( $request ) {

    $order_id = (int) $request->get_param( 'id' );
    $order    = wc_get_order( $order_id );

    if ( ! $order ) {
        return new WP_Error( 'not_found', __( 'Order not found', 'hifqa-fraud-detector' ), array( 'status' => 404 ) );
    }

    // Update review status
    $order->update_meta_data( '_aifd_review_status', 'CONFIRMED_FRAUD' );
    $order->update_meta_data( '_aifd_reviewed_at', current_time( 'mysql' ) );
    $order->update_meta_data( '_aifd_reviewed_by', get_current_user_id() );
    $order->save();

    // Save pattern to Pinecone — only when the vector layer is switched on
    $vector_on = ( new AIFD_Pinecone() )->is_configured();
    $result    = $vector_on ? aifd_save_confirmed_pattern_to_pinecone( $order_id ) : false;
    $saved     = ( true === $result );
    $why       = is_wp_error( $result ) ? $result->get_error_message() : '';

    if ( $why ) {
        aifd_log_failure( 'confirm_fraud', $why, $order_id );
    }

    // Add order note
    if ( $saved ) {
        $pinecone_note = __( 'Pattern saved to Pinecone vector store — system will recognize this fraud type faster next time.', 'hifqa-fraud-detector' );
    } elseif ( $vector_on ) {
        /* translators: %s: the reason the pattern could not be saved */
        $pinecone_note = sprintf( __( 'Note: the pattern was not saved to Pinecone. %s', 'hifqa-fraud-detector' ), $why );
    } else {
        $pinecone_note = '';
    }

    $order->add_order_note(
        trim( '🚨 ' . __( 'CONFIRMED FRAUD: Manually confirmed by merchant.', 'hifqa-fraud-detector' ) . ' ' . $pinecone_note ),
        false
    );
    $order->save();

    // Keep the pattern locally so it can be re-embedded if the provider changes
    $confirmed_patterns   = get_option( 'aifd_confirmed_patterns', array() );
    $fired_signals        = $order->get_meta( '_aifd_fired_signals' );

    $confirmed_patterns[] = array(
        'order_id'     => $order_id,
        'fired_signals'=> $fired_signals,
        'risk_score'   => $order->get_meta( '_aifd_risk_score' ),
        'pattern_text'  => implode( ' ', (array) $fired_signals ),
        'confirmed_at' => current_time( 'mysql' ),
    );

    update_option( 'aifd_confirmed_patterns', $confirmed_patterns, false );

    // Increment confirmed fraud counter
    update_option(
        'aifd_total_confirmed_fraud',
        get_option( 'aifd_total_confirmed_fraud', 0 ) + 1
    );

    if ( $saved ) {
        $message = __( 'Confirmed as fraud. Pattern saved to Pinecone — the system will recognize this faster next time.', 'hifqa-fraud-detector' );
    } elseif ( $vector_on ) {
        /* translators: %s: the reason the pattern could not be saved */
        $message = sprintf( __( 'Confirmed as fraud. The pattern was not saved to Pinecone: %s', 'hifqa-fraud-detector' ), $why );
    } else {
        $message = __( 'Confirmed as fraud.', 'hifqa-fraud-detector' );
    }

    return rest_ensure_response( array(
        'success'         => true,
        'message'         => $message,
        'status'          => 'CONFIRMED_FRAUD',
        'pinecone_saved'  => $saved,
    ));

}

// Override handler
function aifd_override_fraud( $request ) {

    $order_id = (int) $request->get_param( 'id' );
    $order    = wc_get_order( $order_id );

    if ( ! $order ) {
        return new WP_Error( 'not_found', __( 'Order not found', 'hifqa-fraud-detector' ), array( 'status' => 404 ) );
    }

    // Get fired signals BEFORE we change anything
    $fired_signals = $order->get_meta( '_aifd_fired_signals' );

    // Update review status
    $order->update_meta_data( '_aifd_review_status', 'FALSE_POSITIVE' );
    $order->update_meta_data( '_aifd_reviewed_at', current_time( 'mysql' ) );
    $order->update_meta_data( '_aifd_reviewed_by', get_current_user_id() );
    // Clear the BLOCK first, or aifd_enforce_block_status puts the order straight back on hold.
    $order->update_meta_data( '_aifd_decision', 'APPROVED' );

    // Release order back to processing
    $order->update_status(
        'processing',
        '✅ ' . __( 'FALSE POSITIVE: Override by merchant — order approved for processing.', 'hifqa-fraud-detector' )
    );

    $order->add_order_note(
        '✅ ' . __( 'FALSE POSITIVE: Merchant confirmed this is a legitimate order. Signal weights adjusted.', 'hifqa-fraud-detector' ),
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
        'message' => __( 'Order approved. Signal weights adjusted to reduce false positives.', 'hifqa-fraud-detector' ),
        'status'  => 'FALSE_POSITIVE',
    ));

}

function aifd_get_weights() {

    $defaults = aifd_get_default_signal_weights();

    $merchant_weights  = get_option( 'aifd_merchant_weights', array() );
    $current_weights   = wp_parse_args( $merchant_weights, $defaults );
    $false_positives   = (int) get_option( 'aifd_total_false_positives', 0 );
    $overrides_by_signal = get_option( 'aifd_signal_override_counts', array() );

    $result = array();

    foreach ( $defaults as $signal => $default_weight ) {
        $current = round( $current_weights[ $signal ] ?? $default_weight, 4 );
        $reduced = $current < $default_weight;

        $result[] = array(
            'signal'          => $signal,
            'default_weight'  => $default_weight,
            'current_weight'  => $current,
            'reduced'         => $reduced,
            'reduction'       => $reduced ? round( ( $default_weight - $current ) / $default_weight * 100 ) : 0,
            'times_overridden'=> $overrides_by_signal[ $signal ] ?? 0,
            'tier'            => aifd_get_signal_tier( $signal ),
        );
    }

    return rest_ensure_response( array(
        'weights'         => $result,
        'false_positives' => $false_positives,
        'personalized'    => ! empty( $merchant_weights ),
        'calibration'     => $false_positives >= 10
            ? __( 'Well calibrated', 'hifqa-fraud-detector' )
            /* translators: %d: number of merchant overrides so far */
            : sprintf( __( 'Still learning (%d/10 overrides)', 'hifqa-fraud-detector' ), $false_positives ),
    ));

}

function aifd_reset_weights() {

    delete_option( 'aifd_merchant_weights' );
    delete_option( 'aifd_signal_override_counts' );

    return rest_ensure_response( array(
        'success' => true,
        'message' => __( 'Weights reset to global defaults', 'hifqa-fraud-detector' ),
    ));

}

function aifd_get_signal_tier( $signal ) {
    $tier1 = array( 'card_testing_pattern', 'disposable_email', 'high_velocity', 'address_mismatch', 'freight_forwarder' );
    $tier2 = array( 'chargeback_history', 'first_order_high_value', 'ip_proxy_vpn', 'risky_country_combo', 'mismatched_names', 'no_phone_number' );
    if ( in_array( $signal, $tier1, true ) ) return 1;
    if ( in_array( $signal, $tier2, true ) ) return 2;
    return 3;
}

// Migrate patterns to new provider
add_action( 'wp_ajax_aifd_migrate_patterns', 'aifd_ajax_migrate_patterns' );

function aifd_ajax_migrate_patterns() {

    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'aifd_settings_nonce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Security check failed', 'hifqa-fraud-detector' ) ) );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied', 'hifqa-fraud-detector' ) ) );
    }

    $from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
    $to   = sanitize_text_field( wp_unslash( $_POST['to']   ?? '' ) );

    if ( empty( $from ) || empty( $to ) ) {
        wp_send_json_error( array( 'message' => __( 'Missing provider information', 'hifqa-fraud-detector' ) ) );
    }

    $allowed = aifd_get_allowed_providers();

    if ( ! in_array( $from, $allowed, true ) || ! in_array( $to, $allowed, true ) ) {
        wp_send_json_error( array( 'message' => __( 'Unknown AI provider', 'hifqa-fraud-detector' ) ) );
    }

    // The settings page loads the seeds in batches first and then asks for the
    // confirmed patterns only.
    $load_seeds = empty( $_POST['skip_seeds'] );

    $result = ( new AIFD_Pinecone() )->aifd_migrate_namespace( $from, $to, $load_seeds );

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

    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'aifd_settings_nonce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Security check failed', 'hifqa-fraud-detector' ) ) );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied', 'hifqa-fraud-detector' ) ) );
    }

    update_option( 'aifd_provider_switched', array( 'handled' => true ) );

    // Load fresh seeds for new provider — unless the page is about to do it in batches
    if ( empty( $_POST['skip_seeds'] ) ) {
        $loader = new AIFD_Seed_Loader();
        $loader->load_seeds();
    }

    wp_send_json_success( array( 'message' => __( 'Starting fresh with new provider', 'hifqa-fraud-detector' ) ) );

}