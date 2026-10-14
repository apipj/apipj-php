<?php

declare(strict_types=1);

namespace Apipj;

/**
 * Limitador de vazão do consultarVarios: janela deslizante de `limite` inícios de requisição por
 * `janela` segundos (60), com o limite lido de X-RateLimit-Limit (ou o `rpm` pedido, o que for menor).
 *
 * @internal
 */
final class Limitador
{
    private ?int $rpmUsuario;
    private ?int $limite;
    /** @var list<float> instantes (s) dos inícios dentro da janela */
    private array $inicios = [];
    private float $bloqueadoAte = 0.0;

    /**
     * @param callable(float): void $dormir
     * @param callable(): float     $relogio
     */
    public function __construct(
        ?int $rpm,
        private readonly float $janela,
        private $dormir,
        private $relogio,
    ) {
        $this->rpmUsuario = $rpm !== null && $rpm > 0 ? $rpm : null;
        $this->limite = $this->rpmUsuario;
    }

    /** Espera até uma nova requisição poder começar e já a registra na janela. */
    public function aguardar(): void
    {
        for (;;) {
            $agora = ($this->relogio)();
            while ($this->inicios !== [] && $this->inicios[0] <= $agora - $this->janela) {
                array_shift($this->inicios);
            }
            $espera = max(0.0, $this->bloqueadoAte - $agora);
            $n = \count($this->inicios);
            if ($this->limite !== null && $n >= $this->limite) {
                $espera = max($espera, $this->inicios[$n - $this->limite] + $this->janela - $agora);
            }
            if ($espera <= 0) {
                $this->inicios[] = $agora;
                return;
            }
            ($this->dormir)($espera);
        }
    }

    /** Registra os cabeçalhos X-RateLimit-Limit e X-RateLimit-Remaining de uma resposta. */
    public function registrar(?int $limite, ?int $restante): void
    {
        if ($limite !== null && $limite > 0) {
            $this->limite = $this->rpmUsuario !== null ? min($this->rpmUsuario, $limite) : $limite;
            if ($restante === 0) {
                $this->bloquear($this->janela / $limite);
            }
        }
    }

    /** Ninguém começa nada nos próximos `$segundos`. */
    public function bloquear(float $segundos): void
    {
        $this->bloqueadoAte = max($this->bloqueadoAte, ($this->relogio)() + $segundos);
    }

    public function limite(): ?int
    {
        return $this->limite;
    }
}
