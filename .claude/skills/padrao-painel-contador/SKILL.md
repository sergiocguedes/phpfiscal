---
name: padrao-painel-contador
description: Padrão obrigatório para TODA página ou tela nova da API Fiscal (phpfiscal/api-fiscal) — layout, cores e navegação do Portal do Contador (resources/portal): menu lateral que abre as janelas, listagem com filtros em cima e ações que abrem em janela própria. Use sempre que for criar uma tela, aba, cadastro, consulta ou área web nova, no portal ou fora dele, ou refazer uma existente.
---

# Padrão Painel do Contador

Referência viva: `api-fiscal/resources/portal/` (`index.html`, `portal.css`, `portal.js`).
Toda tela **nova** segue esse padrão. Telas antigas com outro visual (ex.: `resources/painel`, o painel
administrativo) só mudam quando o usuário pedir.

## Onde a tela nova entra

1. **Tela do Portal do Contador** (o caso normal): é uma *view* dentro de `portal.js`.
   - Acrescente o item em `NAV` (grupo, chave, rótulo) e a função em `views` dentro de `route()`.
   - Se o sistema puder abrir a tela direto pelo atalho, inclua a chave em `PortalAcesso::ABAS`
     (`src/Portal/PortalAcesso.php`) e na tabela de `abrir.aba` de `docs/PORTAL-CONTADOR.md`.
   - As rotas da API usadas pela tela precisam do marcador `$portal` em `src/App.php` (e `$owner + $portal`
     nas que alteram dados), senão a sessão do portal é recusada.
   - Rotas por hash: `#/chave` (listagem), `#/chave/novo`, `#/chave/editar/<id>`, `#/chave/copiar/<id>`.
2. **Área web nova fora do portal**: nova pasta em `resources/<area>/` com `index.html` + `<area>.js` no
   mesmo esqueleto do portal (`#app`, `#toast`, `#modal`), reaproveitando `portal.css` (servir o mesmo arquivo
   ou copiar sem alterar tokens). Registre a rota e a lista branca de arquivos em `src/Http/Panel.php`
   (igual ao bloco `/portal`). CSP é `'self'`: nada de CDN, script inline ou estilo inline com cor fixa.

Nunca crie outro tema, outra paleta ou outro framework de CSS. Faltou um componente → acrescente ao
`portal.css` com os tokens existentes.

## Estrutura obrigatória

```
┌ side (256px, branco) ─┬ top: empresa/CNPJ · usuário · perfil · Sair (sticky) ───┐
│ marca + nome          │ content (max 1280px)                                     │
│ GRUPO                 │  page-head: crumb / h1 / lead ............ [Novo …]      │
│  • Janela A (active)  │  card.flush                                              │
│  • Janela B           │   form.toolbar: [Perfil][Operação][UF][Buscar.......]    │
│ EM BREVE              │   table: th maiúsculo cinza · linhas · td.actions        │
│  • X  (em breve)      │   count: "12 regra(s)"                                   │
│ side-foot (aviso)     │                                                          │
└───────────────────────┴──────────────────────────────────────────────────────────┘
```

### 1. Menu lateral abre as janelas
- Cada janela é um item de `NAV`: `['Grupo', [['chave', 'Rótulo'], ...]]`; o item ativo usa `.nav a.active`
  (`--brand-soft` / `--brand-2`). Item ainda não feito: `[null, 'Rótulo']` (selo “em breve”).
- `layout(atual)` desenha menu + barra superior; a view só preenche `#view`.

### 2. Listagem com filtros em cima
- Janela de consulta = `head(título, lead, botãoNovo)` + `div.card.flush` contendo, nesta ordem:
  `form.toolbar#filtro` → `div.table-wrap > table` → `div.count`.
- Filtros **acima** da tabela, dentro do mesmo card. Selects com “Todos/Todas” primeiro (`optsPerfis(c, …, 'Todos')`);
  busca textual em `<label class="grow">Buscar<input name="busca">`.
- Filtro aplica no `change` (busca no Enter/digitação) e fica salvo em `sessionStorage`
  (`portal_filtro_<janela>`), como `filtroIcms` em `regrasIcms`, para voltar da janela de ação com o mesmo filtro.
- Colunas numéricas com `class="num"`; códigos/situações em `.badge` (`brand`, `ok`, `warn`).
- Sem resultado: `<tr><td colspan="N" class="empty">Nenhum … encontrado para o filtro.</td></tr>`.

### 3. Ações abrem em outra janela
- Novo / Alterar / Ver / Copiar são **links** `<a class="btn ghost sm" href="#/chave/editar/…">` que abrem uma
  janela própria (rota) — nada de edição na linha ou em modal. “Novo …” fica em `page-head .actions` (`a.btn`).
- Janela de ação: `head(...)` com `crumb` `<a href="#/chave">Listagem</a> / item`, `form.card#f` com `.form-grid`
  (`three` para 3 colunas, `.full`/`.span2` para largura), `.section-title` entre blocos e
  `.form-actions` com **Voltar** (`a.btn.ghost`) + **Salvar …** (`button[type=submit]`).
- Envio com `bindForm(form, async d => …)`; ao salvar `toast('… salvo.')` e `go('#/chave')`.
- Excluir é a única ação na linha: `button.danger.sm[data-del]` + `await confirmar(...)` antes do `DELETE`.
- Somente consulta (`!pode()`): esconda Novo/Salvar/Excluir, troque “Alterar” por “Ver” e use `ro()` nos campos.

