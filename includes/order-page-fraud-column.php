<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add HIFQA fraud scores to WooCommerce's Orders list.
 */

// Add the column after the order status.
function tab_hifqa_order_columns( $columns ) {
    $new_columns = array();

    foreach ( $columns as $key => $label ) {
        $new_columns[ $key ] = $label;

        if ( 'order_status' === $key ) {
            $new_columns['tab_hifqa_score'] = 'Fraud Score';
        }
    }

    // Fallback if the status column was removed by another plugin.
    if ( ! isset( $new_columns['tab_hifqa_score'] ) ) {
        $new_columns['tab_hifqa_score'] = 'Fraud Score';
    }

    return $new_columns;
}

add_filter(
    'manage_woocommerce_page_wc-orders_columns',
    'tab_hifqa_order_columns',
    20
);

add_filter(
    'manage_edit-shop_order_columns',
    'tab_hifqa_order_columns',
    20
);

// Render the saved score. This does not run or change fraud checks.
function tab_hifqa_render_order_score( $column, $order_or_id ) {
    if ( 'tab_hifqa_score' !== $column ) {
        return;
    }

    $order = $order_or_id instanceof WC_Order
        ? $order_or_id
        : wc_get_order( $order_or_id );

    if ( ! $order ) {
        return;
    }

    $score = $order->get_meta( '_aifd_risk_score', true );

    // A score of zero is valid; an empty value means no saved score.
    if ( '' === $score || ! is_numeric( $score ) ) {
        echo '<span style="color:#757575;">Not scored</span>';
        return;
    }

    $level  = strtoupper(
        (string) $order->get_meta( '_aifd_risk_level', true )
    );
    $reason = (string) $order->get_meta( '_aifd_reason', true );

    $colors = array(
        'LOW'    => array( '#e7f5e9', '#1b5e20' ),
        'MEDIUM' => array( '#fff4ce', '#795500' ),
        'HIGH'   => array( '#fce8e6', '#a12622' ),
    );

    $palette = $colors[ $level ] ?? array( '#eeeeee', '#444444' );
    $label   = sprintf( '%d/100', (int) $score );

    if ( in_array( $level, array( 'LOW', 'MEDIUM', 'HIGH' ), true ) ) {
        $label .= ' · ' . ucfirst( strtolower( $level ) );
    }

    printf(
        '<span title="%s" style="display:inline-block;padding:4px 8px;border-radius:4px;background:%s;color:%s;font-weight:600;white-space:nowrap;">%s</span>',
        esc_attr( $reason ),
        esc_attr( $palette[0] ),
        esc_attr( $palette[1] ),
        esc_html( $label )
    );
}

add_action(
    'manage_woocommerce_page_wc-orders_custom_column',
    'tab_hifqa_render_order_score',
    20,
    2
);

add_action(
    'manage_shop_order_posts_custom_column',
    'tab_hifqa_render_order_score',
    20,
    2
);
