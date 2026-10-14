# apipj: API CNPJ gratuita para PHP

[![Testes](https://github.com/apipj/apipj-php/actions/workflows/ci.yml/badge.svg)](https://github.com/apipj/apipj-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/apipj/apipj.svg)](https://packagist.org/packages/apipj/apipj)
[![PHP](https://img.shields.io/packagist/php-v/apipj/apipj.svg)](https://packagist.org/packages/apipj/apipj)

**API para consultar CNPJ** em PHP: o cliente oficial da **API de CNPJ do [apipj](https://apipj.com.br)**, com **plano gratuito** de 50 mil consultas por mês, sem cartão. Consulte CNPJ e receba razão social, situação cadastral, endereço, CNAEs, Simples/MEI e sócios a partir dos dados abertos da Receita Federal, com suporte ao **CNPJ alfanumérico**.

- PHP 8.1 a 8.4, **zero dependências** (usa só as extensões `curl` e `json`). Funciona em Laravel, Symfony, WordPress ou PHP puro.
- Valida o CNPJ (inclusive alfanumérico) localmente antes de chamar a API: CNPJ inválido não gasta cota.
- Completa os zeros à esquerda que se perderam em planilhas e colunas numéricas (`191` vira `00000000000191`).
- Repete sozinho em limite por minuto (`429` com `Retry-After`), erro `5xx` e falha de rede.
- Consulta em lote com `consultarVarios`, respeitando o limite por minuto do seu plano.

> **Atenção: a chave de API é secreta.** Use este pacote só no servidor (back-end, filas, scripts). Não escreva a chave no código-fonte, não a envie para o Git e nunca a coloque em JavaScript do navegador ou em apps distribuídos: qualquer pessoa conseguiria copiá-la e gastar a sua cota. Guarde-a numa variável de ambiente (`.env`, configuração do PHP-FPM/Apache) ou num cofre de segredos.

## Instalação

```bash
composer require apipj/apipj
```

Crie uma conta grátis em [apipj.com.br/criar-conta](https://apipj.com.br/criar-conta), gere a chave no painel e coloque-a no ambiente do servidor:

```bash
export APIPJ_KEY=apipj_live_...
```

## Consultar CNPJ

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Apipj\Apipj;
use Apipj\ApipjException;
use Apipj\Cnpj;

$api = new Apipj(
    chave: null,          // padrão: lê APIPJ_KEY do ambiente só na hora de consultar
    timeout: 10,          // segundos, por tentativa
    tentativas: 3,        // contando a primeira
);

// Documento completo (array), ou null se o CNPJ não está na base da Receita (404)
$empresa = $api->consultar('11.378.117/0001-20');
if ($empresa !== null) {
    echo $empresa['razao_social'], PHP_EOL;                       // LEADS2B S/A
    echo $empresa['situacao_cadastral']['descricao'], PHP_EOL;    // ATIVA
    echo $empresa['endereco']['municipio']['nome'], PHP_EOL;
}

// Formato simplificado: plano, com datas DD/MM/AAAA e CNPJ, CEP e telefone já formatados
$simples = $api->consultar('11378117000120', 'simplificado');

// Número que perdeu os zeros à esquerda (planilha, coluna INT): vira 00000000000191
$bb = $api->consultar(191);                                       // BANCO DO BRASIL SA

// Validação pela API (GET /v1/valida: sem chave e sem consumo de cota)
$v = $api->validar('12.ABC.345/01DE-35');
// ['cnpj' => '12ABC34501DE35', 'valido' => true, 'formatado' => '12.ABC.345/01DE-35', 'alfanumerico' => true, 'erro' => null]

// Status do serviço e mês do lote da Receita carregado (sem chave)
$status = $api->status();   // ['status' => 'OK', 'dataset_month' => '2026-09', 'rows' => 73366147, ...]

// Seu plano e o uso do mês (com chave, não consome cota)
$uso = $api->uso();         // ['plan' => 'free', 'limits' => ['rpm' => 10, 'monthly_quota' => 50000], 'used' => ..., 'remaining' => ..., 'reset_at' => ...]

// Planos, preços e limites (sem chave)
$planos = $api->planos();
```

Os retornos são arrays associativos (o JSON da API decodificado). Campos e formatos estão na [documentação](https://apipj.com.br/docs).

### Validar CNPJ (inclusive alfanumérico), offline e sem chamar a API

```php
use Apipj\Cnpj;

Cnpj::valido('12.ABC.345/01DE-35');      // true  (alfanumérico)
Cnpj::valido('12ABC34501DE36');          // false (dígito verificador errado)
Cnpj::valido('00.000.000/0000-00');      // false (zerado)
Cnpj::valido(191);                       // true  (00.000.000/0001-91)
Cnpj::normalizar('12.abc.345/01de-35');  // '12ABC34501DE35'
Cnpj::formatar('11378117000120');        // '11.378.117/0001-20'
Cnpj::validarLocal('12.ABC.345/01DE-35');// mesmo formato de resposta de $api->validar()
Cnpj::calcularDv('12ABC34501DE');        // '35'
```

`Cnpj::valido` confere formato e dígitos verificadores (numérico e alfanumérico, IN RFB 2.229/2024: valor de cada caractere = código ASCII - 48); não diz se o CNPJ existe na Receita. Para isso, use `consultar`.

### Consultar CNPJ em lote

```php
$lista = ['11.378.117/0001-20', '00.000.000/0001-91', '11378117000120', '123', 191];

foreach ($api->consultarVarios($lista) as $item) {
    // $item = ['cnpj' => string, 'documento' => ?array, 'erro' => ?ApipjException]
    if ($item['erro']) {
        echo $item['cnpj'], ' ', $item['erro']->erro, ' ', $item['erro']->getMessage(), PHP_EOL;
    } elseif ($item['documento'] === null) {
        echo $item['cnpj'], ' não encontrado', PHP_EOL;
    } else {
        echo $item['cnpj'], ' ', $item['documento']['razao_social'], PHP_EOL;
    }
}
```

- Consulta em sequência e **nunca passa do limite por minuto** do seu plano, lido dos cabeçalhos `X-RateLimit-Limit`/`Remaining`. Use `rpm:` para ficar abaixo do plano, por exemplo para dividir a chave com outro processo: `$api->consultarVarios($lista, rpm: 5)`.
- CNPJs repetidos (mesmo com máscaras diferentes, ou `191` e `00000000000191`) são consultados uma vez só.
- CNPJs inválidos **não chamam a API**: saem com `documento` `null` e `erro->erro === 'invalid_cnpj'`.
- Erro de um CNPJ (por exemplo `5xx` depois das tentativas) sai no próprio item e o lote continua.
- `401` (chave inválida) e cota mensal esgotada (`quota_exceeded`) interrompem o lote lançando `ApipjException`, depois de entregar os resultados anteriores.
- Aceita array ou qualquer `iterable` (por exemplo, um gerador que lê um CSV linha a linha) e devolve um `Generator`; a chave de cada item é o CNPJ normalizado. Use `$item['cnpj']` quando precisar do CNPJ como string (o PHP converte chaves numéricas sem zero à esquerda em `int`).
- Opções: `formato`, `rpm`.

## Tratamento de erros

Todos os erros viram `Apipj\ApipjException`, com `$e->status` (HTTP, ou `0` para rede/tempo esgotado; `getCode()` devolve o mesmo número), `$e->erro` (código estável, em snake_case) e `getMessage()` (em português).

```php
try {
    $empresa = $api->consultar($cnpj);
    if ($empresa === null) {
        return 'CNPJ não encontrado na base da Receita';
    }
} catch (ApipjException $e) {
    switch ($e->erro) {
        case 'invalid_cnpj':   // 400: formato ou dígito verificador inválido (verificado antes de chamar a API)
            break;
        case 'unauthorized':   // 401: chave ausente, inválida ou revogada
            break;
        case 'quota_exceeded': // 429: cota mensal esgotada; a mensagem traz os links para assinar Dev ou Pro, $e->reset é quando zera
            break;
        case 'rate_limited':   // 429: limite por minuto, mesmo depois de esperar o Retry-After em todas as tentativas; $e->retryAfter
            break;
        default:               // 5xx (dataset_unavailable, server_error), network_error, timeout
            error_log("{$e->status} {$e->erro}: {$e->getMessage()}");
    }
}
```

| Situação | O que o cliente faz |
|---|---|
| CNPJ inválido | lança `ApipjException` 400 `invalid_cnpj` **sem chamar a API** |
| `404` (CNPJ válido, fora da base) | devolve `null` |
| `400`, `401` | lança na hora |
| `429 quota_exceeded` | lança na hora, com links para assinar [Dev](https://apipj.com.br/painel?assinar=dev) ou [Pro](https://apipj.com.br/painel?assinar=pro) |
| `429 rate_limited` | espera o `Retry-After` e repete, até 3 tentativas; depois lança com `retryAfter` |
| `5xx`, falha de rede, tempo esgotado | repete com espera de 1 s, 2 s, 4 s...; depois lança |

Depois de cada consulta, `$api->limites` traz o que a API informou: `['por_minuto' => ['limite', 'restante', 'reset'], 'mensal' => ['limite', 'restante', 'reset']]`.

## Laravel

Em `config/services.php`:

```php
'apipj' => [
    'key' => env('APIPJ_KEY'),
],
```

No `.env` (nunca versionado): `APIPJ_KEY=apipj_live_...`. Registre o cliente como singleton em `app/Providers/AppServiceProvider.php`:

```php
use Apipj\Apipj;

public function register(): void
{
    $this->app->singleton(Apipj::class, fn () => new Apipj(chave: config('services.apipj.key')));
}
```

E use por injeção de dependência, com cache (a base da Receita muda uma vez por mês):

```php
use Apipj\Apipj;
use Apipj\ApipjException;
use Illuminate\Support\Facades\Cache;

class FornecedorController extends Controller
{
    public function cnpj(string $cnpj, Apipj $api)
    {
        try {
            $empresa = Cache::remember('cnpj:' . \Apipj\Cnpj::normalizar($cnpj), now()->addDays(30), fn () => $api->consultar($cnpj));
        } catch (ApipjException $e) {
            return response()->json(['erro' => $e->erro, 'mensagem' => $e->getMessage()], $e->status ?: 502);
        }
        return $empresa ?? response()->json(['erro' => 'not_found'], 404);
    }
}
```

Leia a chave via `config()` (e não `env()` direto no código) para funcionar com `php artisan config:cache`. Para listas grandes, rode `consultarVarios` num job de fila em vez de na requisição do usuário.

## Limites e plano gratuito

Cada consulta respondida (inclusive `404`) conta 1 na cota mensal; também vale um limite de consultas por minuto por chave. `validar`, `status`, `uso` e `planos` não consomem cota.

| Plano | Consultas por minuto | Consultas por mês | Preço | |
|---|---|---|---|---|
| Gratuito | 10 | 50.000 | R$ 0 | [criar conta](https://apipj.com.br/criar-conta) |
| Dev | 30 | 200.000 | R$ 12,90/mês | [assinar Dev](https://apipj.com.br/painel?assinar=dev) |
| Pro | 200 | 600.000 | R$ 59,90/mês | [assinar Pro](https://apipj.com.br/painel?assinar=pro) |

Valores atuais em [apipj.com.br/precos](https://apipj.com.br/precos) (ou, no código, `$api->planos()`).

## Opções do cliente

| Parâmetro | Padrão | |
|---|---|---|
| `chave` | `APIPJ_KEY` | chave de API (`apipj_live_...`) |
| `baseUrl` | `APIPJ_BASE_URL` ou `https://apipj.com.br` | URL da API |
| `timeout` | `10` | segundos por tentativa (`0` desliga) |
| `tentativas` | `3` | tentativas por chamada, contando a primeira |
| `transporte` | `CurlTransporte` | callable `fn(string $url, array $cabecalhos, float $timeout): array{status, cabecalhos, corpo}`, para testes ou outro cliente HTTP |

## Links

- Documentação da API: [apipj.com.br/docs](https://apipj.com.br/docs)
- Guia: [Como consultar CNPJ em PHP](https://apipj.com.br/guias/consultar-cnpj-php)
- OpenAPI: [apipj.com.br/openapi-publica.json](https://apipj.com.br/openapi-publica.json)
- Outras linguagens: [JavaScript/TypeScript (npm `apipj`)](https://github.com/apipj/apipj-js), [Python (PyPI `apipj`)](https://github.com/apipj/apipj-py)
- Preços: [apipj.com.br/precos](https://apipj.com.br/precos)

---

## English

**apipj** is the official PHP client for the [apipj](https://apipj.com.br) **Brazilian CNPJ lookup API**: company data (legal name, registration status, address, CNAE activity codes, Simples/MEI, partners) from the Receita Federal open data, with support for the new **alphanumeric CNPJ**. Free plan: 50,000 lookups/month, no credit card.

```bash
composer require apipj/apipj
```

```php
$api = new Apipj\Apipj();                       // reads the APIPJ_KEY env var
$company = $api->consultar('11.378.117/0001-20'); // array, or null when not found (404)
echo $company['razao_social'];

Apipj\Cnpj::valido('12.ABC.345/01DE-35');       // true: offline check-digit validation
foreach ($api->consultarVarios($list) as $item) { /* batch, respects your plan's rate limit */ }
```

- PHP 8.1+, zero dependencies (ext-curl, ext-json), MIT license.
- Validates check digits locally (invalid CNPJs never hit the API), zero-pads numbers that lost leading zeros, retries `429 rate_limited` (honoring `Retry-After`), `5xx` and network errors; throws `Apipj\ApipjException` (`status`, `erro` code) otherwise.
- Keep your API key server-side only. Get a free key at [apipj.com.br/criar-conta](https://apipj.com.br/criar-conta).

## Licença

[MIT](LICENSE). Os dados vêm dos dados abertos do CNPJ publicados pela Receita Federal.
