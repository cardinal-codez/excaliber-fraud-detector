<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ══════════════════════════════════════════════
// REGISTER SETTINGS PAGE
// ══════════════════════════════════════════════

add_action( 'admin_menu', 'aifd_register_settings_page', 20 );

function aifd_register_settings_page() {
    // The submenu hook suffix embeds the *parent's* menu title, so capture the
    // returned suffix instead of reconstructing it by hand.
    $GLOBALS['aifd_settings_hook'] = add_submenu_page(
        'disham-fraud-detector',
        __( 'Settings', 'disham-fraud-detector' ),
        __( 'Settings', 'disham-fraud-detector' ),
        'manage_options',
        'disham-fraud-detector-settings',
        'aifd_render_settings_page'
    );
}

add_action( 'admin_enqueue_scripts', 'aifd_enqueue_settings_script' );

function aifd_enqueue_settings_script( $hook ) {

    // The provider switch notice also renders on the dashboard, so load there too.
    $hooks = array(
        $GLOBALS['aifd_settings_hook'] ?? '',
        $GLOBALS['aifd_dashboard_hook'] ?? '',
    );
    if ( ! in_array( $hook, array_filter( $hooks ), true ) ) return;

    wp_enqueue_script(
        'aifd-settings',
        AIFD_URL . 'assets/js/settings.js',
        array(),
        AIFD_VERSION,
        true
    );

    // Pass nonce and strings to settings page JavaScript
    wp_localize_script( 'aifd-settings', 'aifd_settings', array(
        'nonce'   => wp_create_nonce( 'aifd_settings_nonce' ),
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'i18n'    => array(
            'loadingSeeds'  => __( 'Embedding and loading patterns…', 'disham-fraud-detector' ),
            'reembedding'   => __( 'Re-embedding patterns…', 'disham-fraud-detector' ),
            'requestFailed' => __( 'Request failed', 'disham-fraud-detector' ),
            'confirmFresh'  => __( 'Start fresh? Your learned patterns from the old provider will not be migrated.', 'disham-fraud-detector' ),
        ),
    ));

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
        'default'           => 'gemini-2.5-flash'
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
        <h1><?php esc_html_e( 'DISHAM — Settings', 'disham-fraud-detector' ); ?></h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'aifd_settings' ); ?>

            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 18px;margin-bottom:20px">
                <strong>✅ <?php esc_html_e( 'Local Fraud Detection — Always Active', 'disham-fraud-detector' ); ?></strong>
                <p style="margin:6px 0 0;font-size:13px;color:#166534">
                    <?php esc_html_e( 'A 17-signal weighted scoring engine checks every order automatically. It is pure PHP, needs no API keys, sends no order data to external services, and adds negligible overhead to checkout. This is the foundation — everything below is optional.', 'disham-fraud-detector' ); ?>
                </p>
            </div>

            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:20px">
                <strong>ℹ️ <?php esc_html_e( 'Optional layers activate automatically once configured', 'disham-fraud-detector' ); ?></strong>
                <p style="margin:6px 0 0;font-size:13px;color:#1d4ed8">
                    <?php esc_html_e( 'Add a Pinecone key below to enable vector pattern search. Add an AI provider key to enable AI reasoning. Each layer turns on the moment its keys are saved — no separate toggle needed.', 'disham-fraud-detector' ); ?>
                </p>
            </div>

            <div id="third-party-disclosure" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin:16px 0">
                <strong><?php esc_html_e( 'Third-Party Data Disclosure', 'disham-fraud-detector' ); ?></strong>
                <table style="width:100%;margin-top:10px;font-size:13px">
                    <tr style="border-bottom:1px solid #e2e8f0">
                        <th style="text-align:left;padding:6px 0"><?php esc_html_e( 'Service', 'disham-fraud-detector' ); ?></th>
                        <th style="text-align:left;padding:6px 0"><?php esc_html_e( 'Data Sent', 'disham-fraud-detector' ); ?></th>
                        <th style="text-align:left;padding:6px 0"><?php esc_html_e( 'Purpose', 'disham-fraud-detector' ); ?></th>
                    </tr>
                    <tr style="border-bottom:1px solid #f1f5f9">
                        <td style="padding:6px 0">OpenAI / Anthropic / Gemini</td>
                        <td style="padding:6px 0"><?php esc_html_e( 'Email, IP, country, order total, payment method', 'disham-fraud-detector' ); ?></td>
                        <td style="padding:6px 0"><?php esc_html_e( 'AI risk scoring (Tier 2 only)', 'disham-fraud-detector' ); ?></td>
                    </tr>
                    <tr style="border-bottom:1px solid #f1f5f9">
                        <td style="padding:6px 0">OpenAI / Voyage / Gemini / Ollama</td>
                        <td style="padding:6px 0"><?php esc_html_e( 'Signal pattern text only (no PII)', 'disham-fraud-detector' ); ?></td>
                        <td style="padding:6px 0"><?php esc_html_e( 'Embedding generation (Tier 1 only)', 'disham-fraud-detector' ); ?></td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0">Pinecone</td>
                        <td style="padding:6px 0"><?php esc_html_e( 'Pattern vectors + metadata (no PII)', 'disham-fraud-detector' ); ?></td>
                        <td style="padding:6px 0"><?php esc_html_e( 'Vector storage and search (Tier 1 only)', 'disham-fraud-detector' ); ?></td>
                    </tr>
                </table>
                <p style="margin-top:10px;font-size:12px;color:#64748b">
                    <?php esc_html_e( 'Links:', 'disham-fraud-detector' ); ?> <a href="https://openai.com/policies/privacy-policy" target="_blank">OpenAI Privacy Policy</a> ·
                    <a href="https://www.anthropic.com/legal/privacy" target="_blank">Anthropic Privacy Policy</a> ·
                    <a href="https://policies.google.com/privacy" target="_blank">Google Privacy Policy</a> ·
                    <a href="https://www.pinecone.io/legal/privacy-policy/" target="_blank">Pinecone Privacy Policy</a> ·
                    <a href="https://www.voyageai.com/privacy" target="_blank">Voyage AI Privacy Policy</a>
                </p>
            </div>

            <h2><?php esc_html_e( 'AI Provider', 'disham-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Choose Provider', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <label><input type="radio" name="aifd_ai_provider" value="openai" <?php checked( $provider, 'openai' ); ?>> OpenAI</label><br>
                        <label><input type="radio" name="aifd_ai_provider" value="anthropic" <?php checked( $provider, 'anthropic' ); ?>> Anthropic (Claude)</label><br>
                        <label><input type="radio" name="aifd_ai_provider" value="gemini" <?php checked( $provider, 'gemini' ); ?>> Google Gemini</label><br>
                        <label><input type="radio" name="aifd_ai_provider" value="ollama" <?php checked( $provider, 'ollama' ); ?>> <?php esc_html_e( 'Ollama (Local — Private)', 'disham-fraud-detector' ); ?></label>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'OpenAI Settings', 'disham-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'API Key', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <input type="password" name="aifd_openai_key" value="<?php echo esc_attr( get_option( 'aifd_openai_key' ) ); ?>" class="regular-text" placeholder="sk-proj-...">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Model', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <select name="aifd_openai_model">
                            <option value="gpt-4o-mini" <?php selected( get_option( 'aifd_openai_model' ), 'gpt-4o-mini' ); ?>><?php esc_html_e( 'gpt-4o-mini (recommended)', 'disham-fraud-detector' ); ?></option>
                            <option value="gpt-4o" <?php selected( get_option( 'aifd_openai_model' ), 'gpt-4o' ); ?>>gpt-4o</option>
                        </select>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Anthropic Settings', 'disham-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'API Key', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <input type="password" name="aifd_anthropic_key" value="<?php echo esc_attr( get_option( 'aifd_anthropic_key' ) ); ?>" class="regular-text" placeholder="sk-ant-...">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Model', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <select name="aifd_anthropic_model">
                            <option value="claude-haiku-4-5-20251001" <?php selected( get_option( 'aifd_anthropic_model' ), 'claude-haiku-4-5-20251001' ); ?>><?php esc_html_e( 'Claude Haiku 4.5 (recommended)', 'disham-fraud-detector' ); ?></option>
                            <option value="claude-sonnet-4-6" <?php selected( get_option( 'aifd_anthropic_model' ), 'claude-sonnet-4-6' ); ?>>Claude Sonnet 4.6</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Voyage AI Key', 'disham-fraud-detector' ); ?> <span style="color:#856404"><?php esc_html_e( '(for embeddings)', 'disham-fraud-detector' ); ?></span></th>
                    <td>
                        <input type="password"
                            name="aifd_voyage_key"
                            value="<?php echo esc_attr( get_option('aifd_voyage_key') ); ?>"
                            class="regular-text"
                            placeholder="pa-...">
                        <p class="description" style="color:#856404">
                            ⚠️ <?php esc_html_e( 'Anthropic doesn\'t provide embeddings. Get a free Voyage AI key at', 'disham-fraud-detector' ); ?>
                            <a href="https://www.voyageai.com" target="_blank">voyageai.com</a>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Google Gemini Settings', 'disham-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'API Key', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <input type="password" name="aifd_gemini_key" value="<?php echo esc_attr( get_option( 'aifd_gemini_key' ) ); ?>" class="regular-text" placeholder="AIza...">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Model', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <select name="aifd_gemini_model">
                            <option value="gemini-2.5-flash" <?php selected( get_option( 'aifd_gemini_model' ), 'gemini-2.5-flash' ); ?>><?php esc_html_e( 'Gemini 2.5 Flash (recommended)', 'disham-fraud-detector' ); ?></option>
                            <option value="gemini-2.5-pro" <?php selected( get_option( 'aifd_gemini_model' ), 'gemini-2.5-pro' ); ?>>Gemini 2.5 Pro</option>
                            <option value="gemini-2.0-flash" <?php selected( get_option( 'aifd_gemini_model' ), 'gemini-2.0-flash' ); ?>>Gemini 2.0 Flash</option>
                        </select>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Ollama Settings (Local AI)', 'disham-fraud-detector' ); ?></h2>
            <div style="background:#fff3cd;border:1px solid #f59e0b;border-radius:6px;padding:12px 16px;margin-bottom:16px">
                <strong>⚠️ <?php esc_html_e( 'Ollama — Local Deployment Only', 'disham-fraud-detector' ); ?></strong>
                <p style="margin:6px 0 0">
                    <?php echo wp_kses( __( 'Ollama requires a locally running LLM server on the same machine as WordPress. It is <strong>not compatible with shared hosting</strong> environments (Cloudways, SiteGround, Bluehost, WP Engine etc).', 'disham-fraud-detector' ), array( 'strong' => array() ) ); ?>
                </p>
                <p style="margin:6px 0 0"><?php esc_html_e( 'Ollama works for:', 'disham-fraud-detector' ); ?></p>
                <ul style="margin:4px 0 0;padding-left:20px">
                    <li><?php esc_html_e( 'Local development environments', 'disham-fraud-detector' ); ?></li>
                    <li><?php esc_html_e( 'Self-hosted VPS with 8GB+ RAM', 'disham-fraud-detector' ); ?></li>
                    <li><?php esc_html_e( 'Dedicated servers where you control the environment', 'disham-fraud-detector' ); ?></li>
                </ul>
                <p style="margin:6px 0 0">
                    <?php esc_html_e( 'If you are on shared hosting, choose OpenAI, Anthropic, or Gemini instead.', 'disham-fraud-detector' ); ?>
                </p>
            </div>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Host URL', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <input type="url"
                            name="aifd_ollama_host"
                            value="<?php echo esc_attr( get_option( 'aifd_ollama_host', 'http://localhost:11434' ) ); ?>"
                            class="regular-text">
                        <p class="description"><?php esc_html_e( 'Default: http://localhost:11434', 'disham-fraud-detector' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Model', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <input type="text"
                            name="aifd_ollama_model"
                            value="<?php echo esc_attr( get_option( 'aifd_ollama_model', 'llama3' ) ); ?>"
                            class="regular-text"
                            placeholder="llama3, mistral, qwen2.5...">
                        <p class="description">
                            <?php esc_html_e( 'Must support embeddings via nomic-embed-text. Run:', 'disham-fraud-detector' ); ?> <code>ollama pull nomic-embed-text</code>
                        </p>
                    </td>
                </tr>
            </table>


            <h2><?php esc_html_e( 'Risk Thresholds', 'disham-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Block Threshold', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <input type="number" name="aifd_block_threshold" value="<?php echo esc_attr( get_option( 'aifd_block_threshold', 70 ) ); ?>" min="0" max="100" class="small-text"> / 100
                        <p class="description"><?php esc_html_e( 'Orders above this score are blocked automatically.', 'disham-fraud-detector' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Review Threshold', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <input type="number" name="aifd_review_threshold" value="<?php echo esc_attr( get_option( 'aifd_review_threshold', 40 ) ); ?>" min="0" max="100" class="small-text"> / 100
                        <p class="description"><?php esc_html_e( 'Orders above this score require manual review.', 'disham-fraud-detector' ); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Pinecone Settings', 'disham-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'API Key', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <input type="password"
                            name="aifd_pinecone_key"
                            value="<?php echo esc_attr( get_option('aifd_pinecone_key') ); ?>"
                            class="regular-text"
                            placeholder="pcsk-xxxxxxxxxxxxxxxx">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Index Host URL', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <input type="url"
                            name="aifd_pinecone_host"
                            value="<?php echo esc_attr( get_option('aifd_pinecone_host') ); ?>"
                            class="regular-text"
                            placeholder="https://fraud-patterns-xxx.svc.xxx.pinecone.io">
                        <p class="description">
                            <?php esc_html_e( 'The index host depends on which provider you select below — each embedding dimension needs its own index.', 'disham-fraud-detector' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top:1.5rem"><?php esc_html_e( 'Index Per Embedding Dimension', 'disham-fraud-detector' ); ?></h3>
            <p class="description" style="margin-bottom:10px">
                <?php esc_html_e( 'Different providers produce different vector sizes. Create one Pinecone index per dimension and enter the names below — the plugin automatically picks the right one.', 'disham-fraud-detector' ); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'OpenAI Index', 'disham-fraud-detector' ); ?> <span style="color:#64748b"><?php esc_html_e( '(1536 dimensions)', 'disham-fraud-detector' ); ?></span>
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
                        <?php esc_html_e( 'Anthropic Index', 'disham-fraud-detector' ); ?> <span style="color:#64748b"><?php esc_html_e( '(1024 dimensions — Voyage AI)', 'disham-fraud-detector' ); ?></span>
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
                        <?php esc_html_e( 'Gemini / Ollama Index', 'disham-fraud-detector' ); ?> <span style="color:#64748b"><?php esc_html_e( '(768 dimensions)', 'disham-fraud-detector' ); ?></span>
                    </th>
                    <td>
                        <input type="text"
                            name="aifd_pinecone_index_768"
                            value="<?php echo esc_attr( get_option('aifd_pinecone_index_768', 'fraud-patterns-768') ); ?>"
                            class="regular-text">
                        <p class="description">
                            <?php esc_html_e( 'Gemini and Ollama both produce 768-dimension vectors and can share one index (they use separate namespaces, so data never mixes).', 'disham-fraud-detector' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <!-- Load Seeds Button -->
            <h2><?php esc_html_e( 'Seed Patterns', 'disham-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Load Seed Patterns', 'disham-fraud-detector' ); ?></th>
                    <td>
                        <button type="button" id="aifd-load-seeds" class="button button-secondary">
                            <?php esc_html_e( 'Load 65 Seed Patterns into Pinecone', 'disham-fraud-detector' ); ?>
                        </button>
                        <span id="aifd-seed-status" style="margin-left:10px;"></span>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: currently selected AI provider name */
                                esc_html__( 'Loads 50 fraud patterns + 15 legitimate patterns into Pinecone, embedded with your currently selected provider (%s) and stored in your merchant namespace. Only needs to be done once per provider.', 'disham-fraud-detector' ),
                                '<strong>' . esc_html( strtoupper( get_option( 'aifd_ai_provider', 'openai' ) ) ) . '</strong>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>


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
    if ( ! $screen || strpos( $screen->id, 'disham-fraud-detector' ) === false ) return;

    $switch = get_option( 'aifd_provider_switched', array() );
    if ( empty( $switch ) || ! empty( $switch['handled'] ) ) return;

    $from = strtoupper( $switch['from'] );
    $to   = strtoupper( $switch['to'] );

    ?>
    <div class="notice notice-warning" style="border-left-color:#f59e0b">
        <h3 style="margin:.5rem 0">⚠️ <?php esc_html_e( 'AI Provider Changed — Action Required', 'disham-fraud-detector' ); ?></h3>
        <p>
            <?php
            printf(
                /* translators: 1: previous provider name, 2: new provider name */
                esc_html__( 'You switched from %1$s to %2$s.', 'disham-fraud-detector' ),
                '<strong>' . esc_html( $from ) . '</strong>',
                '<strong>' . esc_html( $to ) . '</strong>'
            );
            ?>
        </p>
        <p>
            <?php
            printf(
                /* translators: %s: previous provider namespace */
                esc_html__( 'Your confirmed fraud patterns were stored in the %s namespace. The new provider uses a different embedding model and a separate namespace — your learned patterns are not available yet.', 'disham-fraud-detector' ),
                '<code>' . esc_html( $switch['from'] ) . '</code>'
            );
            ?>
        </p>
        <p><strong><?php esc_html_e( 'Choose how to handle this:', 'disham-fraud-detector' ); ?></strong></p>
        <p>
            <button type="button"
                id="aifd-migrate-patterns"
                class="button button-primary"
                data-from="<?php echo esc_attr( $switch['from'] ); ?>"
                data-to="<?php echo esc_attr( $switch['to'] ); ?>">
                <?php
                printf(
                    /* translators: %s: new provider name */
                    esc_html__( 'Re-embed patterns for %s', 'disham-fraud-detector' ),
                    esc_html( $to )
                );
                ?>
            </button>
            &nbsp;
            <button type="button"
                id="aifd-start-fresh"
                class="button button-secondary">
                <?php esc_html_e( 'Start fresh with new provider', 'disham-fraud-detector' ); ?>
            </button>
            &nbsp;
            <span id="aifd-migration-status" style="margin-left:10px"></span>
        </p>
        <p class="description">
            <?php
            printf(
                /* translators: 1: previous provider name, 2: new provider name */
                esc_html__( 'Re-embedding copies all confirmed patterns from %1$s namespace and re-embeds them using %2$s embedding model. This may take 1-2 minutes depending on pattern count.', 'disham-fraud-detector' ),
                '<strong>' . esc_html( $from ) . '</strong>',
                '<strong>' . esc_html( $to ) . '</strong>'
            );
            ?>
        </p>
    </div>

    <?php

}