// Chat page shared by both examples. Everything from the server - including the model's text -
// is inserted with textContent, never innerHTML: model output is untrusted input.
(() => {
  const root = document.body;
  const endpoint = root.dataset.endpoint;          // chat.php of the example
  const USERS = { 'demo-token-ona': 'Ona', 'demo-token-jonas': 'Jonas' };
  const USER_IDS = { 'demo-token-ona': 1, 'demo-token-jonas': 2 };   // only to highlight rows in the DB view

  const T = {
    lt: { user: 'Naudotojas', send: 'Siųsti', placeholder: 'Klauskite apie savo siuntas…', chat: 'Pokalbis',
          trace: 'Kas vyko serveryje', thinking: 'Galvoja…', tokens: 'tokenai', examples: 'Pavyzdžiai:',
          clear: 'Išvalyti', empty: 'Užduokite klausimą — čia matysite kiekvieną modelio ir įrankių žingsnį.',
          dbTitle: 'Duomenų bazė (tik skaitymui)', close: 'Uždaryti', reload: 'Atnaujinti',
          dbHint: 'Paryškintos dabartinio naudotojo eilutės — tik jas mato įrankiai. Pastabą (note) rašo siuntėjas.' },
    en: { user: 'User', send: 'Send', placeholder: 'Ask about your parcels…', chat: 'Conversation',
          trace: 'What happened on the server', thinking: 'Thinking…', tokens: 'tokens', examples: 'Examples:',
          clear: 'Clear', empty: 'Ask a question — every model and tool step will appear here.',
          dbTitle: 'Database (read-only)', close: 'Close', reload: 'Reload',
          dbHint: 'Highlighted rows belong to the current user — the tools can see only those. The note is written by the sender.' },
  };
  const EXAMPLES = {
    lt: ['Kur mano siunta 150?', 'Kurios mano siuntos dar keliauja?', 'Kas nutiko siuntai 154?', 'Kas su siunta 152?'],
    en: ['Where is package 150?', 'Which of my parcels are still on the way?', 'What happened to package 154?', 'What about package 152?'],
  };
  let lang = localStorageGet('llm-lang') || 'lt';
  let token = 'demo-token-ona';
  let history = [];

  const $ = (sel) => document.querySelector(sel);
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };

  function localStorageGet(k) { try { return localStorage.getItem(k); } catch { return null; } }
  function localStorageSet(k, v) { try { localStorage.setItem(k, v); } catch { /* private mode */ } }

  function applyLang() {
    const t = T[lang];
    $('#lbl-user').textContent = t.user;
    $('#send').textContent = t.send;
    $('#q').placeholder = t.placeholder;
    $('#h-chat').textContent = t.chat;
    $('#h-trace').textContent = t.trace;
    $('#clear').textContent = t.clear;
    $('#lang').textContent = lang === 'lt' ? 'EN' : 'LT';
    const ex = $('#examples');
    ex.replaceChildren(el('span', 'hint', t.examples));
    for (const q of EXAMPLES[lang]) {
      const b = el('button', null, q);
      b.type = 'button';
      b.onclick = () => { $('#q').value = q; $('#form').requestSubmit(); };
      ex.append(b);
    }
    if (!$('#trace').children.length) $('#trace').append(el('p', 'hint', t.empty));
  }

  function addMsg(role, text, meta) {
    const m = el('div', 'msg ' + role, text);
    if (meta) m.append(el('span', 'meta', meta));
    $('#chat').append(m);
    $('#chat').scrollTop = $('#chat').scrollHeight;
    return m;
  }

  const json = (v) => JSON.stringify(v, null, 2);

  function step(cls, head, sub, body) {
    const s = el('div', 'step ' + cls);
    const h = el('div', 'head', head);
    if (sub) h.append(el('span', 'sub', sub));
    s.append(h);
    if (body != null) s.append(el('pre', null, typeof body === 'string' ? body : json(body)));
    $('#trace').append(s);
  }

  // One renderer for the trace entries both chat.php files produce.
  function renderTrace(trace) {
    for (const t of trace) {
      switch (t.type) {
        case 'model_request':
          step('model', `→ ${lang === 'lt' ? 'Modelis' : 'Model'} · #${t.step}`,
               `messages: ${t.messages} · tools: ${t.tools.join(', ')}`);
          break;
        case 'model_response': {
          const uses = t.blocks.filter((b) => b.type === 'tool_use');
          step(uses.length ? 'tool_use' : 'final', `← ${lang === 'lt' ? 'Modelis' : 'Model'} · ${t.stop_reason}`,
               `${t.usage.input_tokens} in / ${t.usage.output_tokens} out`,
               uses.length ? uses.map((u) => `tool_use  ${u.name} ${JSON.stringify(u.input)}`).join('\n')
                           : t.blocks.filter((b) => b.type === 'text').map((b) => b.text).join('\n'));
          break;
        }
        case 'rpc_request':  step('rpc', '→ JSON-RPC', t.url, t.body); break;
        case 'rpc_response': step('rpc', '← JSON-RPC', 'HTTP ' + t.http_status, t.body); break;
        case 'mcp_connect':  step('mcp', '⇄ MCP initialize', t.url, t.server); break;
        case 'mcp_list':     step('mcp', '← MCP tools/list', t.tools.length + ' tools', t.tools); break;
        case 'mcp_call':     step('mcp', '→ MCP tools/call', t.name, t.arguments); break;
        case 'mcp_result':   step('mcp', '← MCP result', t.is_error ? 'isError: true' : '', t.content); break;
        case 'tool_result':  step(t.is_error ? 'err' : 'result', '→ tool_result', t.tool + (t.is_error ? ' · is_error' : ''),
                                  prettyMaybe(t.content)); break;
        default: step('', t.type, '', t);
      }
    }
    $('#trace').scrollTop = $('#trace').scrollHeight;
  }

  function prettyMaybe(s) { try { return json(JSON.parse(s)); } catch { return s; } }

  async function ask(question) {
    addMsg('user', question);
    const waiting = addMsg('assistant', T[lang].thinking);
    $('#send').disabled = true;
    $('#trace').replaceChildren();
    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ question, history }),
      });
      const data = await res.json();
      if (data.trace) renderTrace(data.trace);
      if (!res.ok || data.error) throw new Error(data.error || data.answer || ('HTTP ' + res.status));
      waiting.remove();
      const u = data.usage;
      addMsg('assistant', data.answer, u ? `${u.input_tokens} in / ${u.output_tokens} out ${T[lang].tokens}` : '');
      history.push({ role: 'user', text: question }, { role: 'assistant', text: data.answer });
    } catch (e) {
      waiting.remove();
      addMsg('error', String(e.message || e));
    } finally {
      $('#send').disabled = false;
      $('#q').focus();
    }
  }

  // ---------------------------------------------------------------- DB view (learning aid)
  let dbData = null;
  let dbTab = 'packages';

  async function openDb(reload) {
    const dlg = $('#db-dialog');
    if (!dlg.open) dlg.showModal();
    if (!dbData || reload) {
      $('#db-body').replaceChildren(el('p', 'hint', '…'));
      try {
        const res = await fetch(root.dataset.dbView || '../db-view.php', { cache: 'no-store' });
        dbData = await res.json();
        if (!res.ok || dbData.error) throw new Error(dbData.error || 'HTTP ' + res.status);
      } catch (e) {
        dbData = null;
        $('#db-body').replaceChildren(el('p', 'msg error', String(e.message || e)));
        return;
      }
    }
    renderDb();
  }

  function renderDb() {
    const t = T[lang];
    $('#db-title').textContent = t.dbTitle;
    $('#db-close').textContent = t.close;
    $('#db-reload').textContent = t.reload;
    $('#db-meta').textContent = `${dbData.database} · ${dbData.created}`;
    const tabs = el('div', 'db-tabs');
    for (const name of Object.keys(dbData.tables)) {
      const b = el('button', name === dbTab ? 'on' : null, `${name} (${dbData.tables[name].rows.length})`);
      b.type = 'button';
      b.onclick = () => { dbTab = name; renderDb(); };
      tabs.append(b);
    }
    const { columns, rows } = dbData.tables[dbTab];
    // which column links a row to a user: users.id, packages.owner_id, package_events -> via its package
    const uid = USER_IDS[token];
    const ownPackages = new Set(dbData.tables.packages.rows.filter((r) => r[1] === uid).map((r) => r[0]));
    const mine = (r) => dbTab === 'users' ? r[0] === uid : dbTab === 'packages' ? r[1] === uid : ownPackages.has(r[1]);

    const table = el('table', 'db');
    const head = el('tr');
    for (const c of columns) head.append(el('th', null, c));
    table.append(head);
    for (const r of rows) {
      const tr = el('tr', mine(r) ? 'mine' : null);
      r.forEach((v, i) => tr.append(el('td', columns[i] === 'note' && v ? 'note' : null, v === null ? 'NULL' : String(v))));
      table.append(tr);
    }
    const wrap = el('div', 'db-wrap');
    wrap.append(table);
    $('#db-body').replaceChildren(tabs, el('p', 'hint', t.dbHint), wrap);
  }

  function reset() {
    history = [];
    $('#chat').replaceChildren();
    $('#trace').replaceChildren(el('p', 'hint', T[lang].empty));
  }

  // wiring
  const sel = $('#user');
  for (const [tok, name] of Object.entries(USERS)) {
    const o = el('option', null, name); o.value = tok; sel.append(o);
  }
  sel.onchange = () => { token = sel.value; reset(); if (dbData && $('#db-dialog').open) renderDb(); };
  $('#lang').onclick = () => {
    lang = lang === 'lt' ? 'en' : 'lt'; localStorageSet('llm-lang', lang); applyLang();
    if (dbData && $('#db-dialog').open) renderDb();
  };
  $('#db').onclick = () => openDb(true);
  $('#db-reload').onclick = () => openDb(true);
  $('#db-close').onclick = () => $('#db-dialog').close();
  $('#clear').onclick = reset;
  $('#form').onsubmit = (e) => {
    e.preventDefault();
    const q = $('#q').value.trim();
    if (!q) return;
    $('#q').value = '';
    ask(q);
  };
  applyLang();
})();
