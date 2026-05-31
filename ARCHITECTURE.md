# AI Fraud Detector — Architecture Document

> Version: 2.0.0
> Last updated: May 2026
> Status: Active development

---

## Overview

AI Fraud Detector is a production-grade WordPress plugin that uses AI function calling, Pinecone vector search, and per-merchant learning to detect and prevent ecommerce fraud in real time.

The plugin supports multiple AI providers — OpenAI, Anthropic, Google Gemini, and Ollama (local) — giving merchants full flexibility over their AI infrastructure.

---

## Core Architecture

```
New Order Placed
      ↓
Extract 16 weighted signals
      ↓
Calculate preliminary weighted score
      ↓
Embed behavior pattern (OpenAI text-embedding-3-small)
      ↓
Search Pinecone for similar confirmed patterns
      ↓
AI Provider Agent (function calling):
  ├── check_signal_weights(signals, merchant_weights)
  ├── search_fraud_patterns(embedding)
  ├── check_velocity(email, ip)
  └── calculate_final_risk(all_results)
      ↓
Decision: ALLOW / REVIEW / BLOCK
      ↓
REVIEW/BLOCK → merchant dashboard (React)
      ↓
Merchant: Confirm Fraud OR Override
      ↓
Confirm → embed pattern → store in Pinecone ✅
Override → record false positive
        → reduce signal weights for this merchant ✅
      ↓
System learns per merchant over time 🧠
```

---

## Multi-Provider AI Architecture

### Strategy Pattern

The plugin uses the Strategy Pattern to decouple fraud detection logic from the AI provider. Merchants choose their preferred provider from the settings page.

```php
// Provider abstraction layer
$provider = get_option('aifd_ai_provider', 'openai');
$result   = aifd_call_provider($provider, $prompt, $tools);

// Routes internally to:
switch ($provider) {
    case 'openai':    return aifd_call_openai($prompt, $tools);
    case 'anthropic': return aifd_call_anthropic($prompt, $tools);
    case 'gemini':    return aifd_call_gemini($prompt, $tools);
    case 'ollama':    return aifd_call_ollama($prompt);
}
```

### Supported Providers

| Provider | Model | Function Calling | Cost | Privacy |
|----------|-------|-----------------|------|---------|
| OpenAI | gpt-4o-mini | Full support | Low | Cloud |
| Anthropic | claude-haiku-4-5-20251001 | Full (tool use) | Low | Cloud |
| Google Gemini | gemini-1.5-flash | Full support | Very low | Cloud |
| Ollama | llama3, mistral etc | Prompt fallback | Free | Local |

### Provider File Structure

```
includes/
├── providers/
│   ├── class-provider-base.php      ← shared interface
│   ├── class-provider-openai.php    ← OpenAI implementation
│   ├── class-provider-anthropic.php ← Anthropic implementation
│   ├── class-provider-gemini.php    ← Google Gemini implementation
│   └── class-provider-ollama.php    ← Local Ollama implementation
```

### Provider Implementations

```php
// OpenAI
function aifd_call_openai($prompt, $tools) {
    // POST https://api.openai.com/v1/chat/completions
    // model: gpt-4o-mini
    // supports: full function calling via "tools"
}

// Anthropic (Claude)
function aifd_call_anthropic($prompt, $tools) {
    // POST https://api.anthropic.com/v1/messages
    // model: claude-haiku-4-5-20251001
    // supports: full tool use (same concept, different syntax)
}

// Google Gemini
function aifd_call_gemini($prompt, $tools) {
    // POST https://generativelanguage.googleapis.com/v1beta/models
    // model: gemini-1.5-flash
    // supports: function_declarations
}

// Ollama (local — FREE, no data leaves server)
function aifd_call_ollama($prompt) {
    // POST http://localhost:11434/api/chat
    // model: merchant configures (llama3, mistral, qwen etc)
    // no function calling — uses structured prompt fallback
    // no API key needed
    // complete privacy — all inference on merchant server
}
```

### Function Calling — Provider Differences

