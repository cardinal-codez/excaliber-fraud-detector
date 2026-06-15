# AI Fraud Detector — Architecture Document

> Version: 3.0.0
> Last updated: June 2026
> Status: Active development

---

## Overview

AI Fraud Detector is a production-grade WordPress plugin that uses AI function calling, Pinecone vector search, and per-merchant learning to detect and prevent ecommerce fraud in real time.

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
| Google Gemini | gemini-1.5-flash | text-embedding-004 | 768 | Gemini only |
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

## AI Function Calling (Day 19)

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
└── Model: gemini-1.5-flash | gemini-1.5-pro

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
| AI scoring (Gemini) | gemini-1.5-flash (function declarations) |
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
ai-fraud-detector/
├── ai-fraud-detector.php              # Main plugin file
├── ARCHITECTURE.md                    # This document
├── ROADMAP.md                         # Development roadmap
├── package.json                       # Node dependencies
├── includes/
│   ├── fraud-detection.php            # Signal extraction + scoring
│   ├── rest-api.php                   # REST API + AJAX handlers
│   ├── admin-menu.php                 # Admin menu + React loader
│   ├── settings-page.php              # Settings page + registration
│   ├── class-embeddings.php           # Embedding abstraction layer
│   ├── class-pinecone.php             # Pinecone integration
│   ├── class-seed-loader.php          # Seed pattern loader
│   └── providers/
│       ├── class-provider-base.php    # Shared interface
│       ├── class-provider-openai.php  # OpenAI
│       ├── class-provider-anthropic.php # Anthropic
│       ├── class-provider-gemini.php  # Google Gemini
│       └── class-provider-ollama.php  # Local Ollama
├── admin/
│   └── dashboard.php                  # React mount point
├── src/
│   └── index.js                       # React dashboard
├── build/                             # Compiled React
└── data/
    └── seed-patterns.json             # 65 seed patterns
```

---

## Development Roadmap

### Phase 1 — Foundation (Complete ✅)
- [x] WooCommerce order capture
- [x] 17 fraud signal extraction
- [x] Weighted rule scoring
- [x] OpenAI basic scoring
- [x] Auto flag + email alerts
- [x] React dashboard
- [x] WordPress REST API
- [x] Multi-provider architecture
- [x] Settings page

### Phase 2 — Production AI (In Progress 🔥)
- [x] 65 seed patterns created
- [x] chargeback_history signal #17
- [x] Pinecone account + index setup
- [x] Initial seed load (OpenAI namespace)
- [ ] Embedding abstraction layer (Day 16)
- [ ] Re-seed per provider namespace (Day 17)
- [ ] Connect Pinecone to scoring pipeline (Day 18)
- [ ] Function calling replaces basic prompt (Day 19)
- [ ] Test function calling end to end (Day 20)
- [ ] Feedback loop UI (Day 21)
- [ ] Feedback loop saves to Pinecone (Day 22)
- [ ] Per-merchant weight personalization (Day 23)
- [ ] End to end testing (Day 24)
- [ ] Bug fixes (Day 25)
- [ ] README + ARCHITECTURE.md update (Day 26)
- [ ] GitHub cleanup, portfolio ready (Day 27)

### Phase 3 — Platform Expansion (Planned)
- [ ] EDD integration
- [ ] SureCart integration
- [ ] Shopify webhook + Python API on Azure
- [ ] WordPress.org submission

### Phase 4 — Growth (Future)
- [ ] Community pattern sharing (opt-in, anonymized)
- [ ] Analytics and reporting
- [ ] White label option
- [ ] SaaS pricing model

---

## Security Considerations

- All API keys stored in wp_options (encrypted)
- All REST API endpoints require manage_options capability
- Nonce verification on all admin actions
- Input sanitization on all user data
- No customer PII stored in Pinecone — patterns only
- GDPR compliant — embeddings contain no personal data
- Ollama option for strict data sovereignty requirements
- Voyage AI fallback to OpenAI if key not provided

---

## Competitive Advantages

| Feature | Our Plugin | Competitors |
|---------|-----------|-------------|
| Multi-provider AI | ✅ 4 providers | ❌ OpenAI only |
| Provider-native embeddings | ✅ Each provider uses own model | ❌ None |
| Namespaced vector store | ✅ Per merchant + provider | ❌ None |
| Local AI option (Ollama) | ✅ Free, private, no API key | ❌ None |
| Vector memory (Pinecone) | ✅ Learns from fraud history | ❌ None |
| Per-merchant learning | ✅ Weight personalization | ❌ None |
| Feedback loop | ✅ Confirm/Override | ❌ None |
| Seed patterns on install | ✅ 65 patterns day one | ❌ None |
| Function calling agent | ✅ Agentic AI | ❌ Basic prompts |
| Multi-platform | ✅ WooCommerce + EDD + SureCart + Shopify | ❌ WooCommerce only |

---

