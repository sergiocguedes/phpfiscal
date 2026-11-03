# Trechos de app/control/configuracoes/ParametrosGeraisForm.php

Métodos completos extraídos do fonte atual. No editor de ação que já contém a assinatura, copie apenas o corpo. Não duplique declarações.

## onSave — linha de referência 490

```php
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
```