```
OpenAI    → "tools" parameter
Anthropic → "tools" parameter (identical concept)
Gemini    → "function_declarations" parameter
Ollama    → prompt-based JSON extraction (fallback)
```

All three cloud providers support function calling natively. Ollama uses a structured prompt that forces JSON output as a fallback.

---

## AI Function Calling — Tool Definitions

The AI agent autonomously decides which tools to call and in what order:

```javascript
tools: [
  {
    name: "check_signal_weights",
    description: "Evaluate weighted fraud signals for this order using merchant-specific weights",
    parameters: {
      signals: "object — 16 fraud signals extracted from order",
      merchant_weights: "object — personalized weights for this merchant"
    }
  },
  {
    name: "search_fraud_patterns",
    description: "Search Pinecone vector store for similar confirmed fraud patterns",
    parameters: {
      embedding: "array — behavior pattern embedding vector",
      threshold: "number — similarity threshold (default 0.85)"
    }
  },
  {
    name: "check_velocity",
    description: "Check order velocity for email and IP — detect card testing and rapid ordering",
    parameters: {
      email: "string — customer email",
      ip: "string — customer IP address",
      window_minutes: "number — time window to check (default 60)"
    }
  },
  {
    name: "calculate_final_risk",
    description: "Synthesize all signals, patterns, and velocity into final risk score and decision",
    parameters: {
      signal_score: "number — weighted signal score",
      pattern_matches: "array — similar patterns from Pinecone",
      velocity_score: "number — velocity check result"
    }
  }
]
```

---

## Signal Matrix — 16 Weighted Signals

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
| first_order_high_value | 0.15 | New customer, order > $500 |
| ip_proxy_vpn | 0.12 | Order placed via VPN or proxy |
| risky_country_combo | 0.12 | Known high-fraud billing/shipping pair |
| mismatched_names | 0.10 | Billing name ≠ shipping name |
| no_phone_number | 0.10 | No contact number provided |

### Tier 3 — Contextual signals (weak alone, strong combined)

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
raw_score = sum(signal_weight × signal_present)
            / sum(all_possible_weights) × 100

score is capped at 100
```

---

## Decision States

| Decision | Score Range | Action |
|----------|-------------|--------|
| ALLOW | 0 – 39 | Process order normally |
| REVIEW | 40 – 70 | Hold order, notify merchant, await decision |
| BLOCK | 71 – 100 | Hold order, alert merchant, send email |

REVIEW is the most valuable state — merchants trust a system that flags for review over one that blocks aggressively. False positives cost merchants more than fraud.

---

## Pinecone Vector Store

### What we store

We do NOT store raw order data. We store fraud behavior patterns.

```json
{
  "id": "pattern_uuid",
  "values": [0.123, 0.456, ...],
  "metadata": {
    "pattern": "disposable_email address_mismatch high_value no_phone new_customer",
    "fraud_type": "card_fraud",
    "confidence": 0.95,
    "times_seen": 1250,
    "source": "confirmed",
    "merchant_id": "global"
  }
}
```

### Embedding strategy

```
text = signal combination as natural language description
     = "disposable_email address_mismatch
        high_value_order no_phone
        new_customer GB_to_US cod_payment"

vector = openai.embeddings.create(
    input = text,
    model = "text-embedding-3-small"
)
```

Note: Embeddings always use OpenAI text-embedding-3-small regardless of chosen AI provider. This is intentional — embeddings need consistency across all stored patterns.

### Cold start solution

50 seed patterns pre-loaded at plugin install covering:
- Card testing fraud (10 patterns)
- Card fraud (10 patterns)
- Reshipping fraud (10 patterns)
- Chargeback fraud (10 patterns)
- Identity and account fraud (10 patterns)

Seed patterns are stored in `data/seed-patterns.json` and loaded into Pinecone on plugin activation.

---

## Feedback Loop

```
Order blocked or flagged for review
      ↓
Merchant sees order in React dashboard
      ↓
Two actions available:
├── Confirm Fraud
│     → embed behavior pattern
│     → store in Pinecone with confidence 1.0
│     → increment fraud counter
│     → update merchant stats
│
└── Override (not fraud)
      → record as false positive
      → do NOT store in Pinecone
      → trigger per-merchant weight adjustment
      → log for pattern analysis
