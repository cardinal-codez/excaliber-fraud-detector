<?php
namespace Hifqa\Tests\Integration;

use Hifqa\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The aifd/v1 REST routes used by the React dashboard.
 */
class RestApiTest extends TestCase {

	private $admin_id;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function request( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/aifd/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	private function make_blocked_order() {
		$order = $this->make_order( $this->risky_order_props() );
		$order->update_meta_data( '_aifd_decision', 'BLOCK' );
		$order->update_meta_data( '_aifd_risk_score', 88 );
		$order->set_status( 'on-hold' );
		$order->save();
		return $order;
	}

	// ── Permissions ────────────────────────────────────

	public function routes() {
		return array(
			array( 'GET', '/stats' ),
			array( 'GET', '/orders' ),
			array( 'GET', '/weights' ),
			array( 'POST', '/weights/reset' ),
			array( 'POST', '/order/1/action' ),
			array( 'POST', '/order/1/confirm-fraud' ),
			array( 'POST', '/order/1/override' ),
		);
	}

	/**
	 * @dataProvider routes
	 */
	public function test_logged_out_visitors_are_rejected( $method, $route ) {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( $method, $route )->get_status() );
	}

	/**
	 * @dataProvider routes
	 */
	public function test_shop_managers_without_manage_options_are_rejected( $method, $route ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertSame( 403, $this->request( $method, $route )->get_status() );
	}

	// ── Read endpoints ─────────────────────────────────

	public function test_stats_reflect_scored_orders() {
		wp_set_current_user( $this->admin_id );
		$this->make_order();
		$this->make_order( $this->risky_order_props() );

		$data = $this->request( 'GET', '/stats' )->get_data();

		$this->assertSame( 1, $data['allowed'] );
		$this->assertSame( 1, $data['review'] );
		$this->assertFalse( $data['tier1_active'] );
		$this->assertFalse( $data['tier2_active'] );
	}

	public function test_orders_lists_only_scored_orders_awaiting_action() {
		wp_set_current_user( $this->admin_id );
		$blocked = $this->make_blocked_order();

		// An order Hifqa never scored (e.g. created before activation).
		remove_action( 'woocommerce_new_order', 'aifd_catch_new_order', 10 );
		$unscored = $this->make_order( array( 'billing_email' => 'old@example.com' ) );
		$unscored->set_status( 'on-hold' );
		$unscored->save();
		add_action( 'woocommerce_new_order', 'aifd_catch_new_order', 10, 1 );

		$ids = wp_list_pluck( $this->request( 'GET', '/orders' )->get_data(), 'id' );

		$this->assertContains( $blocked->get_id(), $ids );
		$this->assertNotContains( $unscored->get_id(), $ids );
	}

	public function test_weights_report_defaults_then_personalisation() {
		wp_set_current_user( $this->admin_id );

		$data = $this->request( 'GET', '/weights' )->get_data();
		$this->assertCount( 17, $data['weights'] );
		$this->assertFalse( $data['personalized'] );

		aifd_adjust_weights_on_override( array( 'guest_checkout' ) );

		$data  = $this->request( 'GET', '/weights' )->get_data();
		$guest = wp_list_filter( $data['weights'], array( 'signal' => 'guest_checkout' ) );
		$guest = reset( $guest );
		$this->assertTrue( $data['personalized'] );
		$this->assertTrue( $guest['reduced'] );
		$this->assertSame( 1, $guest['times_overridden'] );
	}

	public function test_weights_endpoint_defaults_match_scoring_defaults() {
		wp_set_current_user( $this->admin_id );
		$data = $this->request( 'GET', '/weights' )->get_data();

		$scoring = aifd_get_signal_weights();
		foreach ( $data['weights'] as $row ) {
			$this->assertSame( $scoring[ $row['signal'] ], $row['default_weight'], $row['signal'] );
		}
	}

