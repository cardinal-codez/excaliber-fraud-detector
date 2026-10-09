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
        'hifqa-fraud-detector',
        __( 'Settings', 'hifqa-fraud-detector' ),
        __( 'Settings', 'hifqa-fraud-detector' ),
        'manage_options',
        'hifqa-fraud-detector-settings',
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

    wp_enqueue_style(
        'aifd-admin',
        AIFD_URL . 'assets/css/admin.css',
        array(),
        AIFD_VERSION
    );

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
            'loadingSeeds'  => __( 'Embedding and loading patterns…', 'hifqa-fraud-detector' ),
            /* translators: 1: number of patterns processed so far, 2: total number of patterns */
            'seedProgress'  => __( 'Loading patterns… %1$s of %2$s', 'hifqa-fraud-detector' ),
            /* translators: 1: number of patterns loaded, 2: total number of patterns, 3: number that failed */
            'seedDone'      => __( 'Loaded %1$s of %2$s patterns. Failed: %3$s', 'hifqa-fraud-detector' ),
            'reembedding'   => __( 'Re-embedding patterns…', 'hifqa-fraud-detector' ),
            'requestFailed' => __( 'Request failed', 'hifqa-fraud-detector' ),
            'confirmFresh'  => __( 'Start fresh? Your learned patterns from the old provider will not be migrated.', 'hifqa-fraud-detector' ),
        ),
    ));

}

// ══════════════════════════════════════════════
// REGISTER SETTINGS
// ══════════════════════════════════════════════

add_action( 'admin_init', 'aifd_register_settings' );

/**
 * Chat models offered for each provider — the first one is the default.
 */
function aifd_get_allowed_models() {
    return array(
        'aifd_openai_model'    => array( 'gpt-4o-mini', 'gpt-4o' ),
        'aifd_anthropic_model' => array( 'claude-haiku-4-5-20251001', 'claude-sonnet-4-6' ),
        'aifd_gemini_model'    => array( 'gemini-2.5-flash', 'gemini-2.5-pro' ),
    );
}

/**
 * True while options.php is saving this plugin's settings form.
 */
function aifd_is_settings_form_save() {

    if ( ! isset( $_POST['option_page'], $_POST['_wpnonce'] ) ) {
        return false;
    }

    if ( 'aifd_settings' !== sanitize_key( wp_unslash( $_POST['option_page'] ) ) ) {
        return false;
    }

    return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'aifd_settings-options' );

}

/**
 * Saved keys are never printed into the form, so a blank field means "no
 * change". A key is replaced only by a new value, and removed only by ticking
 * the field's "Remove the saved key" box.
 */
function aifd_sanitize_api_key( $value, $option ) {

    $value = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';

    // Outside the settings form (WP-CLI, code) the value is taken as given.
    if ( '' !== $value || ! aifd_is_settings_form_save() ) {
        return $value;
    }

    // Nonce verified in aifd_is_settings_form_save().
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $remove = isset( $_POST['aifd_clear_keys'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['aifd_clear_keys'] ) ) : array();

    if ( in_array( $option, $remove, true ) ) {
        return '';
    }

    return (string) get_option( $option, '' );

}

function aifd_sanitize_provider( $value ) {
    $value = sanitize_key( (string) $value );
    return in_array( $value, aifd_get_allowed_providers(), true ) ? $value : 'openai';
}

function aifd_sanitize_model( $value, $option ) {
    $allowed = aifd_get_allowed_models()[ $option ];
    $value   = sanitize_text_field( (string) $value );
    return in_array( $value, $allowed, true ) ? $value : $allowed[0];
}

function aifd_sanitize_ollama_model( $value ) {
    // Ollama model names look like "llama3", "qwen2.5:7b" or "library/model:tag".
    $value = preg_replace( '/[^A-Za-z0-9._:\/\-]/', '', (string) $value );
    return '' === $value ? 'llama3' : $value;
}

function aifd_sanitize_block_threshold( $value ) {
    return min( 100, max( 1, absint( $value ) ) );
}

