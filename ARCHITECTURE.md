# Vigilo — Architecture Document

> Plugin version: 1.0.0
> Last updated: July 2026

---

## Overview

Vigilo is a production-grade WordPress plugin that uses AI function calling, Pinecone vector search, and per-merchant learning to detect and prevent ecommerce fraud in real time.

The plugin supports multiple AI providers — OpenAI, Anthropic, Google Gemini, and Ollama (local) — with provider-native embeddings and namespaced Pinecone indexes for full provider independence.

---

## Core Architecture

```
New Order Placed
      ↓
Extract 17 weighted signals
      ↓
Build behavior pattern text from active signals
      ↓
Embed using provider-native embedding model
      ↓
Search Pinecone in merchant+provider namespace
      ↓
AI Provider Agent (function calling):
  ├── check_signal_weights(signals, merchant_weights)
  ├── search_fraud_patterns(pinecone_context)
  ├── check_velocity(email, ip)
  └── calculate_final_risk(all_results)
      ↓
Decision: ALLOW / REVIEW / BLOCK
      ↓
REVIEW/BLOCK → merchant dashboard (React)
      ↓
Merchant: Confirm Fraud OR Override
      ↓
Confirm → embed pattern → store in Pinecone namespace ✅
Override → record false positive
        → reduce signal weights for this merchant ✅
      ↓
System learns per merchant over time 🧠
```

## Capability Tiers (Local-First Architecture)

To meet WordPress.org review requirements and protect merchants from
checkout-breaking outages, the plugin uses a three-tier capability
gate instead of requiring AI on every order.

| Tier | What it does | Default | Requires |
|------|--------------|---------|----------|
| 0 — Local | 17 weighted signals, pure PHP | Always on | Nothing |
| 1 — Vector Memory | Pinecone similarity search | Off | Pinecone key + host |
| 2 — AI Reasoning | Function calling agent | Off | Provider API key |

Each tier wraps its external calls in a try/catch. A circuit breaker
trips after 3 consecutive failures and disables that tier for 10
minutes, falling back to the next tier down. Checkout is never
blocked by a third-party outage — `aifd_catch_new_order()` always
returns a usable score from Tier 0 at minimum.

### How a tier is activated

**The API key field is the switch.** There is no separate enable or
consent checkbox — `aifd_tier1_ready()` and `aifd_tier2_ready()` gate
purely on whether the relevant key (and host, for Pinecone and Ollama)
is present, and on whether that tier's circuit breaker is currently
open:

```php
function aifd_tier1_ready() {
    if ( ! get_option( 'aifd_pinecone_key' ) )  return false;
    if ( ! get_option( 'aifd_pinecone_host' ) ) return false;
    if ( get_transient( 'aifd_pinecone_circuit_open' ) ) return false;
    return true;
}
```

Entering a key is the merchant's affirmative act of enabling that tier;
clearing the field turns it off again and the plugin falls back to
Tier 0. This is what satisfies WordPress.org's requirement that a plugin
not contact external servers without user action — a fresh install with
empty key fields makes no outbound request at all. The services
contacted, and the data sent to each, are disclosed in `readme.txt`.

---

## Multi-Provider AI Architecture

### Strategy Pattern

The plugin uses the Strategy Pattern to decouple fraud detection logic from the AI provider. Merchants choose their preferred provider from the settings page.

```php
$provider = get_option('aifd_ai_provider', 'openai');
$result   = aifd_call_provider($signals);
// Routes internally to correct provider class
```

### Supported Providers

| Provider | Scoring Model | Embedding Model | Dimensions | Keys Needed |
|----------|--------------|-----------------|------------|-------------|
| OpenAI | gpt-4o-mini | text-embedding-3-small | 1536 | OpenAI only |
| Anthropic | claude-haiku-4-5-20251001 | voyage-3 (Voyage AI) | 1024 | Anthropic + Voyage ⚠️ |
| Google Gemini | gemini-2.5-flash | text-embedding-004 | 768 | Gemini only |
| Ollama | llama3 / any | nomic-embed-text | 768 | None (free, local) |

