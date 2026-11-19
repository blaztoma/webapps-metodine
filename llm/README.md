# LLM integravimas į saityno programas — pavyzdžiai

**Lietuviškai** · [English](README_EN.md)

Du nedideli PHP projektai [skaidrėms](slides/LLM_integration_2026.pptx) iliustruoti. Abu naudoja tą pačią **ParcelShop** siuntų
duomenų bazę (SQLite) ir tą patį pokalbio puslapį, kuriame matosi kiekvienas serverio žingsnis.

| | Projektas | Ką rodo | Skaidrės |
|---|---|---|---|
| 1 | `01-tool-calling/` | Modelis prašo įrankio (`tool_use`), `chat.php` jį vykdo kviesdamas **mūsų siuntų API per JSON-RPC 2.0** su naudotojo žetonu ir grąžina `tool_result`. | *Tool calling: the model asks, your code acts*, *Tool calling in code* |
| 2 | `02-mcp/` | Tie patys įrankiai **MCP serveryje** (oficialus MCP PHP SDK, Streamable HTTP). `chat.php` yra MCP klientas: `tools/list` → modeliui, `tool_use` → `tools/call`. | *MCP: one protocol for tools*, *Exposing your API as an MCP server*, *Where the MCP server sits* |

```
1)  naršyklė ──JWT──▶ chat.php ──messages + tools──▶ modelis (Claude)
                       chat.php ◀──tool_use──────────
                       chat.php ──JSON-RPC──▶ api/rpc.php ──▶ shared/db.php ──▶ SQLite
                       chat.php ──tool_result────────▶ modelis ──▶ atsakymas

2)  naršyklė ──JWT──▶ chat.php (MCP klientas) ──initialize, tools/list──▶ server.php (MCP serveris)
                       chat.php ──messages + tools──▶ modelis
                       chat.php ──tools/call (naudotojo žetonas)──▶ server.php ──▶ shared/db.php ──▶ SQLite
```

Abiejų `chat.php` ciklas yra toks pat — skiriasi tik tai, **iš kur** ateina įrankių aprašai ir
**kaip** įrankis įvykdomas. Tai ir yra atsakymas į klausimą „ar tool calling ir MCP yra tas pats?“.

## Kur yra API MCP pavyzdyje?

MCP serveris nekviečia JSON-RPC API per HTTP. `api/rpc.php` ir `02-mcp/server.php` yra **dvi sąsajos
virš to paties sluoksnio** `shared/db.php`. Jo funkcijos `packages_of()` ir `package_of()` visada filtruoja
pagal savininką, todėl taisyklės ir teisės aprašytos vienoje vietoje:

```
api/rpc.php        (JSON-RPC) ─┐
                               ├──▶ shared/db.php (užklausos, teisės) ──▶ SQLite
02-mcp/server.php  (MCP)      ─┘
```

MCP serveris yra sąsajos sluoksnis, kaip REST valdiklis, todėl verslo logika jame negyvena. Praktikoje
sutinkami trys variantai (skaidrė *Where the MCP server sits*):

| | Variantas | Kada | Į ką atkreipti dėmesį |
|---|---|---|---|
| 1 | **Dar viena programos sąsaja**: MCP įrankiai šalia REST valdiklių kviečia tas pačias paslaugas. *Mūsų pavyzdys.* | Jūsų pačių programa; įprastas pasirinkimas. | Logika ir teisių tikrinimas — paslaugų sluoksnyje, ne įrankiuose. |
| 2 | **Adapteris virš esamo API**: MCP serveris per HTTP kviečia jau esamą REST API. | API jau yra, arba MCP serverį kuria kita komanda. Taip daro dauguma SaaS produktų. | **Jokio *token passthrough*.** MCP serveris priima tik jam išduotus žetonus (OAuth *audience*) ir neperduoda jų toliau. API kvietimui jis gauna savo žetoną naudotojo vardu, pvz., per *token exchange* (RFC 8693). |
| 3 | **Tiesiai į duomenų bazę**, pvz., bendras PostgreSQL MCP serveris. | Vidiniai kūrėjų įrankiai, analitika, prototipai. | Nėra verslo taisyklių, todėl tinka ne galutiniams naudotojams. |

