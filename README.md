# webapps-metodine

**Lietuviškai** · [English](README_EN.md)

Modulio „Saityno taikomųjų programų projektavimas“ (T120B165) / *Web Application Design* mokomieji pavyzdžiai.

| Katalogas | Turinys | Skaidrės |
|---|---|---|
| [`llm/`](llm/) | LLM integravimas į saityno programas: **tool calling** su JSON-RPC API ir **MCP serveris** (PHP, SQLite, oficialūs Anthropic ir MCP PHP SDK) | [`llm/slides/LLM_integration_2026.pptx`](llm/slides/LLM_integration_2026.pptx) (anglų k.) |

## Greitas paleidimas

```bash
git clone https://github.com/blaztoma/webapps-metodine.git
cd webapps-metodine/llm
php composer.phar install          # Composer: https://getcomposer.org/download/
cp config.local.example.php config.local.php   # ir įrašykite savo API raktą
```

Tada atidarykite `llm/` per Apache (XAMPP) arba hostingą. Išsamiau — [llm/README.md](llm/README.md).

> API raktai ir `config.local.php` į repozitoriją nekeliami (žr. `.gitignore`).
