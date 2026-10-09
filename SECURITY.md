# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 1.0.x | ✅ |
| < 1.0 | ❌ |

## Reporting a vulnerability

Please **do not open a public GitHub issue** for security problems.

Report privately via [GitHub's private vulnerability reporting](https://github.com/dilrubaummer/hifqa-fraud-detector/security/advisories/new),
which opens a draft advisory visible only to the maintainers.

Please include:

- What the issue is and why it is exploitable
- The steps or proof-of-concept needed to reproduce it
- The plugin version, WordPress version, and PHP version you tested on
- Any suggested fix, if you have one

You can expect an acknowledgement within 5 working days and an
assessment within 14. If the report is confirmed, a fix will be released
and you will be credited in the changelog unless you would rather not be.

## Scope

In scope:

- The plugin's PHP source in `includes/`, `admin/`, and the main plugin file
- The REST and AJAX endpoints registered by the plugin
- The React admin dashboard in `src/`

Out of scope:

- Vulnerabilities in WordPress core, WooCommerce, or any third-party
  service the plugin talks to (OpenAI, Anthropic, Google, Voyage AI,
  Pinecone, Ollama) — report those to the relevant project
- Findings that require a `manage_options` administrator account to
  exploit. That capability already implies full control of the site
  (see the Security Considerations section of `ARCHITECTURE.md`)
- Reports that API keys are stored unencrypted in `wp_options`. This is a
  known and documented design decision, not an oversight — an encryption
  key stored on the same filesystem would be obfuscation rather than a
  security boundary

## Known design limitations

These are documented rather than hidden, and are not treated as
vulnerabilities:

- **API keys are stored in plaintext** in the `wp_options` table and are
  recoverable by anyone with database read access or a site administrator
  account.
- **The AI tier is advisory, not authoritative.** Its reply is validated
  and can never lower a local BLOCK, but it can lower a local REVIEW to
  ALLOW. Merchants who do not want a model to have that say should leave
  the AI tier off.

Merchants who need stronger guarantees should scope their provider keys
to the minimum permissions available, rotate them periodically, or use
the Ollama tier, which requires no third-party key at all.
