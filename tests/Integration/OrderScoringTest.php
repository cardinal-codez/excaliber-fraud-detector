<?php
namespace excaliber\Tests\Integration;

use excaliber\Tests\TestCase;
use WC_Logger;

/**
 * Real WooCommerce orders flowing through the woocommerce_new_order pipeline.
 */
class OrderScoringTest extends TestCase {

	// ── Local tier (default, no keys) ──────────────────

	public function test_clean_order_from_established_customer_is_allowed() {
		$order = $this->make_order( array( 'customer_id' => $this->make_customer() ) );

		$this->assertSame( 'ALLOW', $order->get_meta( '_aifd_decision' ) );
		$this->assertEquals( 0, $order->get_meta( '_aifd_risk_score' ) );
		$this->assertSame( 'local_rules', $order->get_meta( '_aifd_provider' ) );
		$this->assertSame( 'PENDING', $order->get_meta( '_aifd_review_status' ) );
		$this->assertNotSame( 'on-hold', $order->get_status() );
		$this->assertSame( 1, (int) get_option( 'aifd_total_allowed' ) );
	}

	public function test_allowed_order_gets_a_passed_note_and_no_alert_email() {
		$order = $this->make_order();

		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertStringContainsString( 'FRAUD CHECK PASSED', implode( "\n", $notes ) );
		$this->assertSame( array(), $this->Excaliber_mail_subjects() );
	}

	public function test_suspicious_order_is_flagged_for_review_and_emails_admin() {
		$order = $this->make_order( $this->risky_order_props() );

		$this->assertSame( 'REVIEW', $order->get_meta( '_aifd_decision' ) );
		$fired = $order->get_meta( '_aifd_fired_signals' );
		foreach ( array( 'disposable_email', 'address_mismatch', 'freight_forwarder', 'no_phone_number', 'mismatched_names', 'payment_method_risk', 'guest_checkout', 'first_order_high_value' ) as $signal ) {
			$this->assertContains( $signal, $fired );
		}

		$subjects = $this->Excaliber_mail_subjects();
		$this->assertCount( 1, $subjects );
		$this->assertStringContainsString( 'Needs Review', $subjects[0] );
		$this->assertSame( 1, (int) get_option( 'aifd_total_review' ) );
	}

	public function test_card_testing_burst_is_blocked_and_held() {
		// Local rules alone top out around 64 for this burst, so use a stricter
		// store threshold to exercise the block path.
		update_option( 'aifd_block_threshold', 60 );
		$props = $this->risky_order_props( array( 'customer_ip_address' => '198.51.100.7' ) );

		$this->make_order( $props );
		$this->make_order( $props );
		$third = $this->make_order( $props );

		$this->assertSame( 'BLOCK', $third->get_meta( '_aifd_decision' ) );
		$this->assertContains( 'card_testing_pattern', $third->get_meta( '_aifd_fired_signals' ) );
		$this->assertContains( 'high_velocity', $third->get_meta( '_aifd_fired_signals' ) );
		$this->assertSame( 'on-hold', $third->get_status() );

		$this->assertSame( 1, (int) get_option( 'aifd_total_blocked' ) );
		$this->assertEquals( 900, (float) get_option( 'aifd_total_saved' ) );

		$blocked_mails = array_filter( $this->Excaliber_mail_subjects(), function ( $s ) {
			return strpos( $s, 'Blocked' ) !== false;
		} );
		$this->assertCount( 1, $blocked_mails );
	}

	public function test_previous_blocked_order_counts_as_chargeback_history() {
		$first = $this->make_order( array( 'billing_email' => 'repeat@example.com' ) );
		$first->update_meta_data( '_aifd_decision', 'BLOCK' );
		$first->set_status( 'cancelled' );
		$first->save();

		$second = $this->make_order( array( 'billing_email' => 'repeat@example.com' ) );
		$this->assertContains( 'chargeback_history', $second->get_meta( '_aifd_fired_signals' ) );
	}

	public function test_dispute_note_on_refunded_order_counts_as_chargeback_history() {
		$first = $this->make_order( array( 'billing_email' => 'disputer@example.com' ) );
		$first->set_status( 'refunded' );
		$first->save();
		$first->add_order_note( 'Customer opened a dispute with the bank' );

		$second = $this->make_order( array( 'billing_email' => 'disputer@example.com' ) );
		$this->assertContains( 'chargeback_history', $second->get_meta( '_aifd_fired_signals' ) );
	}

	// ── Block enforcement ──────────────────────────────

	private function make_blocked_order() {
		$order = $this->make_order();
		$order->update_meta_data( '_aifd_decision', 'BLOCK' );
		$order->update_meta_data( '_aifd_risk_score', 88 );
		$order->set_status( 'on-hold' );
		$order->save();
		return $order;
	}

