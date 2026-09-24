# DISHAM — AI Fraud Detector

> Production-grade AI-powered fraud detection plugin 
> for WooCommerce using OpenAI function calling, 
> Pinecone vector search, and per-merchant learning.

---

## What It Does

DISHAM analyzes every WooCommerce order 
in real-time using an autonomous AI agent. 
The agent evaluates 17 weighted fraud signals, 
searches a vector database of known fraud patterns, 
checks order velocity, and synthesizes a final 
risk score with plain-English reasoning.

Orders are automatically:
- ✅ **Allowed** (score 0–39) — processed normally
- ⚠️ **Flagged for review** (score 40–70) — merchant notified
- 🚫 **Blocked** (score 71–100) — held + email alert sent

---

## Local-First by Design

Fraud scoring should never be the reason a checkout fails. The plugin
runs in three capability tiers, and the bottom one needs nothing at all:

| Tier | What it does | Default | Requires |
| --- | --- | --- | --- |
| **0 — Local** | 17 weighted signals, pure PHP | Always on | Nothing |
| **1 — Vector Memory** | Pinecone similarity search | Off | Pinecone key + host |
| **2 — AI Reasoning** | Function-calling agent | Off | Provider API key |

Every external call is wrapped in a circuit breaker: after three
consecutive failures a tier disables itself for ten minutes and the
plugin falls back to the tier below. An OpenAI outage costs you accuracy,
not orders.

Tiers 1 and 2 are activated by entering an API key, and deactivated by
clearing it. **On a fresh install with empty key fields, no order data
leaves your site.**

---

## Key Features

**🤖 Agentic AI (Function Calling)**
Uses OpenAI/Anthropic function calling — the AI 
autonomously decides which tools to invoke:
`check_signal_weights` → `search_fraud_patterns` 
→ `check_velocity` → `calculate_final_risk`

**🧠 Vector Memory (Pinecone)**
65 seed fraud patterns pre-loaded at install.
Merchant-confirmed fraud patterns stored as 
embeddings — system gets smarter over time.

**⚖️ Per-Merchant Learning**
Each false positive override reduces the weight 
of triggered signals for that merchant only. 
Weights personalize automatically over time.

**🔌 Multi-Provider AI**
Choose your AI provider:
- OpenAI (gpt-4o-mini) — default
- Anthropic (claude-haiku-4-5-20251001)
- Google Gemini (gemini-2.5-flash)
- Ollama (local, free, private) ⚠️ VPS only

**📊 Provider-Native Embeddings**
Each provider uses its own embedding model with 
separate Pinecone namespaces — no cross-provider 
contamination:
- OpenAI → text-embedding-3-small (1536d)
- Anthropic → voyage-3 via Voyage AI (1024d)
- Gemini → text-embedding-004 (768d)
- Ollama → nomic-embed-text (768d)

**🔄 Feedback Loop**
- Confirm Fraud → saves pattern to Pinecone (confidence: 1.0)
- Override (Not Fraud) → adjusts merchant signal weights

**⚛️ React Dashboard**
Modern React dashboard built with @wordpress/scripts:
- Real-time stats (blocked, review, prevented)
- Flagged orders with AI reasoning
- Signal weight visualization per tier
- Feedback buttons per order

---

## Architecture
New Order

↓

17 weighted signals extracted

↓

Embed behavior pattern (provider-native model)

↓

Search Pinecone namespace (merchant + provider)

↓

AI Function Calling Agent:

├── check_signal_weights()

├── search_fraud_patterns()

├── check_velocity()

└── calculate_final_risk()

↓

ALLOW / REVIEW / BLOCK

↓

Merchant feedback → Pinecone learns

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Plugin | PHP 8.0+ |
| Frontend | React + @wordpress/scripts |
| REST API | WordPress REST API |
| AI Scoring | OpenAI / Anthropic / Gemini / Ollama |
| Embeddings | Provider-native models |
| Vector Store | Pinecone (serverless, namespaced) |
| Design Pattern | Strategy Pattern (provider abstraction) |

---

## Signal Matrix

### Tier 1 — Critical
| Signal | Weight |
|--------|--------|
| Card Testing Pattern | 0.30 |
| Disposable Email | 0.25 |
| High Velocity | 0.25 |
| Address Mismatch | 0.20 |
| Freight Forwarder | 0.20 |

### Tier 2 — Strong
| Signal | Weight |
|--------|--------|
| Chargeback History | 0.22 |
| First Order High Value | 0.15 |
| IP Proxy / VPN | 0.12 |
| Risky Country Combo | 0.12 |
| Mismatched Names | 0.10 |
| No Phone Number | 0.10 |

### Tier 3 — Contextual
| Signal | Weight |
|--------|--------|
| Payment Method Risk | 0.08 |
| Multiple Addresses | 0.08 |
| Order Total Anomaly | 0.07 |
| Odd Hours | 0.05 |
| Guest Checkout | 0.05 |
| New Account | 0.05 |

---

## Installation

1. Upload the plugin to `/wp-content/plugins/`
2. Activate it in WordPress admin
3. Done — every new order is now scored by the local signal engine.

No API key, no account, and no configuration are required to get
working fraud detection. The AI and vector layers are opt-in:

**To enable Vector Memory (optional)**
Go to **Fraud Detector → Settings**, enter a Pinecone API key + host
URL, then click **Load 65 Seed Patterns**.

**To enable AI Reasoning (optional)**
On the same page, choose a provider and enter its API key — or point
the plugin at a local Ollama host and pay nothing.

Clearing a key field turns that layer back off.

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+

That's the whole list. API keys are only needed for the optional layers:

| To use | You need |
| --- | --- |
| Local signal scoring (Tier 0) | Nothing — works on activation |
| Vector memory (Tier 1) | A Pinecone account (free starter plan works) |
| AI reasoning (Tier 2) | An OpenAI, Anthropic, or Gemini key — or a local Ollama host (free) |

Anthropic users also need a Voyage AI key for embeddings, since
Anthropic does not offer an embedding model of its own.

---

## Running Tests

The suite uses the WordPress core test library with WooCommerce loaded. It
needs an **empty** MySQL database — every table in it is dropped on each run.

```bash
composer install
mysql -uroot -proot -e "CREATE DATABASE disham_tests"
composer test
```

Defaults in `tests/wp-tests-config.php` target a MAMP setup (WordPress three
folders up, MAMP's MySQL socket). Override with `WP_CORE_DIR`,
`WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASS`, `WP_TESTS_DB_HOST`
or `WC_DIR`. All outgoing HTTP is faked, so no API keys are used.

---

## Roadmap

- [x] WooCommerce integration
- [x] 17 fraud signal extraction
- [x] Multi-provider AI (OpenAI / Anthropic / Gemini / Ollama)
- [x] Provider-native embeddings
- [x] Pinecone vector store (namespaced)
- [x] Function calling agent
- [x] React dashboard
- [x] Feedback loop
- [x] Per-merchant weight personalization
- [x] Local-first capability tiers + circuit breakers
- [ ] Unit tests for signal extraction and risk scoring
- [ ] WordPress.org submission
- [ ] Easy Digital Downloads (EDD)
- [ ] SureCart
- [ ] Shopify (Python + FastAPI + Azure)

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full design document.

---

## Security

API keys are stored as standard WordPress options, in plaintext — the
same as most WordPress plugins. See the Security Considerations section
of [ARCHITECTURE.md](ARCHITECTURE.md#security-considerations) for what
that does and does not protect against.

Found a vulnerability? Please see [SECURITY.md](SECURITY.md) — do not
open a public issue.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---