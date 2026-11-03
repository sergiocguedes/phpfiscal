<?php

class ParametrosGeraisForm extends TPage
{
    protected BootstrapFormBuilder $form;
    private $formFields = [];
    private static $database = 'emissor';
    private static $activeRecord = 'ParametrosGerais';
    private static $primaryKey = 'id';
    private static $formName = 'form_ParametrosGeraisForm';

    /**
     * Form constructor
     * @param $param Request
     */
    public function __construct( $param )
    {
        parent::__construct();

        if(!empty($param['target_container']))
        {
            $this->adianti_target_container = $param['target_container'];
        }

        // creates the form
        $this->form = new BootstrapFormBuilder(self::$formName);
        // define the form title
        $this->form->setFormTitle("Configurações Gerais");

        $criteria_ambiente_fiscal_id = new TCriteria();
        $criteria_tipo_desconto_servico_id = new TCriteria();

        // 🔥 CONTROLE GLOBAL DO WIZARD (SESSION)

        // ativa wizard
        if (!empty($param['config_steps'])) {
            TSession::setValue('config_steps', true);
        }

        // guarda step atual
        if (!empty($param['wizard_step'])) {
            TSession::setValue('wizard_step', $param['wizard_step']);
        }

        // recupera estado
        $this->config_steps = TSession::getValue('config_steps');
        $this->wizard_step  = TSession::getValue('wizard_step');

        if ($this->config_steps)
        {
            $page_step = new TPageStep();

            $page_step->addItem(
                'Dados da Empresa',
                new TAction(['EmpresaForm', 'onShow'], ['wizard_step' => 'empresa'])
            );

            $page_step->addItem(
                'Parâmetros Gerais',
                new TAction(['ParametrosGeraisForm', 'onShow'], ['wizard_step' => 'parametros'])
            );

            $page_step->addItem(
                'Certificado Digital',
                new TAction(['CertificadoDigitalForm', 'onShow'], ['wizard_step' => 'certificado'])
            );

            // 🔥 seleção dinâmica (correta)
            switch ($this->wizard_step) {
                case 'parametros':
                    $page_step->select('Parâmetros Gerais');
                    break;
                case 'certificado':
                    $page_step->select('Certificado Digital');
                    break;
                default:
                    $page_step->select('Dados da Empresa');
            }

            $this->form->addContent([$page_step]);
        }

        $ambiente_fiscal_id = new TDBCombo('ambiente_fiscal_id', 'emissor', 'AmbienteFiscal', 'id', '{descricao}','descricao asc' , $criteria_ambiente_fiscal_id );
        $system_unit_id = new THidden('system_unit_id');
        $tipo_desconto_servico_id = new TDBCombo('tipo_desconto_servico_id', 'emissor', 'TipoDescontoServico', 'id', '{descricao}','descricao asc' , $criteria_tipo_desconto_servico_id );
        $id = new THidden('id');
        $ultimo_numero_nfse = new TEntry('ultimo_numero_nfse');
        $serie_nfse = new TEntry('serie_nfse');
        $host_proxy = new TEntry('host_proxy');
        $porta_proxy = new TEntry('porta_proxy');
        $usuario_proxy = new TEntry('usuario_proxy');
        $senha_proxy = new TEntry('senha_proxy');
        $host_smtp = new TEntry('host_smtp');
        $porta_smtp = new TEntry('porta_smtp');
        $usuario_smpt = new TEntry('usuario_smpt');
        $senha_smpt = new TEntry('senha_smpt');
        $autentica_smtp = new TRadioGroup('autentica_smtp');
        $button_testar_e_mail = new TButton('button_testar_e_mail');
        $instancia_whatsapp = new TEntry('instancia_whatsapp');
        $token_whatsapp = new TEntry('token_whatsapp');
        $button_testar_envio = new TButton('button_testar_envio');
        $p_obriga_cpf_pdv = new TRadioGroup('p_obriga_cpf_pdv');
        $p_obriga_pagamento = new TRadioGroup('p_obriga_pagamento');
        $p_importar_obs = new TRadioGroup('p_importar_obs');

        $ambiente_fiscal_id->addValidation("Ambiente fiscal id", new TRequiredValidator()); 
        $tipo_desconto_servico_id->addValidation("Tipo desconto servico id", new TRequiredValidator()); 
        $ultimo_numero_nfse->addValidation("Último número RPS/DPS", new TRequiredValidator()); 
        $serie_nfse->addValidation("Série RPS/DPS", new TRequiredValidator()); 

        $ambiente_fiscal_id->enableSearch();
        $tipo_desconto_servico_id->enableSearch();

        $autentica_smtp->setValue('1');
        $p_importar_obs->setValue('0');

        $button_testar_e_mail->setAction(new TAction([$this, 'onTestarEmail']), "Testar E-mail");
        $button_testar_envio->setAction(new TAction([$this, 'onTestarWhatsapp']), "Testar Envio");

        $button_testar_e_mail->addStyleClass('btn-danger');
        $button_testar_envio->addStyleClass('btn-success');

        $button_testar_envio->setImage('fab:whatsapp #FFFFFF');
        $button_testar_e_mail->setImage('fas:mail-bulk #FFFFFF');

        $host_proxy->setMaxLength(200);
        $senha_proxy->setMaxLength(30);
        $usuario_proxy->setMaxLength(50);

        $autentica_smtp->addItems(["1"=>"Sim","2"=>"Não"]);
        $p_importar_obs->addItems(["1"=>"Sim","2"=>"Não"]);
        $p_obriga_cpf_pdv->addItems(["1"=>"Sim","2"=>"Não"]);
        $p_obriga_pagamento->addItems(["1"=>"Sim","2"=>"Não"]);

        $autentica_smtp->setLayout('horizontal');
        $p_importar_obs->setLayout('horizontal');
        $p_obriga_cpf_pdv->setLayout('horizontal');
        $p_obriga_pagamento->setLayout('horizontal');

        $autentica_smtp->setBooleanMode();
        $p_importar_obs->setBooleanMode();
        $p_obriga_cpf_pdv->setBooleanMode();
        $p_obriga_pagamento->setBooleanMode();

        $id->setSize(200);
        $host_smtp->setSize('100%');
        $serie_nfse->setSize('100%');
        $host_proxy->setSize('100%');
        $porta_smtp->setSize('100%');
        $senha_smpt->setSize('100%');
        $autentica_smtp->setSize(80);
        $p_importar_obs->setSize(80);
        $system_unit_id->setSize(200);
        $porta_proxy->setSize('100%');
        $senha_proxy->setSize('100%');
        $usuario_smpt->setSize('100%');
        $p_obriga_cpf_pdv->setSize(80);
        $usuario_proxy->setSize('100%');
        $token_whatsapp->setSize('100%');
        $p_obriga_pagamento->setSize(80);
        $ambiente_fiscal_id->setSize('100%');
        $ultimo_numero_nfse->setSize('100%');
        $instancia_whatsapp->setSize('100%');
        $tipo_desconto_servico_id->setSize('100%');


        $row1 = $this->form->addFields([new TLabel("Ambiente NFS-e:", '#ff0000', '14px', null, '100%'),$ambiente_fiscal_id,$system_unit_id],[new TLabel("Tipo desconto servico id:", '#ff0000', '14px', null, '100%'),$tipo_desconto_servico_id,$id],[new TLabel("Último número RPS/DPS:", '#F44336', '14px', null, '100%'),$ultimo_numero_nfse],[new TLabel("Série RPS/DPS:", '#F44336', '14px', null, '100%'),$serie_nfse]);
        $row1->layout = [' col-12 col-sm-6 col-xl-3',' col-12 col-sm-6 col-xl-3',' col-12 col-sm-6 col-xl-3',' col-12 col-sm-6 col-xl-3'];

        $bcontainer_699d01c7f5cec = new BContainer('bcontainer_699d01c7f5cec');
        $this->bcontainer_699d01c7f5cec = $bcontainer_699d01c7f5cec;

        $bcontainer_699d01c7f5cec->setTitle("Proxy", '#333', '18px', '', '#fff');
        $bcontainer_699d01c7f5cec->setBorderColor('#c0c0c0');
        $bcontainer_699d01c7f5cec->enableExpander();

        $row2 = $bcontainer_699d01c7f5cec->addFields([new TLabel("Host proxy:", null, '14px', null, '100%'),$host_proxy],[new TLabel("Porta proxy:", null, '14px', null, '100%'),$porta_proxy],[new TLabel("Usuario proxy:", null, '14px', null, '100%'),$usuario_proxy],[new TLabel("Senha proxy:", null, '14px', null, '100%'),$senha_proxy]);
        $row2->layout = ['col-sm-3','col-12 col-sm-4 col-xl-3','col-12 col-sm-4 col-xl-3','col-12 col-sm-4 col-xl-3'];

        $row3 = $this->form->addFields([$bcontainer_699d01c7f5cec]);
        $row3->layout = [' col-sm-12'];

        $bcontainer_699d02ebf5cf4 = new BContainer('bcontainer_699d02ebf5cf4');
        $this->bcontainer_699d02ebf5cf4 = $bcontainer_699d02ebf5cf4;

        $bcontainer_699d02ebf5cf4->setTitle("Configuração SMPT (E-mail)", '#333', '18px', '', '#fff');
        $bcontainer_699d02ebf5cf4->setBorderColor('#c0c0c0');
        $bcontainer_699d02ebf5cf4->enableExpander();

        $row4 = $bcontainer_699d02ebf5cf4->addFields([new TLabel("Host SMTP:", null, '14px', null, '100%'),$host_smtp],[new TLabel("Porta SMTP:", null, '14px', null, '100%'),$porta_smtp],[new TLabel("Usuário:", null, '14px', null, '100%'),$usuario_smpt],[new TLabel("Senha:", null, '14px', null, '100%'),$senha_smpt]);
        $row4->layout = [' col-12 col-sm-6 col-xl-3',' col-sm-6 col-xl-3',' col-12 col-sm-6 col-xl-3',' col-12 col-sm-6 col-xl-3'];

        $row5 = $bcontainer_699d02ebf5cf4->addFields([new TLabel("Autentica SMTP?", null, '14px', null, '100%'),$autentica_smtp],[],[$button_testar_e_mail]);
        $row5->layout = ['col-sm-3','col-sm-6','col-sm-3'];

        $row6 = $this->form->addFields([$bcontainer_699d02ebf5cf4]);
        $row6->layout = [' col-sm-12'];

        $bcontainer_699d03e4f5d02 = new BContainer('bcontainer_699d03e4f5d02');
        $this->bcontainer_699d03e4f5d02 = $bcontainer_699d03e4f5d02;

        $bcontainer_699d03e4f5d02->setTitle("Configuração de Whatsapp", '#333', '18px', '', '#fff');
        $bcontainer_699d03e4f5d02->setBorderColor('#c0c0c0');

        $row7 = $bcontainer_699d03e4f5d02->addFields([new TLabel("Instancia:", null, '14px', null, '100%'),$instancia_whatsapp],[new TLabel("Token:", null, '14px', null, '100%'),$token_whatsapp],[new TLabel(" ", null, '14px', null, '100%'),$button_testar_envio]);
        $row7->layout = [' col-12 col-sm-6 col-xl-3',' col-12 col-sm-6 col-xl-6','col-sm-2'];

        $row8 = $this->form->addFields([$bcontainer_699d03e4f5d02]);
        $row8->layout = [' col-12 col-sm-12 col-xl-12'];

        $row9 = $this->form->addContent([new TFormSeparator("Parametros Gerais", '#333', '18', '#eee')]);
        $row10 = $this->form->addFields([new TLabel("Obriga cpf pdv:", null, '14px', null, '100%'),$p_obriga_cpf_pdv],[new TLabel("Obriga Pagamento na NFS-e:", null, '14px', null, '100%'),$p_obriga_pagamento],[new TLabel("Juntar Itens na descrição:", null, '14px', null, '100%'),$p_importar_obs]);
        $row10->layout = ['col-sm-6 col-xl-2','col-sm-2 col-xl-2','col-sm-2'];

/*

        // create the form actions
        $btn_onsave = $this->form->addAction("Salvar", new TAction([$this, 'onSave']), 'fas:save #ffffff');
        $this->btn_onsave = $btn_onsave;
        $btn_onsave->addStyleClass('btn-primary'); 

        $btn_onajuda = $this->form->addHeaderAction("Ajuda?", new TAction([$this, 'onAjuda']), 'fas:video #E91E63');
        $this->btn_onajuda = $btn_onajuda;

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->class = 'form-container';
        if(empty($param['target_container']))
        {
            $container->add(TBreadCrumb::create(["Configurações","Configurações Gerais"]));
        }
        $container->add($this->form);

*/
        $btn_onsave = $this->form->addAction("Salvar", new TAction([$this, 'onSave']), 'fas:save #ffffff');
        if (!empty($this->config_steps)) {
            $btn_onsave->setLabel('Avançar');
        }
        $this->btn_onsave = $btn_onsave;
        $btn_onsave->addStyleClass('btn-primary'); 

        $btn_onajuda = $this->form->addHeaderAction("Ajuda?", new TAction([$this, 'onAjuda']), 'fas:video #E91E63');
        $this->btn_onajuda = $btn_onajuda;

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->class = 'form-container';
        if(empty($param['target_container']))
        {
            $container->add(TBreadCrumb::create(["Configurações","Configurações Gerais"]));
        }
        $container->add($this->form);

        parent::add($container);

    }

