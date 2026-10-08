# Changelog

## 0.32.0
- Added ACF Options Page discovery/read/write support for archive and global theme settings.
- Added rollback-safe option updates with stale-state protection and audit compensation.
- Added runtime MCP visibility diagnostics for code_read, code_write and ACF Options tools.
- Added ACF Options regression coverage and phase 32 metadata.

## 0.31.0
- Fixed stale OAuth scope issuance when ChatGPT reauthorized with an older narrow `read content` request after additional WordPress connector permissions had been enabled.
- Added managed interactive OAuth grant synchronization: explicit authorization now grants the full intersection of globally enabled connector permissions and the signed-in WordPress user's native capabilities, and displays the exact grant on the consent screen.
- OAuth refresh-token rotation remains scope-ceiling safe and can never silently expand permissions; scope expansion requires a fresh interactive consent.
- Added diagnostics for requested vs policy-added OAuth scopes and capability reporting metadata for the managed reauthorization mode.
- Added regression coverage for stale-client scope expansion, WordPress capability boundaries, and refresh scope ceiling behavior.


## 0.30.0
- Added separate high-risk `code_write` scope.
- Added approval-gated existing source-file updates with stale SHA-256 checks, PHP syntax validation, atomic replace, audit logging and rollback.
- Fixed misleading capability reporting by separating global, credential and effective scopes and reporting scopes that require OAuth re-authorization.
- Added `preview_source_file_update` MCP action and code-write rollback support.

## 0.29.0

- Added dedicated `code_read` OAuth/PAT scope, disabled by default.
- Added read-only active-theme/active-plugin source inspection and bounded literal code search.
- Added same-site frontend HTML/assets/headers inspection.
- Added strict realpath sandboxing, path traversal protection, source extension/size limits, dependency/secret-file blocking, and credential redaction.
- Added six MCP code/frontend audit tools and regression/release-audit coverage.

## 0.28.0

- Added MCP protocol `2026-07-28` support, including authenticated `server/discover` and modern stateless discovery metadata.
- Added required modern result metadata (`resultType`, `ttlMs`, `cacheScope`) to tool discovery and modern tool results.
- Made `tools/list` authorization-aware so ChatGPT only scans actions allowed by the authenticated OAuth credential and WordPress user.
- Added dependency-aware discovery so unavailable ACF, Elementor, and WooCommerce actions are hidden while their status probes remain available.
- Added conservative ChatGPT discovery-schema normalization that removes problematic schema compositions from the advertised tool contract while preserving server-side validation.
- Kept legacy `initialize` protocol support for older MCP clients.
- Added regression tests for `server/discover`, safe tool schemas, and scope-filtered action discovery.

## 0.27.0

- Fixed OAuth `invalid_scope` connection failures caused by advertising globally disabled connector permissions.
- Discovery metadata now exposes enabled connector permissions plus `offline_access`.
- Added safe OAuth scope negotiation for cached/broad client requests.
- Added WordPress-user capability narrowing and token-issuance revalidation.
- Added OAuth regression tests and release-audit invariants.

## 0.26.0
- Verified Gutenberg apply/rollback compensation after audit-log failures and added unknown-state diagnostics.
- Verified SEO mutation and rollback compensation instead of assuming metadata restoration succeeded.
- Hardened custom post type, taxonomy, and term rollback compensation paths across structure and REST rollback handling.
- Marked media file rollback + rollback-log failure as an explicit audit-gap/unknown state.
- Added phase 26 release-audit invariants and regression coverage.

## 0.25.0
- Added verified compensation handling for media metadata updates and Trash/restore audit failures.
- Added verified compensation handling for menu metadata, item updates, reparent/delete and reorder failure paths.
- Compensation failures now surface explicit media/menu unknown-state errors with diagnostics instead of reporting a clean revert.
- Added failure-hardening regression tests and phase 25 release metadata.

# Changelog

## 0.24.0
- Replaced transient get/set rate limiting with a shared atomic SQL fixed-window limiter to prevent concurrent MCP/OAuth registration requests from undercounting limits.
- Added fail-closed 503 handling when rate-limit state cannot be persisted, plus Retry-After on MCP 429 responses.
- Fixed WooCommerce order compensation so rollback callbacks returning `WP_Error` or `false` are treated as unknown-state failures instead of clean reverts.
- Added diagnostics for audit snapshot serialization/size failures and database insert failures.
- Hardened MCP JSON-RPC validation by rejecting boolean request IDs.
- Added regression coverage for the atomic rate-limit table, MCP ceiling enforcement, OAuth dynamic-registration limiting, and malformed request IDs.
- Security/fuzz/concurrency phase metadata updated to 24.

