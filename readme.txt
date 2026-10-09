=== HIFQA Fraud Detector for WooCommerce ===
Contributors: dilrubaummer
Tags: fraud, woocommerce, security, ai, vector search
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real-time fraud detection for WooCommerce. Local signal scoring works out of the box; AI and vector search layers are optional.

== Description ==

HIFQA Fraud Detector scores every WooCommerce order against 17 weighted
fraud signals (disposable emails, address mismatches, card testing
patterns, and more) the moment it is placed. The local scoring engine
is pure PHP, requires no setup, and sends no data anywhere — it works
immediately after activation.

Each order gets a score from 0 to 100 and one of three decisions:

* **Allow** — the order continues normally.
* **Review** — the order continues, a note is added and the store admin is emailed.
* **Block** — the order is put on hold and the store admin is emailed. It stays on hold until you release it.

For stores that want deeper protection, two optional layers can be
enabled separately — each stays off until you enter that service's
API key in Settings:

* **Vector pattern memory** — compares the order's pattern of signals
  with known fraud patterns stored in your own Pinecone index
  (requires a Pinecone account and an embedding provider).
* **AI reasoning layer** — asks OpenAI, Anthropic, Google Gemini, or
  an Ollama server you run yourself for a second opinion and a
  plain-English explanation (requires an API key from your chosen
  provider, or an Ollama host).

= How the AI layer is kept in check =

The local score is the baseline, and an AI reply is treated as
untrusted input:

* An order the local rules **block** stays blocked. The AI layer can
  add its explanation but cannot lower the decision or the score. Only
  you can release a blocked order.
* The AI layer can raise a decision (for example Review to Block). It
  can also lower a local Review to Allow — if you never want that,
  leave the AI layer off.
* A reply with a missing or unexpected decision, or a score that is
  not a number, counts as a failed call. Scores are limited to 0–100
  and the decision is re-derived from the score using your thresholds.
* A failed AI call never marks an order as passed. The order keeps its
  local result and the order note says the AI layer was unavailable.

= What the optional layers cost you at checkout =

The optional layers contact the external service **while the order is
being placed**, so checkout waits for the reply. A slow provider means
a slower checkout, and the AI layer can make several requests for one
order. If a service fails or times out, the order is scored by the
local rules alone. After three failures in a row a layer pauses itself
for ten minutes. With both layers off, no external request is made at
all.

= Compatibility =

* WooCommerce High-Performance Order Storage (HPOS).
* Both the classic checkout and the block-based checkout.

= Source code =

The admin dashboard is built from the unminified source in the `src/`
folder of this plugin. The full development repository, including the
build tooling, is at
https://github.com/dilrubaummer/hifqa-fraud-detector

== External services ==

HIFQA Fraud Detector can connect to the services below. Every one of
them is **off by default**. Nothing is sent until you save that
service's API key (or, for Ollama, its host URL) in
HIFQA → Settings, and removing the key turns the layer off again. If
no optional layer is configured, no order data leaves your site.

Customer names, full email addresses, IP addresses, phone numbers,
street addresses and order items are never sent to any of these
services.

**What "order data" means below.** When the AI reasoning layer is on,
the following is sent to the AI provider you selected, once for each
new order:

* the order total
* the domain of the billing email address (for example `example.com` —
  never the address itself)
