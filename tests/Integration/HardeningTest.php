<?php
namespace Hifqa\Tests\Integration;

use Hifqa\Tests\TestCase;

/**
 * End-to-end checks for the review fixes: AI verdicts can't override local
 * blocks, keys stay out of the page, checkout drafts aren't scored, and
 * uninstall removes only what the plugin owns.
 */
class HardeningTest extends TestCase {

	private function openai_replies_with( array $verdict ) {
		update_option( 'aifd_ai_provider', 'openai' );
		update_option( 'aifd_openai_key', 'sk-test' );
		$this->respond_with( function () use ( $verdict ) {
			return self::json_response( array( 'choices' => array( array(
				'finish_reason' => 'stop',
				'message'       => array( 'role' => 'assistant', 'content' => wp_json_encode( $verdict ) ),
			) ) ) );
		} );
	}

	private function notes( $order ) {
		return implode( "\n", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	// ── AI verdict vs local decision ───────────────────

	public function test_ai_allow_cannot_release_an_order_the_local_rules_blocked() {
		update_option( 'aifd_block_threshold', 60 );
		$this->openai_replies_with( array( 'risk_score' => 2, 'risk_level' => 'LOW', 'decision' => 'ALLOW', 'reason' => 'Trusted customer' ) );
		$props = $this->risky_order_props( array( 'customer_ip_address' => '198.51.100.7' ) );

		$this->make_order( $props );
		$this->make_order( $props );
		$third = $this->make_order( $props );

		$this->assertSame( 'BLOCK', $third->get_meta( '_aifd_decision' ) );
		$this->assertSame( 'HIGH', $third->get_meta( '_aifd_risk_level' ) );
		$this->assertGreaterThanOrEqual( 60, (int) $third->get_meta( '_aifd_risk_score' ) );
		$this->assertSame( 'on-hold', $third->get_status() );
		$this->assertStringContainsString( 'does not lift a local block', $third->get_meta( '_aifd_reason' ) );
		$this->assertStringNotContainsString( 'FRAUD CHECK PASSED', $this->notes( $third ) );
	}

	/**
	 * @dataProvider invalid_ai_replies
	 */
	public function test_invalid_ai_reply_is_a_failure_and_the_local_verdict_stands( $reply ) {
		$this->openai_replies_with( $reply );

		$order = $this->make_order( $this->risky_order_props() );

		$this->assertSame( 'REVIEW', $order->get_meta( '_aifd_decision' ), 'Local rules flagged this order; a bad AI reply must not clear it' );
		$this->assertSame( 'local_rules', $order->get_meta( '_aifd_provider' ) );
		$this->assertSame( 1, (int) get_transient( 'aifd_provider_fails' ) );
		$this->assertStringNotContainsString( 'FRAUD CHECK PASSED', $this->notes( $order ) );
		$this->assertStringContainsString( 'AI layer was unavailable', $this->notes( $order ) );
	}

	public function invalid_ai_replies() {
		return array(
			'unexpected decision' => array( array( 'risk_score' => 1, 'decision' => 'APPROVE', 'reason' => 'ok' ) ),
			'no decision'         => array( array( 'risk_score' => 1, 'reason' => 'ok' ) ),
			'no score'            => array( array( 'decision' => 'ALLOW' ) ),
			'empty object'        => array( array( 'note' => 'nothing useful' ) ),
		);
	}

	public function test_out_of_range_ai_score_is_clamped_before_it_is_stored() {
		$this->openai_replies_with( array( 'risk_score' => 99999, 'decision' => 'BLOCK', 'reason' => 'Very bad' ) );

		$order = $this->make_order();

		$this->assertSame( 100, (int) $order->get_meta( '_aifd_risk_score' ) );
		$this->assertSame( 'BLOCK', $order->get_meta( '_aifd_decision' ) );
	}

	public function test_ai_reason_is_stored_without_markup() {
		$this->openai_replies_with( array( 'risk_score' => 50, 'decision' => 'REVIEW', 'reason' => '<img src=x onerror=alert(1)>Suspicious' ) );

		$order = $this->make_order();

		$this->assertSame( 'Suspicious', $order->get_meta( '_aifd_reason' ) );
	}

	public function test_ai_failure_is_logged_without_keys_or_customer_data() {
		$spy = new class() extends \WC_Logger {
			public $entries = array();
			public function __construct() {
				parent::__construct( array() );
			}
			public function log( $level, $message, $context = array() ) {
				$this->entries[] = $level . ' ' . $message;
			}
		};
		add_filter( 'woocommerce_logging_class', function () use ( $spy ) {
			return $spy;
		} );

		update_option( 'aifd_ai_provider', 'openai' );
		update_option( 'aifd_openai_key', 'sk-proj-super-secret-000111' );
		$this->respond_with( function () {
			return self::json_response( array( 'error' => array( 'message' => 'Incorrect API key provided: sk-proj-super-secret-000111' ) ), 401 );
		} );

		$this->make_order( array( 'billing_email' => 'private.person@example.com' ) );

		$log = implode( "\n", $spy->entries );
		$this->assertStringContainsString( '[ai_reasoning]', $log );
		$this->assertStringContainsString( 'Incorrect API key', $log );
		$this->assertStringNotContainsString( 'super-secret', $log );
		$this->assertStringNotContainsString( 'private.person', $log );
	}

	public function test_requests_to_the_ai_provider_carry_no_direct_identifiers() {
		$this->openai_replies_with( array( 'risk_score' => 10, 'decision' => 'ALLOW', 'reason' => 'ok' ) );

		$this->make_order( array(
			'billing_email'       => 'private.person@example.com',
			'billing_phone'       => '555-867-5309',
			'billing_first_name'  => 'Zebedee',
			'billing_last_name'   => 'Quillfeather',
			'shipping_address_1'  => '42 Wallaby Way',
			'customer_ip_address' => '192.0.2.44',
		) );

		$this->assertNotEmpty( $this->http_requests );
		foreach ( $this->http_requests as $request ) {
			$body = $request['args']['body'];
			$this->assertStringContainsString( 'example.com', $body );
			foreach ( array( 'private.person', '555-867-5309', 'Zebedee', 'Quillfeather', 'Wallaby', '192.0.2.44' ) as $private ) {
				$this->assertStringNotContainsString( $private, $body );
			}
		}
	}

	public function test_vector_lookup_runs_once_per_order_and_is_shared_with_the_ai_tier() {
		update_option( 'aifd_ai_provider', 'gemini' );
		update_option( 'aifd_gemini_key', 'g-test' );
		update_option( 'aifd_pinecone_key', 'pk' );
		update_option( 'aifd_pinecone_host', 'https://idx.pinecone.io' );
		$this->respond_with( function ( $url ) {
			if ( false !== strpos( $url, ':embedContent' ) ) {
				return self::json_response( array( 'embedding' => array( 'values' => array( 0.1 ) ) ) );
			}
			if ( false !== strpos( $url, '/query' ) ) {
				return self::json_response( array( 'matches' => array() ) );
			}
			return self::json_response( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array(
				'text' => wp_json_encode( array( 'risk_score' => 5, 'decision' => 'ALLOW', 'reason' => 'ok' ) ),
			) ) ) ) ) ) );
		} );

		$this->make_order();

		$hosts = array_count_values( $this->requested_hosts() );
		$this->assertSame( 1, $hosts['idx.pinecone.io'], 'One Pinecone query per order, not two' );
		$this->assertSame( 2, $hosts['generativelanguage.googleapis.com'], 'One embedding call and one reasoning call' );
	}

	public function test_pinecone_outage_trips_its_breaker_and_does_not_stop_scoring() {
		update_option( 'aifd_openai_key', 'sk-test' );
		update_option( 'aifd_pinecone_key', 'pk' );
		update_option( 'aifd_pinecone_host', 'https://idx.pinecone.io' );
		$this->respond_with( function ( $url ) {
			if ( false !== strpos( $url, '/v1/embeddings' ) ) {
				return self::json_response( array( 'data' => array( array( 'embedding' => array( 0.1 ) ) ) ) );
			}
			if ( false !== strpos( $url, 'pinecone.io' ) ) {
				return self::json_response( array( 'message' => 'down' ), 503 );
			}
			return self::json_response( array( 'error' => array( 'message' => 'down' ) ), 503 );
		} );

		$order = $this->make_order();

		$this->assertSame( 'ALLOW', $order->get_meta( '_aifd_decision' ) );
		$this->assertSame( 1, (int) get_transient( 'aifd_pinecone_fails' ) );
	}

	// ── Block checkout ─────────────────────────────────

	public function test_block_checkout_draft_is_scored_when_submitted_not_when_created() {
		$draft = new \WC_Order();
		$draft->set_status( 'checkout-draft' );
		$draft->save();

		$this->assertSame( '', wc_get_order( $draft->get_id() )->get_meta( '_aifd_decision' ), 'An empty draft must not be scored' );
		$this->assertSame( 0, (int) get_option( 'aifd_total_allowed' ) );
		$this->assertSame( array(), $this->hifqa_mail_subjects() );

		foreach ( $this->risky_order_props( array( 'billing_first_name' => 'Jane', 'billing_last_name' => 'Doe' ) ) as $key => $value ) {
			$draft->{ 'set_' . $key }( $value );
		}
		$draft->set_status( 'pending' );
		$draft->save();

		do_action( 'woocommerce_store_api_checkout_order_processed', $draft );

		$scored = wc_get_order( $draft->get_id() );
		$this->assertSame( 'REVIEW', $scored->get_meta( '_aifd_decision' ) );
		$this->assertContains( 'disposable_email', $scored->get_meta( '_aifd_fired_signals' ) );
		$this->assertSame( 1, (int) get_option( 'aifd_total_review' ) );
	}

	public function test_resubmitting_a_checkout_does_not_double_count_or_resend_the_alert() {
		$order = $this->make_order( $this->risky_order_props() );

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$this->assertSame( 1, (int) get_option( 'aifd_total_review' ) );
		$this->assertCount( 1, $this->hifqa_mail_subjects() );
	}

	public function test_a_rescore_never_reopens_a_block_or_a_merchant_verdict() {
		$order = $this->make_order();
		$order->update_meta_data( '_aifd_decision', 'BLOCK' );
		$order->save();
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$this->assertSame( 'BLOCK', wc_get_order( $order->get_id() )->get_meta( '_aifd_decision' ) );

		$order->update_meta_data( '_aifd_decision', 'APPROVED' );
		$order->save();
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$this->assertSame( 'APPROVED', wc_get_order( $order->get_id() )->get_meta( '_aifd_decision' ) );
	}

	public function test_refunds_and_missing_orders_are_ignored() {
		$order  = $this->make_order( array( 'total' => 50 ) );
		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 10 ) );

		$this->assertNotWPError( $refund );
		$this->assertSame( '', $refund->get_meta( '_aifd_decision' ) );

		aifd_catch_new_order( 99999999 );
		aifd_enforce_block_status( 99999999, 'pending', 'processing' );
		aifd_enforce_block_on_payment( 99999999 );
		aifd_send_alert_email( 99999999, array( 'decision' => 'BLOCK', 'risk_score' => 90 ) );
		$this->assertFalse( aifd_update_order_status( 99999999, array( 'decision' => 'BLOCK' ), array() ) );
	}

	public function test_order_that_cannot_be_scored_is_held_for_review_not_passed() {
		$order = $this->make_order( $this->risky_order_props() );
		$order->delete_meta_data( '_aifd_decision' );
		$order->delete_meta_data( '_aifd_review_status' );
		$order->save();
		delete_option( 'aifd_total_allowed' );
		reset_phpmailer_instance();

		// Break signal extraction for this run (the country check asks for the store's base country).
		$break = function () {
			throw new \RuntimeException( 'boom' );
		};
		add_filter( 'woocommerce_countries_base_country', $break );
		aifd_catch_new_order( $order->get_id() ); // Must not throw: this runs inside checkout.
		remove_filter( 'woocommerce_countries_base_country', $break );

		$scored = wc_get_order( $order->get_id() );
		$this->assertSame( 'REVIEW', $scored->get_meta( '_aifd_decision' ) );
		$this->assertSame( 'error', $scored->get_meta( '_aifd_provider' ) );
		$this->assertStringContainsString( 'could not be completed', $scored->get_meta( '_aifd_reason' ) );
		$this->assertSame( 0, (int) get_option( 'aifd_total_allowed' ) );
		$this->assertStringNotContainsString( 'FRAUD CHECK PASSED', $this->notes( $scored ) );
		$this->assertCount( 1, $this->hifqa_mail_subjects(), 'The merchant is told to review it' );
	}

	public function test_an_error_while_scoring_never_escapes_into_checkout() {
		$order = $this->make_order();
		$order->delete_meta_data( '_aifd_decision' );
		$order->save();

		// Break everything, including the attempt to save a result.
		$break = function () {
			throw new \RuntimeException( 'boom' );
		};
		add_filter( 'woocommerce_order_get_billing_email', $break );
		aifd_catch_new_order( $order->get_id() );
		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order->get_id() ) );
		remove_filter( 'woocommerce_order_get_billing_email', $break );

		$this->assertTrue( true, 'No exception reached the caller' );
	}

	// ── HPOS-safe order link ───────────────────────────

	public function test_alert_email_links_to_the_orders_own_edit_screen() {
		$order = $this->make_order( $this->risky_order_props() );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertStringContainsString( $order->get_edit_order_url(), $mail->body );
		$this->assertStringContainsString( 'HIFQA Fraud Detector', $mail->body );
	}

	public function test_hpos_and_block_checkout_compatibility_is_declared() {
		$features = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_features_for_plugin( plugin_basename( AIFD_PATH . 'hifqa-fraud-detector.php' ) );

		$this->assertContains( 'custom_order_tables', $features['compatible'] );
		$this->assertContains( 'cart_checkout_blocks', $features['compatible'] );
	}

	// ── API keys in the settings page ──────────────────

	private function key_options() {
		return array(
			'aifd_openai_key'    => 'sk-proj-OPENAI-SECRET',
			'aifd_anthropic_key' => 'sk-ant-ANTHROPIC-SECRET',
			'aifd_gemini_key'    => 'AIza-GEMINI-SECRET',
			'aifd_voyage_key'    => 'pa-VOYAGE-SECRET',
			'aifd_pinecone_key'  => 'pcsk-PINECONE-SECRET',
		);
	}

	public function test_settings_page_never_prints_a_saved_key() {
		foreach ( $this->key_options() as $option => $key ) {
			update_option( $option, $key );
		}

		ob_start();
		aifd_render_settings_page();
		$html = ob_get_clean();

		foreach ( $this->key_options() as $option => $key ) {
			$this->assertStringNotContainsString( $key, $html, $option );
			$this->assertStringNotContainsString( substr( $key, -6 ), $html, $option );
			$this->assertStringContainsString( 'name="aifd_clear_keys[]" value="' . $option . '"', $html );
		}
		$this->assertSame( 5, substr_count( $html, 'saved — leave blank to keep' ) );
		$this->assertStringNotContainsString( 'style="', $html );
		$this->assertSame( substr_count( $html, 'target="_blank"' ), substr_count( $html, 'rel="noopener noreferrer"' ) );
	}

	public function test_settings_page_points_at_the_working_pinecone_privacy_url() {
		ob_start();
		aifd_render_settings_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'https://www.pinecone.io/privacy/', $html );
		$this->assertStringNotContainsString( 'pinecone.io/legal/privacy-policy', $html );
	}

	private function begin_settings_form_save( array $post = array() ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		aifd_register_settings(); // Registers the settings and their sanitizers.
		$_POST = array_merge( array(
			'option_page' => 'aifd_settings',
			'_wpnonce'    => wp_create_nonce( 'aifd_settings-options' ),
		), $post );
	}

	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	public function test_blank_key_field_keeps_the_saved_key_for_every_provider() {
		foreach ( $this->key_options() as $option => $key ) {
			update_option( $option, $key );
		}
		$this->begin_settings_form_save();

		foreach ( $this->key_options() as $option => $key ) {
			update_option( $option, '' );
			$this->assertSame( $key, get_option( $option ), $option );
		}
	}

	public function test_new_non_empty_key_replaces_the_saved_one() {
		update_option( 'aifd_openai_key', 'sk-old' );
		$this->begin_settings_form_save();

		update_option( 'aifd_openai_key', '  sk-new  ' );

		$this->assertSame( 'sk-new', get_option( 'aifd_openai_key' ) );
	}

	public function test_remove_checkbox_clears_only_the_ticked_key() {
		update_option( 'aifd_openai_key', 'sk-old' );
		update_option( 'aifd_pinecone_key', 'pk-old' );
		$this->begin_settings_form_save( array( 'aifd_clear_keys' => array( 'aifd_openai_key' ) ) );

		update_option( 'aifd_openai_key', '' );
		update_option( 'aifd_pinecone_key', '' );

		$this->assertSame( '', get_option( 'aifd_openai_key' ) );
		$this->assertSame( 'pk-old', get_option( 'aifd_pinecone_key' ) );
	}

	public function test_blank_only_means_keep_inside_a_verified_settings_save() {
		update_option( 'aifd_openai_key', 'sk-old' );
		$this->begin_settings_form_save();
		$_POST['_wpnonce'] = 'forged';

		update_option( 'aifd_openai_key', '' );

		$this->assertSame( '', get_option( 'aifd_openai_key' ), 'Code and WP-CLI can still clear a key' );
	}

	public function test_vector_memory_problem_is_shown_to_the_admin() {
		update_option( 'aifd_ai_provider', 'anthropic' );
		update_option( 'aifd_pinecone_key', 'pk' );
		update_option( 'aifd_pinecone_host', 'https://idx.pinecone.io' );

		ob_start();
		aifd_render_settings_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Vector memory is not running.', $html );
		$this->assertStringContainsString( 'Voyage AI key', $html );
	}

	// ── Uninstall ──────────────────────────────────────

	public function test_uninstall_removes_plugin_data_and_leaves_orders_alone() {
		$order = $this->make_order( $this->risky_order_props() );
		foreach ( $this->key_options() as $option => $key ) {
			update_option( $option, $key );
		}
		update_option( 'aifd_ai_provider', 'gemini' );
		update_option( 'aifd_merchant_weights', array( 'odd_hours' => 0.5 ) );
		update_option( 'aifd_confirmed_patterns', array( array( 'order_id' => 1 ) ) );
		set_transient( 'aifd_provider_circuit_open', true, 600 );
		update_option( 'unrelated_plugin_option', 'keep me' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'hifqa-fraud-detector/hifqa-fraud-detector.php' );
		}
		include AIFD_PATH . 'uninstall.php';

		global $wpdb;
		$left = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'aifd\\_%' OR option_name LIKE '\\_transient\\_aifd\\_%' OR option_name LIKE '\\_transient\\_timeout\\_aifd\\_%'" );
		$this->assertSame( array(), $left );
		$this->assertFalse( get_transient( 'aifd_provider_circuit_open' ) );
		$this->assertSame( 'keep me', get_option( 'unrelated_plugin_option' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order, 'Orders are never deleted' );
		$this->assertSame( 'REVIEW', $order->get_meta( '_aifd_decision' ), 'Order history stays with the order' );
		$this->assertSame( 'buyer@mailinator.com', $order->get_billing_email() );
	}
}
