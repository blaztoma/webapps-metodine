# webapps-metodine

[Lietuviškai](README.md) · **English**

Teaching examples for the module *Web Application Design* (T120B165, „Saityno taikomųjų programų projektavimas“).

| Folder | Contents | Slides |
|---|---|---|
| [`llm/`](llm/README_EN.md) | Integrating LLMs into web applications: **tool calling** over a JSON-RPC API and an **MCP server** (PHP, SQLite, official Anthropic and MCP PHP SDKs) | [`llm/slides/LLM_integration_2026.pptx`](llm/slides/LLM_integration_2026.pptx) |

## Quick start

```bash
git clone https://github.com/blaztoma/webapps-metodine.git
cd webapps-metodine/llm
php composer.phar install          # Composer: https://getcomposer.org/download/
cp config.local.example.php config.local.php   # then put your own API key there
```

Then open `llm/` through Apache (XAMPP) or your hosting. Details: [llm/README_EN.md](llm/README_EN.md).

> API keys and `config.local.php` are never committed (see `.gitignore`).
