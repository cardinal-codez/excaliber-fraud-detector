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

    // Add to register_settings:
    register_setting( 'aifd_settings', 'aifd_voyage_key', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => ''
    ));

    // Add these Pinecone index settings per provider:
    register_setting( 'aifd_settings', 'aifd_pinecone_index_openai', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'fraud-patterns-1536'
    ));
    register_setting( 'aifd_settings', 'aifd_pinecone_index_anthropic', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'fraud-patterns-1024'
    ));
    register_setting( 'aifd_settings', 'aifd_pinecone_index_768', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'fraud-patterns-768'
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

                <tr>
                    <th scope="row">Voyage AI Key <span style="color:#856404">(for embeddings)</span></th>
                    <td>
                        <input type="password"
                            name="aifd_voyage_key"
                            value="<?php echo esc_attr( get_option('aifd_voyage_key') ); ?>"
                            class="regular-text"
                            placeholder="pa-...">
                        <p class="description" style="color:#856404">
                            ⚠️ Anthropic doesn't provide embeddings.
                            Get a free Voyage AI key at
                            <a href="https://www.voyageai.com" target="_blank">voyageai.com</a>
                        </p>
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
            <div style="background:#fff3cd;border:1px solid #f59e0b;border-radius:6px;padding:12px 16px;margin-bottom:16px">
                <strong>⚠️ Ollama — Local Deployment Only</strong>
                <p style="margin:6px 0 0">
                    Ollama requires a locally running LLM server on the same machine as WordPress.
                    It is <strong>not compatible with shared hosting</strong> environments
                    (Cloudways, SiteGround, Bluehost, WP Engine etc).
                </p>
                <p style="margin:6px 0 0">Ollama works for:</p>
                <ul style="margin:4px 0 0;padding-left:20px">
                    <li>Local development environments</li>
                    <li>Self-hosted VPS with 8GB+ RAM</li>
                    <li>Dedicated servers where you control the environment</li>
                </ul>
                <p style="margin:6px 0 0">
                    If you are on shared hosting, choose OpenAI, Anthropic, or Gemini instead.
                </p>
            </div>
            <table class="form-table">
                <tr>
                    <th scope="row">Host URL</th>
                    <td>
                        <input type="url"
                            name="aifd_ollama_host"
                            value="<?php echo esc_attr( get_option( 'aifd_ollama_host', 'http://localhost:11434' ) ); ?>"
                            class="regular-text">
                        <p class="description">Default: http://localhost:11434</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Model</th>
                    <td>
                        <input type="text"
                            name="aifd_ollama_model"
                            value="<?php echo esc_attr( get_option( 'aifd_ollama_model', 'llama3' ) ); ?>"
                            class="regular-text"
                            placeholder="llama3, mistral, qwen2.5...">
                        <p class="description">
                            Must support embeddings via nomic-embed-text.
                            Run: <code>ollama pull nomic-embed-text</code>
                        </p>
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
                            placeholder="pcsk-xxxxxxxxxxxxxxxx">
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
                        <p class="description">
                            The index host depends on which provider you select below —
                            each embedding dimension needs its own index.
                        </p>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top:1.5rem">Index Per Embedding Dimension</h3>
            <p class="description" style="margin-bottom:10px">
                Different providers produce different vector sizes. Create one Pinecone index per
                dimension and enter the names below — the plugin automatically picks the right one.
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        OpenAI Index <span style="color:#64748b">(1536 dimensions)</span>
                    </th>
                    <td>
                        <input type="text"
                            name="aifd_pinecone_index_openai"
                            value="<?php echo esc_attr( get_option('aifd_pinecone_index_openai', 'fraud-patterns-1536') ); ?>"
                            class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        Anthropic Index <span style="color:#64748b">(1024 dimensions — Voyage AI)</span>
                    </th>
                    <td>
                        <input type="text"
                            name="aifd_pinecone_index_anthropic"
                            value="<?php echo esc_attr( get_option('aifd_pinecone_index_anthropic', 'fraud-patterns-1024') ); ?>"
                            class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        Gemini / Ollama Index <span style="color:#64748b">(768 dimensions)</span>
                    </th>
                    <td>
                        <input type="text"
                            name="aifd_pinecone_index_768"
                            value="<?php echo esc_attr( get_option('aifd_pinecone_index_768', 'fraud-patterns-768') ); ?>"
                            class="regular-text">
                        <p class="description">
                            Gemini and Ollama both produce 768-dimension vectors and can share one index
                            (they use separate namespaces, so data never mixes).
                        </p>
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
                            Loads 50 fraud patterns + 15 legitimate patterns into Pinecone,
                            embedded with your currently selected provider
                            (<strong><?php echo esc_html( strtoupper( get_option('aifd_ai_provider', 'openai') ) ); ?></strong>)
                            and stored in your merchant namespace. Only needs to be done once per provider.
                        </p>
                    </td>
                </tr>
            </table>

            <script>
            document.getElementById('aifd-load-seeds').addEventListener('click', function() {
                const btn    = this;
                const status = document.getElementById('aifd-seed-status');

                btn.disabled     = true;
                status.innerHTML = '⏳ Embedding and loading patterns...';

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

// Detect provider switch on save
add_action( 'updated_option', 'aifd_detect_provider_switch', 10, 3 );

function aifd_detect_provider_switch( $option, $old_value, $new_value ) {

    if ( $option !== 'aifd_ai_provider' ) return;
    if ( $old_value === $new_value ) return;
    if ( empty( $old_value ) ) return;

    // Store the switch for warning display
    update_option( 'aifd_provider_switched', array(
        'from'    => $old_value,
        'to'      => $new_value,
        'time'    => time(),
        'handled' => false,
    ));

}

// Show warning after provider switch
add_action( 'admin_notices', 'aifd_provider_switch_notice' );

function aifd_provider_switch_notice() {

    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'ai-fraud-detector' ) === false ) return;

    $switch = get_option( 'aifd_provider_switched', array() );
    if ( empty( $switch ) || ! empty( $switch['handled'] ) ) return;

    $from = strtoupper( $switch['from'] );
    $to   = strtoupper( $switch['to'] );

    ?>
    <div class="notice notice-warning" style="border-left-color:#f59e0b">
        <h3 style="margin:.5rem 0">⚠️ AI Provider Changed — Action Required</h3>
        <p>
            You switched from <strong><?php echo esc_html( $from ); ?></strong>
            to <strong><?php echo esc_html( $to ); ?></strong>.
        </p>
        <p>
            Your confirmed fraud patterns were stored in the
            <code><?php echo esc_html( $switch['from'] ); ?></code> namespace.
            The new provider uses a different embedding model and a separate namespace
            — your learned patterns are not available yet.
        </p>
        <p><strong>Choose how to handle this:</strong></p>
        <p>
            <button type="button"
                id="aifd-migrate-patterns"
                class="button button-primary"
                data-from="<?php echo esc_attr( $switch['from'] ); ?>"
                data-to="<?php echo esc_attr( $switch['to'] ); ?>">
                Re-embed patterns for <?php echo esc_html( $to ); ?>
            </button>
            &nbsp;
            <button type="button"
                id="aifd-start-fresh"
                class="button button-secondary">
                Start fresh with new provider
            </button>
            &nbsp;
            <span id="aifd-migration-status" style="margin-left:10px"></span>
        </p>
        <p class="description">
            Re-embedding copies all confirmed patterns from
            <strong><?php echo esc_html( $from ); ?></strong> namespace
            and re-embeds them using <strong><?php echo esc_html( $to ); ?></strong>
            embedding model. This may take 1-2 minutes depending on pattern count.
        </p>
    </div>

    <script>
    document.getElementById('aifd-migrate-patterns').addEventListener('click', function() {
        const btn    = this;
        const status = document.getElementById('aifd-migration-status');
        btn.disabled = true;
        status.innerHTML = '⏳ Re-embedding patterns...';

        fetch(aifd_settings.ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=aifd_migrate_patterns' +
                  '&nonce=' + aifd_settings.nonce +
                  '&from=' + btn.dataset.from +
                  '&to=' + btn.dataset.to
        })
        .then( r => r.json() )
        .then( data => {
            if ( data.success ) {
                status.innerHTML = '✅ ' + data.data.message;
                setTimeout( () => location.reload(), 2000 );
            } else {
                status.innerHTML = '❌ ' + data.data.message;
                btn.disabled = false;
            }
        });
    });

    document.getElementById('aifd-start-fresh').addEventListener('click', function() {
        if ( ! confirm('Start fresh? Your learned patterns from the old provider will not be migrated.') ) return;

        fetch(aifd_settings.ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=aifd_dismiss_migration&nonce=' + aifd_settings.nonce
        })
        .then( r => r.json() )
        .then( () => location.reload() );
    });
    </script>
    <?php

}