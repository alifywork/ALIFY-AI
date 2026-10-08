# ChatGPT MCP setup — v0.32.0

Use the public MCP URL:

`https://YOUR-SITE.example/wp-json/alify-ai/v1/mcp`

The server now supports OAuth Authorization Code + PKCE S256. OAuth-aware MCP clients can discover configuration from:

`https://YOUR-SITE.example/.well-known/oauth-protected-resource`

and
`https://YOUR-SITE.example/.well-known/oauth-authorization-server`

The client dynamically registers its HTTPS redirect URI, sends the user to the WordPress consent screen, exchanges a one-time code with its PKCE verifier, then receives a one-hour access token plus a rotating refresh token. Granted permissions are the intersection of global ALIFY permissions, requested OAuth scopes, and the signed-in WordPress user's native capabilities.

Legacy options remain available for compatibility: manually created per-user bearer tokens and the private MCP URL. OAuth is preferred for clients that support it.

## OAuth scope negotiation (v0.27.0)

Use the public MCP endpoint ending in `/wp-json/alify-ai/v1/mcp` with OAuth. Do not append the legacy private MCP token to an OAuth connection URL.

OAuth discovery now advertises only connector permissions enabled under **ALIFY AI → Permissions**, plus `offline_access` for refresh-token continuity. If a client has cached an older/broader scope list, ALIFY narrows known-but-disabled scopes instead of failing the entire authorization request. Unknown scope names still return `invalid_scope`.

The final grant is also narrowed to the signed-in WordPress user's native capabilities. This means a non-administrator can connect successfully with the subset they are actually allowed to use rather than failing because an administrator-only scope was requested.

## ChatGPT action discovery (v0.32.0)

The MCP endpoint now supports the current stateless MCP `2026-07-28` discovery flow. ChatGPT may call `server/discover` before `tools/list`; ALIFY answers both and retains legacy `initialize` support for older clients.

`tools/list` is filtered to the authenticated credential's effective scopes and active WordPress integrations. Tool input schemas are normalized only for discovery compatibility; the existing REST controllers still enforce the full server-side argument validation and permissions when a tool is called.

If a ChatGPT app was created against an older ALIFY version, delete/recreate the draft app or refresh its actions after upgrading so ChatGPT does not keep the previous frozen tool snapshot.


## Source code inspection (v0.32.0)

Enable **ALIFY AI → Permissions → Inspect source code** only when you want ChatGPT to audit implementation code. The `code_read` scope is read-only and limited to the active theme/parent theme and active directory-based plugins. Secret/config/binary/dependency paths and filesystem traversal are blocked. Frontend inspection can only fetch a site-relative public frontend path on the same WordPress host; admin, login, REST and XML-RPC paths are blocked.

After upgrading an existing connection, enabling `code_read` does not retroactively widen an already issued OAuth token. Enable the permission first, then reconnect/re-authorize the ChatGPT app so the new scope can be granted and the six inspection tools can appear in action discovery.