function aifd_sanitize_review_threshold( $value ) {
    return min( 100, absint( $value ) );
}

function aifd_sanitize_pinecone_host( $value ) {
    // Order data is only ever sent to Pinecone over HTTPS.
    return esc_url_raw( trim( (string) $value ), array( 'https' ) );
}

function aifd_sanitize_ollama_host( $value ) {
    return esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
}

function aifd_register_settings() {

    // AI Provider selection
    register_setting( 'aifd_settings', 'aifd_ai_provider', array(
        'type'              => 'string',
        'sanitize_callback' => 'aifd_sanitize_provider',
        'default'           => 'openai',
    ));

    // API keys — OpenAI, Anthropic, Gemini, Voyage AI, Pinecone
    foreach ( aifd_get_api_key_options() as $option ) {
        register_setting( 'aifd_settings', $option, array(
            'type'              => 'string',
            'sanitize_callback' => function ( $value ) use ( $option ) {
                return aifd_sanitize_api_key( $value, $option );
            },
            'default'           => '',
        ));
    }

    // Chat model per provider
    foreach ( aifd_get_allowed_models() as $option => $models ) {
        register_setting( 'aifd_settings', $option, array(
            'type'              => 'string',
            'sanitize_callback' => function ( $value ) use ( $option ) {
                return aifd_sanitize_model( $value, $option );
            },
            'default'           => $models[0],
        ));
    }

    // Ollama
    register_setting( 'aifd_settings', 'aifd_ollama_host', array(
        'type'              => 'string',
        'sanitize_callback' => 'aifd_sanitize_ollama_host',
        'default'           => 'http://localhost:11434',
    ));
    register_setting( 'aifd_settings', 'aifd_ollama_model', array(
        'type'              => 'string',
        'sanitize_callback' => 'aifd_sanitize_ollama_model',
        'default'           => 'llama3',
    ));

    // Risk thresholds
    register_setting( 'aifd_settings', 'aifd_block_threshold', array(
        'type'              => 'integer',
        'sanitize_callback' => 'aifd_sanitize_block_threshold',
        'default'           => 70,
    ));
    register_setting( 'aifd_settings', 'aifd_review_threshold', array(
        'type'              => 'integer',
        'sanitize_callback' => 'aifd_sanitize_review_threshold',
        'default'           => 40,
    ));

    // Pinecone
    register_setting( 'aifd_settings', 'aifd_pinecone_host', array(
        'type'              => 'string',
        'sanitize_callback' => 'aifd_sanitize_pinecone_host',
        'default'           => '',
    ));

    // Pinecone index name per embedding dimension
    register_setting( 'aifd_settings', 'aifd_pinecone_index_openai', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'fraud-patterns-1536',
    ));
    register_setting( 'aifd_settings', 'aifd_pinecone_index_anthropic', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'fraud-patterns-1024',
    ));
    register_setting( 'aifd_settings', 'aifd_pinecone_index_768', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'fraud-patterns-768',
    ));

}

/**
 * An API key field that never contains the saved key.
 */
function aifd_render_api_key_field( $option, $placeholder ) {

    $has_key = '' !== (string) get_option( $option, '' );
    ?>
    <input type="password"
        name="<?php echo esc_attr( $option ); ?>"
        value=""
        class="regular-text"
        autocomplete="new-password"
        placeholder="<?php echo esc_attr( $has_key ? __( '•••••••••••• saved — leave blank to keep', 'hifqa-fraud-detector' ) : $placeholder ); ?>">
    <?php if ( $has_key ) : ?>
        <label class="aifd-clear-key">
            <input type="checkbox" name="aifd_clear_keys[]" value="<?php echo esc_attr( $option ); ?>">
            <?php esc_html_e( 'Remove the saved key', 'hifqa-fraud-detector' ); ?>
        </label>
    <?php endif; ?>
    <?php

}

/**
 * A model dropdown built from the allowed list.
 */
