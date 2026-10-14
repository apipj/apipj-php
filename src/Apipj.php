<?php

declare(strict_types=1);

namespace Apipj;

/**
 * Cliente da API de CNPJ do apipj (https://apipj.com.br).
 *
 * A chave de API é secreta: use este cliente só no servidor (back-end, filas, scripts). Nunca a
 * escreva no código-fonte nem a envie para o navegador ou para apps distribuídos.
 *
 * ```php
 * $api = new Apipj\Apipj();                 // lê APIPJ_KEY do ambiente
 * $empresa = $api->consultar('11.378.117/0001-20');
 * echo $empresa['razao_social'];
 * ```
 */
final class Apipj
{
    public const VERSAO = '0.1.0';
    public const BASE_PADRAO = 'https://apipj.com.br';
    public const CRIAR_CONTA = 'https://apipj.com.br/criar-conta';
    public const ASSINAR_DEV = 'https://apipj.com.br/painel?assinar=dev';
    public const ASSINAR_PRO = 'https://apipj.com.br/painel?assinar=pro';

    private const FORMATOS = ['completo', 'simplificado'];
    /** Erros que valeriam para todos os CNPJs seguintes: consultarVarios para na hora. */
    private const FATAIS = ['unauthorized', 'quota_exceeded'];

    /** URL base da API, sem barra no fim e sem `/v1`. */
    public readonly string $baseUrl;
    /** Tempo máximo de cada tentativa, em segundos (0 desliga). */
    public readonly float $timeout;
    /** Número máximo de tentativas por chamada, contando a primeira (1 = sem repetir). */
    public readonly int $tentativas;

    /**
     * Limites informados pela última consulta autenticada (cabeçalhos X-RateLimit-* e X-Quota-*).
     *
     * @var array{por_minuto: array{limite: ?int, restante: ?int, reset: ?int}, mensal: array{limite: ?int, restante: ?int, reset: ?int}}|null
     */
    public ?array $limites = null;

    private ?string $chave;
    /** @var callable(string, array<string, string>, float): array{status: int, cabecalhos: array<string, string>, corpo: string} */
    private $transporte;
    /** @var callable(float): void */
    private $dormir;
    /** @var callable(): float */
    private $relogio;

    /**
     * @param string|null   $chave      chave de API (`apipj_live_...`). Padrão: variável de ambiente
     *                                  `APIPJ_KEY`, lida só na hora de consultar.
     * @param string|null   $baseUrl    padrão: `APIPJ_BASE_URL` ou https://apipj.com.br
     * @param float         $timeout    segundos por tentativa (0 desliga)
     * @param int           $tentativas tentativas por chamada, contando a primeira
     * @param callable|null $transporte `fn(string $url, array $cabecalhos, float $timeout): array{status, cabecalhos, corpo}`;
     *                                  padrão {@see CurlTransporte}
     * @param float         $esperaBase base do backoff de 5xx e falhas de rede, em segundos (1 s, 2 s, 4 s...)
     * @param callable|null $dormir     `fn(float $segundos): void` (padrão: usleep); útil em testes
     * @param callable|null $relogio    `fn(): float` em segundos (padrão: microtime(true)); útil em testes
     * @param float         $janela     janela do limite por minuto em segundos (60)
     */
    public function __construct(
        #[\SensitiveParameter] ?string $chave = null,
        ?string $baseUrl = null,
        float $timeout = 10.0,
        int $tentativas = 3,
        ?callable $transporte = null,
        private readonly float $esperaBase = 1.0,
        ?callable $dormir = null,
        ?callable $relogio = null,
        private readonly float $janela = 60.0,
    ) {
        $chave = $chave !== null ? trim($chave) : null;
        $this->chave = $chave !== '' ? $chave : null;
        $base = $baseUrl ?? self::env('APIPJ_BASE_URL') ?? self::BASE_PADRAO;
        $base = rtrim($base, '/');
        if (str_ends_with($base, '/v1')) {
            $base = substr($base, 0, -3);
        }
        $this->baseUrl = $base;
        $this->timeout = max(0.0, $timeout);
        $this->tentativas = max(1, $tentativas);
        $this->transporte = $transporte ?? new CurlTransporte();
        $this->dormir = $dormir ?? static function (float $s): void {
            if ($s > 0) {
                usleep((int) round($s * 1_000_000));
            }
        };
        $this->relogio = $relogio ?? static fn (): float => microtime(true);
    }

