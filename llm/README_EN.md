# Integrating LLMs into web applications — examples

[Lietuviškai](README.md) · **English**

Two small PHP projects that illustrate the [slides](slides/LLM_integration_2026.pptx). Both use the same **ParcelShop** parcel
database (SQLite) and the same chat page, which shows every step the server takes.

| | Project | What it shows | Slides |
|---|---|---|---|
| 1 | `01-tool-calling/` | The model asks for a tool (`tool_use`); `chat.php` runs it by calling **our parcel API over JSON-RPC 2.0** with the user's token and returns a `tool_result`. | *Tool calling: the model asks, your code acts*, *Tool calling in code* |
| 2 | `02-mcp/` | The same tools in an **MCP server** (official MCP PHP SDK, Streamable HTTP). `chat.php` is an MCP client: `tools/list` → the model, `tool_use` → `tools/call`. | *MCP: one protocol for tools*, *Exposing your API as an MCP server*, *Where the MCP server sits* |

```
1)  browser ──JWT──▶ chat.php ──messages + tools──▶ model (Claude)
                     chat.php ◀──tool_use──────────
                     chat.php ──JSON-RPC──▶ api/rpc.php ──▶ shared/db.php ──▶ SQLite
                     chat.php ──tool_result────────▶ model ──▶ answer

2)  browser ──JWT──▶ chat.php (MCP client) ──initialize, tools/list──▶ server.php (MCP server)
                     chat.php ──messages + tools──▶ model
                     chat.php ──tools/call (user's token)──▶ server.php ──▶ shared/db.php ──▶ SQLite
```

The loop in both `chat.php` files is the same — only **where** the tool definitions come from and
**how** a tool is executed differ. That is the answer to "are tool calling and MCP the same thing?".

## Where is the API in the MCP example?

The MCP server does not call the JSON-RPC API over HTTP. `api/rpc.php` and `02-mcp/server.php` are **two
interfaces over the same layer**, `shared/db.php`. Its functions `packages_of()` and `package_of()` always filter
by owner, so the rules and permissions are written in one place:

```
api/rpc.php        (JSON-RPC) ─┐
                               ├──▶ shared/db.php (queries, permissions) ──▶ SQLite
02-mcp/server.php  (MCP)      ─┘
```

An MCP server is an interface layer, like a REST controller, so business logic does not live in it. In practice
there are three options (slide *Where the MCP server sits*):

| | Option | When | Watch out for |
|---|---|---|---|
| 1 | **Another interface in your app**: MCP tools sit next to the REST controllers and call the same services. *Our example.* | Your own application; the usual choice. | Logic and permission checks belong in the service layer, not in the tools. |
| 2 | **Adapter over an existing API**: the MCP server calls an existing REST API over HTTP. | The API already exists, or another team builds the MCP server. Most SaaS products do this. | **No token passthrough.** The MCP server accepts only tokens issued for it (OAuth *audience*) and never forwards them. For the API call it gets its own token on the user's behalf, e.g. with *token exchange* (RFC 8693). |
| 3 | **Straight to the database**, e.g. a generic PostgreSQL MCP server. | Internal developer tools, analytics, prototypes. | There are no business rules, so it is not for end users. |

The `demo-token-…` tokens are for learning only. A remote MCP server in production authorizes with
OAuth 2.1, as the MCP specification requires.

## Structure

```
llm/
├─ index.html                 start page with links to both examples
├─ slides/                    lecture slides (LLM_integration_2026.pptx)
├─ db-view.php                the "DB" button: the whole demo database, read-only (learning aid)
├─ healthz.php                health check: libraries + DB → 200 or 503
├─ Dockerfile, compose.yaml   running it with Docker (docker compose up --build)
├─ tests/smoke.php            smoke test without an API key; CI runs it too (../.github/workflows/ci.yml)
├─ config.php                 settings (model, limits, URLs)
├─ config.local.example.php   → copy to config.local.php and put your API key there
├─ composer.json              anthropic-ai/sdk, guzzlehttp/guzzle, mcp/sdk
├─ data/
│  ├─ schema.sql              tables and sample data (dates are relative to "today")
│  └─ init.php                php data/init.php — re-creates the database
├─ shared/
│  ├─ db.php                  PDO + queries, always scoped to the owner (shared by rpc.php and server.php)
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
- The **DB** button opens all tables: the current user's rows are highlighted — the tools can see only those.
  Compare what a tool returned with what is really in the database.

## Running it with Docker

Instead of XAMPP you can use [Docker](https://www.docker.com/): the same Apache + PHP + SQLite in one container.

```bash
cd webapps-metodine/llm
docker compose up --build
```

Open <http://localhost:8080/>. Compose takes the API key from the `ANTHROPIC_API_KEY` environment variable or from a
`.env` file next to `compose.yaml` (`ANTHROPIC_API_KEY=sk-ant-…`; `.env` is never committed). Without a key the pages,
the API and the MCP server work; only the model calls report that the key is missing.

- The `Dockerfile` has two stages: `composer:2` installs the libraries from `composer.lock`, and the final
  `php:8.4-apache` image keeps only what is needed to run. The key and `config.local.php` never get into the image (`.dockerignore`).
- `healthz.php` checks the libraries and the database and answers 200 or 503. Docker's `HEALTHCHECK` uses it
  (`docker ps` shows *healthy*); it also works as a platform health check or an uptime monitor.
- The database lives inside the container: after `docker compose down` and `up` you have fresh demo data.
- The same image can be deployed to Render, Azure Container Apps, Google Cloud Run or any platform that runs Docker
  images. Set `ANTHROPIC_API_KEY` in the platform's settings and, if external MCP clients will use the MCP server,
  `MCP_ALLOWED_HOSTS=localhost,your-domain`.

## Automatic checks (CI)

Every push to `main` and every pull request starts a [GitHub Actions workflow](../.github/workflows/ci.yml)
(slides *CI: every push is checked*, *CI in code: GitHub Actions*):

| Job | What it does |
|---|---|
| **PHP 8.1 and 8.4** | `composer validate` and `install`, a syntax check of every `.php` file (`php -l`), the database, PHP's built-in server and the smoke test |
| **Docker** | builds the image (tagged with the commit SHA), starts a container, waits until `healthz.php` answers 200, and runs the same smoke test through Apache |

The smoke test [`tests/smoke.php`](tests/smoke.php) needs no API key and calls no model, so it costs nothing. It checks
that the app runs and keeps its rules: Ona sees her package 150 but not Jonas's 152; no token → 401; the JSON-RPC
error codes; MCP `tools/list` and `tools/call`; that `.htaccess` hides `config.php`, `data/` and `vendor/`; and that
`chat.php` reaches the MCP server and reports the missing key clearly.

```bash
php tests/smoke.php http://localhost/metodine/llm --apache       # XAMPP
php tests/smoke.php http://localhost:8080 --apache                # Docker
```

Use `--chat-without-key` only for a server without a key (as CI does): with a key, `chat.php` would call the paid model.

> **Try it:** in `shared/db.php`, remove `AND owner_id = ?` from `package_of()` (and `$userId` from `execute()`)
> and open a pull request. CI should turn red: Ona would start seeing Jonas's package 152.

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
- `db-view.php` shows the whole database, including the demo tokens. Fine for learning; a real application must not have such a page.
- Add your domain to `mcp_allowed_hosts` (config) or to the `MCP_ALLOWED_HOSTS` environment variable — otherwise the MCP server answers 403 (DNS rebinding protection).
- Point the platform's health check or an uptime monitor at `/healthz.php`.
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