### Provider File Structure

```
includes/
├── class-embeddings.php         ← embedding abstraction layer
├── class-pinecone.php           ← Pinecone integration
├── class-seed-loader.php        ← seed pattern loader
└── providers/
    ├── class-provider-base.php
    ├── class-provider-openai.php
    ├── class-provider-anthropic.php
    ├── class-provider-gemini.php
    └── class-provider-ollama.php
```

## Provider Switching Strategy

When a merchant switches AI providers:

1. System detects the change via updated_option hook
2. Admin notice warns merchant about namespace isolation
3. Two options presented:

   Re-embed patterns:
   → All seed patterns re-embedded with new provider model
   → All confirmed merchant patterns re-embedded
   → Stored in new namespace (e.g. 1_gemini)
   → Old namespace preserved as backup
   → Migration takes 1-2 minutes

   Start fresh:
   → New namespace created
   → 65 seed patterns loaded fresh
   → Confirmed patterns NOT migrated
   → Merchant starts learning again

## Ollama Production Warning

Ollama is listed as a provider option but is only suitable for:
- Local development environments
- Self-hosted VPS with 8GB+ RAM
- Dedicated servers with full environment control

Ollama is NOT compatible with shared hosting environments
(Cloudways, SiteGround, Bluehost, WP Engine, Kinsta etc).

A clear warning is shown in the settings page when Ollama is selected.

---

## Embedding Abstraction Layer

### The Problem This Solves

All 65 seed patterns must be embedded with the same model they will be searched with. Mixing embedding models breaks vector similarity search completely.

```
WRONG ❌
Seeds embedded with OpenAI text-embedding-3-small
Search query embedded with Gemini text-embedding-004
→ Different vector spaces
→ Similarity scores are meaningless
→ System returns garbage results

CORRECT ✅
Seeds embedded with provider's own model
Search query embedded with same model
→ Same vector space
→ Similarity scores are accurate
→ System finds real fraud pattern matches
```

### Embedding Model Map

```php
private $models = array(
    'openai'    => array(
        'model'     => 'text-embedding-3-small',
        'dimension' => 1536,
    ),
    'anthropic' => array(
        'model'     => 'voyage-3',        // Voyage AI — separate key needed
        'dimension' => 1024,
    ),
    'gemini'    => array(
        'model'     => 'text-embedding-004',
        'dimension' => 768,
    ),
    'ollama'    => array(
        'model'     => 'nomic-embed-text', // free, runs locally
        'dimension' => 768,
    ),
);
```

### Anthropic Special Case

Anthropic does not provide native embedding models. Voyage AI (voyageai.com) is Anthropic's recommended embedding partner.

Anthropic merchants need two API keys:
- Anthropic API key (for Claude scoring)
- Voyage AI API key (for embeddings)

Fallback: If no Voyage key provided, falls back to OpenAI embeddings with a warning.

---

## Pinecone Namespaced Architecture

### Why Namespaces

Each merchant + provider combination gets its own Pinecone namespace. This means:

- Merchant A using OpenAI never pollutes Merchant B using Gemini
- Switching providers gets a fresh namespace with correct dimensions
- False positive overrides only affect the merchant who reported them
- Confirmed fraud patterns are merchant-specific, not global

### Namespace Convention

```
{merchant_id}_{provider}

Examples:
1_openai     → merchant ID 1, using OpenAI
1_gemini     → merchant ID 1, using Gemini
2_openai     → merchant ID 2, using OpenAI
2_anthropic  → merchant ID 2, using Anthropic
```

### Pinecone Index Structure

Three separate indexes — one per dimension size:

| Index Name | Dimensions | Used By |
|-----------|------------|---------|
| fraud-patterns-1536 | 1536 | OpenAI |
| fraud-patterns-1024 | 1024 | Anthropic (Voyage) |
| fraud-patterns-768 | 768 | Gemini + Ollama |

### Seed Loading Process

At install (or manual trigger from settings):