* the billing country and shipping country codes
* the payment method ID (for example `stripe` or `cod`)
* how many earlier orders used the same billing email (counted up to 20)
* your store's average order value
* which of the fraud signals fired for the order (yes/no values such
  as "disposable email", "billing and shipping country differ", "no
  phone number provided", "guest checkout")

If vector memory is also on, the closest matches found in your
Pinecone index are included (pattern type, similarity and the stored
description of the pattern).

With OpenAI and Anthropic, the plugin also sends the results of its
own checks when the model asks for them: the local risk score with the
reasons behind it, and how many recent orders came from the same IP
address and from the same email (the numbers only — not the IP address
or the email).

**What "pattern text" means below.** When the vector memory layer is
on, the order is reduced to a short line of text made of the names of
the signals that fired, the payment method ID and the country pair —
for example `disposable_email address_mismatch cod_payment NG_to_US`.

= OpenAI =

Used for AI reasoning when OpenAI is the selected provider, and to
turn pattern text into an embedding when vector memory is on with
OpenAI selected.

* Data sent: order data (AI reasoning); pattern text (vector memory).
* When: each time a new order is placed, and when you load seed
  patterns or confirm an order as fraud.
* Endpoint: api.openai.com
* [Terms of Service](https://openai.com/policies/services-agreement/)
* [Privacy Policy](https://openai.com/policies/privacy-policy)

= Anthropic =

Used for AI reasoning when Anthropic (Claude) is the selected provider.

* Data sent: order data.
* When: each time a new order is placed.
* Endpoint: api.anthropic.com
* [Terms of Service](https://www.anthropic.com/legal/commercial-terms)
* [Privacy Policy](https://www.anthropic.com/legal/privacy)

= Google Gemini =

Used for AI reasoning when Google Gemini is the selected provider, and
to turn pattern text into an embedding when vector memory is on with
Gemini selected.

* Data sent: order data (AI reasoning); pattern text (vector memory).
* When: each time a new order is placed, and when you load seed
  patterns or confirm an order as fraud.
* Endpoint: generativelanguage.googleapis.com
* [Terms of Service](https://ai.google.dev/gemini-api/terms)
* [Privacy Policy](https://policies.google.com/privacy)

= Voyage AI =

Anthropic does not offer an embedding model, so vector memory with
Anthropic selected uses Voyage AI and needs its own Voyage AI key.
Without that key, vector memory stays off for Anthropic — the pattern
text is not sent to any other provider in its place.

* Data sent: pattern text.
* When: each time a new order is placed, and when you load seed
  patterns or confirm an order as fraud.
* Endpoint: api.voyageai.com
* [Terms of Service](https://www.voyageai.com/tos)
* [Privacy Policy](https://www.voyageai.com/privacy)

= Pinecone =

Stores and searches fraud patterns for the vector memory layer, in an
index in your own Pinecone account.

* Data sent: the embedding (a list of numbers) of the pattern text,
  and a namespace made of your site's numeric ID and the provider
  name. Stored patterns also carry the pattern text itself, a pattern
  type, a source label, a short fixed description, a confidence value
  and a counter. When you confirm an order as fraud, its pattern is
  stored the same way: no order number, risk score, customer detail or
  AI-written text is included, and the record's ID is a keyed hash,
  not the order number.
* When: a search each time a new order is placed; a write when you
  load seed patterns or confirm an order as fraud.
* Endpoint: the index host URL you enter (HTTPS only, normally
  `*.pinecone.io`)
* [Terms of Service](https://www.pinecone.io/terms/)
* [Privacy Policy](https://www.pinecone.io/privacy/)

= Ollama =

Ollama is software you run on your own server. When Ollama is the
selected provider, the plugin sends requests to the host URL you enter
in Settings. Nothing is sent to ollama.com by this plugin. The data
stays on your own infrastructure only if that host is a server you
control.

* Data sent to your Ollama host: order data (AI reasoning); pattern
  text (vector memory).
* When: each time a new order is placed, and when you load seed
  patterns or confirm an order as fraud.
* [Terms of Service](https://ollama.com/terms)
* [Privacy Policy](https://ollama.com/privacy)

== Installation ==

1. Upload and activate the plugin. WooCommerce must be active.
2. Local fraud scoring is active immediately — no setup required.
3. (Optional) Go to HIFQA → Settings to enable Vector Memory or AI
   Reasoning. Each activates only once you save its API key; leave the
   key field blank to keep the layer off.

== Frequently Asked Questions ==

= Does this work without any API keys? =
Yes. Local signal scoring runs on every order automatically with no
external service required.

= What happens if my AI provider is slow or stops working? =
The optional layers are called while the order is being placed, so a
slow provider makes checkout slower for that order. If the provider
fails or times out, the order is scored by the local rules instead and
the order note says so. After three failures in a row the layer is
paused for ten minutes, and orders during that time are scored locally
without waiting. A provider outage never causes an order to be marked
as passed by the AI layer.

= Can the AI layer approve an order that the local rules blocked? =
No. A local block stays a block. Only you can release it, from the
HIFQA dashboard.

= Is customer data shared with anyone by default? =
No. Out of the box, all scoring runs locally on your own server and no
order data leaves your site. Data is sent to a third party only after
you save that service's API key in Settings. See the External services
section for exactly what is sent to each one.

= Where are my API keys stored, and who can see them? =
Keys are stored in your site's database as plugin options. Once saved,
a key is never shown again in the Settings page — the field stays
empty. Leave it empty to keep the saved key, type a new key to replace
it, or tick "Remove the saved key" to delete it.

= I use Anthropic. Why is vector memory not running? =
Anthropic has no embedding model. Add a Voyage AI key in Settings to
use vector memory with Anthropic. The plugin will not send your data
to a different provider instead.

= What is removed when I delete the plugin? =
Deleting the plugin removes its settings, saved API keys, learned
signal weights and counters. Your orders are not touched, and the
fraud result saved on each order (score, decision, reason) stays with
the order as part of its history. Patterns stored in your Pinecone
index stay in your Pinecone account until you delete them there.

== Screenshots ==

1. Fraud dashboard — every flagged order with its risk score, decision, country route, and one-click Confirm Fraud / Not Fraud feedback buttons.
2. Signal Weight Personalization — the 17 signal weights adapt automatically when you override false positives, so the system learns your store's patterns.
3. Settings — choose your AI provider (OpenAI, Anthropic, Google Gemini, or self-hosted Ollama) and configure each optional layer.

== Changelog ==

= 1.0.0 =
* Initial release — local-first scoring with optional AI and vector memory layers.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
