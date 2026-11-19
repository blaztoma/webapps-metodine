# Integrating LLMs into web applications — examples

[Lietuviškai](README.md) · **English**

Two small PHP projects that illustrate the slides. Both use the same **ParcelShop** parcel
database (SQLite) and the same chat page, which shows every step the server takes.

| | Project | What it shows | Slides |
|---|---|---|---|
| 1 | `01-tool-calling/` | The model asks for a tool (`tool_use`); `chat.php` runs it by calling **our parcel API over JSON-RPC 2.0** with the user's token and returns a `tool_result`. | *Tool calling: the model asks, your code acts*, *Tool calling in code* |
| 2 | `02-mcp/` | The same tools in an **MCP server** (official MCP PHP SDK, Streamable HTTP). `chat.php` is an MCP client: `tools/list` → the model, `tool_use` → `tools/call`. | *MCP: one protocol for tools*, *Exposing your API as an MCP server* |

```
1)  browser ──JWT──▶ chat.php ──messages + tools──▶ model (Claude)
                     chat.php ◀──tool_use──────────
                     chat.php ──JSON-RPC──▶ api/rpc.php ──▶ SQLite
                     chat.php ──tool_result────────▶ model ──▶ answer

2)  browser ──JWT──▶ chat.php (MCP client) ──initialize, tools/list──▶ server.php (MCP server)
                     chat.php ──messages + tools──▶ model
                     chat.php ──tools/call (user's token)──▶ server.php ──▶ SQLite
```

The loop in both `chat.php` files is the same — only **where** the tool definitions come from and
**how** a tool is executed differ. That is the answer to "are tool calling and MCP the same thing?".

## Structure

```
llm/
├─ index.html                 start page with links to both examples
├─ config.php                 settings (model, limits, URLs)
├─ config.local.example.php   → copy to config.local.php and put your API key there
├─ composer.json              anthropic-ai/sdk, guzzlehttp/guzzle, mcp/sdk
├─ data/
│  ├─ schema.sql              tables and sample data (dates are relative to "today")
│  └─ init.php                php data/init.php — re-creates the database
├─ shared/
│  ├─ db.php                  PDO + queries, always scoped to the owner
│  ├─ llm.php                 Anthropic client, system prompt, history, JSON responses
│  └─ chat.js, chat.css       chat page and the "what happened on the server" panel
├─ 01-tool-calling/
│  ├─ index.html
│  ├─ chat.php                ★ the tool loop
│  ├─ tools.php               ★ tool definitions + JSON-RPC client
│  └─ api/rpc.php             ★ parcel API (JSON-RPC 2.0 server, no LLM at all)
└─ 02-mcp/
   ├─ index.html
   ├─ chat.php                ★ MCP client + the same tool loop
   └─ server.php              ★ MCP server (Server::builder()->addTool(...))
```

## Running it with XAMPP

1. **Dependencies** (if there is no `vendor/`):
   ```bash
   cd H:\xamppV8\htdocs\metodine\llm
   H:\xamppV8\php\php.exe H:\xamppV8\php\composer.phar install
   ```
2. **API key** — copy `config.local.example.php` to `config.local.php` and put in a key from
   <https://platform.claude.com>. Or set the `ANTHROPIC_API_KEY` environment variable
   (Apache only sees it if it is set before Apache starts).
3. **Database** is created automatically on first use; to reset it: `H:\xamppV8\php\php.exe data\init.php`.
4. Start Apache and open <http://localhost/metodine/llm/>.

> **"could not find driver" / `Unexpected token '<'`** — SQLite is not enabled in the PHP that Apache uses.
> `PHPINIDir` in `apache\conf\extra\httpd-xampp.conf` tells which PHP that is (e.g. `H:\xamppV8\php84`).
> Uncomment `extension=pdo_sqlite` and `extension=sqlite3` in that PHP's `php.ini`, then restart Apache.
> Error details are in `apache\logs\error.log`.