Demonstraciniai `demo-token-…` žetonai skirti tik mokymuisi. Nutolęs MCP serveris produkcijoje
autorizuoja per OAuth 2.1, kaip nurodyta MCP specifikacijoje.

## Struktūra

```
llm/
├─ index.html                 pradžios puslapis su nuorodomis į abu pavyzdžius
├─ slides/                    paskaitos skaidrės (LLM_integration_2026.pptx, anglų k.)
├─ db-view.php                mygtukas „DB“: visa demonstracinė bazė tik skaitymui (mokymuisi)
├─ config.php                 nustatymai (modelis, ribos, adresai)
├─ config.local.example.php   → nukopijuokite į config.local.php ir įrašykite API raktą
├─ composer.json              anthropic-ai/sdk, guzzlehttp/guzzle, mcp/sdk
├─ data/
│  ├─ schema.sql              lentelės ir pavyzdiniai duomenys (datos — santykinės „šiandien“ atžvilgiu)
│  └─ init.php                php data/init.php — sukuria duomenų bazę iš naujo
├─ shared/
│  ├─ db.php                  PDO + užklausos, visada apribotos savininku (bendras rpc.php ir server.php)
│  ├─ llm.php                 Anthropic klientas, sistemos raginimas, istorija, JSON atsakymai
│  └─ chat.js, chat.css       pokalbio puslapis ir „kas vyko serveryje“ juosta
├─ 01-tool-calling/
│  ├─ index.html
│  ├─ chat.php                ★ įrankių ciklas
│  ├─ tools.php               ★ įrankių aprašai + JSON-RPC klientas
│  └─ api/rpc.php             ★ siuntų API (JSON-RPC 2.0 serveris, be jokio LLM)
└─ 02-mcp/
   ├─ index.html
   ├─ chat.php                ★ MCP klientas + tas pats įrankių ciklas
   └─ server.php              ★ MCP serveris (Server::builder()->addTool(...))
```

## Paleidimas XAMPP

1. **Priklausomybės** (jei `vendor/` nėra):
   ```bash
   cd H:\xamppV8\htdocs\metodine\llm
   H:\xamppV8\php\php.exe H:\xamppV8\php\composer.phar install
   ```
2. **API raktas** — nukopijuokite `config.local.example.php` į `config.local.php` ir įrašykite raktą
   iš <https://platform.claude.com>. Arba nustatykite aplinkos kintamąjį `ANTHROPIC_API_KEY`
   (Apache jį mato tik jei nustatytas prieš paleidžiant Apache).
3. **Duomenų bazė** susikuria pati pirmą kartą; atstatyti: `H:\xamppV8\php\php.exe data\init.php`.
4. Paleiskite Apache ir atidarykite <http://localhost/metodine/llm/>.

> **„could not find driver“ / `Unexpected token '<'`** — Apache naudojamame PHP neįjungtas SQLite.
> Kurį PHP naudoja Apache, rodo `PHPINIDir` faile `apache\conf\extra\httpd-xampp.conf` (pvz., `H:\xamppV8\php84`).
> To PHP `php.ini` faile atkomentuokite `extension=pdo_sqlite` ir `extension=sqlite3`, tada perkraukite Apache.
> Klaidų detalės — `apache\logs\error.log`.

