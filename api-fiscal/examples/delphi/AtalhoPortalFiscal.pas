unit AtalhoPortalFiscal;

{
  Atalho "Portal Fiscal" para os sistemas da LSI.

  Pede à API um link de uso único (válido por poucos minutos) e abre o navegador
  já autenticado no Portal do Contador, sem pedir senha.
  A chave de API fica só no sistema (INI); o navegador recebe apenas o link.

  Uso:
    TAtalhoPortalFiscal.Abrir('C:\LSI\fiscal-api.ini', IntToStr(Usuario.Id),
      Usuario.Nome, Usuario.Email, 'fiscal', 'cliente', 'regras-icms', 'ClickPDV');

  Perfil: 'fiscal' (pode alterar regras) ou 'consulta' (somente leitura).
  Tipo de usuário: 'cliente' ou 'contador'.
  Aba: inicio, perfis, operacoes, regras-icms, regras-piscofins, simulador.
}

interface

type
  TAtalhoPortalFiscal = class
  public
    class procedure Abrir(const ArquivoINI, UsuarioId, UsuarioNome, UsuarioEmail,
      Perfil, TipoUsuario, Aba, Sistema: string); static;
  end;

implementation

uses
  System.SysUtils, System.JSON, Winapi.Windows, Winapi.ShellAPI,
  FiscalApiCliente;

class procedure TAtalhoPortalFiscal.Abrir(const ArquivoINI, UsuarioId,
  UsuarioNome, UsuarioEmail, Perfil, TipoUsuario, Aba, Sistema: string);
var
  Pedido, Usuario, Abrir: TJSONObject;
  Resposta: TJSONValue;
  Corpo, Url, Mensagem: string;
  Status: Integer;
begin
  Pedido := TJSONObject.Create;
  try
    Usuario := TJSONObject.Create;
    Usuario.AddPair('id', UsuarioId);
    Usuario.AddPair('nome', UsuarioNome);
    if UsuarioEmail <> '' then
      Usuario.AddPair('email', UsuarioEmail);
    Pedido.AddPair('usuario', Usuario);
    Pedido.AddPair('perfil', Perfil);
    Pedido.AddPair('tipo_usuario', TipoUsuario);
    Abrir := TJSONObject.Create;
    Abrir.AddPair('aba', Aba);
    Pedido.AddPair('abrir', Abrir);
    Pedido.AddPair('sistema', Sistema);
    Corpo := TFiscalApiCliente.PostJSON(ArquivoINI, '/api/v1/portal/acessos',
      Pedido.ToJSON, '', Status);
  finally
    Pedido.Free;
  end;

  Resposta := TJSONObject.ParseJSONValue(Corpo);
  try
    if (Status = 201) and (Resposta is TJSONObject) and
       TJSONObject(Resposta).TryGetValue<string>('url', Url) and
       (Url.StartsWith('https://') or Url.StartsWith('http://localhost') or
        Url.StartsWith('http://127.0.0.1')) then
      ShellExecute(0, 'open', PChar(Url), nil, nil, SW_SHOWNORMAL)
    else
    begin
      Mensagem := 'Não foi possível abrir o Portal Fiscal (HTTP ' + IntToStr(Status) + ').';
      if (Resposta is TJSONObject) and
         TJSONObject(Resposta).TryGetValue<string>('erro.mensagem', Url) then
        Mensagem := Mensagem + sLineBreak + Url;
      raise Exception.Create(Mensagem);
    end;
  finally
    Resposta.Free;
  end;
end;

end.
