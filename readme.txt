=== Hifqa – Fraud Detector for WooCommerce===
Contributors: dilrubaummer
Tags: fraud, woocommerce, security, ai, vector search
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real-time fraud detection for WooCommerce. Works instantly with local
signal scoring — optional AI and vector search layers available.

== Description ==

Hifqa scores every WooCommerce order against 17 weighted
fraud signals (disposable emails, address mismatches, card testing
patterns, and more) the moment it's placed. The local scoring engine
is pure PHP, requires no setup, and sends no data anywhere — it works
immediately after activation.

For stores that want deeper protection, two optional layers can be
enabled separately — each stays off until you enter that service's
API key in Settings:

* **Vector pattern memory** — searches a database of known fraud
  patterns for similarity matches (requires Pinecone account)
* **AI reasoning layer** — generates plain-English explanations using
  OpenAI, Anthropic, or Google Gemini (requires an API key from your
  chosen provider)

= Third-Party Services & Data Disclosure =

This plugin can optionally connect to the following services. Each
is **off by default** — no data is sent until you enter that
service's API key in Settings, and clearing the key turns the
layer off again.

API endpoints contacted (only when the matching key is configured):
api.openai.com, api.anthropic.com, generativelanguage.googleapis.com,
api.voyageai.com, and your own Pinecone index host (*.pinecone.io).

**Vector Memory (optional)**
Sends signal pattern text (e.g. "disposable_email address_mismatch")
to your chosen embedding provider, then to Pinecone for storage and
search. No customer name, email, or address text is included.
[OpenAI Privacy Policy](https://openai.com/policies/privacy-policy)
[Pinecone Privacy Policy](https://www.pinecone.io/privacy/)
[Voyage AI Privacy Policy](https://www.voyageai.com/privacy)

**AI Reasoning (optional)**
Sends customer email, IP address, billing/shipping country, order
total, and payment method to your chosen AI provider to generate a
risk explanation.
[OpenAI Privacy Policy](https://openai.com/policies/privacy-policy)
[Anthropic Privacy Policy](https://www.anthropic.com/legal/privacy)
[Google Privacy Policy](https://policies.google.com/privacy)

If neither optional layer is enabled, no order data leaves your site.

== Installation ==

1. Upload and activate the plugin.
2. Local fraud scoring is active immediately — no setup required.
3. (Optional) Go to Fraud Detector → Settings to enable Vector Memory
   or AI Reasoning. Each activates only once you enter its API key;
   leave the key blank to keep the layer off.

== Frequently Asked Questions ==

= Does this work without any API keys? =
Yes. Local signal scoring runs on every order automatically with no
external service required.

= What happens if my OpenAI key stops working? =
The plugin automatically falls back to local scoring. Orders are
never blocked or delayed by a third-party outage.

= Is customer data shared with anyone by default? =
No. Out of the box, all scoring runs locally on your own server and no
order data leaves your site. Data is sent to a third party only after you
enter that service's API key in Settings to activate an optional layer —
the Vector Memory layer (Pinecone) or the AI Reasoning layer (OpenAI,
Anthropic, or Google Gemini). Leave those key fields blank and nothing is
shared; clearing a key turns that layer off again. The Ollama reasoning
option runs locally and shares nothing.

== Screenshots ==

1. Fraud dashboard — every flagged order with its AI risk score, decision, country route, and one-click Confirm Fraud / Not Fraud feedback buttons.
2. Signal Weight Personalization — the 17 signal weights adapt automatically when you override false positives, so the system learns your store's patterns.
3. Settings — choose your AI provider (OpenAI, Anthropic, Google Gemini, or local Ollama) and configure each layer with a single API key.

== Changelog ==

= 1.0.0 =
* Initial release — local-first scoring with optional AI/vector tiers

== Upgrade Notice ==

= 1.0.0 =
Initial release.