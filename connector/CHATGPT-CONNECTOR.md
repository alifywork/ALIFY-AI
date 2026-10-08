# REST / OpenAPI connector — v0.32.0

The REST API continues to support manually created per-user bearer tokens and the legacy `X-ALIFY-Key`. OAuth 2.1 access tokens introduced in v0.21.0 are intentionally resource-bound to the public MCP endpoint and are not general-purpose REST credentials.

For MCP, use `/wp-json/alify-ai/v1/mcp` and OAuth discovery at `/.well-known/oauth-authorization-server`.
