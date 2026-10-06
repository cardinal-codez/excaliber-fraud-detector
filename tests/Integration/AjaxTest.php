<?php
namespace Hifqa\Tests\Integration;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

/**
 * admin-ajax handlers used by the settings page script.
 *
 * @group hifqa-ajax
 */
class AjaxTest extends WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();
		add_filter( 'pre_http_request', array( $this, 'block_http' ) );

		// Core update checks run on admin_init and would try to reach WordPress.org.
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'block_http' ) );
		parent::tear_down();
	}

	public function block_http() {
		return new \WP_Error( 'http_blocked', 'Network disabled in tests' );
	}

	private function call( $action, array $post = array() ) {
		global $wpdb;
		$_POST = array_merge( $_POST, $post );

		// WooCommerce's admin_init queries self-join tables, which MySQL can't do on the
		// suite's temporary tables; keep that unrelated DB noise out of the JSON body.
		$wpdb->hide_errors();
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected — wp_send_json_* ends the request.
		} catch ( WPAjaxDieStopException $e ) {
			// Expected for wp_die() with no output.
		}
		$wpdb->show_errors();
		return json_decode( $this->_last_response, true );
	}

	private function as_role( $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		return wp_create_nonce( 'aifd_settings_nonce' );
	}

	// ── aifd_load_seeds ────────────────────────────────

	public function test_load_seeds_rejects_bad_nonce() {
		$this->as_role( 'administrator' );
		$response = $this->call( 'aifd_load_seeds', array( 'nonce' => 'forged' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Security check failed', $response['data']['message'] );
	}

	public function test_load_seeds_rejects_non_admins() {
		$nonce    = $this->as_role( 'editor' );
		$response = $this->call( 'aifd_load_seeds', array( 'nonce' => $nonce ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Permission denied', $response['data']['message'] );
	}

	public function test_load_seeds_explains_missing_pinecone_config() {
		$nonce    = $this->as_role( 'administrator' );
		$response = $this->call( 'aifd_load_seeds', array( 'nonce' => $nonce ) );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Pinecone not configured', $response['data']['message'] );
	}

	// ── aifd_migrate_patterns ──────────────────────────

	public function test_migrate_patterns_rejects_non_admins() {
		$nonce    = $this->as_role( 'editor' );
		$response = $this->call( 'aifd_migrate_patterns', array( 'nonce' => $nonce, 'from' => 'openai', 'to' => 'gemini' ) );

		$this->assertSame( 'Permission denied', $response['data']['message'] );
	}

	public function test_migrate_patterns_requires_both_providers() {
		$nonce    = $this->as_role( 'administrator' );
		$response = $this->call( 'aifd_migrate_patterns', array( 'nonce' => $nonce, 'from' => 'openai' ) );

		$this->assertSame( 'Missing provider information', $response['data']['message'] );
	}

	public function test_migrate_patterns_returns_a_json_answer() {
		$nonce    = $this->as_role( 'administrator' );
		$response = $this->call( 'aifd_migrate_patterns', array( 'nonce' => $nonce, 'from' => 'openai', 'to' => 'gemini' ) );

		$this->assertIsArray( $response, 'Handler must answer with JSON, not a fatal error' );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Pinecone not configured', $response['data']['message'] );
	}

	// ── aifd_dismiss_migration ─────────────────────────

	public function test_dismiss_migration_marks_switch_handled_for_admins() {
		update_option( 'aifd_provider_switched', array( 'from' => 'openai', 'to' => 'gemini', 'handled' => false ) );
		$nonce = $this->as_role( 'administrator' );

		$response = $this->call( 'aifd_dismiss_migration', array( 'nonce' => $nonce ) );

		$this->assertTrue( $response['success'] );
		$this->assertTrue( get_option( 'aifd_provider_switched' )['handled'] );
	}

	public function test_dismiss_migration_rejects_non_admins() {
		update_option( 'aifd_provider_switched', array( 'from' => 'openai', 'to' => 'gemini', 'handled' => false ) );
		$nonce = $this->as_role( 'subscriber' );

		$response = $this->call( 'aifd_dismiss_migration', array( 'nonce' => $nonce ) );

		$this->assertFalse( $response['success'] );
		$this->assertFalse( get_option( 'aifd_provider_switched' )['handled'] );
	}
}
