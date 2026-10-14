<?php

declare(strict_types=1);

namespace Apipj;

/**
 * Erro lançado pelo cliente apipj.
 *
 * - `$status`: status HTTP da resposta (400, 401, 429, 5xx) ou `0` para falha de rede ou tempo
 *   esgotado. `getCode()` devolve o mesmo número.
 * - `$erro`: código estável em snake_case (`invalid_cnpj`, `unauthorized`, `rate_limited`,
 *   `quota_exceeded`, `dataset_unavailable`, `validation_error`, `network_error`, `timeout`...).
 * - `$retryAfter`: segundos a esperar (só em `rate_limited`).
 * - `$upgradeUrl`: página de planos (só em `quota_exceeded`); `$reset`: epoch Unix em que a cota zera.
 */
class ApipjException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $erro,
        string $mensagem,
        public readonly ?float $retryAfter = null,
        public readonly ?string $upgradeUrl = null,
        public readonly ?int $reset = null,
        public readonly ?string $cnpj = null,
        public readonly mixed $detalhes = null,
        public readonly mixed $corpo = null,
        ?\Throwable $anterior = null,
    ) {
        parent::__construct($mensagem, $status, $anterior);
    }

    /** Status HTTP (ou 0 para rede/tempo esgotado). */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** Código estável em snake_case, por exemplo `quota_exceeded`. */
    public function getErro(): string
    {
        return $this->erro;
    }
}
