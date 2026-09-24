<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action('admin_menu', 'aifd_register_menu');

function aifd_register_menu() {
    // Hook suffix is derived from the menu title, so capture it rather than
    // hardcoding it — a rename would otherwise silently stop the enqueues.
    $GLOBALS['aifd_dashboard_hook'] = add_menu_page(
        __( 'DISHAM – AI Fraud Detector', 'disham-fraud-detector' ),
        __( 'DISHAM', 'disham-fraud-detector' ),
        'manage_options',
        'disham-fraud-detector',
        'aifd_render_dashboard',
        'dashicons-shield',
        56
    );
}


function aifd_render_dashboard() {
    include AIFD_PATH . 'admin/dashboard.php';
}

// Enqueue React dashboard script
add_action( 'admin_enqueue_scripts', 'aifd_enqueue_react' );

function aifd_enqueue_react( $hook ) {
    if ( $hook !== ( $GLOBALS['aifd_dashboard_hook'] ?? '' ) ) return;

    $asset_file = include AIFD_PATH. 'build/index.asset.php';

    wp_enqueue_script(
        'aifd-dashboard',
        AIFD_URL . 'build/index.js',
        $asset_file['dependencies'],
        $asset_file['version'],
        true
    );

    // Pass data from PHP to React
    wp_localize_script( 'aifd-dashboard', 'aifdData', array(
        'apiUrl'   => rest_url( 'aifd/v1/' ),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
        'blocked'  => get_option( 'aifd_total_blocked', 0 ),
        'review'   => get_option( 'aifd_total_review', 0 ),
        'allowed'  => get_option( 'aifd_total_allowed', 0 ),
        'saved'    => get_option( 'aifd_total_saved', 0 ),
    ));
}