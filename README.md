# ALIFY AI Connector v0.32.0

## What changed in v0.32.0

- Added ACF Options Page discovery, read and write tools for global/archive/theme settings.
- Option writes are restricted to fields actually assigned to the selected Options Page, including nested group/repeater payloads.
- ACF option writes are verification-backed, audited and rollback-capable with unknown-state reporting when compensation cannot be verified.
- Capability responses now include actual runtime visibility flags for `code_read`, `code_write`, ACF Options read, and ACF Options write tools for the current OAuth/PAT credential.
- Existing source-code write remains approval-gated and available only when `code_write` is effectively granted.
- Runtime phase is 32 and MCP tool inventory is expanded to 158 tools.

## What changed in v0.28.0

- Added native support for the current MCP `2026-07-28` stateless discovery flow used by modern ChatGPT action scanning.
- Added `server/discover` with deterministic server capabilities and supported protocol versions.
- `tools/list` now returns modern list metadata and only exposes actions the authenticated OAuth credential can actually use.
- ACF, Elementor, and WooCommerce actions are dependency-aware during discovery, preventing inactive integrations from poisoning the action scan.
- Tool input schemas are normalized to a conservative JSON Schema subset for ChatGPT discovery; runtime REST validation remains authoritative and unchanged.
- Legacy `initialize` clients remain supported.

## What changed in v0.27.0

- Fixed ChatGPT OAuth `invalid_scope` failures when the client requested a known connector scope that was currently disabled.
- OAuth discovery advertises only permissions enabled by the WordPress administrator, plus `offline_access` for refresh-token continuity.
- Known-but-disabled scopes are narrowed out instead of aborting OAuth; unknown scopes still fail.
- OAuth grants are narrowed to the signed-in WordPress user's capabilities and revalidated at token issuance.

## What changed in v0.26.0

- Added verified compensation for Gutenberg document and pattern audit-log failures; unverified restoration now returns `alify_ai_gutenberg_unknown_state`.
- Hardened SEO apply/rollback verification so failed metadata compensation returns `alify_ai_seo_unknown_state` rather than implying a clean revert.
- Hardened CPT, taxonomy, and term rollback compensation; failed structure restoration now reports `alify_ai_structure_unknown_state` and diagnostics.
- Media file rollback followed by audit-log failure is now treated as an unknown/audit-gap state rather than a normal clean rollback failure.
- Added phase 26 release-audit invariants and regression coverage.

## What changed in v0.19.0

- Preferred per-user bearer credentials with expiry, revocation and one-time secret display.
- Token scopes are additionally constrained by global connector permissions and native WordPress user capabilities.
- Header-based MCP endpoint: `/wp-json/alify-ai/v1/mcp` with `Authorization: Bearer <token>`.
- Existing private MCP URL and `X-ALIFY-Key` REST access remain compatible.
- Audit rows now persist actor/auth identity metadata.
- Admin Settings now includes bearer-token lifecycle controls.
- v0.19 is an OAuth-ready authorization foundation; it does **not** claim a complete OAuth 2.1 Authorization Code + PKCE server yet.

## What changed in v0.18.0

This release focuses on stability: redacted runtime diagnostics, explicit `failed_unknown_state` approval handling for interrupted writes, actual-body MCP request-size validation, administrator-controlled uninstall cleanup, and a WordPress PHPUnit regression-test scaffold.

The v0.17 SEO module remains implemented behind its own default-OFF `seo` permission. ChatGPT can detect Yoast SEO or Rank Math, fall back to ALIFY's generic SEO output when neither is active, read normalized SEO metadata, audit content, and preview/apply title, description, canonical, robots, social-card and supported schema changes with stale-write protection and rollback.

ALIFY AI Connector turns a WordPress site into a scoped MCP server that can be connected to ChatGPT with a single private endpoint URL.

## Quick setup

1. Upload and activate the plugin ZIP in WordPress.
2. Open **ALIFY AI** in WordPress Admin.
3. Review **Permissions** and keep only the capabilities you want ChatGPT to use.
4. Copy the **Private MCP endpoint** from **ALIFY AI → Connection**.
5. In ChatGPT, add/create an MCP app or custom connection and paste the endpoint as the MCP server URL.
6. Connect and ask: `Show me my WordPress site info and capabilities.`

Example private URL:

```text
https://example.com/wp-json/alify-ai/v1/mcp/<random-private-token>
```

The private endpoint itself is a credential. Treat it like a password. If it is exposed, regenerate it in WordPress immediately.

## Permissions

- `read` — site/content discovery and audit reads.
- `content` — create/update pages, posts, and allowed CPT entries.
- `structure` — create/update/delete ALIFY-managed CPTs and manage taxonomies/terms.
- `acf` — full planned ACF group/field lifecycle, nested Group/Repeater fields, conditional logic, and value updates.
- `media` — upload, update, replace, process, regenerate, trash/restore and explicitly confirmed delete Media Library attachments.
- `menus` — full classic navigation-menu lifecycle: create/update/delete menus and items, locations, hierarchy, bulk reorder, auto-add, and rollback.
- `design` — validated Gutenberg documents/patterns/templates and Elementor documents, responsive widget controls, local templates/global widgets, and active Kit global styles.
- `commerce` — full planned WooCommerce catalog lifecycle for products, attributes, variations, shipping/tax classes and downloads.
- `orders` — sensitive WooCommerce order/customer reads plus approval-gated order creation, status/address/customer-note updates, product line-item changes and order notes; payments/refunds/gateway actions stay disabled.
- `seo` — normalized Generic/Yoast/Rank Math SEO reads, audits, and approval-gated metadata/social/schema changes.
- `transactions` — coordinated multi-action proposals with preflight and compensation rollback.

## MCP methods

The private endpoint accepts HTTP POST JSON-RPC 2.0 calls for:

```text
server/discover   (MCP 2026-07-28)
initialize        (legacy compatibility)
ping
tools/list
tools/call
```

MCP notifications are accepted with HTTP 202.

## Safety boundaries

The connector intentionally does not expose arbitrary PHP execution, direct SQL, WordPress password management, plugin/theme installation or deletion, arbitrary remote media fetching, WooCommerce payment capture/refunds/payment-token changes/gateway capture-void, or permanent deletion of existing orders. Permanent page/post deletion is supported only with explicit force + confirmation and is irreversible.

Audit/approval payload limits remain enforced. CPT registrations are audit-backed; deleting a CPT registration with existing content requires explicit force + orphan confirmation and does not delete the underlying content rows.

## Requirements

- WordPress 6.4+
- PHP 8.0+
- Public HTTPS site for remote ChatGPT connectivity
- WordPress REST API access must not be blocked by a firewall/security plugin

## Known limitations

- The private MCP URL is still the connection credential; full OAuth 2.1/per-user authorization is not implemented yet.
- MCP media upload uses base64 JSON and is intentionally capped at 8 MiB raw file size; larger files should use the REST multipart endpoint.
- File-changing media operations require locally available WordPress uploads; remote/offloaded-only attachments are refused rather than modified unsafely.

## Existing REST API

The REST/OpenAPI interface remains available. Generate the optional legacy API key under **ALIFY AI → Settings** if another client specifically needs `X-ALIFY-Key` authentication.