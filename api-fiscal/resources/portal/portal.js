'use strict';
/* Portal do Contador — área do contador e do cliente para acompanhar a empresa.
   A tributação (perfis, operações e regras) foi retirada: os códigos fiscais vêm no JSON de cada nota enviado pelo ERP.
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
const caixas = (nome, lista, marcados, wide) => `<div class="checks ${wide ? 'wide' : ''}">${lista.map(([x, l]) => `<label><input type="checkbox" name="${nome}" value="${esc(x)}" ${marcados.includes(x) ? 'checked' : ''}>${esc(l)}</label>`).join('')}</div>`;

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
      location.hash = '#/' + (ctx.rota || ctx.aba || 'inicio');
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
  <div class="user"><div class="user-info"><div>${esc(u.nome)}</div><small>${({contador: 'Contador', suporte: 'Suporte', cliente: 'Cliente'})[ctx.tipo_usuario] || 'Cliente'} · ${ctx.perfil === 'fiscal' ? 'acesso fiscal' : 'somente consulta'}</small></div><div class="avatar" aria-hidden="true">${esc(ini)}</div><button type="button" class="ghost sm" id="sair">Sair</button></div></header>
  ${ctx.tipo_usuario === 'suporte' ? '<div class="support-bar" role="status">Acesso de suporte pelo painel administrativo. As alterações ficam registradas com o seu usuário.</div>' : ''}
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
  const views = {inicio};
  const fn = views[sec] || inicio;
  const v = layout(views[sec] ? sec : 'inicio');
  try { await fn(v, rest); } catch (e) { fail(e); v.innerHTML = `<div class="card empty">Não foi possível carregar esta tela.</div>`; }
  window.scrollTo(0, 0);
}
const go = h => { if (location.hash === h) route(); else location.hash = h; };

// ---------------------------------------------------------------- início
async function inicio(v) {
  v.innerHTML = head(`Olá, ${(ctx.usuario.nome || '').split(' ')[0]}`, 'Acompanhamento fiscal da empresa. Os códigos fiscais de cada nota (CFOP, CST, alíquotas, cClassTrib) vêm do sistema da empresa no envio, e a API calcula os impostos nesse momento.') +
  `<div class="card"><h2>Em breve</h2><div class="quick">
  <div class="soon-card"><strong>Notas fiscais</strong><span>Consulta das NF-e, NFC-e e NFS-e emitidas, com XML e DANFE.</span></div>
  <div class="soon-card"><strong>Relatórios</strong><span>Resumos de impostos por período para a apuração.</span></div></div></div>
  ${!pode() ? '<div class="card note warn">Seu acesso é somente de consulta.</div>' : ''}`;
}

boot();
})();