## 0.23.0
- Fixed missing `update_acf` rollback handling so audited ACF value updates can be restored through the normal rollback endpoint.
- Added ACF stale-value conflict protection and verified compensation; unverifiable restores now return an explicit unknown-state error and diagnostics.
- Transaction ACF compensation now uses the ACF rollback implementation, and partial manual transaction rollback is surfaced as unknown state.
- Hardened WooCommerce `audit_or_revert()` so compensation callbacks returning `WP_Error` or `false` are treated as failures rather than silently accepted.
- Added dependency-aware ACF/WooCommerce runtime integration tests.
- Added a Docker/WP-CLI staging harness and runtime smoke script for repeatable WordPress validation.
- Runtime/staging phase metadata updated to 23.

## 0.22.0
- Hardened Elementor document writes: failed API/fallback saves or post-save verification now restore the exact previous Elementor metadata and emit diagnostics instead of leaving partially mutated documents.
- Fixed WooCommerce variation rollback consistency by re-syncing the parent variable product after variation create/update/trash/restore compensation.
- Transactions now distinguish clean rollback from incomplete compensation; incomplete rollback returns an explicit unknown-state error and diagnostic event.
- Hardened MCP JSON-RPC validation: non-object `params`, non-object `tools/call.arguments`, non-string methods, and complex request IDs are rejected rather than silently coerced.
- Added MCP malformed-request regression coverage.
- Production runtime compatibility phase metadata updated to 22.

## 0.21.0

- Fixed a release-blocking OAuth dynamic-registration schema mismatch: `client_id_issued_at` is now present in the clients table and uses the correct integer insert format.
- Added OAuth DB migration v1.1.0 so existing v0.20 installations receive the missing column automatically.
- Added explicit malformed-request validation for authorization-code and refresh-token grants.
- Added OAuth schema/runtime regression coverage, malformed grant tests, redirect validation tests, and token resource-binding assertions.
- Replaced the remaining silent Elementor Kit write exception with diagnostics logging.
- Added a standalone release audit script for PHP lint, OAuth schema/insert consistency, version metadata, and silent exception checks.


## 0.20.0

- Added OAuth Authorization Code + mandatory PKCE S256 for MCP clients.
- Added dynamic client registration, consent, token exchange and discovery metadata.
- Added one-hour OAuth access tokens and rotating 30-day refresh tokens.
- Bound OAuth access tokens to the ALIFY MCP resource and retained WordPress capability checks.
- Added OAuth client/token revocation, cleanup and uninstall cleanup.
- Added OAuth discovery hints to unauthenticated MCP responses.
- Updated connector capabilities metadata to phase 20.

## 0.19.0
- Added scoped, expiring, revocable per-user bearer credentials stored only as HMAC-SHA256 digests.
- Added WordPress capability enforcement per connector scope; bearer access is the intersection of global permissions, token permissions and the assigned user's native capabilities.
- Added preferred header-based MCP endpoint at `/wp-json/alify-ai/v1/mcp` using `Authorization: Bearer <token>` while retaining the legacy private MCP URL for compatibility.
- Added bearer authentication to the REST API while retaining the legacy `X-ALIFY-Key` flow.
- Added admin UI to create/revoke per-user credentials, choose expiry (7–365 days), assign scopes and inspect last-use/status metadata.
- Extended the audit table with authentication type, WordPress user ID and credential identifiers so connector changes record who/what performed them.
- Changed MCP rate-limit identity from the raw private URL token to a non-secret credential identity.
- Updated connector discovery/capabilities metadata to advertise per-user bearer authentication and phase 19.
- Added access-token regression tests for creation, verification, revocation, scope restriction and WordPress capability enforcement.
- Full OAuth 2.1 Authorization Code + PKCE discovery/consent is not claimed by this release; v0.19 provides the per-user credential and authorization foundation for that follow-up.

## 0.18.0
- Added opt-in redacted runtime diagnostics for Elementor fallbacks, approval exceptions, WooCommerce compensation failures and oversized MCP requests.
- Hardened approval failure state tracking: runtime/stale-lock interruptions now become `failed_unknown_state` instead of looking like clean failures, with stored failure codes/messages for investigation.
- Hardened MCP request-size checks by validating both declared `Content-Length` and the actual parsed request body length.
- Added explicit uninstall cleanup controls plus `uninstall.php` for connector tables, credentials/settings, transients and protected media backups only when the administrator opts in.
- Updated health metadata to phase 18 and bumped the connector to v0.18.0.
- Added a WordPress PHPUnit test scaffold with smoke and approval lifecycle regression tests.