```
Merchant clicks "Load Seed Patterns"
        ↓
Detect merchant's chosen provider
        ↓
Embed all 65 seeds using THAT provider's embedding model
        ↓
Store in merchant's namespace (e.g. 1_openai)
        ↓
One time cost — never repeated unless provider changes
        ↓
Seeds available for similarity search immediately
```

### What We Store in Pinecone

We never store raw order data. We store behavior pattern embeddings.

```json
{
  "id": "seed_001",
  "values": [0.123, 0.456, ...],
  "metadata": {
    "pattern":     "disposable_email address_mismatch high_value no_phone",
    "fraud_type":  "card_fraud",
    "confidence":  0.97,
    "times_seen":  1250,
    "source":      "seed_dataset",
    "description": "Classic stolen card pattern",
    "provider":    "openai"
  },
  "namespace": "1_openai"
}
```

### Pattern Text Construction

We embed a natural language description of active signals, not raw data:

```
Active signals: disposable_email, address_mismatch, high_value, no_phone
Payment method: cod
Countries: US → GB

Pattern text:
"disposable_email address_mismatch no_phone_number
 first_order_high_value cod_payment US_to_GB"
        ↓
Embedded → 1536d vector (OpenAI) or 768d (Gemini) etc
```

---

## Signal Matrix — 17 Weighted Signals

### Tier 1 — Critical signals (high weight)

| Signal | Weight | Description |
|--------|--------|-------------|
| card_testing_pattern | 0.30 | Multiple small orders same IP < 1 hour |
| disposable_email | 0.25 | Known disposable email provider |
| high_velocity | 0.25 | Same email or IP, 3+ orders < 1 hour |
| address_mismatch | 0.20 | Billing country ≠ shipping country |
| freight_forwarder | 0.20 | Known reshipping warehouse address |

### Tier 2 — Strong signals

| Signal | Weight | Description |
|--------|--------|-------------|
| chargeback_history | 0.22 | Previous chargeback or blocked order |
| first_order_high_value | 0.15 | New customer, order > $500 |
| ip_proxy_vpn | 0.12 | Order placed via VPN or proxy |
| risky_country_combo | 0.12 | Known high-fraud billing/shipping pair |
| mismatched_names | 0.10 | Billing name ≠ shipping name |
| no_phone_number | 0.10 | No contact number provided |

### Tier 3 — Contextual signals

| Signal | Weight | Description |
|--------|--------|-------------|
| payment_method_risk | 0.08 | Prepaid card or unusual payment method |
| multiple_addresses | 0.08 | Same email, 3+ different shipping addresses |
| order_total_anomaly | 0.07 | Order > 3x store average order value |
| odd_hours | 0.05 | Order placed between 12am and 5am |
| guest_checkout | 0.05 | No account created |
| new_account | 0.05 | Account created < 24 hours ago |

### Scoring Formula

```
raw_score   = sum(signal_weight × signal_present)
max_possible = sum(all_weights)
normalized  = (raw_score / max_possible) × 100
final_score = min(100, normalized)
```

---

## Decision States

| Decision | Score Range | Action |
|----------|-------------|--------|
| ALLOW | 0 – 39 | Process order normally |
| REVIEW | 40 – 70 | Hold order, notify merchant, await decision |
| BLOCK | 71 – 100 | Hold order, alert merchant, send email |

REVIEW is the most valuable state. False positives cost merchants more than fraud. A system that flags for review builds more trust than one that aggressively blocks.

---

## AI Function Calling

OpenAI and Anthropic both support function calling natively. The agent autonomously decides which tools to call:

```javascript
tools: [
  {
    name: "check_signal_weights",
    description: "Evaluate weighted fraud signals using merchant-specific weights",
    parameters: { signals: object, merchant_weights: object }
  },
  {
    name: "search_fraud_patterns",
    description: "Search Pinecone for similar confirmed fraud patterns",
    parameters: { embedding: array, namespace: string, threshold: number }
  },
  {
    name: "check_velocity",
    description: "Check order velocity for email and IP",
    parameters: { email: string, ip: string, window_minutes: number }
  },
  {
    name: "calculate_final_risk",
    description: "Synthesize all inputs into final risk score and decision",
    parameters: { signal_score: number, pattern_matches: array, velocity_score: number }
  }
]
```