    public static function onTestarEmail($param = null) 
    {
    try
    {
        if (empty($param['host_smtp']))
        {
            throw new Exception('Informe o host SMTP');
        }

        if (empty($param['porta_smtp']))
        {
            throw new Exception('Informe a porta SMTP');
        }

        if (empty($param['usuario_smpt']))
        {
            throw new Exception('Informe o usuário SMTP');
        }

        if (empty($param['senha_smpt']))
        {
            throw new Exception('Informe a senha SMTP');
        }

        $payload = [

            'host' =>
                trim($param['host_smtp']),

            'porta' =>
                (int) trim($param['porta_smtp']),

            'usuario' =>
                trim($param['usuario_smpt']),

            'senha' =>
                trim($param['senha_smpt']),

            'nome_remetente' =>
                'Teste SMTP',

            'destino' => [
                trim($param['usuario_smpt'])
            ],

            'assunto' =>
                'Teste de envio SMTP',

            'mensagem' =>
                '<h2>E-mail enviado com sucesso</h2>
                 <p>Configuração SMTP válida.</p>',

            'anexos' => [

                [
                    'nome' => 'teste.txt',

                    'base64' => base64_encode(
                        'Arquivo de teste SMTP'
                    )
                ]

            ]
        ];

        $url = 'http://painel.lsisistemas.com.br/paglink/rest.php?class=EmailApiService&method=testar_email';
        //$url = 'http://127.0.0.1:5000/paglink/rest.php?class=EmailApiService&method=testar_email';
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        $ch = curl_init();

        curl_setopt_array($ch, [

            CURLOPT_URL => $url,

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_POST => true,

            CURLOPT_POSTFIELDS => $json,

            CURLOPT_HTTPHEADER => [

                'Content-Type: application/json',

                'Content-Length: ' . strlen($json),

                'Authorization: Basic_034bd030c93615992c2db7e7425c9de499e8292e976cc6d93efa7cc410c4'
            ],

            CURLOPT_SSL_VERIFYPEER => false,

            CURLOPT_SSL_VERIFYHOST => false,

            CURLOPT_CONNECTTIMEOUT => 30,

            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);

        $httpCode =
            curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

        if (curl_errno($ch))
        {
            throw new Exception(
                'Erro CURL: '
                . curl_error($ch)
            );
        }

        curl_close($ch);

        if (empty($response))
        {
            throw new Exception(
                'API retornou vazio'
            );
        }

        $retorno =
            json_decode($response, true);

        if (!$retorno)
        {
            throw new Exception(
                'Resposta inválida da API: '
                . $response
            );
        }

        if (
            $httpCode != 200
            ||
            empty($retorno['sucesso'])
        )
        {
            throw new Exception(
                $retorno['erro']
                ?? 'Erro ao enviar email'
            );
        }

        TToast::show(
            'success',
            'E-mail enviado com sucesso',
            'topRight',
            'far:check-circle'
        );

        TScript::create("
            $('button').prop('disabled', false);
            $('.btn').prop('disabled', false);
        ");

        }
        catch (Exception $e) 
        {
            new TMessage('error', $e->getMessage());    
        }
    }

    public static function onTestarWhatsapp($param = null) 
    {
        try 
        {
            PermissaoService::verificarFuncionalidadePro('O envio por WhatsApp');

            if (empty($param['instancia_whatsapp']))
            {
                throw new Exception('Informe o telefone da instância do WhatsApp.');
            }

            if (empty($param['token_whatsapp']))
            {
                throw new Exception('Informe o token do WhatsApp.');
            }

            TTransaction::open('emissor');

            $empresa = Empresa::where(
                'system_unit_id',
                '=',
                TSession::getValue('userunitid')
            )->first();

            TTransaction::close();

            if (!$empresa)
            {
                throw new Exception('Empresa não encontrada para a unidade atual.');
            }

            $telefoneInstancia = trim($param['instancia_whatsapp']);

            WhatsAppService::envia_mensagem_texto_zap(
                'Teste de envio WhatsApp - Emissor NFS-e',
                $telefoneInstancia,
                $empresa->id,
                $telefoneInstancia,
                trim($param['token_whatsapp'])
            );

            TToast::show(
                'success',
                'WhatsApp enviado com sucesso',
                'topRight',
                'far:check-circle'
            );

            TScript::create("
                $('button').prop('disabled', false);
                $('.btn').prop('disabled', false);
            ");

        }
        catch (Exception $e) 
        {
            new TMessage('error', $e->getMessage());    
        }
    }

    public function onSave($param = null)
    {
        $salvoLocalmente = false;
        try
        {
            $this->form->validate();
            $data = $this->form->getData();
            $ultimo = trim((string) $data->ultimo_numero_nfse);
            $serie = trim((string) $data->serie_nfse);
            $ambiente = (int) $data->ambiente_fiscal_id;
            if (!preg_match('/^\d{1,15}$/', $ultimo) || (int) $ultimo >= 999999999999999) {
                throw new Exception('Informe o último RPS/DPS entre 0 e 999999999999998.');
            }
            if (!preg_match('/^\d{1,5}$/', $serie) || (int) $serie < 1 || (int) $serie > 49999) {
                throw new Exception('Informe uma série DPS entre 1 e 49999.');
            }
            if (!in_array($ambiente, [1, 2], true)) {
                throw new Exception('Selecione produção ou homologação.');
            }
            $data->ultimo_numero_nfse = (int) $ultimo;
            $data->serie_nfse = (int) $serie;
            $data->ambiente_fiscal_id = $ambiente;
            $unit = TSession::getValue('userunitid');
            TTransaction::open(self::$database);
            $object = ParametrosGerais::where('system_unit_id', '=', $unit)->first() ?: new ParametrosGerais();
            $values = (array) $data;
            unset($values['id'], $values['system_unit_id']);
            $object->fromArray($values);
            $object->system_unit_id = $unit;
            $object->store();
            $data->id = $object->id;
            $data->system_unit_id = $unit;
            TTransaction::close();
            $salvoLocalmente = true;
            $this->form->setData($data);

            // HTTP somente depois do commit; a tela de parâmetros não exige reenviar o A1.
            $retorno = NFSeService::sincronizarParametros();
            if (empty($retorno['sucesso'])) {
                throw new Exception($retorno['mensagem'] ?? 'A API não confirmou a sincronização.');
            }
            $ultimoEfetivo = (int) $retorno['proximo_numero'] - 1;
            if ($ultimoEfetivo > $data->ultimo_numero_nfse) {
                TTransaction::open(self::$database);
                $atual = ParametrosGerais::where('system_unit_id', '=', $unit)->first();
                if ((int) $atual->serie_nfse !== $data->serie_nfse || (int) $atual->ambiente_fiscal_id !== $ambiente) {
                    throw new Exception('Os parâmetros foram alterados por outra sessão. Confira ambiente e série antes de emitir.');
                }
                $atual->ultimo_numero_nfse = max((int) $atual->ultimo_numero_nfse, $ultimoEfetivo);
                $atual->store();
                TTransaction::close();
                $data->ultimo_numero_nfse = $atual->ultimo_numero_nfse;
                $this->form->setData($data);
            }
            if (!empty($this->config_steps)) {
                TApplication::loadPage('CertificadoDigitalForm', 'onShow', ['wizard_step' => 'certificado']);
                return;
            }
            TToast::show('success', 'Parâmetros salvos e sincronizados com a API. Próximo RPS/DPS: ' . (int) $retorno['proximo_numero'], 'center', 'far:check-circle');
        }
        catch (Exception $e)
        {
            if (TTransaction::get()) { TTransaction::rollback(); }
            $this->form->setData($this->form->getData());
            $prefixo = $salvoLocalmente ? 'Os parâmetros foram salvos no emissor, mas a sincronização com a API não foi concluída. Corrija o problema e salve novamente. ' : '';
            new TMessage($salvoLocalmente ? 'warning' : 'error', $prefixo . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    }

    public static function onAjuda($param = null) 
    {
        try 
        {
              $url = "https://www.youtube.com/watch?v=vT1R324I57E&list=PLH4tRINb7qOPmgigEPd76PtNjQYNl-LnX&index=1";

            // Crie o script JavaScript para abrir o link em uma nova aba
            TScript::create("window.open('$url', '_blank');");

        }
        catch (Exception $e) 
        {
            new TMessage('error', $e->getMessage());    
        }
    }

    public function onEdit( $param )
    {
        try
        {
            if (isset($param['key']))
            {
                $key = $param['key'];  // get the parameter $key
                TTransaction::open(self::$database); // open a transaction

                $object = new ParametrosGerais($key); // instantiates the Active Record 

                $this->form->setData($object); // fill the form 

                TTransaction::close(); // close the transaction 
            }
            else
            {
                $this->form->clear();
            }
        }
        catch (Exception $e) // in case of exception
        {
            new TMessage('error', $e->getMessage()); // shows the exception error message
            TTransaction::rollback(); // undo all pending operations
        }
    }

    /**
     * Clear form data
     * @param $param Request
     */
    public function onClear( $param )
    {
        $this->form->clear(true);

    }

    public function onShow($param = null)
    {

        $id_system_unit = TSession::getValue('userunitid');
        TTransaction::open(self::$database);

        $object = ParametrosGerais::where('system_unit_id', '=', $id_system_unit)->first();
        if ($object)
        {
             // $this->form->setData($object);
             TForm::sendData(self::$formName, $object, false, false, false, 500);   
        }

        TTransaction::close();
        $this->aplicarRestricaoWhatsappFree();

    } 

    public static function getFormName()
    {
        return self::$formName;
    }

    /**
     * Plano Free: desabilita a configuração de WhatsApp (recurso Pro),
     * sem alterar o código gerado do Builder.
     */
    private function aplicarRestricaoWhatsappFree()
    {
        if (!PermissaoService::isPlanoFree())
        {
            return;
        }

        TEntry::disableField(self::$formName, 'instancia_whatsapp');
        TEntry::disableField(self::$formName, 'token_whatsapp');

        TScript::create(<<<'JS'
(function () {
    function aplicar() {
        var painel = null;

        document.querySelectorAll('.panel-title, .card-title, .card-header').forEach(function (el) {
            if (el.textContent && el.textContent.indexOf('Configuração de Whatsapp') !== -1) {
                painel = el.closest('.panel, .card') || el.parentElement;
            }
        });

        if (!painel) {
            return false;
        }

        if (!painel.querySelector('.aviso-whatsapp-free')) {
            var aviso = document.createElement('div');
            aviso.className = 'alert alert-warning aviso-whatsapp-free';
            aviso.style.margin = '8px';
            aviso.innerHTML = '<i class="fa fa-lock"></i> O envio por WhatsApp está disponível apenas a partir do plano Pro. Faça upgrade em Minha Conta.';
            painel.insertBefore(aviso, painel.firstChild.nextSibling);
        }

        painel.querySelectorAll('button').forEach(function (btn) {
            btn.disabled = true;
        });

        return true;
    }

    if (!aplicar()) {
        setTimeout(aplicar, 200);
    }
})();
JS);
    }

}