	public function test_blocked_order_cannot_be_moved_to_processing_by_a_gateway() {
		$order = $this->make_blocked_order();

		$order->update_status( 'processing' );

		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_blocked_order_stays_on_hold_after_payment_complete() {
		$order = $this->make_blocked_order();

		$order->payment_complete( 'txn_123' );

		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_allowed_order_moves_to_processing_normally() {
		$order = $this->make_order();
		$order->update_status( 'processing' );

		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	// ── Logging ────────────────────────────────────────

	public function test_log_entry_never_contains_customer_pii() {
		$spy = new class() extends WC_Logger {
			public $entries = array();
			public function __construct() {
				parent::__construct( array() );
			}
			public function log( $level, $message, $context = array() ) {
				$this->entries[] = $message;
			}
		};
		add_filter( 'woocommerce_logging_class', function () use ( $spy ) {
			return $spy;
		} );

		$this->make_order( array(
			'billing_email'       => 'private.person@example.com',
			'billing_phone'       => '555-867-5309',
			'customer_ip_address' => '192.0.2.44',
		) );

		$log = implode( "\n", $spy->entries );
		$this->assertStringContainsString( 'Order #', $log );
		$this->assertStringNotContainsString( 'private.person@example.com', $log );
		$this->assertStringNotContainsString( '555-867-5309', $log );
		$this->assertStringNotContainsString( '192.0.2.44', $log );
	}

	// ── AI tier (opt-in) ───────────────────────────────

	public function test_ai_verdict_replaces_local_score_when_configured() {
		update_option( 'aifd_ai_provider', 'openai' );
		update_option( 'aifd_openai_key', 'sk-test' );
		$this->respond_with( function () {
			return self::json_response( array( 'choices' => array( array(
				'finish_reason' => 'stop',
				'message'       => array( 'content' => wp_json_encode( array(
					'risk_score' => 91,
					'risk_level' => 'HIGH',
					'decision'   => 'BLOCK',
					'reason'     => 'AI: stolen card pattern',
				) ) ),
			) ) ) );
		} );

		$order = $this->make_order();

		$this->assertSame( 'BLOCK', $order->get_meta( '_aifd_decision' ) );
		$this->assertSame( 'openai_function_calling', $order->get_meta( '_aifd_provider' ) );
		$this->assertSame( 'on-hold', $order->get_status() );
	}

	public function test_ai_failure_falls_back_to_local_score_and_counts_toward_circuit() {
		update_option( 'aifd_ai_provider', 'openai' );
		update_option( 'aifd_openai_key', 'sk-test' );
		$this->respond_with( function () {
			return self::json_response( array( 'error' => array( 'message' => 'Overloaded' ) ), 503 );
		} );

		$order = $this->make_order();

		$this->assertSame( 'ALLOW', $order->get_meta( '_aifd_decision' ), 'Local result stands' );
		$this->assertSame( 'local_rules', $order->get_meta( '_aifd_provider' ) );
		$this->assertSame( 1, (int) get_transient( 'aifd_provider_fails' ) );
	}

	public function test_open_circuit_skips_ai_calls_entirely() {
		update_option( 'aifd_openai_key', 'sk-test' );
		set_transient( 'aifd_provider_circuit_open', true, 600 );

		$this->make_order();

		$this->assertSame( array(), $this->http_requests );
	}

	public function test_no_network_calls_without_any_keys() {
		$this->make_order( $this->risky_order_props() );
		$this->assertSame( array(), $this->http_requests );
	}

	/**
	 * Every provider choice should reach its own API — not someone else's.
	 *
	 * @dataProvider provider_routes
	 */
	public function test_selected_provider_is_the_one_called( $provider, $options, $expected_host, $reply ) {
		update_option( 'aifd_ai_provider', $provider );
		foreach ( $options as $key => $value ) {
			update_option( $key, $value );
		}
		$this->respond_with( function () use ( $reply ) {
			return self::json_response( $reply );
		} );

		$order = $this->make_order();

		$this->assertContains( $expected_host, $this->requested_hosts() );
		$this->assertSame( 'BLOCK', $order->get_meta( '_aifd_decision' ), "{$provider} verdict should be applied" );
	}

	public function provider_routes() {
		$verdict = wp_json_encode( array(
			'risk_score' => 85,
			'risk_level' => 'HIGH',
			'decision'   => 'BLOCK',
			'reason'     => 'AI verdict',
		) );

		return array(
			'anthropic' => array(
				'anthropic',
				array( 'aifd_anthropic_key' => 'ak-test' ),
				'api.anthropic.com',
				array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => $verdict ) ) ),
			),
			'gemini'    => array(
				'gemini',
				array( 'aifd_gemini_key' => 'g-test' ),
				'generativelanguage.googleapis.com',
				array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => $verdict ) ) ) ) ) ),
			),
			'ollama'    => array(
				'ollama',
				array( 'aifd_ollama_host' => 'http://ollama.local:11434' ),
				'ollama.local',
				array( 'message' => array( 'content' => $verdict ) ),
			),
		);
	}
}
