<?php
namespace excaliber\Tests\Integration;

use excaliber\Tests\TestCase;

/**
 * Admin screens: asset loading (the WordPress.org review items) and the provider-switch flow.
 */
class AdminPagesTest extends TestCase {

	const SETTINGS_HOOK  = 'Excaliber_page_excaliber-fraud-detector-settings';
	const DASHBOARD_HOOK = 'toplevel_page_excaliber-fraud-detector';

	public function set_up() {
		parent::set_up();
		$GLOBALS['wp_scripts']          = null;
		$GLOBALS['aifd_settings_hook']  = self::SETTINGS_HOOK;
		$GLOBALS['aifd_dashboard_hook'] = self::DASHBOARD_HOOK;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		$GLOBALS['wp_scripts'] = null;
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	// ── Asset loading ──────────────────────────────────

	public function test_menu_registers_Excaliber_pages() {
		set_current_screen( 'dashboard' );
		aifd_register_menu();
		aifd_register_settings_page();

		$this->assertSame( self::DASHBOARD_HOOK, $GLOBALS['aifd_dashboard_hook'] );
		$this->assertSame( self::SETTINGS_HOOK, $GLOBALS['aifd_settings_hook'] );
	}

	public function test_settings_script_is_enqueued_with_localized_data_on_settings_page() {
		aifd_enqueue_settings_script( self::SETTINGS_HOOK );

		$this->assertTrue( wp_script_is( 'aifd-settings', 'enqueued' ) );
		$this->assertSame( AIFD_URL . 'assets/js/settings.js', wp_scripts()->registered['aifd-settings']->src );
		$this->assertSame( AIFD_VERSION, wp_scripts()->registered['aifd-settings']->ver );

		$data = wp_scripts()->get_data( 'aifd-settings', 'data' );
		$this->assertStringContainsString( 'var aifd_settings = ', $data );
		$this->assertStringContainsString( wp_create_nonce( 'aifd_settings_nonce' ), $data );
		$this->assertStringContainsString( 'admin-ajax.php', $data );
	}

	public function test_settings_script_also_loads_on_dashboard_for_the_switch_notice() {
		aifd_enqueue_settings_script( self::DASHBOARD_HOOK );
		$this->assertTrue( wp_script_is( 'aifd-settings', 'enqueued' ) );
	}

	public function test_settings_script_does_not_load_on_other_admin_pages() {
		aifd_enqueue_settings_script( 'edit.php' );
		aifd_enqueue_settings_script( 'woocommerce_page_wc-orders' );
		$this->assertFalse( wp_script_is( 'aifd-settings', 'enqueued' ) );
	}

	public function test_settings_script_file_exists() {
		$this->assertFileExists( AIFD_PATH . 'assets/js/settings.js' );
	}

	// ── No inline <script>/<style> tags (WordPress.org requirement) ─

	public function test_settings_page_renders_without_inline_scripts() {
		ob_start();
		aifd_render_settings_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="aifd-load-seeds"', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<style', $html );
	}

	public function test_provider_switch_notice_renders_without_inline_scripts() {
		update_option( 'aifd_provider_switched', array( 'from' => 'openai', 'to' => 'gemini', 'handled' => false ) );
		set_current_screen( self::SETTINGS_HOOK );

		ob_start();
		aifd_provider_switch_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="aifd-migrate-patterns"', $html );
		$this->assertStringContainsString( 'data-to="gemini"', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_no_php_file_prints_inline_script_or_style_tags() {
		$files = array_merge(
			glob( AIFD_PATH . '*.php' ),
			glob( AIFD_PATH . 'admin/*.php' ),
			glob( AIFD_PATH . 'includes/*.php' ),
			glob( AIFD_PATH . 'includes/providers/*.php' )
		);
		foreach ( $files as $file ) {
			$source = file_get_contents( $file );
			$this->assertDoesNotMatchRegularExpression( '/<script[\s>]/i', $source, basename( $file ) );
			$this->assertDoesNotMatchRegularExpression( '/<style[\s>]/i', $source, basename( $file ) );
			$this->assertDoesNotMatchRegularExpression( '/(?<!wp_)json_encode\s*\(/', $source, basename( $file ) . ' should use wp_json_encode' );
		}
	}

	// ── Provider switch detection ──────────────────────

	public function test_switching_provider_records_a_pending_migration() {
		update_option( 'aifd_ai_provider', 'openai' );
		update_option( 'aifd_ai_provider', 'gemini' );

		$switch = get_option( 'aifd_provider_switched' );
		$this->assertSame( 'openai', $switch['from'] );
		$this->assertSame( 'gemini', $switch['to'] );
		$this->assertFalse( $switch['handled'] );
	}

	public function test_saving_same_provider_does_not_record_a_switch() {
		update_option( 'aifd_ai_provider', 'openai' );
		update_option( 'aifd_ai_provider', 'openai' );

		$this->assertFalse( get_option( 'aifd_provider_switched' ) );
	}

	public function test_notice_hidden_once_switch_is_handled() {
		update_option( 'aifd_provider_switched', array( 'handled' => true ) );
		set_current_screen( self::SETTINGS_HOOK );

		ob_start();
		aifd_provider_switch_notice();
		$this->assertSame( '', trim( ob_get_clean() ) );
	}

	public function test_notice_only_shows_on_Excaliber_screens() {
		update_option( 'aifd_provider_switched', array( 'from' => 'openai', 'to' => 'gemini', 'handled' => false ) );
		set_current_screen( 'edit-post' );

		ob_start();
		aifd_provider_switch_notice();
		$this->assertSame( '', trim( ob_get_clean() ) );
	}

	// ── Text domain matches the slug ───────────────────

	public function test_all_translation_calls_use_the_Excaliber_text_domain() {
		$files = array_merge(
			glob( AIFD_PATH . '*.php' ),
			glob( AIFD_PATH . 'admin/*.php' ),
			glob( AIFD_PATH . 'includes/*.php' ),
			glob( AIFD_PATH . 'includes/providers/*.php' )
		);
		foreach ( $files as $file ) {
			preg_match_all( "/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_n)\(\s*'(?:[^'\\\\]|\\\\.)*'\s*,\s*'([^']+)'\s*\)/", file_get_contents( $file ), $m );
			foreach ( $m[1] as $domain ) {
				$this->assertSame( 'excaliber-fraud-detector', $domain, basename( $file ) );
			}
		}
	}
}