### Function Calling Support Per Provider

| Provider | Function Calling | Implementation |
|----------|-----------------|----------------|
| OpenAI | ✅ Full | "tools" parameter |
| Anthropic | ✅ Full | "tools" parameter (same concept) |
| Gemini | ✅ Full | "function_declarations" parameter |
| Ollama | ⚠️ Model dependent | Structured prompt fallback |

---

## Feedback Loop

```
Order blocked or flagged for review
      ↓
Merchant sees in React dashboard
      ↓
Two actions:
├── Confirm Fraud
│     → embed behavior pattern
│     → store in merchant namespace in Pinecone
│     → confidence: 1.0 (merchant confirmed)
│     → increment fraud counter
│
└── Override (not fraud — false positive)
      → record as false positive
      → do NOT store in Pinecone
      → trigger per-merchant weight adjustment
      → log fired signals for analysis
```

---

## Per-Merchant Weight Personalization

### The Problem

Global weights suit average stores. A luxury jewelry store has very different fraud patterns from a $10 digital download shop.

### The Solution

Each merchant gets a weight profile that adjusts automatically based on their override history.

```
Override happens
      ↓
Record which signals fired on this false positive
      ↓
Reduce those signal weights by delta (0.02)
for this merchant only — never globally
      ↓
Save as merchant_weight_profile in wp_options
      ↓
Next order uses personalized weights
      ↓
After 10+ overrides, weights are calibrated
to this merchant's specific business type 🧠
```

### Weight Adjustment Formula

```php
$delta = 0.02; // small reduction per override
$floor = 0.01; // never go below this

foreach ($fired_signals as $signal) {
    $weights[$signal] = max(
        $floor,
        $weights[$signal] - $delta
    );
}

update_option('aifd_merchant_weights', $weights);
```

### Why Per-Merchant Not Global

```
Digital goods store
→ odd_hours weight reduced over time
   (customers legitimately buy at 3am)

Physical goods store
→ odd_hours weight stays high
   (3am physical orders are suspicious)
```

---

## Seed Pattern Dataset

65 patterns loaded at install into merchant's Pinecone namespace:

| Category | Count | Fraud Types Covered |
|----------|-------|---------------------|
| Card fraud | 20 | Stolen cards, prepaid cards, high value |
| Card testing | 10 | Bot testing, velocity attacks, overnight |
| Reshipping fraud | 10 | Freight forwarders, organized rings |
| Chargeback fraud | 8 | Serial chargebacks, cycling identities |
| Identity fraud | 7 | VPN masking, account takeover |
| Legitimate orders | 15 | Returns, corporates, gifts, renewals |

Without legitimate patterns, every order looks suspicious because there is no contrast. The 15 legitimate patterns are as important as the 50 fraud patterns.

---

## Platform Architecture

```
WooCommerce (WordPress Hook) ─────────┐
EDD (WordPress Hook) ─────────────────├──► AI Fraud Engine (PHP)
SureCart (WordPress Hook) ────────────┤    ├── 17 signal extraction
Shopify (Webhook → Python API) ───────┘    ├── Embedding abstraction
                                           ├── Pinecone namespace search
                                           ├── Provider routing
                                           └── Weight personalization
```

WooCommerce, EDD, SureCart — WordPress hooks (same server, zero latency).
Shopify — webhook to separate Python/FastAPI service on Azure.

---

## Settings Page Structure

```
AI Provider
└── Choose: OpenAI / Anthropic / Gemini / Ollama

OpenAI Settings
├── API Key (used for scoring + embeddings)
└── Model: gpt-4o-mini | gpt-4o

Anthropic Settings
├── API Key (Claude — for scoring only)
├── Voyage AI Key ⚠️ (for embeddings — separate key)
└── Model: claude-haiku-4-5-20251001 | claude-sonnet-4-6

Google Gemini Settings
├── API Key (used for scoring + embeddings)
└── Model: gemini-2.5-flash | gemini-2.5-pro | gemini-2.0-flash

Ollama Settings
├── Host URL (default: http://localhost:11434)
└── Model (any installed model)

Pinecone Settings
├── API Key
├── Index: fraud-patterns-1536 (OpenAI)
│         fraud-patterns-1024 (Anthropic)
│         fraud-patterns-768  (Gemini/Ollama)
└── [Load 65 Seed Patterns] button

Risk Thresholds
├── Block threshold: 70 (default)
└── Review threshold: 40 (default)
```