```

---

## Per-Merchant Weight Personalization

This is what makes the plugin 10x more valuable than competitors.

### The problem

Global weights work for average stores. A luxury jewelry store has very different fraud patterns from a $10 digital download shop.

### The solution

Each merchant gets their own weight profile that adjusts automatically over time based on their confirmed overrides.

```
Override happens
      ↓
Which signals fired on this false positive?
      ↓
Reduce those signal weights by small delta
for this merchant only (never globally)
      ↓
Store as merchant_weight_profile in wp_options
      ↓
Next order uses personalized weights
      ↓
After 10+ overrides, weights are calibrated
to this merchant's specific business type
```

### Weight adjustment formula

```php
$delta = 0.02; // small reduction per override

foreach ($fired_signals as $signal) {
    $merchant_weights[$signal] = max(
        0.01, // floor — never go below this
        $merchant_weights[$signal] - $delta
    );
}

// Save personalized profile
update_option('aifd_merchant_weights', $merchant_weights);
```

### Why per-merchant not global

```
Global weight reduction = breaks detection for all merchants
Per-merchant reduction  = personalizes to your store only

Example:
Digital goods store     → odd_hours weight reduced
                          (customers buy at 3am legitimately)

Physical goods store    → odd_hours weight unchanged
                          (3am physical orders are suspicious)
```

### Weight profile storage

```php
// Stored per merchant in wp_options
// Starts as global defaults
// Adjusts over time based on overrides
$weight_profile = array(
    'odd_hours'        => 0.03, // reduced — ships globally
    'guest_checkout'   => 0.02, // reduced — most are guests
    'address_mismatch' => 0.20, // unchanged — still strong
    'disposable_email' => 0.25, // unchanged — always reliable
    // ...
);
```

---

## Platform Architecture

```
WooCommerce (WordPress Hook) ─────────┐
EDD (WordPress Hook) ─────────────────├──► AI Fraud Engine
SureCart (WordPress Hook) ────────────┤    (Core PHP Logic)
Shopify (Webhook → Python API) ───────┘    ├── Signal extraction
                                           ├── Provider routing
                                           ├── Pinecone search
                                           └── Weight personalization
```

WooCommerce, EDD, SureCart connect via WordPress hooks (same server).
Shopify connects via webhook to a separate Python/FastAPI service on Azure.

---

## Settings Page

```
AI Provider Settings
────────────────────
Provider: ○ OpenAI  ○ Anthropic  ○ Google Gemini  ○ Ollama

OpenAI Settings:
  API Key: [sk-proj-xxxxxxxxxxxxx    ]
  Model:   [gpt-4o-mini            ▼]

Anthropic Settings:
  API Key: [sk-ant-xxxxxxxxxxxxx    ]
  Model:   [claude-haiku-4-5-20251001 ▼]

Google Gemini Settings:
  API Key: [AIzaxxxxxxxxxxxxx       ]
  Model:   [gemini-1.5-flash       ▼]

