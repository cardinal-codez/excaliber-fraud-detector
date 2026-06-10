<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ══════════════════════════════════════════════
// REGISTER SETTINGS PAGE
// ══════════════════════════════════════════════

add_action( 'admin_menu', 'aifd_register_settings_page', 20 );

function aifd_register_settings_page() {
    add_submenu_page(
        'ai-fraud-detector',
        'Settings',
        'Settings',
        'manage_options',
        'ai-fraud-detector-settings',
        'aifd_render_settings_page'
    );
}

add_action( 'admin_enqueue_scripts', 'aifd_enqueue_settings_script' );

function aifd_enqueue_settings_script( $hook ) {

    if ( $hook !== 'fraud-detector_page_ai-fraud-detector-settings' ) return;

    // Pass nonce to settings page JavaScript
    wp_add_inline_script(
        'jquery',
        'var aifd_settings = ' . json_encode( array(
            'nonce'   => wp_create_nonce( 'aifd_settings_nonce' ),
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
        )) . ';'
    );

}

// ══════════════════════════════════════════════
// REGISTER SETTINGS
// ══════════════════════════════════════════════

add_action( 'admin_init', 'aifd_register_settings' );

function aifd_register_settings() {

    // AI Provider selection
    register_setting( 'aifd_settings', 'aifd_ai_provider', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'openai'
    ));

    // OpenAI
    register_setting( 'aifd_settings', 'aifd_openai_key', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => ''
    ));
    register_setting( 'aifd_settings', 'aifd_openai_model', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'gpt-4o-mini'
    ));

    // Anthropic
    register_setting( 'aifd_settings', 'aifd_anthropic_key', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => ''
    ));
    register_setting( 'aifd_settings', 'aifd_anthropic_model', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'claude-haiku-4-5-20251001'
    ));

    // Gemini
    register_setting( 'aifd_settings', 'aifd_gemini_key', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => ''
    ));
    register_setting( 'aifd_settings', 'aifd_gemini_model', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'gemini-1.5-flash'
    ));

    // Ollama
    register_setting( 'aifd_settings', 'aifd_ollama_host', array(
        'sanitize_callback' => 'esc_url_raw',
        'default'           => 'http://localhost:11434'
    ));
    register_setting( 'aifd_settings', 'aifd_ollama_model', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'llama3'
    ));

    // Risk thresholds
    register_setting( 'aifd_settings', 'aifd_block_threshold', array(
        'sanitize_callback' => 'absint',
        'default'           => 70
    ));
    register_setting( 'aifd_settings', 'aifd_review_threshold', array(
        'sanitize_callback' => 'absint',
        'default'           => 40
    ));

    // Pinecone settings
    register_setting( 'aifd_settings', 'aifd_pinecone_key', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => ''
    ));
    register_setting( 'aifd_settings', 'aifd_pinecone_host', array(
        'sanitize_callback' => 'esc_url_raw',
        'default'           => ''
    ));
    register_setting( 'aifd_settings', 'aifd_pinecone_index', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'fraud-patterns'
    ));

}

// ══════════════════════════════════════════════
// RENDER SETTINGS PAGE
// ══════════════════════════════════════════════