	public function test_reset_restores_default_weights() {
		wp_set_current_user( $this->admin_id );
		aifd_adjust_weights_on_override( array( 'guest_checkout' ) );

		$this->request( 'POST', '/weights/reset' );

		$this->assertFalse( get_option( 'aifd_merchant_weights' ) );
		$this->assertSame( 0.05, aifd_get_signal_weights()['guest_checkout'] );
	}

	// ── Order actions ──────────────────────────────────

	public function test_admin_can_manually_block_an_order() {
		wp_set_current_user( $this->admin_id );
		$order = $this->make_order();

		$response = $this->request( 'POST', '/order/' . $order->get_id() . '/action', array( 'action_type' => 'block' ) );

		$this->assertTrue( $response->get_data()['success'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( 'BLOCKED', $order->get_meta( '_aifd_decision' ) );
	}

	public function test_admin_can_approve_a_blocked_order() {
		wp_set_current_user( $this->admin_id );
		$order = $this->make_blocked_order();

		$this->request( 'POST', '/order/' . $order->get_id() . '/action', array( 'action_type' => 'approve' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status(), 'Approve must release the hold' );
		$this->assertSame( 'APPROVED', $order->get_meta( '_aifd_decision' ) );
	}

	public function test_unknown_action_changes_nothing() {
		wp_set_current_user( $this->admin_id );
		$order = $this->make_blocked_order();

		$response = $this->request( 'POST', '/order/' . $order->get_id() . '/action', array( 'action_type' => 'explode' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_action_without_an_action_type_is_rejected() {
		wp_set_current_user( $this->admin_id );
		$order = $this->make_blocked_order();

		$this->assertSame( 400, $this->request( 'POST', '/order/' . $order->get_id() . '/action' )->get_status() );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_actions_on_missing_orders_return_404() {
		wp_set_current_user( $this->admin_id );

		$this->assertSame( 404, $this->request( 'POST', '/order/999999/action', array( 'action_type' => 'block' ) )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/order/999999/confirm-fraud' )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/order/999999/override' )->get_status() );
	}

	// ── Feedback loop ──────────────────────────────────

	public function test_false_positive_override_releases_order_and_learns() {
		wp_set_current_user( $this->admin_id );
		$order = $this->make_blocked_order();
		$fired = $order->get_meta( '_aifd_fired_signals' );

		$data = $this->request( 'POST', '/order/' . $order->get_id() . '/override' )->get_data();

		$this->assertSame( 'FALSE_POSITIVE', $data['status'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'FALSE_POSITIVE', $order->get_meta( '_aifd_review_status' ) );
		$this->assertSame( 1, (int) get_option( 'aifd_total_false_positives' ) );
		$this->assertLessThan( 0.25, aifd_get_signal_weights()[ $fired[0] ] ?? 0.25 );
		$this->assertSame( 'processing', $order->get_status(), 'Override must release the hold' );
	}

	public function test_confirm_fraud_without_pinecone_still_records_the_verdict() {
		wp_set_current_user( $this->admin_id );
		$order = $this->make_blocked_order();

		$data = $this->request( 'POST', '/order/' . $order->get_id() . '/confirm-fraud' )->get_data();

		$this->assertFalse( $data['pinecone_saved'] );
		$this->assertSame( 'CONFIRMED_FRAUD', wc_get_order( $order->get_id() )->get_meta( '_aifd_review_status' ) );
		$this->assertSame( 1, (int) get_option( 'aifd_total_confirmed_fraud' ) );

		$patterns = get_option( 'aifd_confirmed_patterns' );
		$this->assertSame( $order->get_id(), $patterns[0]['order_id'] );
		$this->assertStringContainsString( 'disposable_email', $patterns[0]['pattern_text'] );
	}

	public function test_confirm_fraud_with_pinecone_upserts_to_merchant_namespace() {
		wp_set_current_user( $this->admin_id );
		update_option( 'aifd_openai_key', 'sk-test' );
		update_option( 'aifd_pinecone_key', 'pk' );
		update_option( 'aifd_pinecone_host', 'https://idx.pinecone.io' );
		$order = $this->make_blocked_order();

		$this->http_requests = array();
		$this->respond_with( function ( $url ) {
			if ( false !== strpos( $url, 'api.openai.com/v1/embeddings' ) ) {
				return self::json_response( array( 'data' => array( array( 'embedding' => array( 0.1, 0.2 ) ) ) ) );
			}
			if ( false !== strpos( $url, '/query' ) ) {
				return self::json_response( array( 'matches' => array() ) );
			}
			return self::json_response( array( 'upsertedCount' => 1 ) );
		} );

		$data = $this->request( 'POST', '/order/' . $order->get_id() . '/confirm-fraud' )->get_data();

		$this->assertTrue( $data['pinecone_saved'] );
		$upserts = array_values( array_filter( $this->http_requests, function ( $r ) {
			return false !== strpos( $r['url'], '/vectors/upsert' );
		} ) );
		$this->assertCount( 1, $upserts );
		$body = json_decode( $upserts[0]['args']['body'], true );
		$this->assertSame( 'merchant_1_openai', $body['namespace'] );
		$this->assertSame( aifd_pattern_vector_id( $order->get_id(), 'openai' ), $body['vectors'][0]['id'] );
	}

	public function test_confirmed_pattern_sent_to_pinecone_carries_no_order_or_customer_detail() {
		wp_set_current_user( $this->admin_id );
		update_option( 'aifd_openai_key', 'sk-test' );
		update_option( 'aifd_pinecone_key', 'pk' );
		update_option( 'aifd_pinecone_host', 'https://idx.pinecone.io' );
		$order = $this->make_blocked_order();
		$order->update_meta_data( '_aifd_reason', 'AI said buyer@mailinator.com looks fraudulent' );
		$order->save();

		$this->http_requests = array();
		$this->respond_with( function ( $url ) {
			if ( false !== strpos( $url, 'api.openai.com/v1/embeddings' ) ) {
				return self::json_response( array( 'data' => array( array( 'embedding' => array( 0.1, 0.2 ) ) ) ) );
			}
			return self::json_response( array( 'matches' => array(), 'upsertedCount' => 1 ) );
		} );

		$this->request( 'POST', '/order/' . $order->get_id() . '/confirm-fraud' );

		$sent = '';
		foreach ( $this->http_requests as $request ) {
			$sent .= $request['args']['body'];
		}
		$upsert   = array_values( array_filter( $this->http_requests, function ( $r ) {
			return false !== strpos( $r['url'], '/vectors/upsert' );
		} ) )[0];
		$vector   = json_decode( $upsert['args']['body'], true )['vectors'][0];
		$metadata = $vector['metadata'];

		$this->assertSame(
			array( 'pattern', 'fraud_type', 'confidence', 'times_seen', 'source', 'provider', 'description' ),
			array_keys( $metadata )
		);
		$this->assertSame( 'Merchant-confirmed fraud pattern', $metadata['description'] );
		$this->assertMatchesRegularExpression( '/^confirmed_[a-f0-9]{32}_openai$/', $vector['id'], 'The id is a keyed hash' );
		$this->assertNotSame( 'confirmed_' . $order->get_id() . '_openai', $vector['id'], 'The order number is not the id' );
		$this->assertStringNotContainsString( 'mailinator', $sent, 'Neither the email nor the stored reason may leave the site' );
		$this->assertStringNotContainsString( 'John', $sent );
	}

	public function test_confirm_fraud_reports_why_pinecone_did_not_save() {
		wp_set_current_user( $this->admin_id );
		update_option( 'aifd_ai_provider', 'anthropic' );
		update_option( 'aifd_pinecone_key', 'pk' );
		update_option( 'aifd_pinecone_host', 'https://idx.pinecone.io' );
		$order = $this->make_blocked_order();

		$this->http_requests = array();
		$data = $this->request( 'POST', '/order/' . $order->get_id() . '/confirm-fraud' )->get_data();

		$this->assertFalse( $data['pinecone_saved'] );
		$this->assertStringContainsString( 'Voyage AI', $data['message'] );
		$this->assertSame( array(), $this->http_requests, 'No other provider is called in its place' );
	}
}