Ollama Settings:
  Host:    [http://localhost:11434  ]
  Model:   [llama3                 ]
  Note: No API key needed. All inference stays on your server.

Pinecone Settings:
  API Key: [pc-xxxxxxxxxxxxx        ]
  Index:   [fraud-patterns          ]

Risk Settings:
  Block threshold:  [70] (orders above this score are blocked)
  Review threshold: [40] (orders above this score need review)

[ Test Connection ]  [ Save Settings ]
```

---

## Technology Stack

| Layer | Technology |
|-------|------------|
| WordPress plugin | PHP 8.0+ |
| Frontend dashboard | React + @wordpress/scripts |
| REST API | WordPress REST API |
| AI scoring (default) | OpenAI GPT-4o-mini (function calling) |
| AI scoring (alt) | Anthropic claude-haiku-4-5-20251001 |
| AI scoring (alt) | Google Gemini 1.5 Flash |
| AI scoring (local) | Ollama (any model, free, private) |
| Embeddings | OpenAI text-embedding-3-small |
| Vector store | Pinecone (serverless) |
| Future: Shopify API | Python + FastAPI + Azure |

---

## Full File Structure

```
ai-fraud-detector/
├── ai-fraud-detector.php              # Main plugin file, constants, requires
├── ARCHITECTURE.md                    # This document
├── package.json                       # Node dependencies
├── includes/
│   ├── fraud-detection.php            # Signal extraction + scoring
│   ├── rest-api.php                   # REST API endpoints
│   ├── admin-menu.php                 # Admin menu + React loader
│   └── providers/
│       ├── class-provider-base.php    # Shared provider interface
│       ├── class-provider-openai.php  # OpenAI implementation
│       ├── class-provider-anthropic.php # Anthropic implementation
│       ├── class-provider-gemini.php  # Google Gemini implementation
│       └── class-provider-ollama.php  # Local Ollama implementation
├── admin/
│   └── dashboard.php                  # React mount point
├── src/
│   └── index.js                       # React dashboard
├── build/                             # Compiled React (committed)
│   ├── index.js
│   └── index.asset.php
└── data/
    └── seed-patterns.json             # 50 seed fraud patterns
```

---

## Development Roadmap

### Phase 1 — Core plugin (complete ✅)
- [x] WooCommerce order capture via hook
- [x] 16 fraud signal extraction
- [x] Rule-based scoring engine
- [x] OpenAI basic scoring
- [x] Auto flag + email alerts
- [x] React dashboard
- [x] WordPress REST API
- [x] Order meta storage
- [x] Stats tracking

### Phase 2 — Production AI (current)
- [ ] Weighted scoring matrix (proper weights)
- [ ] Multi-provider architecture (OpenAI/Anthropic/Gemini/Ollama)
- [ ] Settings page with provider selection
- [ ] Pinecone setup + seed pattern loading
- [ ] Feedback loop (confirm fraud / override)
- [ ] Per-merchant weight personalization
- [ ] OpenAI/Anthropic function calling
- [ ] End-to-end testing

### Phase 3 — Platform expansion
- [ ] EDD integration
- [ ] SureCart integration
- [ ] Shopify webhook + Python API on Azure
- [ ] WordPress.org submission

### Phase 4 — Growth
- [ ] Community pattern sharing (opt-in, anonymized)
- [ ] Analytics and reporting dashboard
- [ ] White label option
- [ ] SaaS pricing model

---

## Security Considerations

- All API keys stored encrypted in wp_options
- All REST API endpoints require manage_options capability
- Nonce verification on all admin actions
- Input sanitization on all user data (sanitize_text_field)
- No customer PII stored in Pinecone — behavior patterns only
- GDPR compliant — pattern embeddings contain no personal data
- Ollama option for merchants with strict data sovereignty requirements

---

## Interview Talking Points

When asked about this system in Qatar AI Engineer interviews:

> "I built a production-grade fraud detection system for WooCommerce using a provider-agnostic AI architecture. Merchants choose between OpenAI, Anthropic, Google Gemini, or run completely locally with Ollama for privacy-sensitive deployments. The AI agent uses function calling to autonomously decide which tools to invoke — signal analysis, Pinecone vector similarity search, and velocity checking — before synthesizing a final risk decision. The system includes a merchant feedback loop where confirmed fraud patterns are stored as embeddings in Pinecone, and false positives trigger per-merchant weight adjustment using a delta-based personalization algorithm. This means the system calibrates itself to each store's specific fraud patterns over time."

---

## Competitive Advantages

| Feature | Our plugin | Competitors |
|---------|-----------|-------------|
| Multi-provider AI | ✅ 4 providers | ❌ OpenAI only |
| Local AI option | ✅ Ollama | ❌ None |
| Vector memory | ✅ Pinecone | ❌ None |
| Per-merchant learning | ✅ Yes | ❌ None |
| Feedback loop | ✅ Confirm/Override | ❌ None |
| Seed patterns | ✅ 50 on install | ❌ None |
| Function calling | ✅ Agentic AI | ❌ Basic prompts |
| WooCommerce + EDD + SureCart + Shopify | ✅ All platforms | ❌ WooCommerce only |