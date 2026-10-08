=== ALIFY AI Connector ===
Contributors: alify
Tags: ai, rest-api, chatgpt, automation, seo, yoast, rank-math, gutenberg, elementor, acf, woocommerce
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 0.32.0
License: GPLv2 or later

Connect ChatGPT directly to WordPress through a private MCP endpoint with scoped permissions, audited previews, approvals and rollback.

== Description ==

ALIFY AI Connector embeds an MCP server directly inside WordPress. No separate Node.js bridge is required.

* One private MCP endpoint generated in WordPress Admin > ALIFY AI > Connection.
* Copy the endpoint and paste it into a ChatGPT MCP app/custom connection.
* Embedded MCP tools for content, CPTs, taxonomies, ACF, full Media Library lifecycle, menus, Gutenberg, Elementor, WooCommerce catalog, approvals, transactions, activity and rollback.
* Existing permission scopes gate every MCP tool call.
* Design, SEO, commerce and transaction mutations keep preview -> approval -> apply protection.
* Version 0.25 hardens media and menu compensation so failed rollbacks surface explicit unknown-state errors instead of implying a clean revert.
* Version 0.24 adds atomic SQL rate limiting, malformed JSON-RPC ID rejection, and WooCommerce order compensation verification.
* Version 0.23 fixes ACF value rollback/compensation, hardens WooCommerce compensation verification, and adds repeatable staging/runtime test harnesses.
* Version 0.20 adds OAuth Authorization Code + PKCE S256, dynamic client registration, rotating refresh tokens and discovery metadata.
* Version 0.19 adds per-user bearer credentials, scope + WordPress-capability enforcement, expiry/revocation, actor-aware audit logging, and header-based MCP authentication.
* Version 0.18 hardens runtime diagnostics, approval failure states, request-size validation, uninstall cleanup and regression-test scaffolding.
* Version 0.17 adds the scoped SEO module: Generic/Yoast/Rank Math metadata, social/schema support, audits, stale checks and rollback.
* Version 0.16 adds the scoped WooCommerce Orders module: HPOS-safe reads, approved order creation/updates, line-item changes, order notes, stale checks and rollback. Payments/refunds remain blocked.
* Version 0.15 completes the planned WooCommerce catalog module: simple/variable products, attributes/terms, variations, shipping, tax classes, downloads, product lifecycle, approvals and rollback.
* Version 0.14 completes Elementor: registered widget/control validation, responsive settings, local templates/global widgets, Kit global styles, approvals and rollback.
* Version 0.13 completes Gutenberg: registered block schemas, deep validation, richer block operations, synced/unsynced user patterns, block templates/template parts, approval-gated writes, and rollback.
* Version 0.12 completes classic Navigation Menus: safe menu/item deletion, location lifecycle, hierarchy validation, taxonomy/archive/custom targets, bulk reorder, auto-add, and rollback.
* Version 0.11 completes the planned Media module: MCP base64 upload, usage discovery, metadata/parent editing, Trash/restore, protected permanent delete, same-ID file replacement, image processing, metadata regeneration, and rollback.
* Version 0.10 completes the planned ACF module: field-group and field CRUD, nested Group/Repeater fields, conditional logic, nested values, and rollback.
* Version 0.9 completes Taxonomies: rich registration settings, durable attach/detach, safe unregister, full term lifecycle, hierarchy validation, and rollback.
* Version 0.8 completes managed Custom Post Types: rich registration settings, safe deletion guards, taxonomy association, capabilities, REST/menu/rewrite settings, block templates, and rollback.
* The legacy REST/OpenAPI API-key interface remains optional for advanced integrations.

WooCommerce payment capture, refunds, payment-token changes, gateway capture/void and permanent deletion of existing orders remain unavailable, along with arbitrary PHP/SQL execution, plugin/theme installation/deletion and remote media fetching. Permanent page/post deletion is available only with explicit force + confirmation and is irreversible.

== Installation ==