---

## Technology Stack

| Layer | Technology |
|-------|------------|
| WordPress plugin | PHP 8.0+ |
| Frontend dashboard | React + @wordpress/scripts |
| REST API | WordPress REST API |
| AI scoring (OpenAI) | GPT-4o-mini (function calling) |
| AI scoring (Anthropic) | claude-haiku-4-5-20251001 (tool use) |
| AI scoring (Gemini) | gemini-2.5-flash (function declarations) |
| AI scoring (local) | Ollama (any model, free, private) |
| Embeddings (OpenAI) | text-embedding-3-small (1536d) |
| Embeddings (Anthropic) | voyage-3 via Voyage AI (1024d) |
| Embeddings (Gemini) | text-embedding-004 (768d) |
| Embeddings (Ollama) | nomic-embed-text (768d) |
| Vector store | Pinecone serverless (namespaced) |
| Future: Shopify API | Python + FastAPI + Azure |

---

## Full File Structure

```
vigilo-ai-fraud-detector/
├── vigilo-ai-fraud-detector.php                # Main plugin file — constants + requires
├── readme.txt                           # WordPress.org readme (ships in the zip)
├── README.md                            # GitHub readme (dev-only, export-ignored)
├── ARCHITECTURE.md                      # This document (dev-only, export-ignored)
├── ROADMAP.MD                           # Development roadmap (dev-only)
├── LICENSE                              # GPL-2.0-or-later
├── SECURITY.md                          # Vulnerability reporting policy
├── package.json                         # Node dependencies (dev-only)
├── includes/
│   ├── fraud-detection.php              # Signal extraction, scoring, order actions
│   ├── rest-api.php                     # REST API + AJAX handlers
│   ├── admin-menu.php                   # Admin menu + React loader
│   ├── settings-page.php                # Settings page + registration
│   ├── class-embeddings.php             # Embedding abstraction layer
│   ├── class-pinecone.php               # Pinecone integration
│   ├── class-seed-loader.php            # Seed pattern loader
│   ├── class-function-calling.php       # Function-calling agent + tool defs
│   └── providers/
│       ├── class-provider-base.php      # Shared interface
│       ├── class-provider-openai.php    # OpenAI
│       ├── class-provider-anthropic.php # Anthropic
│       ├── class-provider-gemini.php    # Google Gemini
│       └── class-provider-ollama.php    # Local Ollama
├── admin/
│   └── dashboard.php                    # React mount point
├── src/
│   └── index.js                         # React dashboard
├── build/                               # Compiled React (ships in the zip)
└── data/
    └── seed-patterns.json               # 65 seed patterns
```

The release zip is produced by `git archive`, so anything marked
`export-ignore` in `.gitattributes` (this file, `README.md`,
`ROADMAP.MD`, `package.json`, build config) is stripped from what
merchants actually install.

---

## Development Roadmap

### Phase 1 — Foundation (Complete ✅)

- [x] WooCommerce order capture
- [x] 17 fraud signal extraction
- [x] Weighted rule scoring
- [x] Auto flag + email alerts
- [x] React dashboard
- [x] WordPress REST API
- [x] Multi-provider architecture (OpenAI / Anthropic / Gemini / Ollama)
- [x] Settings page

### Phase 2 — Production AI (Complete ✅)