> **„Anthropic API Connection Error“ / `cURL error 60: … unable to get local issuer certificate`** — PHP neranda
> SSL sertifikatų paketo. Apache naudojamo PHP `php.ini` nustatykite
> `curl.cainfo = "H:\xamppV8\apache\bin\curl-ca-bundle.crt"` ir `openssl.cafile` tą patį kelią
> (arba naujausią <https://curl.se/ca/cacert.pem>), tada perkraukite Apache.

Demonstraciniai naudotojai (pasirenkami puslapio viršuje):

| Naudotojas | Bearer žetonas | Siuntos |
|---|---|---|
| Ona | `demo-token-ona` | 150 (keliauja), 151 (pristatyta), 154 (pamesta) |
| Jonas | `demo-token-jonas` | 152 (keliauja, **pastaboje — raginimų injekcija**), 153 (sukurta) |

### Ką verta išbandyti paskaitoje

- **„Kur mano siunta 150?“** kaip Ona — vienas `get_package`, atsakymas iš tikrų duomenų.
- **„Kurios mano siuntos dar keliauja?“** — modelis pats pasirenka `list_my_packages` su `status`.
- **„Kas su siunta 152?“** kaip **Ona** — įrankis grąžina klaidą „not found for this user“: asistentas mato tik tai, ką mato naudotojas.
- Tas pats kaip **Jonas** — siuntos pastaboje yra „Ignore all previous instructions…“ (skaidrė *Prompt injection through your own data*). Įrankiai tik skaito, todėl apgautas modelis negalėtų nieko atšaukti.
- Dešinėje juostoje matosi kiekvienas žingsnis ir tokenų skaičius (`usage`).
- Mygtukas **DB** atidaro visas lenteles: paryškintos dabartinio naudotojo eilutės — tik jas mato įrankiai.
  Taip galima palyginti, ką grąžino įrankis, su tuo, kas iš tikrųjų yra duomenų bazėje.

## Siuntų API (1 pavyzdys) be modelio

```bash
curl -X POST http://localhost/metodine/llm/01-tool-calling/api/rpc.php -H "Authorization: Bearer demo-token-ona" -H "Content-Type: application/json" -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"packages.get\",\"params\":{\"id\":150}}"
```

Metodai: `packages.list {status?}`, `packages.get {id}`. Klaidos — JSON-RPC kodai
(`-32601` nėra metodo, `-32602` blogi parametrai, `-32004` siunta nerasta, `-32001` be žetono).
Palaikomi ir paketai (JSON masyvas) bei pranešimai (be `id`).

## MCP serveris (2 pavyzdys) kitiems klientams

Serverį gali naudoti bet kuris MCP klientas, ne tik mūsų `chat.php`:

- **Claude Code:**
  ```bash
  claude mcp add --transport http parcel-shop http://localhost/metodine/llm/02-mcp/server.php --header "Authorization: Bearer demo-token-ona"
  ```
- **MCP Inspector:** `npx @modelcontextprotocol/inspector` → Transport *Streamable HTTP*,
  URL `http://localhost/metodine/llm/02-mcp/server.php`, antraštė `Authorization: Bearer demo-token-ona`.

Serveris palaiko abi protokolo kartas: naujausią be būsenos (2026-07-28, kurią pasirenka SDK klientas)
ir senesnę su `initialize` ir `Mcp-Session-Id` (sesijos saugomos `data/mcp-sessions/`).

## Nuotoliniame serveryje

- PHP ≥ 8.1 su `curl`, `openssl`, `pdo_sqlite`, `fileinfo`; leidžiamas išeinantis HTTPS į `api.anthropic.com`.
- Įkelkite visą katalogą su `vendor/` arba serveryje paleiskite `composer install --no-dev`.
- `config.local.php` su raktu — tik serveryje; `.htaccess` neleidžia jo atsisiųsti (taip pat `data/`, `vendor/`).
- `db-view.php` rodo visą bazę, įskaitant demonstracinius žetonus. Mokymuisi tai gerai, bet tikroje programoje tokio puslapio neturi būti.
- Į `mcp_allowed_hosts` (config) įrašykite savo domeną — kitaip MCP serveris atsakys 403 (DNS rebinding apsauga).
- Jei `Authorization` antraštė nepasiekia PHP (CGI/FPM), ją perduoda šakninis `.htaccess`.

## Pastabos

- Modelis: `claude-opus-5-5`, `effort: low` (pokalbiui greičiau ir pigiau), daugiausia 5 įrankių žingsniai.
  Naudojamas beta galinis taškas tik dėl serverio pusės `fallbacks: "default"`: jei modelis atsisako dėl
  politikos, API automatiškai pabando atsarginį modelį.
- Pokalbio istoriją laiko naršyklė ir siunčia kiekvieną kartą (API neturi būsenos). Tikroje programoje
  istorija būtų duomenų bazėje.
- Atsakymai nesiunčiami srautu (SSE), kad kodas būtų trumpesnis — srautą rodo skaidrės *Streaming in code*.
- Visa, kas ateina iš serverio, puslapyje rodoma per `textContent`, ne `innerHTML` (modelio išvestis nepatikima).
- MCP PHP SDK (`mcp/sdk`) dar 0.x versijos: PHP 8.1 tinka 0.9, naujesnės versijos reikalauja PHP 8.2.