1. Upload the plugin ZIP in WordPress > Plugins > Add New > Upload Plugin.
2. Activate ALIFY AI Connector.
3. Open WordPress Admin > ALIFY AI.
4. Enable only the permissions ChatGPT should use.
5. Open ALIFY AI > Connection and copy the Private MCP endpoint.
6. In ChatGPT, create/add an MCP app or custom connection and paste the endpoint as the server URL.
7. Connect and ask: "Show me my WordPress site info and capabilities."

The private endpoint is a credential. Regenerate it immediately if exposed. A public HTTPS WordPress site is required for remote ChatGPT connectivity.

== API ==

Base route: /wp-json/alify-ai/v1

Public:
GET /health
GET /connector
GET /openapi

SEO (requires `seo` permission):
GET /seo/status
GET /seo/posts/{id}
POST /seo/posts/{id}/preview
GET /seo/audit

Commerce:
GET /woocommerce/status
GET /woocommerce/products
GET /woocommerce/products/{id}
POST /woocommerce/products/preview
POST /woocommerce/products/{id}/preview

Orders (requires `orders` permission):
GET /woocommerce/order-statuses
GET /woocommerce/orders
GET /woocommerce/orders/{id}
POST /woocommerce/orders/preview
POST /woocommerce/orders/{id}/preview
POST /woocommerce/orders/{id}/items/preview
POST /woocommerce/orders/{id}/items/{item_id}/preview
GET /woocommerce/orders/{id}/notes
POST /woocommerce/orders/{id}/notes/preview

Transactions:
POST /transactions/preview

Media:
GET /media
POST /media/upload
GET|PATCH /media/{id}

Approvals:
GET /approvals
GET /approvals/{id}
POST /approvals/{id}/apply
POST /approvals/{id}/cancel

Core/content/design/structure/ACF/menu endpoints remain available.

== Changelog ==

= 0.32.0 =
* Added read-only code inspection permission and safe active theme/plugin source audit tools.
* Added same-site rendered frontend inspection with HTML/assets/header summary.
* Added path sandboxing, traversal/secret/binary blocks, scan limits and source secret redaction.

= 0.28.0 =
* Added MCP 2026-07-28 server/discover support for current ChatGPT action scanning.
* Added scope-aware and dependency-aware tools/list discovery.
* Added modern resultType/ttlMs/cacheScope metadata and conservative tool-schema normalization.
* Kept legacy initialize compatibility and added discovery regression tests.

= 0.27.0 =
* Fixed ChatGPT OAuth invalid_scope failures through dynamic scope discovery and safe scope negotiation.
* Advertises offline_access for refresh-token continuity.
* Narrows grants to enabled connector permissions and WordPress user capabilities.


= 0.26.0 =
* Hardened Gutenberg, SEO, structure and media rollback compensation verification.
* Added explicit unknown-state errors when compensation cannot be verified.
* Added phase 26 regression/release-audit coverage.

= 0.25.0 =
* Added atomic SQL rate limiting for MCP and OAuth client registration to prevent concurrent counter bypass.
* Hardened WooCommerce order compensation and unknown-state reporting.
* Added audit failure diagnostics and stricter JSON-RPC request-id validation.
* Added concurrency/security regression tests and phase 24 metadata.

= 0.23.0 =
* Fixed ACF value-update rollback and verified ACF compensation.
* Hardened WooCommerce compensation failure detection.
* Added ACF/WooCommerce runtime tests and Docker/WP-CLI staging smoke harness.

= 0.20.0 =
* Added OAuth Authorization Code + PKCE S256 for MCP clients.
* Added dynamic client registration and WordPress consent.
* Added rotating refresh tokens, OAuth revocation and discovery metadata.
* OAuth access tokens are short lived and resource-bound to the MCP endpoint.

= 0.19.0 =
* Added per-user, expiring, revocable bearer tokens for REST and MCP.
* Enforced global scope + token scope + WordPress user capability intersections.
* Added actor identity fields to activity audit records.
* Added admin token management and access-token regression tests.
* Retained legacy MCP URL and X-ALIFY-Key compatibility.