## 0.17.0
- Added a separate default-OFF `seo` permission and a normalized SEO adapter for Generic WordPress output, Yoast SEO and Rank Math.
- Added read/preview/apply flows for SEO title, meta description, canonical URL, robots directives, focus keyword, OpenGraph/Facebook metadata, X/Twitter metadata and provider-supported schema fields.
- Added a safe generic SEO fallback that outputs title/description/canonical/robots/social metadata and bounded JSON-LD only when Yoast/Rank Math are not owning the page.
- Added provider-conflict detection, field validation, image/URL validation, stale-write hashes, write read-back verification, audit compensation and rollback.
- Added bounded bulk SEO audit with missing/duplicate title/description checks, length checks, noindex/canonical/social-image diagnostics, plus REST/OpenAPI and MCP parity.
- Custom arbitrary schema JSON writes are intentionally restricted to the generic provider; Yoast and Rank Math keep ownership of their generated schema graphs.

## 0.15.0
- Completed the planned WooCommerce catalog module with simple and variable products, global/product attributes, attribute terms, variations, stock/backorders, sale scheduling, dimensions/shipping classes, tax status/classes, virtual/downloadable products and downloadable files.
- Added typed read and approval-gated mutation flows for product attributes/terms, variations, shipping classes, tax classes and product Trash/restore.
- Added audit-backed rollback/compensation for product, variation, attribute, attribute-term, shipping-class and tax-class mutations. Global attribute deletion snapshots and restores terms plus product relationships because WooCommerce deletes those terms with the attribute.
- Added precise WooCommerce REST/OpenAPI and MCP catalog schemas and discovery tools while keeping orders, customers, payments and refunds outside this catalog phase.
- Fixed global attribute taxonomy serialization so `pa_color` remains a valid taxonomy identifier rather than being converted to `pa-color`.
- Added WooCommerce catalog runtime regression coverage for variable-product creation, nested variation creation, downloads, shipping/tax classes, lifecycle changes and attribute deletion rollback.

## 0.14.0
- Completed the planned Elementor module with Core/Pro capability detection, responsive breakpoint discovery, registered widget/control schemas, document validation, responsive control writes, page settings, global-style references, safe add/remove/move/duplicate/replace operations, and verified document saves.
- Added local Elementor Library template/global-widget list/read/create/update/delete proposals, operation-based template edits, registered document-type validation, audit compensation, stale-write protection, and rollback.
- Added active Elementor Kit global colors/typography/settings read/update proposals with save verification and rollback.
- Added precise REST/OpenAPI and MCP parity for Elementor status, breakpoints, widget schemas, document validation, templates, and global Kit styles.
- Added runtime regression coverage for responsive-control validation, unknown controls, unsupported widget nesting, document apply, template lifecycle/rollback, and Kit global-style apply.

## 0.13.0
- Completed the Gutenberg module: registered block-type discovery, deep block-tree validation, richer move/duplicate/multi-insert/document-replace operations, theme/core pattern reads, persistent synced/unsynced user patterns, block templates/template parts, and approval-gated pattern/template mutations.
- Added rollback for user-pattern and customized template/template-part create/update/delete/restore flows, including safe rollback of theme-file overrides.
- Added precise REST/OpenAPI and MCP parity for Gutenberg registry, validation, patterns and templates.

## 0.12.0
- Completed the classic Navigation Menus module: menu CRUD/delete rollback, slug/description/auto-add, theme-location assign/unassign, post/taxonomy/archive/custom items, link metadata, hierarchy/cycle validation, child-safe deletion, bulk reorder, and item/location rollback.
- Added precise REST/OpenAPI and MCP parity for the completed menu lifecycle.

## 0.11.0
- Completed the Media module: paginated library reads, MCP base64 upload, duplicate-file hashing, metadata/parent edits, usage checks, Trash/restore, backed-up permanent delete, in-place file replacement, image resize/crop/rotate/flip, attachment metadata regeneration, media rollback, and backup cleanup.
- Added REST/OpenAPI and MCP parity for the completed Media lifecycle.
- Increased the authenticated MCP request ceiling only enough to support bounded 8 MiB raw base64 media uploads.

## 0.10.0

### ACF module completion
- Added ACF field-group detail/update/delete with rich location and editor-display settings.
- Added ACF field detail/update/delete plus nested Group/Repeater field-tree creation and editing.
- Added conditional-logic validation, same-group reference checks, sibling-name and parent/cycle validation, wrapper settings, and supported field-type settings.
- Nested Group/Repeater values can be read and updated through the existing value API.
- Added explicit delete confirmation, audit-backed compensation, and rollback for field-group and field create/update/delete operations.
- Added ACF PRO/Repeater capability reporting and precise MCP/OpenAPI tools/routes.
- MCP tool catalog now exposes 70 unique tools.