function aifd_render_model_select( $option, $labels ) {

    $models  = aifd_get_allowed_models()[ $option ];
    $current = get_option( $option, $models[0] );
    ?>
    <select name="<?php echo esc_attr( $option ); ?>">
        <?php foreach ( $models as $model ) : ?>
            <option value="<?php echo esc_attr( $model ); ?>" <?php selected( $current, $model ); ?>><?php echo esc_html( $labels[ $model ] ?? $model ); ?></option>
        <?php endforeach; ?>
    </select>
    <?php

}

/**
 * A link to a third party's own site.
 */
function aifd_external_link( $url, $label ) {
    return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
}

// ══════════════════════════════════════════════
// RENDER SETTINGS PAGE
// ══════════════════════════════════════════════

function aifd_render_settings_page() {

    $provider   = get_option( 'aifd_ai_provider', 'openai' );
    $embeddings = new AIFD_Embeddings();
    $pinecone   = new AIFD_Pinecone();

    // Vector memory is configured but the selected provider cannot embed: say so plainly.
    $vector_problem = $pinecone->is_configured() ? $embeddings->get_setup_problem( $provider ) : '';
    ?>

    <div class="wrap aifd-settings">
        <h1><?php esc_html_e( 'HIFQA Fraud Detector — Settings', 'hifqa-fraud-detector' ); ?></h1>

        <?php settings_errors(); ?>

        <?php if ( '' !== $vector_problem ) : ?>
            <div class="notice notice-warning inline">
                <p>
                    <strong><?php esc_html_e( 'Vector memory is not running.', 'hifqa-fraud-detector' ); ?></strong>
                    <?php echo esc_html( $vector_problem ); ?>
                </p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'aifd_settings' ); ?>

            <div class="aifd-callout aifd-callout--success">
                <strong>✅ <?php esc_html_e( 'Local Fraud Detection — Always Active', 'hifqa-fraud-detector' ); ?></strong>
                <p>
                    <?php esc_html_e( 'A 17-signal weighted scoring engine checks every order automatically. It is pure PHP, needs no API keys and sends no order data to external services. This is the foundation — everything below is optional.', 'hifqa-fraud-detector' ); ?>
                </p>
            </div>

            <div class="aifd-callout aifd-callout--info">
                <strong>ℹ️ <?php esc_html_e( 'Optional layers activate automatically once configured', 'hifqa-fraud-detector' ); ?></strong>
                <p>
                    <?php esc_html_e( 'Add a Pinecone key below to enable vector pattern search. Add an AI provider key to enable AI reasoning. Each layer turns on the moment its keys are saved — no separate toggle needed.', 'hifqa-fraud-detector' ); ?>
                </p>
                <p>
                    <?php esc_html_e( 'These layers call the external service while the order is being placed, so checkout waits for the reply. If a service is slow, checkout is slower; if it fails, the order is scored by the local rules alone, and after three failures in a row the layer is paused for ten minutes.', 'hifqa-fraud-detector' ); ?>
                </p>
            </div>

            <div id="third-party-disclosure" class="aifd-callout aifd-callout--neutral">
                <strong><?php esc_html_e( 'Third-Party Data Disclosure', 'hifqa-fraud-detector' ); ?></strong>
                <p><?php esc_html_e( 'Nothing is sent anywhere until you save a key for that service. Customer names, full email addresses, IP addresses, phone numbers and street addresses are never sent to any of them.', 'hifqa-fraud-detector' ); ?></p>
                <table class="aifd-disclosure-table">
                    <tr>
                        <th><?php esc_html_e( 'Service', 'hifqa-fraud-detector' ); ?></th>
                        <th><?php esc_html_e( 'Data Sent', 'hifqa-fraud-detector' ); ?></th>
                        <th><?php esc_html_e( 'Purpose', 'hifqa-fraud-detector' ); ?></th>
                    </tr>
                    <tr>
                        <td>OpenAI / Anthropic / Google Gemini</td>
                        <td><?php esc_html_e( 'Order total, the domain of the billing email (not the address itself), billing and shipping country, payment method, how many earlier orders used the same email, the store average order value, and which risk signals fired', 'hifqa-fraud-detector' ); ?></td>
                        <td><?php esc_html_e( 'AI risk scoring (AI reasoning layer)', 'hifqa-fraud-detector' ); ?></td>
                    </tr>
                    <tr>
                        <td>OpenAI / Voyage AI / Google Gemini</td>
                        <td><?php esc_html_e( 'Pattern text only: the names of the risk signals that fired, the payment method and the billing-to-shipping country pair', 'hifqa-fraud-detector' ); ?></td>
                        <td><?php esc_html_e( 'Embedding generation (vector memory layer)', 'hifqa-fraud-detector' ); ?></td>
                    </tr>
                    <tr>
                        <td>Pinecone</td>
                        <td><?php esc_html_e( 'The pattern vector and the same pattern text. No order number, score or customer detail', 'hifqa-fraud-detector' ); ?></td>
                        <td><?php esc_html_e( 'Vector storage and search (vector memory layer)', 'hifqa-fraud-detector' ); ?></td>
                    </tr>
                    <tr>
                        <td>Ollama</td>
                        <td><?php esc_html_e( 'The same data as the two rows above, sent to the Ollama host URL you enter', 'hifqa-fraud-detector' ); ?></td>
                        <td><?php esc_html_e( 'AI risk scoring and embeddings on a server you run', 'hifqa-fraud-detector' ); ?></td>
                    </tr>
                </table>
                <p class="aifd-links">
                    <?php
                    $links = array(
                        aifd_external_link( 'https://openai.com/policies/privacy-policy', __( 'OpenAI Privacy Policy', 'hifqa-fraud-detector' ) ),
                        aifd_external_link( 'https://openai.com/policies/services-agreement/', __( 'OpenAI Terms', 'hifqa-fraud-detector' ) ),
                        aifd_external_link( 'https://www.anthropic.com/legal/privacy', __( 'Anthropic Privacy Policy', 'hifqa-fraud-detector' ) ),
                        aifd_external_link( 'https://www.anthropic.com/legal/commercial-terms', __( 'Anthropic Terms', 'hifqa-fraud-detector' ) ),
                        aifd_external_link( 'https://policies.google.com/privacy', __( 'Google Privacy Policy', 'hifqa-fraud-detector' ) ),
                        aifd_external_link( 'https://ai.google.dev/gemini-api/terms', __( 'Gemini API Terms', 'hifqa-fraud-detector' ) ),
                        aifd_external_link( 'https://www.voyageai.com/privacy', __( 'Voyage AI Privacy Policy', 'hifqa-fraud-detector' ) ),
                        aifd_external_link( 'https://www.voyageai.com/tos', __( 'Voyage AI Terms', 'hifqa-fraud-detector' ) ),
                        aifd_external_link( 'https://www.pinecone.io/privacy/', __( 'Pinecone Privacy Policy', 'hifqa-fraud-detector' ) ),
                        aifd_external_link( 'https://www.pinecone.io/terms/', __( 'Pinecone Terms', 'hifqa-fraud-detector' ) ),
                        aifd_external_link( 'https://ollama.com/privacy', __( 'Ollama Privacy Policy', 'hifqa-fraud-detector' ) ),
                        aifd_external_link( 'https://ollama.com/terms', __( 'Ollama Terms', 'hifqa-fraud-detector' ) ),
                    );
                    // Each link is escaped inside aifd_external_link().
                    echo implode( ' · ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    ?>
                </p>
            </div>

            <h2><?php esc_html_e( 'AI Provider', 'hifqa-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Choose Provider', 'hifqa-fraud-detector' ); ?></th>
                    <td>
                        <label><input type="radio" name="aifd_ai_provider" value="openai" <?php checked( $provider, 'openai' ); ?>> OpenAI</label><br>
                        <label><input type="radio" name="aifd_ai_provider" value="anthropic" <?php checked( $provider, 'anthropic' ); ?>> Anthropic (Claude)</label><br>
                        <label><input type="radio" name="aifd_ai_provider" value="gemini" <?php checked( $provider, 'gemini' ); ?>> Google Gemini</label><br>
                        <label><input type="radio" name="aifd_ai_provider" value="ollama" <?php checked( $provider, 'ollama' ); ?>> <?php esc_html_e( 'Ollama (self-hosted)', 'hifqa-fraud-detector' ); ?></label>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'OpenAI Settings', 'hifqa-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'API Key', 'hifqa-fraud-detector' ); ?></th>
                    <td><?php aifd_render_api_key_field( 'aifd_openai_key', 'sk-proj-...' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Model', 'hifqa-fraud-detector' ); ?></th>
                    <td>
                        <?php
                        aifd_render_model_select( 'aifd_openai_model', array(
                            'gpt-4o-mini' => __( 'gpt-4o-mini (recommended)', 'hifqa-fraud-detector' ),
                        ) );
                        ?>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Anthropic Settings', 'hifqa-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'API Key', 'hifqa-fraud-detector' ); ?></th>
                    <td><?php aifd_render_api_key_field( 'aifd_anthropic_key', 'sk-ant-...' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Model', 'hifqa-fraud-detector' ); ?></th>
                    <td>
                        <?php
                        aifd_render_model_select( 'aifd_anthropic_model', array(
                            'claude-haiku-4-5-20251001' => __( 'Claude Haiku 4.5 (recommended)', 'hifqa-fraud-detector' ),
                            'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
                        ) );
                        ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Voyage AI Key', 'hifqa-fraud-detector' ); ?> <span class="aifd-warn"><?php esc_html_e( '(for embeddings)', 'hifqa-fraud-detector' ); ?></span></th>
                    <td>
                        <?php aifd_render_api_key_field( 'aifd_voyage_key', 'pa-...' ); ?>
                        <p class="description aifd-warn">
                            ⚠️ <?php esc_html_e( 'Anthropic has no embedding model, so vector memory with Anthropic uses Voyage AI. Without a Voyage AI key, vector memory stays off — pattern text is never sent to a different provider instead. Get a key at', 'hifqa-fraud-detector' ); ?>
                            <?php echo aifd_external_link( 'https://www.voyageai.com', 'voyageai.com' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in aifd_external_link(). ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Google Gemini Settings', 'hifqa-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'API Key', 'hifqa-fraud-detector' ); ?></th>
                    <td><?php aifd_render_api_key_field( 'aifd_gemini_key', 'AIza...' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Model', 'hifqa-fraud-detector' ); ?></th>
                    <td>
                        <?php
                        aifd_render_model_select( 'aifd_gemini_model', array(
                            'gemini-2.5-flash' => __( 'Gemini 2.5 Flash (recommended)', 'hifqa-fraud-detector' ),
                            'gemini-2.5-pro'   => 'Gemini 2.5 Pro',
                        ) );
                        ?>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Ollama Settings (Self-Hosted AI)', 'hifqa-fraud-detector' ); ?></h2>
            <div class="aifd-callout aifd-callout--warning">
                <strong>⚠️ <?php esc_html_e( 'Ollama — Self-Hosted Only', 'hifqa-fraud-detector' ); ?></strong>
                <p>
                    <?php echo wp_kses( __( 'Ollama needs an LLM server that you run yourself. It is <strong>not compatible with shared hosting</strong> environments (Cloudways, SiteGround, Bluehost, WP Engine etc).', 'hifqa-fraud-detector' ), array( 'strong' => array() ) ); ?>
                </p>
                <p><?php esc_html_e( 'Order data is sent to whatever host URL you enter below. It stays on your own infrastructure only if that host is a server you control.', 'hifqa-fraud-detector' ); ?></p>
                <p><?php esc_html_e( 'Ollama works for:', 'hifqa-fraud-detector' ); ?></p>
                <ul>
                    <li><?php esc_html_e( 'Local development environments', 'hifqa-fraud-detector' ); ?></li>
                    <li><?php esc_html_e( 'Self-hosted VPS with 8GB+ RAM', 'hifqa-fraud-detector' ); ?></li>
                    <li><?php esc_html_e( 'Dedicated servers where you control the environment', 'hifqa-fraud-detector' ); ?></li>
                </ul>
                <p>
                    <?php esc_html_e( 'If you are on shared hosting, choose OpenAI, Anthropic, or Gemini instead.', 'hifqa-fraud-detector' ); ?>
                </p>
            </div>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Host URL', 'hifqa-fraud-detector' ); ?></th>
                    <td>
                        <input type="url"
                            name="aifd_ollama_host"
                            value="<?php echo esc_attr( get_option( 'aifd_ollama_host', 'http://localhost:11434' ) ); ?>"
                            class="regular-text">
                        <p class="description"><?php esc_html_e( 'Default: http://localhost:11434', 'hifqa-fraud-detector' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Model', 'hifqa-fraud-detector' ); ?></th>
                    <td>
                        <input type="text"
                            name="aifd_ollama_model"
                            value="<?php echo esc_attr( get_option( 'aifd_ollama_model', 'llama3' ) ); ?>"
                            class="regular-text"
                            placeholder="llama3, mistral, qwen2.5...">
                        <p class="description">
                            <?php esc_html_e( 'Vector memory with Ollama also needs the nomic-embed-text model. Run:', 'hifqa-fraud-detector' ); ?> <code>ollama pull nomic-embed-text</code>
                        </p>
                    </td>
                </tr>
            </table>


            <h2><?php esc_html_e( 'Risk Thresholds', 'hifqa-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Block Threshold', 'hifqa-fraud-detector' ); ?></th>
                    <td>
                        <input type="number" name="aifd_block_threshold" value="<?php echo esc_attr( get_option( 'aifd_block_threshold', 70 ) ); ?>" min="1" max="100" class="small-text"> / 100
                        <p class="description"><?php esc_html_e( 'Orders scoring this or higher are put on hold automatically.', 'hifqa-fraud-detector' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Review Threshold', 'hifqa-fraud-detector' ); ?></th>
                    <td>
                        <input type="number" name="aifd_review_threshold" value="<?php echo esc_attr( get_option( 'aifd_review_threshold', 40 ) ); ?>" min="0" max="100" class="small-text"> / 100
                        <p class="description"><?php esc_html_e( 'Orders scoring this or higher are flagged for manual review. If it is set above the block threshold, the block threshold is used for both.', 'hifqa-fraud-detector' ); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Pinecone Settings', 'hifqa-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'API Key', 'hifqa-fraud-detector' ); ?></th>
                    <td><?php aifd_render_api_key_field( 'aifd_pinecone_key', 'pcsk-xxxxxxxxxxxxxxxx' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Index Host URL', 'hifqa-fraud-detector' ); ?></th>
                    <td>
                        <input type="url"
                            name="aifd_pinecone_host"
                            value="<?php echo esc_attr( get_option('aifd_pinecone_host') ); ?>"
                            class="regular-text"
                            placeholder="https://fraud-patterns-xxx.svc.xxx.pinecone.io">
                        <p class="description">
                            <?php esc_html_e( 'Must start with https://. The index host depends on which provider you select — each embedding dimension needs its own index.', 'hifqa-fraud-detector' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'Index Per Embedding Dimension', 'hifqa-fraud-detector' ); ?></h3>
            <p class="description">
                <?php esc_html_e( 'Different providers produce different vector sizes. Create one Pinecone index per dimension and note the names below for your own reference.', 'hifqa-fraud-detector' ); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'OpenAI Index', 'hifqa-fraud-detector' ); ?> <span class="aifd-muted"><?php esc_html_e( '(1536 dimensions)', 'hifqa-fraud-detector' ); ?></span>
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
                        <?php esc_html_e( 'Anthropic Index', 'hifqa-fraud-detector' ); ?> <span class="aifd-muted"><?php esc_html_e( '(1024 dimensions — Voyage AI)', 'hifqa-fraud-detector' ); ?></span>
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
                        <?php esc_html_e( 'Gemini / Ollama Index', 'hifqa-fraud-detector' ); ?> <span class="aifd-muted"><?php esc_html_e( '(768 dimensions)', 'hifqa-fraud-detector' ); ?></span>
                    </th>
                    <td>
                        <input type="text"
                            name="aifd_pinecone_index_768"
                            value="<?php echo esc_attr( get_option('aifd_pinecone_index_768', 'fraud-patterns-768') ); ?>"
                            class="regular-text">
                        <p class="description">
                            <?php esc_html_e( 'Gemini and Ollama both produce 768-dimension vectors and can share one index (they use separate namespaces, so data never mixes).', 'hifqa-fraud-detector' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Seed Patterns', 'hifqa-fraud-detector' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Load Seed Patterns', 'hifqa-fraud-detector' ); ?></th>
                    <td>
                        <button type="button" id="aifd-load-seeds" class="button button-secondary">
                            <?php esc_html_e( 'Load 65 Seed Patterns into Pinecone', 'hifqa-fraud-detector' ); ?>
                        </button>
                        <span id="aifd-seed-status" class="aifd-status"></span>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: currently selected AI provider name */
                                esc_html__( 'Loads 50 fraud patterns + 15 legitimate patterns into Pinecone, embedded with your currently selected provider (%s) and stored in your merchant namespace. Only needs to be done once per provider. Save your settings first.', 'hifqa-fraud-detector' ),
                                '<strong>' . esc_html( strtoupper( $provider ) ) . '</strong>'
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
    if ( ! $screen || strpos( $screen->id, 'hifqa-fraud-detector' ) === false ) return;

    $switch = get_option( 'aifd_provider_switched', array() );
    if ( empty( $switch ) || ! empty( $switch['handled'] ) ) return;
    if ( empty( $switch['from'] ) || empty( $switch['to'] ) ) return;

    $from = strtoupper( $switch['from'] );
    $to   = strtoupper( $switch['to'] );

    ?>
    <div class="notice notice-warning aifd-switch-notice">
        <h3>⚠️ <?php esc_html_e( 'AI Provider Changed — Action Required', 'hifqa-fraud-detector' ); ?></h3>
        <p>
            <?php
            printf(
                /* translators: 1: previous provider name, 2: new provider name */
                esc_html__( 'You switched from %1$s to %2$s.', 'hifqa-fraud-detector' ),
                '<strong>' . esc_html( $from ) . '</strong>',
                '<strong>' . esc_html( $to ) . '</strong>'
            );
            ?>
        </p>
        <p>
            <?php
            printf(
                /* translators: %s: previous provider namespace */
                esc_html__( 'Your confirmed fraud patterns were stored in the %s namespace. The new provider uses a different embedding model and a separate namespace — your learned patterns are not available yet.', 'hifqa-fraud-detector' ),
                '<code>' . esc_html( $switch['from'] ) . '</code>'
            );
            ?>
        </p>
        <p><strong><?php esc_html_e( 'Choose how to handle this:', 'hifqa-fraud-detector' ); ?></strong></p>
        <p>
            <button type="button"
                id="aifd-migrate-patterns"
                class="button button-primary"
                data-from="<?php echo esc_attr( $switch['from'] ); ?>"
                data-to="<?php echo esc_attr( $switch['to'] ); ?>">
                <?php
                printf(
                    /* translators: %s: new provider name */
                    esc_html__( 'Re-embed patterns for %s', 'hifqa-fraud-detector' ),
                    esc_html( $to )
                );
                ?>
            </button>
            &nbsp;
            <button type="button"
                id="aifd-start-fresh"
                class="button button-secondary">
                <?php esc_html_e( 'Start fresh with new provider', 'hifqa-fraud-detector' ); ?>
            </button>
            &nbsp;
            <span id="aifd-migration-status" class="aifd-status"></span>
        </p>
        <p class="description">
            <?php
            printf(
                /* translators: 1: previous provider name, 2: new provider name */
                esc_html__( 'Re-embedding copies all confirmed patterns from %1$s namespace and re-embeds them using %2$s embedding model. This may take 1-2 minutes depending on pattern count.', 'hifqa-fraud-detector' ),
                '<strong>' . esc_html( $from ) . '</strong>',
                '<strong>' . esc_html( $to ) . '</strong>'
            );
            ?>
        </p>
    </div>

    <?php

}