= 0.18.0 =
* Added redacted diagnostics, ambiguous-failure tracking, hardened MCP size validation, opt-in uninstall cleanup and PHPUnit scaffolding.

= 0.17.0 =
* Added default-OFF SEO permission with Generic, Yoast SEO and Rank Math provider detection.
* Added normalized SEO metadata reads plus approval-gated title, description, canonical, robots, social-card and supported schema changes.
* Added bounded SEO audits, stale-write protection, read-back verification and rollback.

= 0.15.0 =
* Completed the planned WooCommerce catalog module with simple/variable products, global/product attributes, attribute terms, variations, stock/backorders, sale scheduling, shipping/dimensions, tax classes, downloadable files and product Trash/restore.
* Added approval-gated catalog mutation tools, typed MCP schemas, catalog discovery endpoints and rollback for products, attributes/terms, variations, shipping classes and tax classes.
* Kept WooCommerce orders, customers, payments and refunds outside this catalog phase.

= 0.14.0 =
* Completed the planned Elementor module with widget/control registry validation, responsive breakpoints/settings, document operations, local templates/global widgets, Kit global styles, approval-gated saves and rollback.
* Added precise REST/OpenAPI and MCP tools for Elementor validation, templates and global styles.

= 0.12.0 =
* Completed the planned classic Navigation Menus module with menu/item delete, slug/description/auto-add settings, dedicated location assignment, taxonomy/post-type archive/custom targets, link metadata, hierarchy/cycle validation, safe child reparenting, bulk reorder, and rollback.
* Added precise REST/OpenAPI and MCP tools for location lifecycle, delete operations, and bulk reorder.


= 0.11.0 =
* Completed the planned Media module with MCP base64 upload, metadata/parent editing, usage discovery, Trash/restore, backed-up permanent deletion, in-place file replacement, image processing, metadata regeneration, and rollback.
* Added duplicate hashing and protected media-file backups with daily retention cleanup.
* Added precise REST/OpenAPI and MCP routes for the full media lifecycle.

= 0.9.0 =
* Completed Taxonomy registration lifecycle, durable post-type attach/detach, safe unregister, full term lifecycle, hierarchy validation, and rollback.
* Added taxonomy/term detail and delete tools plus precise MCP/OpenAPI schemas.


= 0.7.0 =
* Completed Pages/Posts lifecycle support: Trash, restore, explicit confirmed permanent delete, scheduled publication dates, author discovery/assignment, active-theme templates, revision list/read/restore, and rollback-aware snapshots.
* Added MCP tools and OpenAPI routes for authors, templates, deletion/restore, and revisions.
* Added author/date/template fields to content serialization and rollback snapshots.
* Added safe rollback of create-to-Trash, Trash/untrash, updates, and revision restores.

= 0.6.0 =
* Fixed MCP/transaction payload mismatch and tightened OpenAPI transaction schemas.
* Added atomic pending-to-applying approval locks to prevent double execution.
* Added compensated/revert-safe content updates and transaction operations.
* Added bounded audit/approval payloads, slim activity queries and 90-day cleanup.
* Hardened WooCommerce, Elementor, Gutenberg, Media, ACF, menus, CPT/taxonomy and rollback error paths.
* Added MCP request-size/rate limits and menu-location discovery.

= 0.5.1 =
* Redesigned WordPress admin into Overview, Connection, Permissions, Integrations, Activity and Settings screens.

= 0.5.0 =
* Embedded MCP server directly in WordPress.
* Single private endpoint URL for ChatGPT MCP connections.
* No separate Node bridge required.

= 0.4.0 =
* Added connector discovery metadata, local media uploads, WooCommerce catalog tools and multi-action transactions.

= 0.3.0 =
* Added Gutenberg/Elementor preview, approvals, stale-write protection and design rollback.

= 0.2.0 =
* Added CPT/taxonomy, ACF, media and navigation menu integrations.

= 0.1.0 =
* Initial MVP with pages/posts, API key auth, activity log and basic rollback.