> **"Anthropic API Connection Error" / `cURL error 60: … unable to get local issuer certificate`** — PHP cannot
> find a CA certificate bundle. In the `php.ini` of the PHP Apache uses, set
> `curl.cainfo = "H:\xamppV8\apache\bin\curl-ca-bundle.crt"` and `openssl.cafile` to the same path
> (or to a current <https://curl.se/ca/cacert.pem>), then restart Apache.

Demo users (chosen at the top of the page):

| User | Bearer token | Packages |
|---|---|---|
| Ona | `demo-token-ona` | 150 (in transit), 151 (delivered), 154 (lost) |
| Jonas | `demo-token-jonas` | 152 (in transit, **prompt injection in the note**), 153 (created) |

The sample data (package descriptions, cities, tracking events) is in Lithuanian; the assistant
answers in the language of the question.

### Worth trying in the lecture

- **"Where is package 150?"** as Ona — one `get_package` call, an answer from real data.
- **"Which of my parcels are still on the way?"** — the model picks `list_my_packages` with a `status` on its own.
- **"What about package 152?"** as **Ona** — the tool returns "not found for this user": the assistant sees only what the user sees.
- The same as **Jonas** — the package note contains "Ignore all previous instructions…" (slide *Prompt injection through your own data*). The tools are read-only, so a fooled model could not cancel anything.
- The right-hand panel shows every step and the token counts (`usage`).

## The parcel API (example 1) without the model

```bash
curl -X POST http://localhost/metodine/llm/01-tool-calling/api/rpc.php -H "Authorization: Bearer demo-token-ona" -H "Content-Type: application/json" -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"packages.get\",\"params\":{\"id\":150}}"
```

Methods: `packages.list {status?}`, `packages.get {id}`. Errors use JSON-RPC codes
(`-32601` method not found, `-32602` invalid params, `-32004` package not found, `-32001` no token).
Batches (a JSON array) and notifications (no `id`) are supported too.

## The MCP server (example 2) for other clients

Any MCP client can use the server, not only our `chat.php`:

- **Claude Code:**
  ```bash
  claude mcp add --transport http parcel-shop http://localhost/metodine/llm/02-mcp/server.php --header "Authorization: Bearer demo-token-ona"
  ```
- **MCP Inspector:** `npx @modelcontextprotocol/inspector` → transport *Streamable HTTP*,
  URL `http://localhost/metodine/llm/02-mcp/server.php`, header `Authorization: Bearer demo-token-ona`.

The server supports both protocol generations: the newest stateless one (2026-07-28, chosen by the
SDK client) and the older one with `initialize` and `Mcp-Session-Id` (sessions are stored in `data/mcp-sessions/`).

## On a hosting server

- PHP ≥ 8.1 with `curl`, `openssl`, `pdo_sqlite`, `fileinfo`; outbound HTTPS to `api.anthropic.com` must be allowed.
- Upload the folder and run `composer install --no-dev` on the server (or upload `vendor/` as well).
- Use an API key ("Somewhere else" in the Claude Console) and keep `config.local.php` on the server only;
  `.htaccess` blocks it from download (and also `data/` and `vendor/`).
- Add your domain to `mcp_allowed_hosts` (config) — otherwise the MCP server answers 403 (DNS rebinding protection).
- If the `Authorization` header does not reach PHP (CGI/FPM), the root `.htaccess` passes it on.

## Notes

- Model: `claude-opus-5-5`, `effort: low` (faster and cheaper for chat), at most 5 tool steps.
  The beta endpoint is used only for server-side `fallbacks: "default"`: if the model declines for
  policy reasons, the API automatically tries a fallback model.
- The browser keeps the conversation history and sends it every time (the API is stateless). A real
  application would keep the history in its database.
- Answers are not streamed (SSE) to keep the code short — streaming is shown on the slide *Streaming in code*.
- Everything that comes from the server is shown with `textContent`, never `innerHTML` (model output is untrusted).
- The MCP PHP SDK (`mcp/sdk`) is still 0.x: version 0.9 works on PHP 8.1, newer versions require PHP 8.2.
