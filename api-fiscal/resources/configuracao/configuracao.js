'use strict';
/* Configuração do sistema — administração central.
   Os valores ficam cifrados no PostgreSQL (chave-mestra fora do banco). Fora do banco só existem a conexão
   com o banco e a chave-mestra (janela "Inicialização", somente leitura). Padrão visual: Portal do Contador. */
(() => {
const API = '/api/v1';
let ctx = null;   // sessão do painel (/auth/sessao)
let dados = null; // /admin/configuracao
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const enc = encodeURIComponent;

// ---------------------------------------------------------------- infraestrutura (mesmos helpers do portal)
class ApiError extends Error { constructor(m, status, det) { super(m); this.status = status; this.det = det || []; } }
async function api(path, method = 'GET', body) {
  const h = {'Accept': 'application/json'};
  if (body !== undefined) h['Content-Type'] = 'application/json';
  if (method !== 'GET' && ctx) h['X-CSRF-Token'] = ctx.csrf_token;
  const r = await fetch(API + path, {method, headers: h, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'same-origin'});
  if (r.status === 204) return null;
  const d = await r.json().catch(() => ({}));
  if (!r.ok) {
    if (r.status === 401 && ctx) { ctx = null; gate('Sua sessão terminou', 'Entre novamente no painel para continuar.', true); }
    throw new ApiError(d?.erro?.mensagem || 'Falha na comunicação com a API', r.status, d?.erro?.detalhes);
  }
  return d;
}
function toast(msg, error = false) {
  const t = document.createElement('div'); t.className = 'toast' + (error ? ' error' : ''); t.textContent = msg;
  $('#toast').append(t); setTimeout(() => t.remove(), error ? 7000 : 3500);
}
function fail(e) {
  if (e instanceof ApiError && e.status === 401) return;
  const det = (e.det || []).slice(0, 6).map(d => `${d.campo}: ${d.mensagem}`).join(' · ');
  toast(e.message + (det ? ' — ' + det : ''), true);
}
const sel = (name, label, value, opts, attrs = '') => `<label>${label}<select name="${name}" ${attrs}>${opts.map(([v, t]) => `<option value="${esc(v)}" ${String(v) === String(value ?? '') ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></label>`;
const inp = (name, label, value, attrs = '') => `<label>${label}<input name="${name}" value="${esc(value ?? '')}" ${attrs}></label>`;
function bindForm(form, fn) {
  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    const btn = $('button[type=submit]', form); if (btn) btn.disabled = true;
    try { await fn(Object.fromEntries(new FormData(form))); } catch (e) { fail(e); } finally { if (btn) btn.disabled = false; }
  });
}
const go = h => { if (location.hash === h) route(); else location.hash = h; };
async function carregar(force = false) { if (!dados || force) dados = await api('/admin/configuracao'); return dados; }

// ---------------------------------------------------------------- entrada
function gate(titulo, texto, login = false) {
  $('#app').className = '';
  $('#app').innerHTML = `<div class="gate"><div class="card"><div class="brand"><div class="brand-mark">F</div><div><div class="brand-name">API Fiscal</div><div class="brand-sub">configuração do sistema</div></div></div><h1>${esc(titulo)}</h1><p class="muted">${esc(texto)}</p>${login ? '<p><a class="btn" href="/login">Entrar no painel</a></p>' : ''}</div></div>`;
}
async function boot() {
  try { ctx = await api('/auth/sessao'); } catch (e) { return gate('Entre no painel', 'A configuração do sistema usa a sessão do painel administrativo.', true); }
  if (ctx.usuario?.acesso_central !== 'admin') { ctx = null; return gate('Acesso restrito', 'Somente administradores centrais alteram a configuração do sistema.'); }
  window.addEventListener('hashchange', route);
  route();
}

// ---------------------------------------------------------------- layout e rotas
const GRUPOS_NAV = [['acesso', 'Endereço e acesso'], ['sessao', 'Sessões e login'], ['portal', 'Portal do Contador'], ['api', 'Limites da API'], ['email', 'E-mail (SMTP)'], ['fiscal', 'NFS-e e NFC-e'], ['armazenamento', 'Armazenamento de XML']];
const NAV = [
  ['Visão geral', [['parametros', 'Todos os parâmetros'], ['inicializacao', 'Inicialização e segurança']]],
  ['Grupos', GRUPOS_NAV.map(([k, l]) => ['grupo/' + k, l])],
  ['Documentos fiscais', [['responsavel', 'Responsável técnico']]],
];
function layout(atual) {
  const u = ctx.usuario || {};
  const ini = (u.nome || '?').trim().split(/\s+/).map(p => p[0]).slice(0, 2).join('').toUpperCase();
  $('#app').className = '';
  $('#app').innerHTML = `<div class="shell"><aside class="side"><div class="brand"><div class="brand-mark">F</div><div><div class="brand-name">API Fiscal</div><div class="brand-sub">configuração do sistema</div></div></div>
  ${NAV.map(([t, itens]) => `<nav class="nav nav-group" aria-label="${esc(t)}"><div class="nav-title">${esc(t)}</div>${itens.map(([k, l]) => `<a href="#/${k}" class="${atual === k ? 'active' : ''}"><i class="dot"></i>${esc(l)}</a>`).join('')}</nav>`).join('')}
  <div class="side-foot">Valores cifrados no banco. Alterações valem na próxima requisição, sem reiniciar.</div></aside>
  <div class="main"><header class="top"><div class="company"><strong>Configuração do sistema</strong><small><a href="/administracao">← Voltar ao painel</a></small></div>
  <div class="user"><div class="user-info"><div>${esc(u.nome)}</div><small>Administrador central</small></div><div class="avatar" aria-hidden="true">${esc(ini)}</div><button type="button" class="ghost sm" id="sair">Sair</button></div></header>
  <section class="content" id="view"></section></div></div>`;
  $('#sair').onclick = async () => { try { await api('/auth/logout', 'POST'); } catch (e) { /* sessão já encerrada */ } ctx = null; gate('Sessão encerrada', 'Até logo.', true); };
  return $('#view');
}
function head(titulo, lead, acoes = '', crumb = '') {
  return `<div class="page-head"><div>${crumb ? `<div class="crumb">${crumb}</div>` : ''}<h1>${esc(titulo)}</h1>${lead ? `<p class="lead">${lead}</p>` : ''}</div>${acoes ? `<div class="actions">${acoes}</div>` : ''}</div>`;
}
async function route() {
  if (!ctx) return;
  const [sec, ...rest] = location.hash.replace(/^#\/?/, '').split('/').map(decodeURIComponent);
  const views = {parametros, inicializacao, grupo, responsavel};
  const fn = views[sec] || parametros;
  const v = layout(sec === 'grupo' ? 'grupo/' + rest[0] : (views[sec] ? sec : 'parametros'));
  try { await fn(v, rest); } catch (e) { fail(e); v.innerHTML = `<div class="card empty">Não foi possível carregar esta tela.</div>`; }
}

const ORIGEM = {banco: ['ok', 'Banco'], arquivo: ['warn', 'Arquivo antigo'], padrao: ['', 'Padrão']};
const badgeOrigem = o => `<span class="badge ${ORIGEM[o][0]}">${ORIGEM[o][1]}</span>`;
function mostraValor(i) {
  if (i.tipo === 'secret') return i.definido ? '<span class="badge ok">definida</span>' : '<span class="muted">não definida</span>';
  if (i.tipo === 'bool') return i.valor === '1' || i.valor === 'true' ? 'Sim' : 'Não';
  if (i.tipo === 'select') { const o = (i.opcoes || []).find(([x]) => String(x) === String(i.valor)); return esc(o ? o[1] : i.valor); }
  return i.valor === '' ? '<span class="muted">—</span>' : `<span class="mono">${esc(i.valor)}</span>`;
}
const nomeGrupo = g => (dados.grupos.find(x => x.codigo === g) || {}).titulo || g;

// ---------------------------------------------------------------- listagem
const filtroP = JSON.parse(sessionStorage.getItem('config_filtro_parametros') || '{}');
async function parametros(v) {
  const d = await carregar(true);
  const b = (filtroP.busca || '').toLowerCase();
  const lista = d.itens.filter(i => (!filtroP.grupo || i.grupo === filtroP.grupo) && (!filtroP.origem || i.origem === filtroP.origem)
    && (!b || `${i.chave} ${i.rotulo} ${i.tipo === 'secret' ? '' : i.valor}`.toLowerCase().includes(b)));
  const antigos = d.itens.filter(i => i.origem === 'arquivo').length;
  v.innerHTML = head('Todos os parâmetros', 'Configuração usada por todas as empresas desta instalação. <strong>Alterar</strong> abre o grupo do parâmetro.') +
  (antigos ? `<p class="note warn">${antigos} parâmetro(s) ainda vêm de um arquivo .env antigo. Ao salvar o grupo, o valor passa para o banco e o arquivo deixa de ser usado.</p><br>` : '') +
  `<div class="card flush"><form class="toolbar" id="filtro">${sel('grupo', 'Grupo', filtroP.grupo, [['', 'Todos'], ...d.grupos.map(g => [g.codigo, g.titulo])])}${sel('origem', 'Origem', filtroP.origem, [['', 'Todas'], ['banco', 'Banco'], ['arquivo', 'Arquivo antigo'], ['padrao', 'Padrão']])}<label class="grow">Buscar<input name="busca" value="${esc(filtroP.busca || '')}" placeholder="Nome, chave ou valor"></label></form>
  <div class="table-wrap"><table><thead><tr><th>Parâmetro</th><th>Grupo</th><th>Valor</th><th>Origem</th><th></th></tr></thead><tbody>
  ${lista.map(i => `<tr><td><b>${esc(i.rotulo)}</b><br><small class="muted mono">${esc(i.chave)}</small></td><td><span class="badge brand">${esc(nomeGrupo(i.grupo))}</span></td><td>${mostraValor(i)}</td><td>${badgeOrigem(i.origem)}</td><td class="actions"><a class="btn ghost sm" href="#/grupo/${enc(i.grupo)}">Alterar</a></td></tr>`).join('') || '<tr><td colspan="5" class="empty">Nenhum parâmetro encontrado para o filtro.</td></tr>'}
  </tbody></table></div><div class="count">${lista.length} parâmetro(s)${d.atualizado_em ? ` · última alteração em ${esc(new Date(d.atualizado_em).toLocaleString('pt-BR'))}` : ''}</div></div>`;
  const f = $('#filtro', v);
  f.onchange = f.oninput = ev => { Object.assign(filtroP, Object.fromEntries(new FormData(f))); sessionStorage.setItem('config_filtro_parametros', JSON.stringify(filtroP)); if (ev.type === 'change' || ev.target.name !== 'busca') route(); };
  $('[name=busca]', f).addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); route(); } });
  f.onsubmit = ev => ev.preventDefault();
}

// ---------------------------------------------------------------- janela de um grupo
function campo(i) {
  const ajuda = i.ajuda ? `<span class="hint">${esc(i.ajuda)}</span>` : '';
  const lim = i.min != null ? ` min="${i.min}" max="${i.max}"` : '';
  const ph = i.padrao !== '' ? ` placeholder="Padrão: ${esc(i.padrao)}"` : '';
  const val = i.origem === 'padrao' ? '' : i.valor;
  let el;
  if (i.tipo === 'bool') el = sel(i.chave, esc(i.rotulo), i.valor === '1' || i.valor === 'true' ? '1' : '0', [['1', 'Sim'], ['0', 'Não']]);
  else if (i.tipo === 'select') el = sel(i.chave, esc(i.rotulo), i.valor, i.opcoes);
  else if (i.tipo === 'secret') el = `<label>${esc(i.rotulo)}<input name="${i.chave}" type="password" autocomplete="new-password" placeholder="${i.definido ? 'Definida — deixe em branco para manter' : 'Não definida'}"></label>${i.definido ? `<label class="inline-check"><input type="checkbox" name="limpar__${i.chave}"> Apagar a senha guardada</label>` : ''}`;
  else if (i.tipo === 'int') el = inp(i.chave, esc(i.rotulo), val, `type="number" inputmode="numeric"${lim}${ph}`);
  else el = inp(i.chave, esc(i.rotulo), val, `type="${i.tipo === 'url' ? 'url' : i.tipo === 'email' ? 'email' : 'text'}" maxlength="500"${ph}`);
  const wide = ['APP_URL', 'PORTAL_URL', 'TRUSTED_PROXIES', 'NFSE_LEIAUTE_PRODUCAO', 'NFSE_LEIAUTE_HOMOLOGACAO', 'S3_ENDPOINT'].includes(i.chave);
  return `<div class="${wide ? 'span2' : ''}">${el}${ajuda}${i.origem === 'arquivo' ? '<span class="hint">Valor atual vem do arquivo antigo; salve para gravar no banco.</span>' : ''}</div>`;
}
async function grupo(v, [codigo]) {
  const d = await carregar(true);
  const g = d.grupos.find(x => x.codigo === codigo);
  if (!g) return go('#/parametros');
  const itens = d.itens.filter(i => i.grupo === codigo);
  const arm = codigo === 'armazenamento';
  const st = arm ? await api('/admin/armazenamento') : null;
  v.innerHTML = head(g.titulo, esc(g.descricao) + ' Campo vazio = valor padrão do sistema.', '', `<a href="#/parametros">Todos os parâmetros</a> / ${esc(g.titulo)}`) +
  (arm ? `<div class="stats"><div class="stat"><b>${st.provedor === 'b2' ? 'Backblaze B2' : 'Banco'}</b><span>Destino atual</span></div><div class="stat"><b>${st.pendentes}</b><span>Aguardando envio ao B2</span></div><div class="stat"><b>${st.arquivados}</b><span>Enviados e conferidos</span></div><div class="stat"><b>${st.com_falha}</b><span>Com falha (nova tentativa automática)</span></div></div>` : '') +
  `<form class="card" id="f"><div class="form-grid three">${itens.map(campo).join('')}</div>
  <div class="form-actions"><a class="btn ghost" href="#/parametros">Voltar</a>${arm ? '<button type="button" class="ghost" id="testar">Testar conexão</button>' : ''}<button type="submit">Salvar ${esc(g.titulo.toLowerCase())}</button></div></form>`;
  if (arm) $('#testar', v).onclick = async ev => {
    const b = ev.currentTarget; b.disabled = true; const t = b.textContent; b.textContent = 'Testando…';
    try { const f = Object.fromEntries(new FormData($('#f', v))); const r = await api('/admin/configuracao/armazenamento/testar', 'POST', f); toast(r.mensagem); }
    catch (e) { fail(e); } finally { b.disabled = false; b.textContent = t; }
  };
  bindForm($('#f', v), async f => {
    const body = {limpar_segredos: []};
    for (const i of itens) {
      if (f['limpar__' + i.chave]) { body.limpar_segredos.push(i.chave); continue; }
      body[i.chave] = f[i.chave] ?? '';
    }
    dados = await api('/admin/configuracao/' + enc(codigo), 'PUT', body);
    toast(g.titulo + ' salvo.'); go('#/parametros');
  });
}

// ---------------------------------------------------------------- inicialização (somente leitura)
const BOOT = {
  DATABASE_DSN: 'Endereço do PostgreSQL', DATABASE_USER: 'Usuário do PostgreSQL', DATABASE_PASSWORD: 'Senha do PostgreSQL',
  DATABASE_SCHEMA: 'Schema', STORAGE_NAMESPACE: 'Namespace dos dados', CERT_ENCRYPTION_KEY: 'Chave-mestra (cifra certificados e configurações)',
};
async function inicializacao(v) {
  const d = await carregar(true);
  const ini = d.inicializacao;
  v.innerHTML = head('Inicialização e segurança', 'Únicos dados que ficam fora do banco: sem eles a API não chega ao banco nem decifra o que está nele. Na VPS ficam em <code>/etc/phpfiscal/bootstrap.env</code> (root, 600). Os valores nunca são exibidos.') +
  (ini.arquivo_legado_com.length ? `<p class="note warn">O arquivo .env ainda tem ${ini.arquivo_legado_com.length} parâmetro(s) que agora pertencem ao banco: ${ini.arquivo_legado_com.map(esc).join(', ')}. Depois de conferir os valores em "Todos os parâmetros", remova essas linhas do arquivo.</p><br>` : '') +
  `<div class="card flush"><div class="table-wrap"><table><thead><tr><th>Dado</th><th>Variável</th><th>Situação</th><th>Origem</th></tr></thead><tbody>
  ${ini.itens.map(i => `<tr><td><b>${esc(BOOT[i.chave] || i.chave)}</b></td><td class="mono">${esc(i.chave)}</td><td>${i.definido ? '<span class="badge ok">definido</span>' : (['DATABASE_PASSWORD', 'CERT_ENCRYPTION_KEY'].includes(i.chave) ? '<span class="badge warn">ausente</span>' : '<span class="muted">padrão</span>')}</td><td>${i.origem === 'ambiente' ? 'Variável de ambiente' : i.origem === 'arquivo' ? 'Arquivo .env' : '—'}</td></tr>`).join('')}
  </tbody></table></div><div class="count">Perder a chave-mestra torna ilegíveis os certificados A1 e esta configuração: guarde uma cópia fora do servidor.</div></div>`;
}

// ---------------------------------------------------------------- responsável técnico (NF-e/NFC-e)
async function responsavel(v) {
  const c = await api('/admin/config');
  const rt = c.responsavel_tecnico || {};
  v.innerHTML = head('Responsável técnico', 'Grupo infRespTec da NF-e e NFC-e, igual para todas as empresas desta instalação.', '', '<a href="#/parametros">Todos os parâmetros</a> / Responsável técnico') +
  `<form class="card" id="f"><div class="form-grid">${inp('cnpj', 'CNPJ do desenvolvedor *', rt.cnpj, 'required inputmode="numeric" maxlength="18"')}${inp('contato', 'Contato técnico *', rt.contato, 'required maxlength="60"')}${inp('email', 'E-mail técnico *', rt.email, 'required type="email"')}${inp('fone', 'Telefone técnico *', rt.fone, 'required inputmode="tel" maxlength="20"')}</div>
  <div class="form-actions"><a class="btn ghost" href="#/parametros">Voltar</a><button type="submit">Salvar responsável técnico</button></div></form>`;
  bindForm($('#f', v), async d => { await api('/admin/config', 'PUT', {responsavel_tecnico: d}); toast('Responsável técnico salvo.'); go('#/parametros'); });
}

boot();
})();
