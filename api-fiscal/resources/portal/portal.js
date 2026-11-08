'use strict';
/* Portal do Contador — consulta e manutenção fiscal da empresa.
   Entrada: /portal/acesso#t=<token> (link de uso único gerado pelo sistema da empresa).
   Emitir, cancelar e configurar ficam no sistema da empresa: a API recusa essas rotas para a sessão do portal. */
(() => {
const API = '/api/v1';
let ctx = null;
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const br = v => v == null || v === '' ? '' : Number(v).toLocaleString('pt-BR', {maximumFractionDigits: 4});
const money = v => Number(v || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
const num = v => { if (v === '' || v == null) return undefined; const t = String(v).trim(); return Number(t.includes(',') ? t.replace(/\./g, '').replace(',', '.') : t); };
const enc = encodeURIComponent;

const UFS = ['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO','EX'];
const PESSOA = {F: 'Física', J: 'Jurídica', E: 'Estrangeiro'};
const CST_ICMS = [['00','00 · Tributada integralmente'],['02','02 · Monofásico próprio (combustíveis)'],['10','10 · Tributada com ICMS ST'],['20','20 · Com redução de base de cálculo'],['30','30 · Isenta/não tributada com ICMS ST'],['40','40 · Isenta'],['41','41 · Não tributada'],['50','50 · Suspensão'],['51','51 · Diferimento'],['60','60 · ICMS cobrado anteriormente por ST'],['61','61 · Monofásico cobrado anteriormente'],['70','70 · Redução de base com ICMS ST'],['90','90 · Outras'],['101','101 · Simples: com permissão de crédito'],['102','102 · Simples: sem permissão de crédito'],['103','103 · Simples: isenção por faixa de receita'],['201','201 · Simples: com crédito e ICMS ST'],['202','202 · Simples: sem crédito e com ICMS ST'],['203','203 · Simples: isenção por faixa e ICMS ST'],['300','300 · Simples: imune'],['400','400 · Simples: não tributada'],['500','500 · Simples: ICMS cobrado anteriormente por ST'],['900','900 · Simples: outros']];
const CST_PC = [['01','01 · Alíquota básica'],['02','02 · Alíquota diferenciada'],['03','03 · Alíquota por unidade'],['04','04 · Monofásica, revenda a alíquota zero'],['05','05 · Substituição tributária'],['06','06 · Alíquota zero'],['07','07 · Isenta'],['08','08 · Sem incidência'],['09','09 · Suspensão'],['49','49 · Outras operações de saída'],['99','99 · Outras operações']];
const CAMPO = {
  modBC: ['Modalidade BC', [[3,'3 · Valor da operação'],[0,'0 · Margem de valor agregado'],[1,'1 · Pauta'],[2,'2 · Preço tabelado máximo']]],
  pICMS: ['Alíquota ICMS (%)'], pRedBC: ['Redução da BC do ICMS (%)'], pFCP: ['FCP (%)'], pDif: ['Diferimento (%)'],
  pCredSN: ['Alíquota de crédito do Simples (%)'],
  modBCST: ['Modalidade BC ST', [[4,'4 · Margem de valor agregado (MVA)'],[6,'6 · Valor da operação'],[0,'0 · Preço tabelado (pauta do produto)'],[5,'5 · Pauta (do produto)']]],
  pMVAST: ['MVA ST (%)'], pICMSST: ['Alíquota ICMS ST (%)'], pRedBCST: ['Redução da BC ST (%)'], pFCPST: ['FCP ST (%)'],
  motDesICMS: ['Motivo da desoneração', [['','Sem desoneração'],[1,'1 · Táxi'],[3,'3 · Produtor agropecuário'],[4,'4 · Frotista/locadora'],[5,'5 · Diplomático/consular'],[6,'6 · Utilitários e motos (Amazônia Ocidental/ALC)'],[7,'7 · SUFRAMA'],[8,'8 · Venda a órgão público'],[9,'9 · Outros'],[10,'10 · Deficiente condutor'],[11,'11 · Deficiente não condutor'],[12,'12 · Órgão de fomento'],[90,'90 · Solicitado pelo Fisco']]],
  indDeduzDeson: ['Deduz a desoneração do total da nota', [[0,'Não'],[1,'Sim']]],
  metodo_desoneracao: ['Cálculo do ICMS desonerado (conforme base legal)', [['por_fora','Por fora · base × alíquota'],['por_dentro','Por dentro · base ÷ (1 − alíquota) × alíquota']]],
};
const TEXTO = ['metodo_desoneracao'];
const ORIGENS = [[0,'0 · Nacional'],[1,'1 · Estrangeira, importação direta'],[2,'2 · Estrangeira, mercado interno'],[3,'3 · Nacional, importação > 40%'],[4,'4 · Nacional, processos básicos'],[5,'5 · Nacional, importação ≤ 40%'],[6,'6 · Estrangeira direta, sem similar'],[7,'7 · Estrangeira interna, sem similar'],[8,'8 · Nacional, importação > 70%']];

// ---------------------------------------------------------------- infraestrutura
class ApiError extends Error { constructor(m, status, det) { super(m); this.status = status; this.det = det || []; } }
async function api(path, method = 'GET', body) {
  const h = {'X-Portal': '1', 'Accept': 'application/json'};
  if (body !== undefined) h['Content-Type'] = 'application/json';
  if (method !== 'GET' && ctx) h['X-CSRF-Token'] = ctx.csrf_token;
  const r = await fetch(API + path, {method, headers: h, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'same-origin'});
  if (r.status === 204) return null;
  const d = await r.json().catch(() => ({}));
  if (!r.ok) {
    if (r.status === 401 && ctx) { ctx = null; gate('Sua sessão terminou', d?.erro?.mensagem || 'Abra o painel novamente pelo atalho do seu sistema.'); }
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
function confirmar(titulo, texto, botao = 'Excluir') {
  return new Promise(res => {
    const m = $('#modal');
    m.innerHTML = `<div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="mt"><h2 id="mt">${esc(titulo)}</h2><p>${esc(texto)}</p><div class="form-actions"><button type="button" class="ghost" data-r="0">Cancelar</button><button type="button" class="danger" data-r="1">${esc(botao)}</button></div></div>`;
    m.hidden = false; $('[data-r="0"]', m).focus();
    const close = v => { m.hidden = true; m.innerHTML = ''; document.removeEventListener('keydown', key); res(v); };
    const key = ev => { if (ev.key === 'Escape') close(false); };
    document.addEventListener('keydown', key);
    $$('[data-r]', m).forEach(b => b.onclick = () => close(b.dataset.r === '1'));
  });
}
const sel = (name, label, value, opts, attrs = '') => `<label>${label}<select name="${name}" ${attrs}>${opts.map(([v, t]) => `<option value="${esc(v)}" ${String(v) === String(value ?? '') ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></label>`;
const inp = (name, label, value, attrs = '') => `<label>${label}<input name="${name}" value="${esc(value ?? '')}" ${attrs}></label>`;
const req = t => `<span>${t} <span class="req">*</span></span>`;
const pode = () => ctx?.pode_alterar;
const ro = () => pode() ? '' : 'disabled';
function bindForm(form, fn) {
  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    const btn = $('button[type=submit]', form); if (btn) btn.disabled = true;
    try { await fn(Object.fromEntries(new FormData(form))); } catch (e) { fail(e); } finally { if (btn) btn.disabled = false; }
  });
}
let cadCache = null;
async function cad(force = false) { if (!cadCache || force) cadCache = await api('/tributacao'); return cadCache; }
const optsPerfis = (c, tipo, todos) => [...(todos ? [['', todos]] : []), ...c.perfis.filter(p => p.tipo === tipo).map(p => [p.codigo, `${p.codigo} · ${p.nome}`])];
const optsOps = (c, todos) => [...(todos ? [['', todos]] : []), ...c.operacoes.map(o => [o.codigo, `${o.codigo} · ${o.nome}`])];

// ---------------------------------------------------------------- entrada
function gate(titulo, texto) {
  $('#app').className = '';
  $('#app').innerHTML = `<div class="gate"><div class="card"><div class="brand"><div class="brand-mark">F</div><div><div class="brand-name">Portal Fiscal</div><div class="brand-sub">meuclick</div></div></div><h1>${esc(titulo)}</h1><p class="muted">${esc(texto)}</p></div></div>`;
}
async function boot() {
  try {
    if (location.pathname.replace(/\/$/, '') === '/portal/acesso') {
      const token = new URLSearchParams(location.hash.slice(1)).get('t');
      history.replaceState(null, '', '/portal');
      if (!token) return gate('Link incompleto', 'Abra o painel pelo atalho "Painel fiscal" do seu sistema.');
      ctx = await api('/portal/sessao', 'POST', {token});
      location.hash = '#/' + (ctx.aba || 'inicio');
    } else {
      ctx = await api('/portal/contexto');
    }
  } catch (e) {
    return gate(e.status === 401 ? 'Acesso não autorizado' : 'Não foi possível abrir o portal', e.status === 401 ? e.message : 'Tente novamente em instantes.');
  }
  window.addEventListener('hashchange', route);
  route();
}

// ---------------------------------------------------------------- layout e rotas
const NAV = [
  ['Visão geral', [['inicio', 'Início']]],
  ['Tributação', [['perfis', 'Perfis tributários'], ['operacoes', 'Operações'], ['regras-icms', 'Regras de ICMS'], ['regras-piscofins', 'Regras de PIS/COFINS'], ['regras-iss', 'Regras de ISS (NFS-e)'], ['simulador', 'Simulador de cálculo']]],
  ['Em breve', [[null, 'Notas fiscais'], [null, 'Relatórios']]],
];
function layout(atual) {
  const u = ctx.usuario || {};
  const ini = (u.nome || '?').trim().split(/\s+/).map(p => p[0]).slice(0, 2).join('').toUpperCase();
  $('#app').className = '';
  $('#app').innerHTML = `<div class="shell"><aside class="side"><div class="brand"><div class="brand-mark">F</div><div><div class="brand-name">Portal Fiscal</div><div class="brand-sub">meuclick</div></div></div>
  ${NAV.map(([t, itens]) => `<nav class="nav nav-group" aria-label="${esc(t)}"><div class="nav-title">${esc(t)}</div>${itens.map(([k, l]) => k ? `<a href="#/${k}" class="${atual === k ? 'active' : ''}"><i class="dot"></i>${esc(l)}</a>` : `<span><i class="dot"></i>${esc(l)}<em class="soon">em breve</em></span>`).join('')}</nav>`).join('')}
  <div class="side-foot">Emissão e cancelamento de notas são feitos no sistema da empresa.</div></aside>
  <div class="main"><header class="top"><div class="company"><strong>${esc(ctx.empresa.nome)}</strong><small>${ctx.empresa.cnpj ? 'CNPJ ' + esc(fmtCnpj(ctx.empresa.cnpj)) : ''}</small></div>
  <div class="user"><div class="user-info"><div>${esc(u.nome)}</div><small>${ctx.tipo_usuario === 'contador' ? 'Contador' : 'Cliente'} · ${ctx.perfil === 'fiscal' ? 'pode alterar regras' : 'somente consulta'}</small></div><div class="avatar" aria-hidden="true">${esc(ini)}</div><button type="button" class="ghost sm" id="sair">Sair</button></div></header>
  <section class="content" id="view"></section></div></div>`;
  $('#sair').onclick = async () => { try { await api('/portal/sair', 'POST'); } catch (e) { /* sessão já encerrada */ } ctx = null; gate('Sessão encerrada', 'Para voltar, use o atalho "Painel fiscal" do seu sistema.'); };
  return $('#view');
}
const fmtCnpj = c => String(c).replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
function head(titulo, lead, acoes = '', crumb = '') {
  return `<div class="page-head"><div>${crumb ? `<div class="crumb">${crumb}</div>` : ''}<h1>${esc(titulo)}</h1>${lead ? `<p class="lead">${lead}</p>` : ''}</div>${acoes ? `<div class="actions">${acoes}</div>` : ''}</div>`;
}
async function route() {
  if (!ctx) return;
  const parts = location.hash.replace(/^#\/?/, '').split('/').map(decodeURIComponent);
  const [sec, ...rest] = parts;
  const views = {inicio, perfis, operacoes, 'regras-icms': regrasIcms, 'regras-piscofins': regrasPc, 'regras-iss': regrasIss, simulador};
  const fn = views[sec] || inicio;
  const v = layout(views[sec] ? sec : 'inicio');
  try { await fn(v, rest); } catch (e) { fail(e); v.innerHTML = `<div class="card empty">Não foi possível carregar esta tela.</div>`; }
  window.scrollTo(0, 0);
}
const go = h => { if (location.hash === h) route(); else location.hash = h; };

// ---------------------------------------------------------------- início
async function inicio(v) {
  const [c, icms, pc] = await Promise.all([cad(true), api('/tributacao/regras-icms'), api('/tributacao/regras-piscofins')]);
  const n = t => c.perfis.filter(p => p.tipo === t).length;
  v.innerHTML = head(`Olá, ${(ctx.usuario.nome || '').split(' ')[0]}`, 'Regras tributárias usadas pela API no cálculo das notas desta empresa. O produto informa o perfil, a venda informa a operação e a API encontra a regra pela UF de destino e pelo tipo de pessoa do cliente.') +
  `<div class="stats"><div class="stat"><b>${n('icms')}</b><span>Perfis de ICMS</span></div><div class="stat"><b>${n('piscofins')}</b><span>Perfis de PIS/COFINS</span></div><div class="stat"><b>${c.operacoes.length}</b><span>Operações</span></div><div class="stat"><b>${icms.regras.length}</b><span>Regras de ICMS</span></div><div class="stat"><b>${pc.regras.length}</b><span>Regras de PIS/COFINS</span></div></div>
  <div class="card"><h2>Atalhos</h2><div class="quick">
  <a href="#/regras-icms"><strong>Regras de ICMS</strong><span>CST, CFOP, alíquotas, redução de base, ST e IPI por UF e tipo de pessoa.</span></a>
  <a href="#/regras-piscofins"><strong>Regras de PIS/COFINS</strong><span>CST e alíquotas por perfil e operação.</span></a>
  <a href="#/simulador"><strong>Simulador de cálculo</strong><span>Monte uma venda de teste e confira os impostos antes de valer.</span></a>
  <a href="#/perfis"><strong>Perfis e operações</strong><span>A "tributação" do produto e a natureza da operação.</span></a></div></div>
  ${!pode() ? '<div class="card note warn">Seu acesso é somente de consulta. Para alterar regras, abra o portal com um usuário que tenha permissão fiscal no sistema.</div>' : ''}`;
}

// ---------------------------------------------------------------- perfis e operações
async function perfis(v, [acao, codigo]) {
  const c = await cad(true);
  if (acao === 'novo' || acao === 'editar') {
    const p = acao === 'editar' ? c.perfis.find(x => x.codigo === codigo) : {codigo: '', nome: '', tipo: 'icms'};
    if (!p) return go('#/perfis');
    v.innerHTML = head(acao === 'novo' ? 'Novo perfil tributário' : 'Alterar perfil tributário', 'O perfil é a "tributação" do produto (ex.: 1 · Produto tributado, 2 · Produto substituição). O produto no sistema informa o código do perfil de ICMS e o de PIS/COFINS.', '', '<a href="#/perfis">Perfis tributários</a> / ' + (acao === 'novo' ? 'Novo' : esc(p.codigo))) +
    `<form class="card" id="f"><div class="form-grid three">${inp('codigo', req('Código'), p.codigo, `required maxlength="30" pattern="[A-Za-z0-9_\\-]+" ${acao === 'editar' ? 'disabled' : ''}`)}${inp('nome', req('Nome'), p.nome, `required maxlength="60" ${ro()}`)}${sel('tipo', req('Tipo'), p.tipo, [['icms', 'ICMS'], ['piscofins', 'PIS/COFINS']], ro())}</div>
    <div class="form-actions"><a class="btn ghost" href="#/perfis">Voltar</a>${pode() ? '<button type="submit">Salvar perfil</button>' : ''}</div></form>`;
    bindForm($('#f', v), async d => { await api('/tributacao/perfis/' + enc(p.codigo || d.codigo), 'PUT', {nome: d.nome, tipo: d.tipo}); toast('Perfil salvo.'); go('#/perfis'); });
    return;
  }
  v.innerHTML = head('Perfis tributários', 'Tributação de ICMS e de PIS/COFINS atribuída aos produtos.', pode() ? '<a class="btn" href="#/perfis/novo">Novo perfil</a>' : '') +
  `<div class="card flush"><div class="table-wrap"><table><thead><tr><th>Código</th><th>Nome</th><th>Tipo</th><th></th></tr></thead><tbody>${c.perfis.map(p => `<tr><td><b>${esc(p.codigo)}</b></td><td>${esc(p.nome)}</td><td><span class="badge ${p.tipo === 'icms' ? 'brand' : 'ok'}">${p.tipo === 'icms' ? 'ICMS' : 'PIS/COFINS'}</span></td><td class="actions"><a class="btn ghost sm" href="#/perfis/editar/${enc(p.codigo)}">${pode() ? 'Alterar' : 'Ver'}</a>${pode() ? ` <button type="button" class="danger sm" data-del="${esc(p.codigo)}">Excluir</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="4" class="empty">Nenhum perfil cadastrado.</td></tr>'}</tbody></table></div></div>`;
  $$('[data-del]', v).forEach(b => b.onclick = async () => { if (!await confirmar('Excluir perfil', `Excluir o perfil ${b.dataset.del}? Perfis com regras não podem ser excluídos.`)) return; try { await api('/tributacao/perfis/' + enc(b.dataset.del), 'DELETE'); toast('Perfil excluído.'); route(); } catch (e) { fail(e); } });
}
async function operacoes(v, [acao, codigo]) {
  const c = await cad(true);
  if (acao === 'novo' || acao === 'editar') {
    const o = acao === 'editar' ? c.operacoes.find(x => x.codigo === codigo) : {codigo: '', nome: ''};
    if (!o) return go('#/operacoes');
    v.innerHTML = head(acao === 'novo' ? 'Nova operação' : 'Alterar operação', 'A operação equivale à natureza de operação do sistema (venda, devolução, remessa…). O nome vai para a natureza da operação da nota quando ela não é informada.', '', '<a href="#/operacoes">Operações</a> / ' + (acao === 'novo' ? 'Nova' : esc(o.codigo))) +
    `<form class="card" id="f"><div class="form-grid">${inp('codigo', req('Código'), o.codigo, `required maxlength="30" pattern="[A-Za-z0-9_\\-]+" ${acao === 'editar' ? 'disabled' : ''}`)}${inp('nome', req('Nome'), o.nome, `required maxlength="60" placeholder="VENDA DE PRODUTOS" ${ro()}`)}</div>
    <div class="form-actions"><a class="btn ghost" href="#/operacoes">Voltar</a>${pode() ? '<button type="submit">Salvar operação</button>' : ''}</div></form>`;
    bindForm($('#f', v), async d => { await api('/tributacao/operacoes/' + enc(o.codigo || d.codigo), 'PUT', {nome: d.nome}); toast('Operação salva.'); go('#/operacoes'); });
    return;
  }
  v.innerHTML = head('Operações', 'Naturezas de operação usadas para encontrar as regras.', pode() ? '<a class="btn" href="#/operacoes/novo">Nova operação</a>' : '') +
  `<div class="card flush"><div class="table-wrap"><table><thead><tr><th>Código</th><th>Nome</th><th></th></tr></thead><tbody>${c.operacoes.map(o => `<tr><td><b>${esc(o.codigo)}</b></td><td>${esc(o.nome)}</td><td class="actions"><a class="btn ghost sm" href="#/operacoes/editar/${enc(o.codigo)}">${pode() ? 'Alterar' : 'Ver'}</a>${pode() ? ` <button type="button" class="danger sm" data-del="${esc(o.codigo)}">Excluir</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="3" class="empty">Nenhuma operação cadastrada.</td></tr>'}</tbody></table></div></div>`;
  $$('[data-del]', v).forEach(b => b.onclick = async () => { if (!await confirmar('Excluir operação', `Excluir a operação ${b.dataset.del}? Operações com regras não podem ser excluídas.`)) return; try { await api('/tributacao/operacoes/' + enc(b.dataset.del), 'DELETE'); toast('Operação excluída.'); route(); } catch (e) { fail(e); } });
}

// ---------------------------------------------------------------- regras de ICMS
const filtroIcms = JSON.parse(sessionStorage.getItem('portal_filtro_icms') || '{}');
const chaveIcms = r => [r.perfil, r.operacao, r.uf, r.tipo_pessoa].map(enc).join('/');
async function regrasIcms(v, rest) {
  const [acao, ...k] = rest;
  const c = await cad(true);
  if (acao === 'novo' || acao === 'editar') return formIcms(v, c, acao === 'editar' ? k : null);
  if (acao === 'copiar') return copiarIcms(v, c, k);
  const q = new URLSearchParams(Object.entries({perfil: filtroIcms.perfil, operacao: filtroIcms.operacao, uf: filtroIcms.uf}).filter(([, x]) => x));
  const {regras} = await api('/tributacao/regras-icms?' + q);
  const lista = regras.filter(r => (!filtroIcms.pessoa || r.tipo_pessoa === filtroIcms.pessoa) && (!filtroIcms.busca || `${r.descricao} ${r.cfop} ${r.icms.CST || r.icms.CSOSN}`.toLowerCase().includes(filtroIcms.busca.toLowerCase())));
  v.innerHTML = head('Regras de ICMS', 'Uma regra por perfil, operação, UF de destino e tipo de pessoa. Os campos de cada regra dependem do CST.', pode() ? '<a class="btn" href="#/regras-icms/novo">Nova regra</a>' : '') +
  `<div class="card flush"><form class="toolbar" id="filtro">${sel('perfil', 'Perfil', filtroIcms.perfil, optsPerfis(c, 'icms', 'Todos'))}${sel('operacao', 'Operação', filtroIcms.operacao, optsOps(c, 'Todas'))}${sel('uf', 'UF de destino', filtroIcms.uf, [['', 'Todas'], ...UFS.map(u => [u, u])])}${sel('pessoa', 'Pessoa', filtroIcms.pessoa, [['', 'Todas'], ...Object.entries(PESSOA)])}<label class="grow">Buscar<input name="busca" value="${esc(filtroIcms.busca || '')}" placeholder="Descrição, CFOP ou CST"></label></form>
  <div class="table-wrap"><table><thead><tr><th>Perfil</th><th>Operação</th><th>UF</th><th>Pessoa</th><th>CST</th><th>CFOP</th><th class="num">ICMS %</th><th class="num">Red. BC %</th><th class="num">MVA %</th><th class="num">ICMS ST %</th><th>Descrição</th><th></th></tr></thead><tbody>
  ${lista.map(r => `<tr><td>${esc(r.perfil)}</td><td>${esc(r.operacao)}</td><td><b>${esc(r.uf)}</b></td><td>${PESSOA[r.tipo_pessoa]}</td><td><span class="badge brand">${esc(r.icms.CST || r.icms.CSOSN)}</span></td><td>${esc(r.cfop)}</td><td class="num">${br(r.icms.pICMS)}</td><td class="num">${br(r.icms.pRedBC)}</td><td class="num">${br(r.icms.pMVAST)}</td><td class="num">${br(r.icms.pICMSST)}</td><td>${esc(r.descricao)}</td>
  <td class="actions"><a class="btn ghost sm" href="#/regras-icms/editar/${chaveIcms(r)}">${pode() ? 'Alterar' : 'Ver'}</a>${pode() ? ` <a class="btn ghost sm" href="#/regras-icms/copiar/${chaveIcms(r)}">Copiar</a> <button type="button" class="danger sm" data-del="${esc(chaveIcms(r))}">Excluir</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="12" class="empty">Nenhuma regra encontrada para o filtro.</td></tr>'}
  </tbody></table></div><div class="count">${lista.length} regra(s)</div></div>`;
  const f = $('#filtro', v);
  f.onchange = f.oninput = ev => { const d = Object.fromEntries(new FormData(f)); Object.assign(filtroIcms, d); sessionStorage.setItem('portal_filtro_icms', JSON.stringify(filtroIcms)); if (ev.type === 'change' || ev.target.name !== 'busca') route(); };
  $('[name=busca]', f).addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); route(); } });
  f.onsubmit = ev => ev.preventDefault();
  $$('[data-del]', v).forEach(b => b.onclick = async () => { const [p, o, u, t] = b.dataset.del.split('/').map(decodeURIComponent); if (!await confirmar('Excluir regra de ICMS', `Excluir a regra ${p} · ${o} · ${u} · ${PESSOA[t]}?`)) return; try { await api('/tributacao/regras-icms/' + b.dataset.del, 'DELETE'); toast('Regra excluída.'); route(); } catch (e) { fail(e); } });
}
async function formIcms(v, c, chave) {
  let r;
  if (chave) {
    const [p, o, u, t] = chave;
    const {regras} = await api(`/tributacao/regras-icms?perfil=${enc(p)}&operacao=${enc(o)}&uf=${enc(u)}`);
    r = regras.find(x => x.tipo_pessoa === t);
    if (!r) { toast('Regra não encontrada.', true); return go('#/regras-icms'); }
  } else {
    r = {perfil: filtroIcms.perfil || '', operacao: filtroIcms.operacao || '', uf: filtroIcms.uf || '', tipo_pessoa: filtroIcms.pessoa || 'F', descricao: '', cfop: '5102', icms: {CST: '00', modBC: 3}};
  }
  const cst = r.icms.CST || r.icms.CSOSN;
  v.innerHTML = head(chave ? 'Alterar regra de ICMS' : 'Nova regra de ICMS', 'Somente os campos do CST escolhido são exibidos. Campos com * são obrigatórios.', '', '<a href="#/regras-icms">Regras de ICMS</a> / ' + (chave ? esc(`${r.perfil} · ${r.operacao} · ${r.uf} · ${PESSOA[r.tipo_pessoa]}`) : 'Nova')) +
  `<form class="card" id="f"><div class="form-grid">
  <div class="section-title">Aplicação da regra</div>
  ${sel('perfil', req('Perfil de ICMS'), r.perfil, optsPerfis(c, 'icms'), chave ? 'disabled' : 'required')}${sel('operacao', req('Operação'), r.operacao, optsOps(c), chave ? 'disabled' : 'required')}
  ${sel('uf', req('UF de destino'), r.uf, UFS.map(u => [u, u]), chave ? 'disabled' : 'required')}${sel('tipo_pessoa', req('Tipo de pessoa'), r.tipo_pessoa, Object.entries(PESSOA), chave ? 'disabled' : '')}
  ${inp('descricao', req('Descrição da regra'), r.descricao, `required maxlength="120" placeholder="Identificação da regra" ${ro()}`)}${inp('cfop', req('CFOP'), r.cfop, `required pattern="[1-35-7][0-9]{3}" inputmode="numeric" ${ro()}`)}
  <div class="section-title">ICMS</div>
  <label class="full">${req('CST / CSOSN')}<select name="cst" ${ro()}>${CST_ICMS.map(([x, t]) => `<option value="${x}" ${x === cst ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></label>
  </div><div class="form-grid" id="campos"></div>
  <div class="form-grid"><div class="section-title">IPI (somente NF-e)</div>${inp('ipi_cst', 'CST do IPI', r.ipi?.CST, `pattern="[0-9]{2}" placeholder="Ex.: 52" ${ro()}`)}${inp('ipi_cenq', 'Enquadramento legal', r.ipi?.cEnq || '999', `pattern="[0-9]{3}" ${ro()}`)}${inp('ipi_p', 'Alíquota do IPI (%)', br(r.ipi?.pIPI), `inputmode="decimal" ${ro()}`)}</div>
  <div class="form-actions"><a class="btn ghost" href="#/regras-icms">Voltar</a>${pode() ? '<button type="submit">Salvar regra</button>' : ''}</div></form>`;
  const f = $('#f', v);
  const desenhar = () => {
    const x = f.elements.cst.value, lista = c.campos_por_cst[x] || [], obrig = c.obrigatorios_por_cst[x] || [];
    $('#campos', v).innerHTML = lista.length ? lista.map(k => {
      const [t, ops] = CAMPO[k]; const titulo = obrig.includes(k) ? req(t) : t;
      const val = r.icms[k] ?? (k === 'modBC' ? 3 : k === 'modBCST' ? 4 : '');
      return ops ? sel('i_' + k, titulo, val, ops, ro()) : inp('i_' + k, titulo, br(val), `inputmode="decimal" ${obrig.includes(k) ? 'required' : ''} ${ro()}`);
    }).join('') : '<p class="muted full">Este CST não tem parâmetros na regra (valores retidos e ad rem vêm do produto, na venda).</p>';
  };
  f.elements.cst.onchange = desenhar; desenhar();
  bindForm(f, async d => {
    const x = d.cst, g = {[x.length === 3 ? 'CSOSN' : 'CST']: x};
    for (const k of c.campos_por_cst[x] || []) { const val = f.elements['i_' + k].value; if (val !== '') g[k] = TEXTO.includes(k) ? val : num(val); }
    const body = {descricao: d.descricao, cfop: d.cfop, icms: g};
    if (d.ipi_cst) body.ipi = {CST: d.ipi_cst, cEnq: d.ipi_cenq || '999', ...(d.ipi_p ? {pIPI: num(d.ipi_p)} : {})};
    const k = chave ? r : d;
    await api(`/tributacao/regras-icms/${chaveIcms(k)}`, 'PUT', body);
    toast('Regra salva.'); go('#/regras-icms');
  });
}
async function copiarIcms(v, c, chave) {
  const [p, o, u, t] = chave;
  const {regras} = await api(`/tributacao/regras-icms?perfil=${enc(p)}&operacao=${enc(o)}&uf=${enc(u)}`);
  const r = regras.find(x => x.tipo_pessoa === t);
  if (!r || !pode()) return go('#/regras-icms');
  const caixas = (nome, lista, marcados, wide) => `<div class="checks ${wide ? 'wide' : ''}">${lista.map(([x, l]) => `<label><input type="checkbox" name="${nome}" value="${esc(x)}" ${marcados.includes(x) ? 'checked' : ''}>${esc(l)}</label>`).join('')}</div>`;
  v.innerHTML = head('Copiar regra de ICMS', `Origem: <b>${esc(r.perfil)} · ${esc(r.operacao)} · ${esc(r.uf)} · ${PESSOA[r.tipo_pessoa]}</b> — ${esc(r.descricao)} (CST ${esc(r.icms.CST || r.icms.CSOSN)}, CFOP ${esc(r.cfop)}). Será criada uma regra para cada combinação marcada.`, '', '<a href="#/regras-icms">Regras de ICMS</a> / Copiar') +
  `<form class="card" id="f"><fieldset><legend>UFs de destino <button type="button" class="link" id="todas">marcar todas</button> · <button type="button" class="link" id="nenhuma">desmarcar</button></legend>${caixas('ufs', UFS.map(x => [x, x]), [r.uf])}</fieldset>
  <div class="form-grid"><fieldset><legend>Tipos de pessoa</legend>${caixas('tipos', Object.entries(PESSOA), [r.tipo_pessoa])}</fieldset>
  <fieldset><legend>Perfis de ICMS</legend>${caixas('perfis', optsPerfis(c, 'icms'), [r.perfil], true)}</fieldset>
  <fieldset class="full"><legend>Operações</legend>${caixas('ops', optsOps(c), [r.operacao], true)}</fieldset>
  <label class="inline-check full"><input type="checkbox" name="sobrescrever"> Substituir regras que já existem nas combinações marcadas</label></div>
  <p class="muted" id="resumo"></p>
  <div class="form-actions"><a class="btn ghost" href="#/regras-icms">Voltar</a><button type="submit">Copiar regra</button></div></form>`;
  const f = $('#f', v);
  const marc = n => $$(`[name=${n}]:checked`, f).map(x => x.value);
  const resumo = () => { const n = marc('ufs').length * marc('tipos').length * marc('perfis').length * marc('ops').length; $('#resumo', v).textContent = `${n} combinação(ões) marcada(s), incluindo a de origem (que não é alterada).`; };
  f.onchange = resumo; resumo();
  $('#todas', v).onclick = () => { $$('[name=ufs]', f).forEach(x => x.checked = true); resumo(); };
  $('#nenhuma', v).onclick = () => { $$('[name=ufs]', f).forEach(x => x.checked = false); resumo(); };
  bindForm(f, async () => {
    const res = await api('/tributacao/regras-icms/copiar', 'POST', {origem: {perfil: r.perfil, operacao: r.operacao, uf: r.uf, tipo_pessoa: r.tipo_pessoa}, destino: {ufs: marc('ufs'), tipos_pessoa: marc('tipos'), perfis: marc('perfis'), operacoes: marc('ops')}, sobrescrever: f.elements.sobrescrever.checked});
    toast(`Cópia concluída: ${res.criadas} criada(s), ${res.sobrescritas} substituída(s), ${res.ignoradas.length} já existia(m) e foi(ram) mantida(s).`);
    go('#/regras-icms');
  });
}

// ---------------------------------------------------------------- regras de PIS/COFINS
async function regrasPc(v, [acao, p, o]) {
  const c = await cad(true);
  if (acao === 'novo' || acao === 'editar') {
    let r = {perfil: '', operacao: '', descricao: '', pis: {CST: '01'}, cofins: {CST: '01'}};
    if (acao === 'editar') { const {regras} = await api(`/tributacao/regras-piscofins?perfil=${enc(p)}&operacao=${enc(o)}`); r = regras[0]; if (!r) return go('#/regras-piscofins'); }
    const grupo = (g, ap, x) => `<div class="section-title">${g.toUpperCase()}</div>${sel(g + '_cst', req('CST ' + g.toUpperCase()), x.CST, CST_PC, ro())}${inp(g + '_p', `Alíquota ${g.toUpperCase()} (%)`, br(x[ap]), `inputmode="decimal" ${ro()}`)}${inp(g + '_q', 'Valor por unidade (R$) · CST 03', br(x.vAliqProd), `inputmode="decimal" ${ro()}`)}`;
    v.innerHTML = head(acao === 'novo' ? 'Nova regra de PIS/COFINS' : 'Alterar regra de PIS/COFINS', 'CST 01/02: alíquota obrigatória · CST 03: valor por unidade · CST 04 a 09: sem alíquota.', '', '<a href="#/regras-piscofins">Regras de PIS/COFINS</a> / ' + (acao === 'novo' ? 'Nova' : esc(`${r.perfil} · ${r.operacao}`))) +
    `<form class="card" id="f"><div class="form-grid three">${sel('perfil', req('Perfil de PIS/COFINS'), r.perfil, optsPerfis(c, 'piscofins'), acao === 'editar' ? 'disabled' : 'required')}${sel('operacao', req('Operação'), r.operacao, optsOps(c), acao === 'editar' ? 'disabled' : 'required')}${inp('descricao', req('Descrição da regra'), r.descricao, `required maxlength="120" ${ro()}`)}
    ${grupo('pis', 'pPIS', r.pis)}${grupo('cofins', 'pCOFINS', r.cofins)}</div>
    <div class="form-actions"><a class="btn ghost" href="#/regras-piscofins">Voltar</a>${pode() ? '<button type="submit">Salvar regra</button>' : ''}</div></form>`;
    const g = (d, n, ap) => ({CST: d[n + '_cst'], ...(d[n + '_p'] ? {[ap]: num(d[n + '_p'])} : {}), ...(d[n + '_q'] ? {vAliqProd: num(d[n + '_q'])} : {})});
    bindForm($('#f', v), async d => { const k = acao === 'editar' ? r : d; await api(`/tributacao/regras-piscofins/${enc(k.perfil)}/${enc(k.operacao)}`, 'PUT', {descricao: d.descricao, pis: g(d, 'pis', 'pPIS'), cofins: g(d, 'cofins', 'pCOFINS')}); toast('Regra salva.'); go('#/regras-piscofins'); });
    return;
  }
  const {regras} = await api('/tributacao/regras-piscofins');
  const al = (x, ap) => x.vAliqProd != null ? 'R$ ' + br(x.vAliqProd) : br(x[ap]);
  v.innerHTML = head('Regras de PIS/COFINS', 'Uma regra por perfil de PIS/COFINS e operação.', pode() ? '<a class="btn" href="#/regras-piscofins/novo">Nova regra</a>' : '') +
  `<div class="card flush"><div class="table-wrap"><table><thead><tr><th>Perfil</th><th>Operação</th><th>CST PIS</th><th class="num">PIS</th><th>CST COFINS</th><th class="num">COFINS</th><th>Descrição</th><th></th></tr></thead><tbody>
  ${regras.map(r => `<tr><td>${esc(r.perfil)}</td><td>${esc(r.operacao)}</td><td><span class="badge ok">${esc(r.pis.CST)}</span></td><td class="num">${al(r.pis, 'pPIS')}</td><td><span class="badge ok">${esc(r.cofins.CST)}</span></td><td class="num">${al(r.cofins, 'pCOFINS')}</td><td>${esc(r.descricao)}</td><td class="actions"><a class="btn ghost sm" href="#/regras-piscofins/editar/${enc(r.perfil)}/${enc(r.operacao)}">${pode() ? 'Alterar' : 'Ver'}</a>${pode() ? ` <button type="button" class="danger sm" data-del="${enc(r.perfil)}/${enc(r.operacao)}">Excluir</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="8" class="empty">Nenhuma regra cadastrada.</td></tr>'}
  </tbody></table></div><div class="count">${regras.length} regra(s)</div></div>`;
  $$('[data-del]', v).forEach(b => b.onclick = async () => { if (!await confirmar('Excluir regra de PIS/COFINS', `Excluir a regra ${decodeURIComponent(b.dataset.del).replace('/', ' · ')}?`)) return; try { await api('/tributacao/regras-piscofins/' + b.dataset.del, 'DELETE'); toast('Regra excluída.'); route(); } catch (e) { fail(e); } });
}

// ---------------------------------------------------------------- regras de ISS (NFS-e)
const TRIB_ISS = [[1, '1 · Operação tributável'], [2, '2 · Imunidade'], [4, '4 · Não incidência']];
const RET_ISS = [[1, '1 · Não retido'], [2, '2 · Retido pelo tomador']];
const IMUN = [[0, '0 · Imunidade (tipo não informado)'], [1, '1 · Patrimônio, renda ou serviços entre entes públicos'], [2, '2 · Templos de qualquer culto'], [3, '3 · Partidos, sindicatos, educação e assistência social'], [4, '4 · Livros, jornais, periódicos e papel'], [5, '5 · Fonogramas e videofonogramas musicais']];
async function regrasIss(v, [acao, codigo]) {
  if (acao === 'novo' || acao === 'editar') {
    let r = {codigo: '', descricao: '', servico: {}, iss: {tributacao_iss: 1, retencao_iss: 1}};
    if (acao === 'editar') { try { r = await api('/tributacao/regras-iss/' + enc(codigo)); } catch (e) { fail(e); return go('#/regras-iss'); } }
    const cod = r.servico.codigo_tributacao_nacional || '';
    v.innerHTML = head(acao === 'novo' ? 'Nova regra de ISS' : 'Alterar regra de ISS', 'Usada na emissão da NFS-e: o sistema informa <code>regra_iss</code> e a API preenche o serviço e o ISS. O que o sistema enviar na nota prevalece.', '', '<a href="#/regras-iss">Regras de ISS</a> / ' + (acao === 'novo' ? 'Nova' : esc(r.codigo))) +
    `<form class="card" id="f"><div class="form-grid three">${inp('codigo', req('Código da regra'), r.codigo, `required maxlength="30" pattern="[A-Za-z0-9_\\-]+" ${acao === 'editar' ? 'disabled' : ''}`)}<div class="span2">${inp('descricao', req('Descrição da regra'), r.descricao, `required maxlength="120" ${ro()}`)}</div>
    <div class="section-title">SERVIÇO</div>
    ${pode() ? `<label>Buscar na lista nacional<input id="busca" placeholder="Código ou parte da descrição" autocomplete="off"></label>` : ''}
    <div class="span2"><label>${req('Código de tributação nacional')}<select name="ctn" id="ctn" required ${ro()}>${cod ? `<option value="${esc(cod)}">${esc(cod)}${r.servico_descricao ? ' · ' + esc(r.servico_descricao) : ''}</option>` : '<option value="">Busque o serviço ao lado</option>'}</select></label></div>
    ${inp('nbs', 'NBS (9 dígitos)', r.servico.nbs, `inputmode="numeric" maxlength="12" ${ro()}`)}${inp('ctm', 'Código de tributação municipal', r.servico.codigo_tributacao_municipal, `inputmode="numeric" maxlength="3" ${ro()}`)}${inp('ci', 'Código interno', r.servico.codigo_interno, `maxlength="20" ${ro()}`)}
    <div class="section-title">ISS</div>
    ${sel('trib', req('Tributação do ISS'), r.iss.tributacao_iss, TRIB_ISS, ro())}${sel('ret', req('Retenção'), r.iss.retencao_iss, RET_ISS, ro())}${inp('aliq', 'Alíquota do ISS (%)', br(r.iss.aliquota_iss), `inputmode="decimal" ${ro()}`)}
    <div class="span2" id="imunbox">${sel('imun', 'Tipo de imunidade', r.iss.tipo_imunidade ?? 0, IMUN, ro())}</div></div>
    <p class="hint">Alíquota até 5%. Se o regime do prestador ou o convênio do município não permitir informar a alíquota, a API a omite na DPS e a Sefin aplica a do município.</p>
    <div class="form-actions"><a class="btn ghost" href="#/regras-iss">Voltar</a>${pode() ? '<button type="submit">Salvar regra</button>' : ''}</div></form>`;
    const f = $('#f', v);
    const imun = () => { $('#imunbox', f).hidden = f.trib.value !== '2'; };
    f.trib.onchange = imun; imun();
    const busca = $('#busca', f); let t;
    if (busca) busca.oninput = () => { clearTimeout(t); t = setTimeout(async () => {
      const q = busca.value.trim(); if (q.length < 2) return;
      try { const {servicos} = await api('/nfse/servicos-nacionais?busca=' + enc(q)); $('#ctn', f).innerHTML = servicos.length ? servicos.map(s => `<option value="${esc(s.codigo)}">${esc(s.codigo)} · ${esc(s.descricao)}</option>`).join('') : '<option value="">Nenhum serviço encontrado</option>'; } catch (e) { fail(e); }
    }, 300); };
    bindForm(f, async d => {
      const k = acao === 'editar' ? r.codigo : d.codigo;
      const servico = {codigo_tributacao_nacional: d.ctn, ...(d.nbs ? {nbs: d.nbs.replace(/\D/g, '')} : {}), ...(d.ctm ? {codigo_tributacao_municipal: d.ctm} : {}), ...(d.ci ? {codigo_interno: d.ci} : {})};
      const iss = {tributacao_iss: Number(d.trib), retencao_iss: Number(d.ret), ...(d.trib === '2' ? {tipo_imunidade: Number(d.imun)} : {}), ...(d.aliq ? {aliquota_iss: num(d.aliq)} : {})};
      await api('/tributacao/regras-iss/' + enc(k), 'PUT', {descricao: d.descricao, servico, iss}); toast('Regra salva.'); go('#/regras-iss');
    });
    return;
  }
  const {regras} = await api('/tributacao/regras-iss');
  v.innerHTML = head('Regras de ISS (NFS-e)', 'Serviço e tratamento do ISS usados na emissão da NFS-e. O sistema informa o código da regra na nota.', pode() ? '<a class="btn" href="#/regras-iss/novo">Nova regra</a>' : '') +
  `<div class="card flush"><div class="table-wrap"><table><thead><tr><th>Código</th><th>Descrição</th><th>Serviço nacional</th><th>Tributação</th><th>Retenção</th><th class="num">Alíquota</th><th></th></tr></thead><tbody>
  ${regras.map(r => `<tr><td><strong>${esc(r.codigo)}</strong></td><td>${esc(r.descricao)}</td><td><span class="badge">${esc(r.servico.codigo_tributacao_nacional)}</span> <small class="muted">${esc((r.servico_descricao || '').slice(0, 60))}</small></td><td>${esc(r.tributacao_iss_descricao)}</td><td>${esc(r.retencao_iss_descricao)}</td><td class="num">${r.iss.aliquota_iss != null ? br(r.iss.aliquota_iss) + '%' : '—'}</td><td class="actions"><a class="btn ghost sm" href="#/regras-iss/editar/${enc(r.codigo)}">${pode() ? 'Alterar' : 'Ver'}</a>${pode() ? ` <button type="button" class="danger sm" data-del="${enc(r.codigo)}">Excluir</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="7" class="empty">Nenhuma regra de ISS cadastrada.</td></tr>'}
  </tbody></table></div><div class="count">${regras.length} regra(s)</div></div>`;
  $$('[data-del]', v).forEach(b => b.onclick = async () => { if (!await confirmar('Excluir regra de ISS', `Excluir a regra ${decodeURIComponent(b.dataset.del)}?`)) return; try { await api('/tributacao/regras-iss/' + b.dataset.del, 'DELETE'); toast('Regra excluída.'); route(); } catch (e) { fail(e); } });
}

// ---------------------------------------------------------------- simulador
async function simulador(v) {
  const c = await cad(true);
  const linha = i => `<tr data-i="${i}"><td>${sel('perfil_icms', '', '', optsPerfis(c, 'icms'), 'aria-label="Perfil de ICMS"')}</td><td>${sel('perfil_piscofins', '', '', [['', '—'], ...optsPerfis(c, 'piscofins')], 'aria-label="Perfil de PIS/COFINS"')}</td><td>${sel('origem', '', 0, ORIGENS, 'aria-label="Origem"')}</td><td><input name="quantidade" value="1" inputmode="decimal" aria-label="Quantidade"></td><td><input name="valor_unitario" value="100,00" inputmode="decimal" aria-label="Valor unitário"></td><td><input name="desconto" value="" inputmode="decimal" aria-label="Desconto"></td><td><button type="button" class="ghost sm" data-rm>Remover</button></td></tr>`;
  v.innerHTML = head('Simulador de cálculo', 'Monte uma venda de teste com as regras cadastradas e veja os impostos que a API calcularia. Nada é gravado nem enviado à SEFAZ.') +
  `<form class="card" id="f"><div class="form-grid three">${sel('operacao', req('Operação'), c.operacoes[0]?.codigo, optsOps(c), 'required')}${sel('tipo_pessoa', req('Tipo de pessoa do cliente'), 'F', Object.entries(PESSOA))}${sel('modelo', 'Documento', 65, [[65, 'NFC-e (venda no PDV)'], [55, 'NF-e']])}${sel('uf_destino', 'UF de destino (NF-e)', '', [['', 'Mesma UF da empresa'], ...UFS.map(u => [u, u])])}</div>
  <h3>Itens</h3><div class="table-wrap"><table><thead><tr><th>Perfil de ICMS</th><th>Perfil PIS/COFINS</th><th>Origem</th><th>Qtd.</th><th>Valor unit.</th><th>Desconto</th><th></th></tr></thead><tbody id="itens">${linha(0)}</tbody></table></div>
  <div class="form-actions"><button type="button" class="ghost" id="add">Adicionar item</button><button type="submit">Calcular</button></div></form><div id="res"></div>`;
  const f = $('#f', v);
  let n = 1;
  $('#add', v).onclick = () => { $('#itens', v).insertAdjacentHTML('beforeend', linha(n++)); };
  $('#itens', v).onclick = ev => { if (ev.target.matches('[data-rm]') && $$('#itens tr', v).length > 1) ev.target.closest('tr').remove(); };
  bindForm(f, async d => {
    const itens = $$('#itens tr', v).map(tr => { const g = k => $(`[name=${k}]`, tr).value; return {quantidade: num(g('quantidade')), valor_unitario: num(g('valor_unitario')), ...(g('desconto') ? {desconto: num(g('desconto'))} : {}), origem: Number(g('origem')), perfil_icms: g('perfil_icms'), ...(g('perfil_piscofins') ? {perfil_piscofins: g('perfil_piscofins')} : {})}; });
    try {
      const r = await api('/tributos/calcular?detalhar=1', 'POST', {modelo: Number(d.modelo), operacao: {codigo: d.operacao, tipo_pessoa: d.tipo_pessoa, ...(d.uf_destino && d.modelo === '55' ? {uf_destino: d.uf_destino} : {}), consumidor_final: true}, itens: itens.map(i => i.perfil_piscofins ? i : {...i, regra: {pis: {CST: '07'}, cofins: {CST: '07'}}})});
      mostrar(r);
    } catch (e) { $('#res', v).innerHTML = ''; throw e; }
  });
  function mostrar(r) {
    const t = r.totais.ICMSTot;
    const kv = (l, x) => `<div class="kv"><span>${l}</span><b>${money(x)}</b></div>`;
    $('#res', v).innerHTML = `<div class="card"><h2>Resultado</h2><div class="result-grid">${kv('Produtos', t.vProd)}${kv('Descontos', t.vDesc)}${kv('Base ICMS', t.vBC)}${kv('ICMS', t.vICMS)}${kv('Base ST', t.vBCST)}${kv('ICMS ST', t.vST)}${kv('PIS', t.vPIS)}${kv('COFINS', t.vCOFINS)}${kv('Total da nota', t.vNF)}</div>
    ${r.alertas.length ? `<ul class="alerts">${r.alertas.map(a => `<li>${esc(a)}</li>`).join('')}</ul>` : ''}
    <div class="table-wrap"><table><thead><tr><th>Item</th><th>Regra aplicada</th><th>CFOP</th><th>CST</th><th class="num">Base</th><th class="num">ICMS</th><th class="num">Base ST</th><th class="num">ICMS ST</th><th class="num">PIS</th><th class="num">COFINS</th></tr></thead><tbody>
    ${r.itens.map(i => { const g = i.impostos.icms || {}; const ra = i.regra_aplicada?.icms; return `<tr><td>${i.item}</td><td>${ra ? esc(`${ra.perfil} · ${ra.operacao} · ${ra.uf} · ${PESSOA[ra.tipo_pessoa]}`) + `<br><small class="muted">${esc(ra.descricao)}</small>` : '—'}${i.alertas.length ? `<ul class="alerts">${i.alertas.map(a => `<li>${esc(a)}</li>`).join('')}</ul>` : ''}<details><summary>Memória de cálculo</summary><div class="mono">${esc((i.memoria_calculo || []).join('\n'))}</div></details></td><td>${esc(i.cfop)}</td><td><span class="badge brand">${esc(g.CST || g.CSOSN || '')}</span></td><td class="num">${money(g.vBC)}</td><td class="num">${money(g.vICMS)}</td><td class="num">${money(g.vBCST)}</td><td class="num">${money(g.vICMSST)}</td><td class="num">${money(i.impostos.pis?.vPIS)}</td><td class="num">${money(i.impostos.cofins?.vCOFINS)}</td></tr>`; }).join('')}
    </tbody></table></div><p class="muted">Cálculo ${esc(r.calculo_id)} · regime ${r.regime === 'simples_nacional' ? 'Simples Nacional' : 'normal'}</p></div>`;
    $('#res', v).scrollIntoView({behavior: 'smooth', block: 'start'});
  }
}

boot();
})();
