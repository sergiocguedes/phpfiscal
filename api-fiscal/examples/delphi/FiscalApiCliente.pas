unit FiscalApiCliente;

interface

uses
  System.SysUtils, System.Classes, System.IniFiles,
  System.Net.URLClient, System.Net.HttpClient;

type
  TFiscalApiCliente = class
  public
    // AHTTPStatus e o corpo devem ser tratados pelo chamador, inclusive em 4xx/5xx.
    class function PostJSON(const ArquivoINI, Rota, JSON, IdempotencyKey: string;
      out AHTTPStatus: Integer): string; static;
  end;

implementation

class function TFiscalApiCliente.PostJSON(const ArquivoINI, Rota, JSON,
  IdempotencyKey: string; out AHTTPStatus: Integer): string;
var
  Ini: TIniFile;
  Base, Chave: string;
  HTTP: THTTPClient;
  Corpo: TStringStream;
  Headers: TNetHeaders;
  Resposta: IHTTPResponse;
begin
  AHTTPStatus := 0;
  Ini := TIniFile.Create(ArquivoINI);
  try
    Base := Trim(Ini.ReadString('FiscalApi', 'UrlBase', ''));
    Chave := Trim(Ini.ReadString('FiscalApi', 'Chave', ''));
  finally
    Ini.Free;
  end;
  while (Length(Base) > 0) and (Base[Length(Base)] = '/') do
    Delete(Base, Length(Base), 1);
  if (Base = '') or (Chave = '') or (Rota = '') or (Rota[1] <> '/') then
    raise Exception.Create('Configure UrlBase, Chave e uma rota iniciada por /.');

  SetLength(Headers, 2);
  Headers[0] := TNameValuePair.Create('Authorization', 'Bearer ' + Chave);
  Headers[1] := TNameValuePair.Create('Content-Type', 'application/json');
  if IdempotencyKey <> '' then
  begin
    SetLength(Headers, 3);
    Headers[2] := TNameValuePair.Create('Idempotency-Key', IdempotencyKey);
  end;
  HTTP := THTTPClient.Create;
  Corpo := TStringStream.Create(JSON, TEncoding.UTF8);
  try
    HTTP.ConnectionTimeout := 10000;
    HTTP.ResponseTimeout := 150000;
    Corpo.Position := 0;
    Resposta := HTTP.Post(Base + Rota, Corpo, nil, Headers);
    AHTTPStatus := Resposta.StatusCode;
    Result := Resposta.ContentAsString(TEncoding.UTF8);
  finally
    Corpo.Free;
    HTTP.Free;
  end;
end;

end.