## Cores e tipografia (tokens do `portal.css`, não inventar outros)

| Token | Valor | Uso |
| --- | --- | --- |
| `--bg` | `#f5f7fb` | fundo |
| `--panel` | `#fff` | menu, cards, tabelas |
| `--ink` / `--ink-2` / `--muted` | `#0f172a` / `#334155` / `#64748b` | texto principal / secundário / apoio |
| `--line` / `--line-2` | `#e5e9f2` / `#eef1f7` | bordas / divisórias |
| `--brand` / `--brand-2` / `--brand-soft` | `#4f46e5` / `#4338ca` / `#eef2ff` | botões, links, ativo |
| `--ok` `--warn` `--danger` (+ `-soft`) | `#047857` `#b45309` `#b91c1c` | estados |
| `--radius` | `14px` | cards (campos/botões 10px) |

Fonte `Inter, "Segoe UI", system-ui` 14px; `h1` 24px/700; `th` 11.5px maiúsculo. Sempre `var(--…)`.
Abaixo de 900px o menu vira faixa horizontal (já no CSS).

## Helpers do `portal.js` (reusar, não duplicar)

`api(path, method, body)` (CSRF e 401 já tratados), `head`, `sel`, `inp`, `req`, `caixas`, `bindForm`, `confirmar`,
`toast`, `fail`, `go`, `route`, `esc` (obrigatório em todo valor interpolado), `enc`, `br`, `money`, `num`,
`pode`, `ro`, `cad`.

## Modelo de janela (listagem + ação)

```js
const filtroX = JSON.parse(sessionStorage.getItem('portal_filtro_x') || '{}');
async function xis(v, [acao, id]) {
  if (acao === 'novo' || acao === 'editar') return formX(v, acao === 'editar' ? id : null);
  const {itens} = await api('/recurso');
  const lista = itens.filter(r => !filtroX.busca || `${r.codigo} ${r.nome}`.toLowerCase().includes(filtroX.busca.toLowerCase()));
  v.innerHTML = head('Título', 'O que esta janela mostra.', pode() ? '<a class="btn" href="#/xis/novo">Novo item</a>' : '') +
  `<div class="card flush"><form class="toolbar" id="filtro">${sel('situacao', 'Situação', filtroX.situacao, [['', 'Todas'], ['A', 'Ativos']])}<label class="grow">Buscar<input name="busca" value="${esc(filtroX.busca || '')}" placeholder="Código ou nome"></label></form>
  <div class="table-wrap"><table><thead><tr><th>Código</th><th>Nome</th><th></th></tr></thead><tbody>
  ${lista.map(r => `<tr><td><b>${esc(r.codigo)}</b></td><td>${esc(r.nome)}</td><td class="actions"><a class="btn ghost sm" href="#/xis/editar/${enc(r.codigo)}">${pode() ? 'Alterar' : 'Ver'}</a>${pode() ? ` <button type="button" class="danger sm" data-del="${esc(r.codigo)}">Excluir</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="3" class="empty">Nenhum item encontrado para o filtro.</td></tr>'}
  </tbody></table></div><div class="count">${lista.length} item(ns)</div></div>`;
  const f = $('#filtro', v);
  f.onchange = f.oninput = ev => { Object.assign(filtroX, Object.fromEntries(new FormData(f))); sessionStorage.setItem('portal_filtro_x', JSON.stringify(filtroX)); if (ev.type === 'change' || ev.target.name !== 'busca') route(); };
  $('[name=busca]', f).addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); route(); } });
  f.onsubmit = ev => ev.preventDefault();
  $$('[data-del]', v).forEach(b => b.onclick = async () => { if (!await confirmar('Excluir item', `Excluir o item ${b.dataset.del}?`)) return; try { await api('/recurso/' + enc(b.dataset.del), 'DELETE'); toast('Item excluído.'); route(); } catch (e) { fail(e); } });
}
async function formX(v, id) {
  const r = id ? await api('/recurso/' + enc(id)) : {codigo: '', nome: ''};
  v.innerHTML = head(id ? 'Alterar item' : 'Novo item', 'Campos com * são obrigatórios.', '', '<a href="#/xis">Título</a> / ' + (id ? esc(id) : 'Novo')) +
  `<form class="card" id="f"><div class="form-grid">${inp('nome', req('Nome'), r.nome, `required maxlength="60" ${ro()}`)}</div>
  <div class="form-actions"><a class="btn ghost" href="#/xis">Voltar</a>${pode() ? '<button type="submit">Salvar item</button>' : ''}</div></form>`;
  bindForm($('#f', v), async d => { await api('/recurso' + (id ? '/' + enc(id) : ''), id ? 'PUT' : 'POST', {nome: d.nome}); toast('Item salvo.'); go('#/xis'); });
}
// NAV: ['Grupo', [['xis', 'Título']]]   ·   views: {..., xis}
```

## Checklist antes de entregar

- [ ] Janela registrada em `NAV` e `views` (ou área nova registrada em `Panel.php`).
- [ ] Listagem com `toolbar` de filtros em cima, filtro persistido e `count`.
- [ ] Novo/Alterar/Copiar abrem rota própria com crumb, Voltar e Salvar.
- [ ] Excluir com `confirmar`; perfil consulta respeitado (`pode()`/`ro()`).
- [ ] Só tokens do `portal.css`; todo valor dinâmico em `esc()`.
- [ ] Testado no navegador em `http://localhost:8081/portal` (via atalho `/portal/acesso`).