- [x] 65 seed patterns created
- [x] chargeback_history signal #17
- [x] Pinecone account + index setup
- [x] Embedding abstraction layer
- [x] Per-provider namespace seeding
- [x] Pinecone wired into the scoring pipeline
- [x] Function calling agent replaces basic prompting
- [x] Feedback loop UI (Confirm Fraud / Override)
- [x] Feedback loop persists confirmed patterns to Pinecone
- [x] Per-merchant weight personalization
- [x] Local-first capability tiers + circuit breakers

### Phase 3 — Hardening (Next)

- [ ] Unit tests for signal extraction and risk scoring
- [ ] Split `fraud-detection.php` by responsibility
- [ ] WordPress.org submission

### Phase 4 — Platform Expansion (Planned)

- [ ] Easy Digital Downloads integration
- [ ] SureCart integration
- [ ] Shopify webhook + Python API on Azure

### Phase 5 — Growth (Future)

- [ ] Community pattern sharing (opt-in, anonymized)
- [ ] Analytics and reporting

---

## Security Considerations

### How API keys are stored

Keys are registered through the WordPress Settings API and saved as
plain options in `wp_options`:

```php
register_setting( 'aifd_settings', 'aifd_openai_key', array(
    'sanitize_callback' => 'sanitize_text_field',
    'default'           => ''
) );
```

Saving goes through core's `options.php` handler, which enforces the
`manage_options` capability and verifies the settings-group nonce
before any value is written. Each key is run through
`sanitize_text_field()` on the way in (`esc_url_raw()` for host URLs,
`absint()` for thresholds).

**The keys are not encrypted at rest.** They are stored as plaintext
option rows, which is the standard WordPress pattern — an encryption
key would itself have to live on the same filesystem, so it would add
obfuscation rather than a real security boundary. The practical
consequences:

- Anyone with database read access, or with `manage_options` on the
  site, can recover the keys.
- The settings page renders the current key into the field's `value`
  attribute (as `type="password"`, so it is masked visually but present
  in the page HTML).
- Keys are therefore only as protected as the WordPress admin account
  and the database behind it.

Merchants who need stronger guarantees should scope their provider keys
to the minimum permissions their provider offers, rotate them
periodically, or run the Ollama tier, which requires no third-party key
at all.

### Other controls

- All REST endpoints declare a `permission_callback` that checks
  `current_user_can( 'manage_options' )`.
- AJAX handlers verify the `aifd_settings_nonce` nonce **and** re-check
  `manage_options` before acting.
- All settings input is sanitized on save (see above).
- Pinecone stores behavior-pattern embeddings and signal metadata — not
  raw order records, names, addresses, or email addresses.

### Data protection

Tier 0 sends nothing off-site. Tiers 1 and 2 transmit the derived
pattern text and signal summary to the merchant's chosen provider only
once that provider's API key has been entered — an empty key field
means no external request is made. Because the transmitted pattern text
is a list of signal names (e.g. `disposable_email address_mismatch
no_phone_number`) plus country codes, it contains no personal data.

This is a design property, not a legal opinion: whether a given
deployment is GDPR-compliant depends on the merchant's own processing
and disclosures. Merchants remain the data controller. The Ollama tier
exists for deployments with strict data-sovereignty requirements.

---

## Design Choices Worth Calling Out

The decisions below are the ones that most shape how the plugin behaves,
and the reasoning behind each:

- **Local-first tiers.** Fraud scoring must never be the reason a
  checkout fails. Tier 0 is pure PHP and always available, so a provider
  outage degrades accuracy rather than breaking the store.
- **Provider-native embeddings.** Mixing embedding models within one
  vector space makes similarity scores meaningless, so each provider
  embeds and searches with its own model.
- **Namespaced vector store.** One namespace per merchant + provider
  keeps confirmed patterns and false-positive corrections scoped to the
  merchant who reported them.
- **Per-merchant weight learning.** A luxury jeweller and a $10 digital
  download shop have genuinely different fraud profiles; a single set of
  global weights cannot serve both.
- **REVIEW as the default middle outcome.** False positives cost
  merchants more than the fraud they prevent, so the mid-range decision
  holds an order for a human rather than blocking it outright.
- **Ollama support.** Gives merchants with data-sovereignty constraints
  a path that sends nothing to a third party.

---