    /** Esconde a chave em var_dump/print_r. */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'tentativas' => $this->tentativas,
            'chave' => $this->chave !== null ? '***' : '(APIPJ_KEY)',
            'limites' => $this->limites,
        ];
    }

    // ------------------------------------------------------------------ API pública

    /**
     * Consulta os dados cadastrais de um CNPJ (exige chave). Valida o DV localmente antes de chamar.
     *
     * @param string|int $cnpj    CNPJ numérico ou alfanumérico, com ou sem máscara
     * @param string     $formato `completo` (padrão) ou `simplificado`
     *
     * @return array<string, mixed>|null o documento, ou `null` se o CNPJ não está na base da Receita (404)
     *
     * @throws ApipjException 400 `invalid_cnpj` (sem chamar a API), 401, 429 `quota_exceeded`,
     *                        429 `rate_limited` depois das tentativas, 5xx ou falha de rede depois das tentativas
     */
    public function consultar(string|int $cnpj, string $formato = 'completo'): ?array
    {
        $v = Cnpj::validarLocal($cnpj);
        if (!$v['valido']) {
            throw new ApipjException(400, 'invalid_cnpj', (string) $v['erro'], cnpj: $v['cnpj']);
        }
        return $this->consultarNormalizado($v['cnpj'], self::conferirFormato($formato), null);
    }

    /**
     * Consulta vários CNPJs em sequência, respeitando o limite por minuto do plano (lido de
     * X-RateLimit-*) ou o `$rpm` informado, o que for menor.
     *
     * Gera itens `['cnpj' => string, 'documento' => ?array, 'erro' => ?ApipjException]` com a
     * chave igual ao CNPJ normalizado (`iterator_to_array()` dá um mapa cnpj => item).
     *
     * - CNPJs repetidos (depois de normalizados) são consultados uma vez só.
     * - CNPJs inválidos não chamam a API: saem com `documento` null e `erro` `invalid_cnpj`.
     * - 404 sai com `documento` null e `erro` null.
     * - Erro de um CNPJ (5xx, rede ou tempo esgotado depois das tentativas) sai no próprio item.
     * - 401 e cota mensal esgotada (`quota_exceeded`) interrompem o lote lançando ApipjException.
     *
     * @param iterable<string|int> $cnpjs
     *
     * @return \Generator<string, array{cnpj: string, documento: array<string, mixed>|null, erro: ApipjException|null}>
     */
    public function consultarVarios(iterable $cnpjs, string $formato = 'completo', ?int $rpm = null): \Generator
    {
        // valida aqui, na chamada; o gerador só roda quando o laço começa
        $formato = self::conferirFormato($formato);
        return $this->lote($cnpjs, $formato, new Limitador($rpm, $this->janela, $this->dormir, $this->relogio));
    }

    /**
     * @param iterable<string|int> $cnpjs
     *
     * @return \Generator<string, array{cnpj: string, documento: array<string, mixed>|null, erro: ApipjException|null}>
     */
    private function lote(iterable $cnpjs, string $formato, Limitador $limitador): \Generator
    {
        $vistos = [];
        foreach ($cnpjs as $entrada) {
            $v = Cnpj::validarLocal($entrada);
            $c = $v['cnpj'];
            if (isset($vistos[$c])) {
                continue;
            }
            $vistos[$c] = true;
            if (!$v['valido']) {
                yield $c => [
                    'cnpj' => $c,
                    'documento' => null,
                    'erro' => new ApipjException(400, 'invalid_cnpj', (string) $v['erro'], cnpj: $c),
                ];
                continue;
            }
            try {
                $documento = $this->consultarNormalizado($c, $formato, $limitador);
            } catch (ApipjException $e) {
                if (\in_array($e->erro, self::FATAIS, true)) {
                    throw $e;
                }
                yield $c => ['cnpj' => $c, 'documento' => null, 'erro' => $e];
                continue;
            }
            yield $c => ['cnpj' => $c, 'documento' => $documento, 'erro' => null];
        }
    }

    /**
     * Valida formato e DV pela API (`GET /v1/valida/{cnpj}`: sem chave e sem consumo de cota).
     * Para validar offline, use {@see Cnpj::valido()}.
     *
     * @return array{cnpj: string, valido: bool, formatado: ?string, alfanumerico: bool, erro: ?string}
     */
    public function validar(string|int $cnpj): array
    {
        $c = Cnpj::normalizar($cnpj);
        if ($c === '' || \strlen($c) > 32) {
            return Cnpj::validarLocal($c);
        }
        /** @var array{cnpj: string, valido: bool, formatado: ?string, alfanumerico: bool, erro: ?string} */
        return self::exigirJson($this->requisitar('/v1/valida/' . rawurlencode($c)));
    }

    /**
     * Status do serviço e da base (`GET /v1/status`, sem chave):
     * `{status, dataset_month, dataset_generated_at, rows, version, time}`.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return self::exigirJson($this->requisitar('/v1/status'));
    }

    /**
     * Plano, limites e uso do mês da sua chave (`GET /v1/usage`, exige chave, não consome cota):
     * `{plan, plan_name, limits: {rpm, monthly_quota}, used, quota, remaining, reset, reset_at, usage: {...}}`.
     *
     * @return array<string, mixed>
     */
    public function uso(): array
    {
        return self::exigirJson($this->requisitar('/v1/usage', autenticar: true));
    }

    /**
     * Planos públicos, preços e limites (`GET /v1/plans`, sem chave).
     *
     * @return array<string, mixed>
     */
    public function planos(): array
    {
        return self::exigirJson($this->requisitar('/v1/plans'));
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string, mixed>|null */
    private function consultarNormalizado(string $cnpj, string $formato, ?Limitador $limitador): ?array
    {
        $sufixo = $formato === 'simplificado' ? '?formato=simplificado' : '';
        $res = $this->requisitar('/v1/cnpj/' . $cnpj . $sufixo, autenticar: true, limitador: $limitador);
        if ($res['status'] === 404) {
            if (\is_array($res['json']) && ($res['json']['code'] ?? null) === 'not_found') {
                return null;
            }
            throw self::erroDaResposta($res);
        }
        return self::exigirJson($res);
    }

    private function obterChave(): string
    {
        $chave = $this->chave ?? self::env('APIPJ_KEY');
        if ($chave === null) {
            throw new ApipjException(
                401,
                'unauthorized',
                'Chave de API ausente: use new Apipj\Apipj(chave: ...) ou defina a variável de ambiente APIPJ_KEY. '
                . 'Conta grátis em ' . self::CRIAR_CONTA,
            );
        }
        return $chave;
    }

    /**
     * Faz a chamada com repetição: 429 `rate_limited` espera Retry-After; 5xx e rede, backoff 1 s, 2 s, 4 s...
     * Devolve a resposta 2xx ou 404 já decodificada; demais status viram ApipjException.
     *
     * @return array{status: int, cabecalhos: array<string, string>, corpo: string, json: mixed}
     */
    private function requisitar(string $caminho, bool $autenticar = false, ?Limitador $limitador = null): array
    {
        $cabecalhos = [
            'Accept' => 'application/json',
            'User-Agent' => 'apipj-php/' . self::VERSAO . ' PHP/' . PHP_VERSION,
        ];
        if ($autenticar) {
            $cabecalhos['Authorization'] = 'Bearer ' . $this->obterChave();
        }
        $url = $this->baseUrl . $caminho;

        for ($tentativa = 1; ; $tentativa++) {
            $ultima = $tentativa >= $this->tentativas;
            $limitador?->aguardar();
            try {
                $bruto = ($this->transporte)($url, $cabecalhos, $this->timeout);
            } catch (ApipjException $e) {
                // falha de rede ou tempo esgotado
                if ($ultima) {
                    throw $e;
                }
                ($this->dormir)($this->backoff($tentativa));
                continue;
            }
            $cab = array_change_key_case($bruto['cabecalhos'], CASE_LOWER);
            $res = [
                'status' => (int) $bruto['status'],
                'cabecalhos' => $cab,
                'corpo' => (string) $bruto['corpo'],
                'json' => self::decodificar((string) $bruto['corpo']),
            ];
            if ($autenticar) {
                $this->guardarLimites($cab);
            }
            $limitador?->registrar(self::inteiro($cab['x-ratelimit-limit'] ?? null), self::inteiro($cab['x-ratelimit-remaining'] ?? null));

            $status = $res['status'];
            if (($status >= 200 && $status < 300) || $status === 404) {
                return $res;
            }
            $erro = self::erroDaResposta($res);
            if ($status === 429 && $erro->erro !== 'quota_exceeded') {
                if ($ultima) {
                    throw $erro;
                }
                ($this->dormir)(max(0.0, $erro->retryAfter ?? 1.0));
                continue;
            }
            if ($status >= 500) {
                if ($ultima) {
                    throw $erro;
                }
                ($this->dormir)($this->backoff($tentativa));
                continue;
            }
            throw $erro;
        }
    }

    private function backoff(int $tentativa): float
    {
        return min(30.0, $this->esperaBase * 2 ** ($tentativa - 1));
    }

    /** @param array<string, string> $cab */
    private function guardarLimites(array $cab): void
    {
        $h = static fn (string $n): ?int => self::inteiro($cab[$n] ?? null);
        $limite = $h('x-ratelimit-limit');
        $cota = $h('x-quota-limit');
        if ($limite === null && $cota === null) {
            return;
        }
        $this->limites = [
            'por_minuto' => ['limite' => $limite, 'restante' => $h('x-ratelimit-remaining'), 'reset' => $h('x-ratelimit-reset')],
            'mensal' => ['limite' => $cota, 'restante' => $h('x-quota-remaining'), 'reset' => $h('x-quota-reset')],
        ];
    }

    private static function conferirFormato(string $formato): string
    {
        if (!\in_array($formato, self::FORMATOS, true)) {
            throw new ApipjException(400, 'validation_error', 'formato deve ser um de: ' . implode(', ', self::FORMATOS));
        }
        return $formato;
    }

    private static function decodificar(string $corpo): mixed
    {
        if ($corpo === '') {
            return null;
        }
        try {
            return json_decode($corpo, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array{status: int, cabecalhos: array<string, string>, corpo: string, json: mixed} $res
     *
     * @return array<string, mixed>
     */
    private static function exigirJson(array $res): array
    {
        $status = $res['status'];
        if ($status < 200 || $status >= 300) {
            throw self::erroDaResposta($res);
        }
        if (!\is_array($res['json'])) {
            throw new ApipjException($status, 'invalid_response', 'Resposta inesperada da API (não é JSON)', corpo: $res['corpo']);
        }
        return $res['json'];
    }

    /** @param array{status: int, cabecalhos: array<string, string>, corpo: string, json: mixed} $res */
    private static function erroDaResposta(array $res): ApipjException
    {
        $status = $res['status'];
        $c = \is_array($res['json']) ? $res['json'] : [];
        $code = \is_string($c['code'] ?? null) ? $c['code'] : self::codigoPadrao($status);
        $mensagem = \is_string($c['message'] ?? null) ? $c['message'] : "A API respondeu HTTP $status";
        $retryAfter = null;
        if ($status === 429) {
            $retryAfter = self::segundosRetryAfter($res['cabecalhos']['retry-after'] ?? null) ?? self::numero($c['retry_after'] ?? null);
        }
        $upgradeUrl = \is_string($c['upgrade_url'] ?? null) ? $c['upgrade_url'] : null;
        if ($code === 'quota_exceeded') {
            $retryAfter = null;
            $upgradeUrl ??= self::ASSINAR_DEV;
            $mensagem = rtrim($mensagem, '. ') . '. A cota mensal do seu plano acabou: para continuar agora, assine o plano Dev ('
                . self::ASSINAR_DEV . ') ou o Pro (' . self::ASSINAR_PRO . ').';
        }
        $reset = self::numero($c['reset'] ?? null);
        return new ApipjException(
            $status,
            $code,
            $mensagem,
            retryAfter: $retryAfter,
            upgradeUrl: $upgradeUrl,
            reset: $reset !== null ? (int) $reset : null,
            cnpj: \is_string($c['cnpj'] ?? null) ? $c['cnpj'] : null,
            detalhes: $c['details'] ?? null,
            corpo: $res['json'] ?? ($res['corpo'] !== '' ? $res['corpo'] : null),
        );
    }

    private static function codigoPadrao(int $status): string
    {
        return match (true) {
            $status === 400 => 'bad_request',
            $status === 401 => 'unauthorized',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            $status === 503 => 'dataset_unavailable',
            $status >= 500 => 'server_error',
            default => 'http_error',
        };
    }

    private static function segundosRetryAfter(?string $v): ?float
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        if (is_numeric($v)) {
            return max(0.0, (float) $v);
        }
        $t = strtotime($v);
        return $t !== false ? (float) max(0, $t - time()) : null;
    }

    private static function numero(mixed $v): ?float
    {
        if (\is_int($v) || \is_float($v)) {
            return (float) $v;
        }
        if (\is_string($v) && is_numeric($v)) {
            return (float) $v;
        }
        return null;
    }

    private static function inteiro(mixed $v): ?int
    {
        $n = self::numero($v);
        return $n !== null ? (int) $n : null;
    }

    private static function env(string $nome): ?string
    {
        $v = getenv($nome);
        if (!\is_string($v) || trim($v) === '') {
            $v = $_ENV[$nome] ?? $_SERVER[$nome] ?? null;
        }
        return \is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
