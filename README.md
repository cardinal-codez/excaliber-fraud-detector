# AI Fraud Detector for WooCommerce

> Production-grade AI-powered fraud detection plugin 
> for WooCommerce using OpenAI function calling, 
> Pinecone vector search, and per-merchant learning.

---

## What It Does

AI Fraud Detector analyzes every WooCommerce order 
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
- Google Gemini (gemini-1.5-flash)
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

1. Upload plugin to `/wp-content/plugins/`
2. Activate in WordPress admin
3. Go to **Fraud Detector → Settings**
4. Enter your AI provider API key
5. Enter Pinecone API key + host URL
6. Click **Load 65 Seed Patterns**
7. Done — every new order is now protected!

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+
- OpenAI API key (required for embeddings)
- Pinecone account (free starter plan works)

---

## Roadmap

- [x] WooCommerce integration
- [x] 17 fraud signal extraction
- [x] Multi-provider AI (OpenAI/Anthropic/Gemini/Ollama)
- [x] Provider-native embeddings
- [x] Pinecone vector store (namespaced)
- [x] Function calling agent
- [x] React dashboard
- [x] Feedback loop
- [x] Per-merchant weight personalization
- [ ] Easy Digital Downloads (EDD)
- [ ] SureCart
- [ ] Shopify (Python + FastAPI + Azure)
- [ ] WordPress.org submission

---

## License

GPL v2 or later

---