## 0.9.0

### Taxonomy module completion
- Added taxonomy detail, safe delete, rollback, durable attach/detach, rich labels, visibility, REST, capability, rewrite, query-var, sort, default-term and object-type settings.
- Managed taxonomy associations now stay synchronized with ALIFY-managed CPT definitions.
- Added term detail, pagination/parent filtering, hierarchy validation, destructive delete confirmation, optional replacement/default term behavior, audit snapshots and delete rollback that restores relationships, children and term meta.
- Added MCP and OpenAPI tools/routes for taxonomy detail/delete, attach/detach, term detail/delete.

## 0.8.0
- Completed the managed Custom Post Types module with read/create/update/delete lifecycle support.
- Added rich labels, visibility/UI flags, REST settings, menu icon/position, capability maps, supports, taxonomy associations, archive/rewrite/query settings, export/delete-with-user behavior, and Gutenberg block templates/template locks.
- Added safe CPT deletion guards so existing content cannot be orphaned without explicit force + confirmation; CPT deletion never silently deletes content.
- Added CPT create/update/delete rollback support and structure-scoped rollback authorization.
- Added precise MCP and OpenAPI schemas plus get/delete CPT tools/routes.
- Added validation for registered taxonomy associations, safe menu-icon formats, template structure, capability maps, and advanced rewrite settings.

## 0.7.0
- Completed Pages/Posts lifecycle support: Trash, restore, explicitly confirmed permanent delete, scheduling, author discovery/assignment, theme templates, and WordPress revisions.
- Added MCP + REST/OpenAPI routes for content authors, templates, delete/restore, revision listing/detail, and revision restore.
- Extended rollback snapshots to preserve author, publication dates, template, featured image and taxonomies while avoiding rendered-content snapshot bloat.
- Added rollback support for create-to-Trash, update, Trash/untrash and revision restore activities.
- Added clean error guards around post reloads after third-party WordPress hooks to avoid typed-helper fatals.

## 0.6.0
- Fixed the MCP transaction schema mismatch and aligned REST/OpenAPI transaction contracts.
- Added atomic `pending` → `applying` approval claiming to prevent concurrent double execution.
- Added compensated, revert-safe content and transaction writes when taxonomy, featured-media, audit, or integration steps fail.
- Added bounded audit and approval payloads, metadata-only activity listings, and 90-day scheduled cleanup.
- Hardened WooCommerce product save/load paths against uncaught runtime exceptions.
- Added Elementor tree/ID/widget validation, write verification, safer hooks, and compensated audit/rollback behavior.
- Hardened Gutenberg, Media, ACF value updates, menus, CPT/taxonomy registration, and transaction rollback paths.
- Added MCP request-size limits, a basic per-token/IP rate limit, and menu-location discovery.

## 0.5.1
- Redesigned the WordPress admin experience into dedicated Overview, Connection, Permissions, Integrations, Activity, and Settings screens.
- Added a guided ChatGPT connection screen with safer regenerate/revoke controls.
- Grouped permissions by risk and added modern toggle controls.
- Added integration health cards for Gutenberg, Elementor, ACF, WooCommerce, Media, and Menus.
- Added pending approval review with Apply/Cancel actions in wp-admin.
- Added a cleaner activity log and centralized advanced approval policies.
- Moved legacy REST API controls out of the main dashboard.

## 0.5.0
- Added an embedded MCP server directly inside the WordPress plugin.
- Added single-URL private ChatGPT connection flow; separate Node bridge no longer required.
- Added WordPress Admin connection card with copy, regenerate, and disable controls.
- Added MCP `initialize`, `ping`, `tools/list`, `tools/call`, and notification handling.
- Exposed content, CPT/taxonomy, ACF, media metadata, menu, Gutenberg, Elementor, WooCommerce catalog, approvals, transaction, activity, and rollback tools through MCP.
- Reused REST controllers internally so MCP honors the same scopes, audit logging, stale-target protection, approvals, and rollback behavior.
- Preserved optional legacy API-key REST/OpenAPI integration.

## 0.4.0
- Added WooCommerce catalog integration with preview/approval workflow.
- Added local multipart media upload.
- Added multi-action transaction previews, preflight checks, and compensation rollback.
- Added connector discovery metadata.

## 0.3.0
- Added Gutenberg and Elementor design adapters.
- Added proposal/approval flow and stale-write protection.

## 0.2.0
- Added CPTs, taxonomies, ACF, Media Library metadata, and menus.

## 0.1.0
- Initial scoped WordPress REST control layer with content operations, audit log, and rollback.
