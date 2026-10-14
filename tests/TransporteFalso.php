<?php

declare(strict_types=1);

namespace Apipj\Tests;

use Apipj\Apipj;
use Apipj\ApipjException;

/**
 * Transporte em memória para os testes: responde a partir de uma fila (ou de um callable),
 * registra as requisições e as esperas, e tem um relógio falso que avança quando o cliente "dorme".
 */
final class TransporteFalso
{
    public const BASE = 'https://api.teste';

    /** @var list<array{url: string, cabecalhos: array<string, string>, timeout: float, t: float}> */
    public array $requisicoes = [];
    /** @var list<float> */
    public array $esperas = [];
    public float $agora = 1000.0;

    /** @var list<array|ApipjException> */
    private array $fila = [];

    /** @var (callable(string): (array|ApipjException))|null */
    private $tratador = null;

    /** Enfileira respostas (array de resposta, ou ApipjException a lançar como falha de rede). */
    public function responder(array|ApipjException ...$respostas): self
    {
        foreach ($respostas as $r) {
            $this->fila[] = $r;
        }
        return $this;
    }

    /** Responde as requisições (depois da fila) com um callable fn(string $url): array|ApipjException. */
    public function sempre(callable $tratador): self
    {
        $this->tratador = $tratador;
        return $this;
    }

    public function __invoke(string $url, array $cabecalhos, float $timeout): array
    {
        $this->requisicoes[] = ['url' => $url, 'cabecalhos' => $cabecalhos, 'timeout' => $timeout, 't' => $this->agora];
        if ($this->fila !== []) {
            $r = array_shift($this->fila);
        } elseif ($this->tratador !== null) {
            $r = ($this->tratador)($url);
        } else {
            throw new \LogicException("Requisição inesperada: $url");
        }
        if ($r instanceof ApipjException) {
            throw $r;
        }
        $corpo = $r['corpo'] ?? '';
        return [
            'status' => $r['status'],
            'cabecalhos' => $r['cabecalhos'] ?? [],
            'corpo' => \is_string($corpo) ? $corpo : json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    public function cliente(array $opcoes = []): Apipj
    {
        return new Apipj(...array_merge([
            'chave' => 'apipj_live_teste',
            'baseUrl' => self::BASE,
            'transporte' => $this,
            'dormir' => function (float $s): void {
                $this->esperas[] = $s;
                $this->agora += $s;
            },
            'relogio' => fn (): float => $this->agora,
        ], $opcoes));
    }

    /** @return list<string> caminhos (sem a base) das requisições feitas */
    public function caminhos(): array
    {
        return array_map(static fn (array $r): string => substr($r['url'], \strlen(self::BASE)), $this->requisicoes);
    }

    public static function json(int $status, array $corpo, array $cabecalhos = []): array
    {
        return ['status' => $status, 'corpo' => $corpo, 'cabecalhos' => $cabecalhos + ['content-type' => 'application/json']];
    }

    public static function documento(string $cnpj, string $razao = 'EMPRESA TESTE LTDA', array $cabecalhos = []): array
    {
        return self::json(200, ['cnpj' => $cnpj, 'razao_social' => $razao, 'situacao_cadastral' => ['codigo' => 2, 'descricao' => 'ATIVA']], $cabecalhos);
    }

    public static function erro(int $status, string $code, string $message, array $extra = [], array $cabecalhos = []): array
    {
        return self::json($status, ['status' => 'ERROR', 'message' => $message, 'code' => $code] + $extra, $cabecalhos);
    }
}
