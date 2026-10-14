<?php

declare(strict_types=1);

namespace Apipj;

/**
 * Validação, normalização e formatação de CNPJ, 100% local (sem rede).
 *
 * Vale para o CNPJ numérico e para o alfanumérico (IN RFB 2.229/2024):
 * - 14 caracteres: posições 1 a 12 em [A-Z0-9], posições 13 e 14 (dígitos verificadores) numéricas;
 * - valor de cada caractere = código ASCII - 48 ('0'..'9' => 0..9, 'A'..'Z' => 17..42);
 * - DV1 com pesos 5,4,3,2,9,8,7,6,5,4,3,2 e DV2 com pesos 6,5,4,3,2,9,8,7,6,5,4,3,2;
 * - resto = soma % 11; DV = 0 se resto < 2, senão 11 - resto;
 * - '00000000000000' é inválido mesmo com o DV "certo".
 *
 * Números que perderam os zeros à esquerda (por exemplo 191, vindo de planilha ou de coluna
 * numérica do banco) são completados com zeros até 14 dígitos.
 */
final class Cnpj
{
    private const PESOS = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private function __construct()
    {
    }

    /**
     * Remove pontuação (`.`, `/`, `-`) e espaços, converte para maiúsculas e completa com zeros à
     * esquerda uma entrada só de dígitos com menos de 14. Não valida.
     *
     * Cnpj::normalizar('12.abc.345/01de-35') // '12ABC34501DE35'
     * Cnpj::normalizar(191)                  // '00000000000191'
     */
    public static function normalizar(string|int|null $cnpj): string
    {
        if ($cnpj === null) {
            return '';
        }
        $c = strtoupper((string) preg_replace('/[.\/\s-]/', '', (string) $cnpj));
        if (preg_match('/^[0-9]{1,13}$/', $c) === 1) {
            $c = str_pad($c, 14, '0', STR_PAD_LEFT);
        }
        return $c;
    }

    /**
     * `true` se o CNPJ (numérico ou alfanumérico, com ou sem máscara) tem formato e dígitos
     * verificadores válidos. Local e offline: não diz se o CNPJ existe na base da Receita Federal.
     *
     * Cnpj::valido('12.ABC.345/01DE-35') // true
     */
    public static function valido(string|int|null $cnpj): bool
    {
        return self::validarLocal($cnpj)['valido'];
    }

    /**
     * Formata como `AA.AAA.AAA/AAAA-DD`. Se a entrada normalizada não tiver 14 caracteres,
     * devolve a entrada normalizada sem máscara. Não confere os dígitos verificadores.
     *
     * Cnpj::formatar('11378117000120') // '11.378.117/0001-20'
     */
    public static function formatar(string|int|null $cnpj): string
    {
        $c = self::normalizar($cnpj);
        if (strlen($c) !== 14) {
            return $c;
        }
        return substr($c, 0, 2) . '.' . substr($c, 2, 3) . '.' . substr($c, 5, 3) . '/'
            . substr($c, 8, 4) . '-' . substr($c, 12, 2);
    }

    /**
     * Validação local com o mesmo formato de resposta de `GET /v1/valida/{cnpj}`.
     *
     * @return array{cnpj: string, valido: bool, formatado: ?string, alfanumerico: bool, erro: ?string}
     */
    public static function validarLocal(string|int|null $cnpj): array
    {
        $c = self::normalizar($cnpj);
        $falha = static fn (string $erro): array => [
            'cnpj' => $c, 'valido' => false, 'formatado' => null, 'alfanumerico' => false, 'erro' => $erro,
        ];
        if ($c === '') {
            return $falha('CNPJ vazio');
        }
        if (strlen($c) !== 14) {
            return $falha(sprintf('CNPJ deve ter 14 caracteres (recebido %d)', strlen($c)));
        }
        if (preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $c) !== 1) {
            if (preg_match('/[^A-Z0-9]/', $c) === 1) {
                return $falha('CNPJ contém caracteres inválidos (permitidos: letras A-Z e dígitos)');
            }
            return $falha('Os dois últimos caracteres (dígitos verificadores) devem ser numéricos');
        }
        if ($c === '00000000000000') {
            return $falha('CNPJ zerado é inválido');
        }
        if (substr($c, 12) !== self::calcularDv(substr($c, 0, 12))) {
            return $falha('Dígitos verificadores inválidos');
        }
        return [
            'cnpj' => $c,
            'valido' => true,
            'formatado' => self::formatar($c),
            'alfanumerico' => preg_match('/[A-Z]/', substr($c, 0, 12)) === 1,
            'erro' => null,
        ];
    }

    /**
     * Calcula os dois dígitos verificadores de uma base de 12 caracteres [A-Z0-9].
     *
     * Cnpj::calcularDv('12ABC34501DE') // '35'
     *
     * @throws \InvalidArgumentException se a base não tiver 12 caracteres [A-Z0-9]
     */
    public static function calcularDv(string $base12): string
    {
        $base12 = strtoupper($base12);
        if (preg_match('/^[A-Z0-9]{12}$/', $base12) !== 1) {
            throw new \InvalidArgumentException('A base do CNPJ deve ter 12 caracteres [A-Z0-9]');
        }
        $s1 = 0;
        $s2 = 0;
        for ($i = 0; $i < 12; $i++) {
            $v = ord($base12[$i]) - 48;
            $s1 += $v * self::PESOS[$i + 1];
            $s2 += $v * self::PESOS[$i];
        }
        $dv1 = $s1 % 11 < 2 ? 0 : 11 - ($s1 % 11);
        $s2 += $dv1 * self::PESOS[12];
        $dv2 = $s2 % 11 < 2 ? 0 : 11 - ($s2 % 11);
        return $dv1 . $dv2;
    }
}
