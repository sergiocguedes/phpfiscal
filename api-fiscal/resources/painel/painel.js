'use strict';
const app = document.querySelector('#app');
const message = document.querySelector('#message');
let session = null;
let pendingMfa = null;
let selectedCompany = sessionStorage.getItem('empresa_selecionada') || '';
let companies = [];
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function notice(text, error = false) { message.textContent = text || ''; message.className = error ? 'error' : ''; }
async function api(path, method = 'GET', body, empresa = selectedCompany) {
  const response = await fetch('/api/v1' + path, {
    method, credentials: 'same-origin',
    headers: {'Content-Type':'application/json', ...(session?.csrf_token ? {'X-CSRF-Token':session.csrf_token} : {}), ...(empresa && !/^\/(auth|admin|empresas)(\/|$)/.test(path) ? {'X-Empresa-Id':empresa} : {})},
    ...(body === undefined ? {} : {body:JSON.stringify(body)})
  });
  const data = response.status === 204 ? {} : await response.json();
  if (!response.ok) {
    const e = data.erro || {};
    throw new Error([e.mensagem || 'Não foi possível concluir a operação.', ...(e.detalhes || []).map(x => x.campo + ': ' + x.mensagem), e.request_id ? 'Referência: ' + e.request_id : ''].filter(Boolean).join('\n'));
  }
  return data;
}
const fiscalEnvironment = v => Number(v)===1?'Produção':'Homologação';
const companyEnvironments = c => ['nfe','nfce','nfse'].map(k=>({nfe:'NF-e',nfce:'NFC-e',nfse:'NFS-e'}[k])+': '+fiscalEnvironment(c['ambiente_'+k])).join(' · ');
const input = (name, title, value = '', type = 'text', attrs = '') => `<label>${esc(title)}<input aria-label="${esc(title)}" name="${esc(name)}" type="${type}" value="${esc(value)}" ${attrs}></label>`;
const select = (name, title, value, options) => `<label>${esc(title)}<select aria-label="${esc(title)}" name="${name}">${options.map(([v,t]) => `<option value="${v}" ${String(v)===String(value)?'selected':''}>${esc(t)}</option>`).join('')}</select></label>`;
function form(id, handler) {
  document.querySelector('#' + id).addEventListener('submit', async event => {
    event.preventDefault(); notice('');
    const button = event.target.querySelector('button[type=submit]');
    if (button) button.disabled = true;
    try { await handler(Object.fromEntries(new FormData(event.target)), event.target); }
    catch (error) { notice(error.message, true); }
    finally { if (button?.isConnected) button.disabled = false; }
  });
}
async function go(path) { history.pushState({}, '', path); notice(''); await render(); }
// Suporte: abre o Portal Fiscal da empresa escolhida com o usuário logado (link de uso único, em outra aba)
document.addEventListener('click', async event => {
  const b = event.target.closest('button[data-portal]');
  if (!b) return;
  const w = window.open('', '_blank');
  b.disabled = true;
  try {
    const r = await api('/portal/acessos', 'POST', {perfil:'fiscal'}, b.dataset.portal);
    if (w) { w.opener = null; w.location.href = r.url; } else { location.href = r.url; }
  } catch (error) { if (w) w.close(); notice(error.message, true); }
  finally { b.disabled = false; }
});
document.addEventListener('click', event => {
  const a = event.target.closest('a[data-route]');
  if (a && !event.ctrlKey && !event.metaKey) { event.preventDefault(); go(a.getAttribute('href')); }
});
window.addEventListener('popstate', () => render());
function authShell(title, lead, contents) {
  app.innerHTML = `<main class="auth"><h1>${title}</h1><p class="lead">${lead}</p><section class="card">${contents}</section></main>`;
}
async function authenticated(data) {
  if (data.mfa_necessario) { pendingMfa = data.mfa_token; return renderLogin(); }
  pendingMfa = null; session = data;
  await go(data.mfa_cadastro_obrigatorio ? '/mfa' : '/empresas');
}
function renderLogin() {
  authShell('Administração da API Fiscal', 'Acesso exclusivo para administradores e usuários autorizados.', pendingMfa
    ? `<form id="login"><p>Digite o código do autenticador ou um código de recuperação.</p>${input('codigo','Código','','text','required autocomplete="one-time-code"')}<button type="submit">Confirmar acesso</button></form>`
    : `<form id="login">${input('email','E-mail','','email','required autocomplete="username"')}${input('senha','Senha','','password','required autocomplete="current-password"')}<button type="submit">Entrar</button></form><div class="auth-links"><a data-route href="/esqueci-senha">Esqueci minha senha</a></div>`);
  form('login', async data => authenticated(await api(pendingMfa ? '/auth/mfa/verificar' : '/auth/login','POST', pendingMfa ? {mfa_token:pendingMfa,...data} : data)));
}
function renderRecovery(reset) {
  authShell(reset ? 'Defina uma nova senha' : 'Recupere seu acesso', reset ? 'Informe o token recebido e sua nova senha.' : 'Enviaremos as instruções se houver uma conta com esse e-mail.', `<form id="recover">${reset ? input('token','Token de recuperação', new URLSearchParams(location.search).get('token') || new URLSearchParams(location.hash.slice(1)).get('token') || '', 'text', 'required') + input('senha','Nova senha','','password','required autocomplete="new-password"') : input('email','E-mail','','email','required')}<button type="submit">${reset?'Salvar nova senha':'Enviar instruções'}</button></form><div class="auth-links"><a data-route href="/login">Voltar ao login</a></div>`);
  form('recover', async data => { const result=await api(reset?'/auth/redefinir-senha':'/auth/esqueci-senha','POST',data); notice(result.mensagem); });
}
function shell(path) {
  const nav=[['/empresas','Todas as empresas'],...(session.usuario?.acesso_central==='admin'?[['/usuarios','Usuários permitidos'],['/administracao','Administração e arquivos']]:[]),['/perfil','Perfil'],['/mfa','Segurança']];
  const current=companies.find(c=>c.id===selectedCompany);
  app.innerHTML=`<aside><p class="company">API Fiscal</p><p>${esc(session.usuario?.nome)}</p><p class="muted">${current?esc(current.nome)+' · '+companyEnvironments(current)+(current.ativa?'':' · Inativa'):'Administração de empresas'}</p><nav>${nav.map(([href,label])=>`<a href="${href}" data-route class="${path===href||(href==='/empresas'&&path==='/empresa')?'active':''}">${label}</a>`).join('')}<button type="button" class="secondary" id="logout">Sair</button></nav></aside><main id="content"></main>`;
  document.querySelector('#logout').onclick=async()=>{try{await api('/auth/logout','POST',{});session=null;selectedCompany='';sessionStorage.removeItem('empresa_selecionada');await go('/login');}catch(e){notice(e.message,true);}};
  return document.querySelector('#content');
}
async function overview(main) {
  const [cfg,cert,health]=await Promise.all([api('/config'),api('/certificado'),api('/health')]); if(!main.isConnected)return;
  main.innerHTML=`<h2>Resumo da configuração fiscal</h2><p class="lead">Prepare a empresa para emitir pelo seu PDV ou integração.</p><div class="grid"><section class="card"><span class="badge">API disponível</span><h2>Endpoint da API</h2><p class="readonly">${esc(location.origin)}/api/v1</p><p class="muted">Versão ${esc(health.versao)} · NF-e, NFC-e e NFS-e</p><a href="/docs" target="_blank" rel="noopener">Consultar documentação ↗</a></section><section class="card"><h2>Preparação da empresa</h2><p>Dados fiscais: <strong>${cfg.configurado?'configurados':'aguardando cadastro'}</strong></p><p>Certificado: <strong>${cert.configurado || cert.titular?'cadastrado':'aguardando envio'}</strong></p><p class="muted">A emissão, os pagamentos e a impressão são iniciados pelo PDV.</p></section></div><section class="card"><h2>Comece pela configuração</h2><p>1. Complete os dados fiscais e a numeração da empresa.</p><p>2. Envie o certificado digital A1 e configure o CSC da NFC-e.</p><p>3. Crie uma chave de API para cada integração.</p><p>4. Configure o prestador se também utilizar NFS-e.</p></section>`;
}
async function fiscalCompany(main) {
  const c=await api('/config'); if(!main.isConnected)return; const e=c.endereco||{};
  main.innerHTML=`<h2>Dados fiscais, séries e numeração</h2><p class="lead">Dados do emitente utilizados pelas APIs de notas fiscais.</p><form id="company"><section class="card"><div class="grid">${input('cnpj','CNPJ',c.cnpj||companies.find(x=>x.id===selectedCompany)?.cnpj,'text','required')}${input('ie','Inscrição estadual',c.ie,'text','required')}${input('razao_social','Razão social',c.razao_social||companies.find(x=>x.id===selectedCompany)?.nome,'text','required')}${input('nome_fantasia','Nome fantasia',c.nome_fantasia)}${select('uf','UF',c.uf||'AM',['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'].map(x=>[x,x]))}${select('crt','Regime tributário',c.crt||'',[['','Selecione o regime'],[1,'Simples Nacional'],[2,'Simples — excesso de sublimite'],[3,'Regime normal'],[4,'MEI']])}<p>NF-e: <strong>${fiscalEnvironment(c.ambiente_nfe)}</strong><br>NFC-e: <strong>${fiscalEnvironment(c.ambiente_nfce)}</strong><br>Altere os ambientes na aba Cadastro e ambiente.</p></div><h2>Endereço</h2><div class="grid">${input('logradouro','Logradouro',e.logradouro,'text','required')}${input('numero_endereco','Número',e.numero,'text','required')}${input('complemento','Complemento',e.complemento)}${input('bairro','Bairro',e.bairro,'text','required')}${input('codigo_municipio','Código IBGE do município',e.codigo_municipio,'text','required')}${input('municipio','Município',e.municipio,'text','required')}${input('cep','CEP',e.cep,'text','required')}${input('telefone','Telefone',e.telefone)}</div><h2>NF-e e NFC-e</h2><div class="grid">${input('serie','Série NF-e',c.serie??1,'number','required min="0" max="889"')}${input('proximo_numero','Próximo número NF-e',c.proximo_numero??1,'number','required min="1"')}${input('serie_nfce','Série NFC-e',c.serie_nfce??1,'number','required min="0" max="889"')}${input('proximo_numero_nfce','Próximo número NFC-e',c.proximo_numero_nfce??1,'number','required min="1"')}${input('csc_id','Identificador CSC',c.csc_id,'text','autocomplete="off"')}${input('csc','CSC da NFC-e','','password','autocomplete="new-password"')}</div><p class="muted">CSC ${c.csc_configurado?'já cadastrado. Deixe em branco para manter.':'não cadastrado. Informe quando exigido pela UF e pelo ambiente.'}</p><div class="actions"><button type="submit">Salvar configuração</button></div></section></form>`;
  form('company', async d=>{
    const body={...c,cnpj:d.cnpj,ie:d.ie,razao_social:d.razao_social,nome_fantasia:d.nome_fantasia,uf:d.uf,crt:Number(d.crt),ambiente:c.ambiente,serie:Number(d.serie),proximo_numero:Number(d.proximo_numero),serie_nfce:Number(d.serie_nfce),proximo_numero_nfce:Number(d.proximo_numero_nfce),endereco:{logradouro:d.logradouro,numero:d.numero_endereco,bairro:d.bairro,codigo_municipio:d.codigo_municipio,municipio:d.municipio,cep:d.cep}};
    if(!d.nome_fantasia)delete body.nome_fantasia; if(d.complemento)body.endereco.complemento=d.complemento;if(d.telefone)body.endereco.telefone=d.telefone;
    if(d.csc)body.csc=d.csc;if(d.csc_id)body.csc_id=d.csc_id;
    await api('/config','PUT',body); if(!main.isConnected)return; await fiscalCompany(main); notice('Configuração fiscal salva.');
  });
}
async function certificate(main) {
  const c=await api('/certificado'); if(!main.isConnected)return;
  main.innerHTML=`<h2>Certificado digital A1</h2><p class="lead">Certificado A1 da empresa, utilizado para assinar e transmitir os documentos.</p><section class="card"><h2>Certificado atual</h2><p>${esc(c.titular||'Nenhum certificado cadastrado.')}</p>${c.valido_ate?`<p class="muted">Validade: ${esc(new Date(c.valido_ate).toLocaleDateString('pt-BR'))}${c.expirado?' · Expirado':''}</p>`:''}<form id="certificate">${input('arquivo','Arquivo PFX ou P12','','file','required accept=".pfx,.p12"')}${input('senha','Senha do certificado','','password','required autocomplete="new-password"')}<div class="actions"><button type="submit">${c.configurado||c.titular?'Substituir':'Cadastrar'} certificado</button></div></form><p class="muted">A senha e a chave privada não são devolvidas pela API.</p></section>`;
  form('certificate',async(d,f)=>{const file=f.elements.arquivo.files[0];if(!file||file.size>1024*1024)throw new Error('Selecione um certificado de até 1 MB.');const bytes=new Uint8Array(await file.arrayBuffer());let binary='';for(let i=0;i<bytes.length;i+=8192)binary+=String.fromCharCode(...bytes.subarray(i,i+8192));await api('/certificado','POST',{certificado_base64:btoa(binary),senha:d.senha});if(!main.isConnected)return;await certificate(main);notice('Certificado cadastrado.');});
}
async function keys(main) {
  const data=await api('/chaves'); if(!main.isConnected)return;
  main.innerHTML=`<h2>Chaves de API da empresa</h2><p class="lead">Uma credencial para cada PDV ou integração desta empresa.</p><section class="card"><form id="key">${input('nome','Nome da integração','','text','required minlength="2" maxlength="60" placeholder="PDV Caixa 01"')}<div class="actions"><button type="submit">Criar chave</button></div></form><div id="new-key"></div></section><section class="card"><table><thead><tr><th>Integração</th><th>Situação</th><th>Ação</th></tr></thead><tbody>${(data.chaves||[]).map(k=>`<tr><td>${esc(k.nome)}</td><td>${k.revogada_em?'Revogada':'Ativa'}</td><td>${!k.revogada_em?`<button type="button" class="danger" data-revoke="${esc(k.id)}">Revogar</button>`:'—'}</td></tr>`).join('')||'<tr><td colspan="3">Nenhuma chave cadastrada.</td></tr>'}</tbody></table></section>`;
  form('key',async d=>{const k=await api('/chaves','POST',d);if(!main.isConnected)return;await keys(main);document.querySelector('#new-key').innerHTML=`<h2>Copie e guarde sua chave</h2><p class="muted">Ela será exibida apenas agora.</p><label>Chave da integração<textarea readonly>${esc(k.chave)}</textarea></label>`;});
  main.querySelectorAll('[data-revoke]').forEach(button=>button.onclick=async()=>{try{button.disabled=true;await api('/chaves/'+encodeURIComponent(button.dataset.revoke),'DELETE');await keys(main);notice('Chave revogada.');}catch(e){notice(e.message,true);button.disabled=false;}});
}
async function nfse(main) {
  const c=await api('/nfse/config');if(!main.isConnected)return;
  main.innerHTML=`<h2>Prestador NFS-e</h2><p class="lead">Os serviços e tributos são enviados no JSON de cada emissão; não há catálogo de serviços na API.</p><section class="card"><p class="hint">${esc(c.aviso_municipio||'')}</p><form id="nfse"><div class="grid">${input('inscricao_municipal','Inscrição municipal',c.inscricao_municipal)}${`<p>Município emissor (código IBGE): <strong>${esc(c.codigo_municipio||'não cadastrado')}</strong> · vem do endereço em Dados fiscais e séries.</p>`}<p>Opção pelo Simples: <strong>${esc(({1:'Não optante (regime normal)',2:'MEI',3:'ME/EPP do Simples Nacional'})[c.opcao_simples_nacional]||'definida pelo regime tributário')}</strong> · vem do regime tributário em Dados fiscais e séries.</p>${c.opcao_simples_nacional===3?select('regime_apuracao_sn','Apuração do Simples (ME/EPP)',c.regime_apuracao_sn||1,[[1,'Federais e ISS pelo Simples'],[2,'Federais pelo Simples, ISS fora'],[3,'Federais e ISS fora do Simples']]):''}${select('regime_especial','Regime especial',c.regime_especial||0,[[0,'Nenhum'],[1,'Ato cooperado'],[2,'Estimativa'],[3,'Microempresa municipal'],[4,'Notário/registrador'],[5,'Profissional autônomo'],[6,'Sociedade de profissionais'],[9,'Outros']])}${select('tributacao_iss','Tipo de tributação do ISSQN',c.tributacao_iss||1,[[1,'Operação tributável'],[2,'Imunidade'],[4,'Não incidência']])}${select('tipo_imunidade','Tipo de imunidade (só com imunidade)',c.tipo_imunidade??0,[[0,'Não informado'],[1,'Patrimônio, renda ou serviços entre entes públicos'],[2,'Templos de qualquer culto'],[3,'Partidos, sindicatos, educação e assistência social'],[4,'Livros, jornais, periódicos e papel'],[5,'Fonogramas e videofonogramas musicais']])}${select('retencao_iss','Retenção do ISSQN',c.retencao_iss||1,[[1,'Não retido'],[2,'Retido pelo tomador']])}${select('susp_tipo','Suspensão da exigibilidade do ISSQN',c.suspensao?.tipo||0,[[0,'Nenhuma'],[1,'Decisão judicial'],[2,'Processo administrativo']])}${input('susp_processo','Número do processo (30 dígitos)',c.suspensao?.numero_processo||'','text','inputmode="numeric" maxlength="30"')}<p>Ambiente NFS-e: <strong>${fiscalEnvironment(c.ambiente)}</strong> · altere na aba Cadastro e ambiente.</p>${input('serie','Série DPS',c.serie||1,'number','required min="1" max="49999"')}${input('proximo_numero','Próximo número DPS',c.proximo_numero||1,'number','required min="1"')}</div><div class="actions"><button type="submit">Salvar prestador</button></div></form></section>`;
  form('nfse',async d=>{const body={...c,inscricao_municipal:d.inscricao_municipal||null,regime_especial:Number(d.regime_especial),ambiente:c.ambiente,serie:Number(d.serie),proximo_numero:Number(d.proximo_numero)};delete body.regime_apuracao_sn;delete body.opcao_simples_nacional;if(c.opcao_simples_nacional===3)body.regime_apuracao_sn=Number(d.regime_apuracao_sn);body.tributacao_iss=Number(d.tributacao_iss);body.retencao_iss=Number(d.retencao_iss);delete body.tipo_imunidade;if(body.tributacao_iss===2)body.tipo_imunidade=Number(d.tipo_imunidade);body.suspensao=Number(d.susp_tipo)?{tipo:Number(d.susp_tipo),numero_processo:d.susp_processo.replace(/\D/g,'')}:null;await api('/nfse/config','PUT',body);if(!main.isConnected)return;await nfse(main);notice('Prestador salvo. Os serviços devem ser informados na emissão.');});
}
async function profile(main) {
  main.innerHTML=`<h1>Seu perfil</h1><p class="lead">${esc(session.usuario.email)}</p><section class="card"><form id="profile">${input('nome','Nome',session.usuario.nome,'text','required')}<div class="actions"><button type="submit">Salvar nome</button></div></form></section><section class="card"><h2>Alterar senha</h2><form id="password">${input('senha_atual','Senha atual','','password','required autocomplete="current-password"')}${input('nova_senha','Nova senha','','password','required autocomplete="new-password"')}<div class="actions"><button type="submit">Alterar senha</button></div></form></section>`;
  form('profile',async d=>{await api('/auth/perfil','PUT',d);session=await api('/auth/sessao');notice('Nome atualizado.');});
  form('password',async d=>{const r=await api('/auth/alterar-senha','POST',d);session.csrf_token=r.csrf_token;await profile(main);notice(r.mensagem);});
}
async function security(main) {
  const m=await api('/auth/mfa');
  main.innerHTML=`<h1>Segurança do acesso</h1><p class="lead">Proteja o acesso às configurações e às credenciais fiscais.</p><section class="card"><h2>Verificação em duas etapas</h2><p>${m.ativo?'Ativada.':'Ainda não ativada.'}</p><div id="mfa-setup"></div>${!m.ativo?'<button type="button" id="start-mfa">Configurar autenticador</button>':`<form id="disable-mfa">${input('senha','Senha atual','','password','required')}${input('codigo','Código do autenticador ou recuperação','','text','required')}<div class="actions"><button type="submit" class="danger">Desativar verificação em duas etapas</button></div></form>`}</section>`;
  if(!m.ativo)document.querySelector('#start-mfa').onclick=async()=>{try{const r=await api('/auth/mfa/iniciar','POST',{});document.querySelector('#start-mfa').hidden=true;document.querySelector('#mfa-setup').innerHTML=`<p>Cadastre esta chave no seu aplicativo autenticador:</p><p class="readonly">${esc(r.segredo)}</p><p class="muted">Conta: ${esc(r.conta)} · ${esc(r.algoritmo)} · ${esc(r.digitos)} dígitos · ${esc(r.periodo)} segundos</p><form id="confirm-mfa">${input('codigo','Código do aplicativo','','text','required inputmode="numeric"')}<div class="actions"><button type="submit">Confirmar ativação</button></div></form>`;form('confirm-mfa',async d=>{const result=await api('/auth/mfa/confirmar','POST',d);session=await api('/auth/sessao');await security(main);document.querySelector('#mfa-setup').innerHTML=`<h2>Guarde os códigos de recuperação</h2><p class="readonly">${esc((result.codigos_recuperacao||result.recuperacao||[]).join(' · '))}</p>`;notice('Verificação em duas etapas ativada.');});}catch(e){notice(e.message,true);}};
  else form('disable-mfa',async d=>{await api('/auth/mfa/desativar','POST',d);session=await api('/auth/sessao');await security(main);notice('Verificação em duas etapas desativada.');});
}
async function render() {
  let path=location.pathname;
  if(path==='/esqueci-senha'||path==='/redefinir-senha')return renderRecovery(path==='/redefinir-senha');
  if(!session)return renderLogin();
  if(companies.length===0 && session.usuario?.acesso_central){try{companies=(await api('/empresas')).empresas;}catch(e){notice(e.message,true);}}
  if(selectedCompany && !companies.some(c=>c.id===selectedCompany)){selectedCompany='';sessionStorage.removeItem('empresa_selecionada');}
  const legacyTabs={'/certificado':'certificado','/chaves':'chaves','/nfse':'nfse'};
  if(legacyTabs[path]){
    const params=new URLSearchParams();if(selectedCompany)params.set('empresa',selectedCompany);params.set('aba',legacyTabs[path]);
    history.replaceState({},'', '/empresa?'+params);path='/empresa';
  }
  if(path==='/empresa'){
    const requested=new URLSearchParams(location.search).get('empresa');
    if(requested!==null){
      if(!companies.some(c=>c.id===requested)){const main=shell(path);main.innerHTML='<h1>Empresa não encontrada</h1><p>Selecione uma empresa cadastrada para abrir as configurações.</p><a class="button" data-route href="/empresas">Ver empresas</a>';return;}
      selectedCompany=requested;sessionStorage.setItem('empresa_selecionada',requested);
    }
  }
  const main=shell(path);
  try {
    if(session.mfa_cadastro_obrigatorio && path!=='/mfa')return go('/mfa');
    if(path==='/empresa'&&!selectedCompany)return companyPicker(main);
    await ({'/':allCompanies,'/login':allCompanies,'/empresas':allCompanies,'/usuarios':allowedUsers,'/administracao':administration,'/empresa':companySettings,'/perfil':profile,'/mfa':security}[path] || allCompanies)(main);
  } catch(error) { main.innerHTML='<h1>Não foi possível carregar</h1><p class="lead">Confira sua conexão e o acesso à empresa.</p>';notice(error.message,true); }
}
function companyPicker(main){
  main.innerHTML='<h1>Escolha uma empresa</h1><p class="lead">Abra a configuração da empresa para ajustar todos os dados necessários à emissão.</p>'+companyCards();
}
function companyCards(){
  return companies.map(c=>`<article class="card company-entry"><div><h2>${esc(c.nome)}</h2><p class="muted">CNPJ: ${esc(c.cnpj||'Não informado')}</p><div class="company-badges"><span class="badge ${c.ativa?'':'inactive'}">${c.ativa?'Ativa':'Inativa'}</span><span class="badge environment">${esc(companyEnvironments(c))}</span></div></div><div class="actions"><a class="button" data-route href="/empresa?empresa=${encodeURIComponent(c.id)}">Configurar empresa</a>${c.ativa?`<button type="button" class="secondary" data-portal="${esc(c.id)}">Abrir Portal Fiscal</button>`:''}</div></article>`).join('')||'<section class="card"><p>Nenhuma empresa cadastrada.</p><a data-route href="/empresas">Cadastrar a primeira empresa</a></section>';
}
async function allCompanies(main){
  companies=(await api('/empresas')).empresas;
  if(!main.isConnected)return;
  main.innerHTML=`<h1>Todas as empresas</h1><p class="lead">Abra uma empresa para configurar dados fiscais, séries, certificado, integrações e NFS-e em uma única tela.</p><section class="card"><h2>Cadastrar empresa</h2><form id="new-company"><div class="grid">${input('nome','Nome da empresa','','text','required maxlength="60"')}${input('cnpj','CNPJ','','text','required')}</div><div class="actions"><button type="submit">Cadastrar e configurar</button></div></form></section><h2>Empresas cadastradas</h2>${companyCards()}`;
  form('new-company',async d=>{const c=await api('/empresas','POST',{...d,ativa:true,ambiente_nfe:2,ambiente_nfce:2,ambiente_nfse:2});companies=(await api('/empresas')).empresas;if(!main.isConnected)return;await go('/empresa?empresa='+encodeURIComponent(c.id));notice('Empresa cadastrada em homologação. Complete as configurações nas abas abaixo.');});
}
async function companyRegistration(main){
  const c=companies.find(x=>x.id===selectedCompany);
  main.innerHTML=`<section class="card"><h2>Cadastro e ambiente fiscal</h2><p class="muted">Este cadastro identifica a empresa. Os dados de emissão e a numeração ficam na aba Dados fiscais e séries.</p><form id="company-registration"><div class="grid">${input('nome','Nome da empresa',c.nome,'text','required maxlength="60"')}${input('cnpj_cadastro','CNPJ cadastrado',c.cnpj,'text','readonly')}${select('ativa','Situação da empresa',c.ativa?'true':'false',[['true','Ativa para novas emissões'],['false','Inativa para novas emissões']])}${select('ambiente_nfe','Ambiente NF-e',c.ambiente_nfe,[[2,'Homologação / testes'],[1,'Produção']])}${select('ambiente_nfce','Ambiente NFC-e',c.ambiente_nfce,[[2,'Homologação / testes'],[1,'Produção']])}${select('ambiente_nfse','Ambiente NFS-e',c.ambiente_nfse,[[2,'Homologação / testes'],[1,'Produção']])}</div><p class="hint">Cada ambiente mantém sua configuração e numeração. Salve as alterações nas outras abas antes de trocar o ambiente. Inativar a empresa preserva os documentos e a recuperação das operações existentes.</p><div class="actions"><button type="submit">Salvar cadastro e ambiente</button></div></form></section>`;
  form('company-registration',async d=>{await api('/empresas/'+c.id,'PUT',{nome:d.nome,ativa:d.ativa==='true',ambiente_nfe:Number(d.ambiente_nfe),ambiente_nfce:Number(d.ambiente_nfce),ambiente_nfse:Number(d.ambiente_nfse)});companies=(await api('/empresas')).empresas;if(!main.isConnected||selectedCompany!==c.id)return;await render();notice('Cadastro e ambiente da empresa atualizados.');});
}
async function companySettings(main){
  const current=companies.find(c=>c.id===selectedCompany);
  if(!current)return companyPicker(main);
  const tabs=[['cadastro','Cadastro e ambiente',companyRegistration],['fiscal','Dados fiscais e séries',fiscalCompany],['certificado','Certificado A1',certificate],['chaves','Chaves de API',keys],['nfse','Prestador NFS-e',nfse],['resumo','Resumo',overview]];
  let active=new URLSearchParams(location.search).get('aba')||'cadastro';
  if(!tabs.some(t=>t[0]===active))active='cadastro';
  main.innerHTML=`<div class="company-heading"><div><a data-route href="/empresas">← Todas as empresas</a><h1>${esc(current.nome)}</h1><p class="lead">CNPJ ${esc(current.cnpj)} · ${esc(companyEnvironments(current))} · ${current.ativa?'Ativa':'Inativa'}</p>${current.ativa?`<button type="button" class="secondary" data-portal="${esc(current.id)}">Abrir Portal Fiscal (suporte)</button>`:''}</div>${select('empresa_selecionada','Configurar outra empresa',current.id,companies.map(c=>[c.id,c.nome]))}</div><div class="company-tabs" role="tablist" aria-label="Configurações da empresa">${tabs.map(([id,label])=>`<button type="button" role="tab" id="tab-${id}" aria-controls="panel-${id}" aria-selected="false" tabindex="-1" data-tab="${id}">${label}</button>`).join('')}</div><p class="muted section-help">Cada aba tem seu próprio botão para salvar. Todas as configurações abaixo pertencem à empresa exibida acima.</p>${tabs.map(([id,label])=>`<div id="panel-${id}" class="company-panel" role="tabpanel" aria-labelledby="tab-${id}" hidden><p>Carregando ${esc(label)}…</p></div>`).join('')}`;
  main.querySelector('[name="empresa_selecionada"]').onchange=async event=>{await go('/empresa?empresa='+encodeURIComponent(event.target.value));};
  const loaded=new Set();
  async function activate(id,focus=false){
    active=id;
    for(const [key] of tabs){const tab=main.querySelector('#tab-'+key);tab.setAttribute('aria-selected',String(key===id));tab.tabIndex=key===id?0:-1;main.querySelector('#panel-'+key).hidden=key!==id;}
    if(focus)main.querySelector('#tab-'+id).focus();
    const params=new URLSearchParams({empresa:current.id,aba:id});history.replaceState({},'','/empresa?'+params);
    if(loaded.has(id)&&id!=='resumo')return;
    const pane=main.querySelector('#panel-'+id);const renderTab=tabs.find(t=>t[0]===id)[2];
    try{await renderTab(pane);if(main.isConnected)loaded.add(id);}
    catch(e){if(!main.isConnected)return;pane.innerHTML='<section class="card"><h2>Não foi possível carregar esta configuração</h2><p class="muted">As outras abas continuam disponíveis. Selecione esta aba novamente para tentar.</p></section>';notice(e.message,true);}
  }
  for(const [id] of tabs){const button=main.querySelector('#tab-'+id);button.onclick=()=>activate(id);button.onkeydown=event=>{let i=tabs.findIndex(t=>t[0]===active);if(event.key==='ArrowRight')i=(i+1)%tabs.length;else if(event.key==='ArrowLeft')i=(i+tabs.length-1)%tabs.length;else if(event.key==='Home')i=0;else if(event.key==='End')i=tabs.length-1;else return;event.preventDefault();activate(tabs[i][0],true);};}
  await activate(active);
}
async function allowedUsers(main){
  const users=(await api('/admin/usuarios')).usuarios;
  main.innerHTML=`<h1>Usuários permitidos</h1><p class="lead">Administradores gerenciam acessos e integrações centrais. Operadores gerenciam todas as empresas e suas configurações fiscais.</p><section class="card"><form id="new-user"><div class="grid">${input('nome','Nome','','text','required')}${input('email','E-mail','','email','required')}${input('senha','Senha inicial','','password','required autocomplete="new-password"')}${select('acesso_central','Acesso','operador',[['operador','Operador de empresas'],['admin','Administrador']])}</div><button type="submit">Criar usuário autorizado</button></form></section><section class="card">${users.map(u=>`<form class="service" id="user-${u.id}"><h2>${esc(u.nome)}</h2><p>${esc(u.email)}</p><div class="grid">${select('acesso_central','Acesso',u.acesso_central,[['operador','Operador'],['admin','Administrador']])}${select('ativo','Situação',u.ativo?'true':'false',[['true','Ativo'],['false','Bloqueado']])}</div><button type="submit">Salvar acesso</button></form>`).join('')}</section>`;
  form('new-user',async d=>{await api('/admin/usuarios','POST',d);await allowedUsers(main);notice('Usuário criado.');});
  for(const u of users){form('user-'+u.id,async d=>{await api('/admin/usuarios/'+u.id,'PUT',{acesso_central:d.acesso_central,ativo:d.ativo==='true'});await allowedUsers(main);notice('Acesso atualizado. Sessões anteriores foram invalidadas.');});}
}
async function administration(main){
  const [data,storage,config]=await Promise.all([api('/admin/chaves'),api('/admin/armazenamento'),api('/admin/config')]);const rt=config.responsavel_tecnico||{};
  main.innerHTML=`<h1>Administração e arquivos</h1><section class="card"><h2>Responsável técnico do desenvolvedor</h2><p class="muted">Configuração geral da API, aplicada a todas as empresas na geração de NF-e e NFC-e.</p><form id="api-config"><div class="grid">${input('rt_cnpj','CNPJ do desenvolvedor',rt.cnpj,'text','required')}${input('rt_contato','Contato técnico',rt.contato,'text','required')}${input('rt_email','E-mail técnico',rt.email,'email','required')}${input('rt_fone','Telefone técnico',rt.fone,'text','required')}</div><div class="actions"><button type="submit">Salvar configuração da API</button></div></form></section><section class="card"><h2>Armazenamento</h2><p>Banco: ${esc(storage.banco)} · XML: ${storage.provedor==='b2'?'Backblaze B2':'no próprio banco'}</p>${storage.provedor==='b2'?`<p>Enviados: ${storage.arquivados} · Aguardando envio: ${storage.pendentes} · Com falha: ${storage.com_falha}</p><p class="muted">O worker envia e confere cada XML; até lá ele fica no banco. Falhas no B2 não perdem o XML nem repetem emissões.</p>`:''}${storage.legado_pendente?`<p class="muted">${storage.legado_pendente} XML de versão anterior ainda em arquivo: restaure a pasta storage/xml e reinicie a API.</p>`:''}</section><section class="card"><h2>Configuração do sistema</h2><p>Endereço, sessões, e-mail, limites e parâmetros fiscais ficam cifrados no banco.</p><a class="button" href="/configuracao">Abrir configuração do sistema</a></section><section class="card"><h2>Chaves administrativas</h2><p>Acesso a todas as empresas e aos usuários. Para um PDV, prefira uma chave restrita à empresa.</p><form id="admin-key">${input('nome','Nome da integração administrativa','','text','required minlength="2" maxlength="60"')}<button type="submit">Criar chave administrativa</button></form><div id="new-admin-key"></div>${data.chaves.map(k=>`<p>${esc(k.nome)} · ${k.revogada_em?'Revogada':`<button type="button" class="danger" data-admin-revoke="${k.id}">Revogar</button>`}</p>`).join('')}</section>`;
  form('api-config',async d=>{await api('/admin/config','PUT',{responsavel_tecnico:{cnpj:d.rt_cnpj,contato:d.rt_contato,email:d.rt_email,fone:d.rt_fone}});notice('Responsável técnico geral da API salvo.');});
  form('admin-key',async d=>{const k=await api('/admin/chaves','POST',d);await administration(main);document.querySelector('#new-admin-key').innerHTML=`<label>Copie agora a chave administrativa<textarea readonly>${esc(k.chave)}</textarea></label>`;});
  main.querySelectorAll('[data-admin-revoke]').forEach(b=>b.onclick=async()=>{try{await api('/admin/chaves/'+b.dataset.adminRevoke,'DELETE');await administration(main);}catch(e){notice(e.message,true);}});
}
(async()=>{try{session=await api('/auth/sessao');}catch{}await render();})();
// Tributação: os códigos fiscais vêm no JSON de cada nota enviado pelo ERP (sem cadastro de regras na API).