function aifd_render_settings_page() {

    $provider = get_option( 'aifd_ai_provider', 'openai' );
    ?>

    <div class="wrap">
        <h1>AI Fraud Detector — Settings</h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'aifd_settings' ); ?>

            <h2>AI Provider</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Choose Provider</th>
                    <td>
                        <label><input type="radio" name="aifd_ai_provider" value="openai" <?php checked( $provider, 'openai' ); ?>> OpenAI</label><br>
                        <label><input type="radio" name="aifd_ai_provider" value="anthropic" <?php checked( $provider, 'anthropic' ); ?>> Anthropic (Claude)</label><br>
                        <label><input type="radio" name="aifd_ai_provider" value="gemini" <?php checked( $provider, 'gemini' ); ?>> Google Gemini</label><br>
                        <label><input type="radio" name="aifd_ai_provider" value="ollama" <?php checked( $provider, 'ollama' ); ?>> Ollama (Local — Private)</label>
                    </td>
                </tr>
            </table>

            <h2>OpenAI Settings</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">API Key</th>
                    <td>
                        <input type="password" name="aifd_openai_key" value="<?php echo esc_attr( get_option( 'aifd_openai_key' ) ); ?>" class="regular-text" placeholder="sk-proj-...">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Model</th>
                    <td>
                        <select name="aifd_openai_model">
                            <option value="gpt-4o-mini" <?php selected( get_option( 'aifd_openai_model' ), 'gpt-4o-mini' ); ?>>gpt-4o-mini (recommended)</option>
                            <option value="gpt-4o" <?php selected( get_option( 'aifd_openai_model' ), 'gpt-4o' ); ?>>gpt-4o</option>
                        </select>
                    </td>
                </tr>
            </table>

            <h2>Anthropic Settings</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">API Key</th>
                    <td>
                        <input type="password" name="aifd_anthropic_key" value="<?php echo esc_attr( get_option( 'aifd_anthropic_key' ) ); ?>" class="regular-text" placeholder="sk-ant-...">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Model</th>
                    <td>
                        <select name="aifd_anthropic_model">
                            <option value="claude-haiku-4-5-20251001" <?php selected( get_option( 'aifd_anthropic_model' ), 'claude-haiku-4-5-20251001' ); ?>>Claude Haiku 4.5 (recommended)</option>
                            <option value="claude-sonnet-4-6" <?php selected( get_option( 'aifd_anthropic_model' ), 'claude-sonnet-4-6' ); ?>>Claude Sonnet 4.6</option>
                        </select>
                    </td>
                </tr>
            </table>

            <h2>Google Gemini Settings</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">API Key</th>
                    <td>
                        <input type="password" name="aifd_gemini_key" value="<?php echo esc_attr( get_option( 'aifd_gemini_key' ) ); ?>" class="regular-text" placeholder="AIza...">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Model</th>
                    <td>
                        <select name="aifd_gemini_model">
                            <option value="gemini-1.5-flash" <?php selected( get_option( 'aifd_gemini_model' ), 'gemini-1.5-flash' ); ?>>Gemini 1.5 Flash (recommended)</option>
                            <option value="gemini-1.5-pro" <?php selected( get_option( 'aifd_gemini_model' ), 'gemini-1.5-pro' ); ?>>Gemini 1.5 Pro</option>
                        </select>
                    </td>
                </tr>
            </table>

            <h2>Ollama Settings (Local AI)</h2>
            <p><em>No API key needed. All inference stays on your server.</em></p>
            <table class="form-table">
                <tr>
                    <th scope="row">Host URL</th>
                    <td>
                        <input type="url" name="aifd_ollama_host" value="<?php echo esc_attr( get_option( 'aifd_ollama_host', 'http://localhost:11434' ) ); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Model</th>
                    <td>
                        <input type="text" name="aifd_ollama_model" value="<?php echo esc_attr( get_option( 'aifd_ollama_model', 'llama3' ) ); ?>" class="regular-text" placeholder="llama3, mistral, qwen2.5...">
                    </td>
                </tr>
            </table>

            <h2>Risk Thresholds</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Block Threshold</th>
                    <td>
                        <input type="number" name="aifd_block_threshold" value="<?php echo esc_attr( get_option( 'aifd_block_threshold', 70 ) ); ?>" min="0" max="100" class="small-text"> / 100
                        <p class="description">Orders above this score are blocked automatically.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Review Threshold</th>
                    <td>
                        <input type="number" name="aifd_review_threshold" value="<?php echo esc_attr( get_option( 'aifd_review_threshold', 40 ) ); ?>" min="0" max="100" class="small-text"> / 100
                        <p class="description">Orders above this score require manual review.</p>
                    </td>
                </tr>
            </table>

            <h2>Pinecone Settings</h2>
        <table class="form-table">
            <tr>
                <th scope="row">API Key</th>
                <td>
                    <input type="password"
                        name="aifd_pinecone_key"
                        value="<?php echo esc_attr( get_option('aifd_pinecone_key') ); ?>"
                        class="regular-text"
                        placeholder="pc-xxxxxxxxxxxxxxxx">
                </td>
            </tr>
            <tr>
                <th scope="row">Index Host URL</th>
                <td>
                    <input type="url"
                        name="aifd_pinecone_host"
                        value="<?php echo esc_attr( get_option('aifd_pinecone_host') ); ?>"
                        class="regular-text"
                        placeholder="https://fraud-patterns-xxx.svc.xxx.pinecone.io">
                    <p class="description">Found in your Pinecone dashboard under the index details.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Index Name</th>
                <td>
                    <input type="text"
                        name="aifd_pinecone_index"
                        value="<?php echo esc_attr( get_option('aifd_pinecone_index', 'fraud-patterns') ); ?>"
                        class="regular-text">
                </td>
            </tr>
        </table>

        <!-- Load Seeds Button -->
        <h2>Seed Patterns</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Load Seed Patterns</th>
                <td>
                    <button type="button" id="aifd-load-seeds" class="button button-secondary">
                        Load 65 Seed Patterns into Pinecone
                    </button>
                    <span id="aifd-seed-status" style="margin-left:10px;"></span>
                    <p class="description">
                        Loads 50 fraud patterns + 15 legitimate patterns into Pinecone.
                        Only needs to be done once.
                    </p>
                </td>
            </tr>
        </table>

        <script>
        document.getElementById('aifd-load-seeds').addEventListener('click', function() {
            const btn    = this;
            const status = document.getElementById('aifd-seed-status');

            btn.disabled    = true;
            status.innerHTML = '⏳ Loading patterns...';

            fetch(aifd_settings.ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=aifd_load_seeds&nonce=' + aifd_settings.nonce
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    status.innerHTML = '✅ ' + data.data.message;
                } else {
                    status.innerHTML = '❌ ' + data.data.message;
                }
                btn.disabled = false;
            })
            .catch(() => {
                status.innerHTML = '❌ Request failed';
                btn.disabled = false;
            });
        });
        </script>

            <?php submit_button(); ?>
        </form>
    </div>

    <?php
}