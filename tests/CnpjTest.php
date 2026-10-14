<?php

declare(strict_types=1);

namespace Apipj\Tests;

use Apipj\Cnpj;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CnpjTest extends TestCase
{
    public static function validos(): array
    {
        return [
            'numérico sem máscara' => ['11378117000120'],
            'numérico com máscara' => ['11.378.117/0001-20'],
            'Banco do Brasil' => ['00.000.000/0001-91'],
            'alfanumérico com máscara' => ['12.ABC.345/01DE-35'],
            'alfanumérico minúsculo' => ['12.abc.345/01de-35'],
            'alfanumérico sem máscara' => ['12ABC34501DE35'],
            'com espaços' => [' 11 378 117 0001 20 '],
            'perdeu os zeros (string)' => ['191'],
            'perdeu os zeros (int)' => [191],
        ];
    }

    public static function invalidos(): array
    {
        return [
            'alfanumérico DV errado' => ['12.ABC.345/01DE-36'],
            'numérico DV errado' => ['11.378.117/0001-21'],
            'zerado' => ['00000000000000'],
            'zerado com máscara' => ['00.000.000/0000-00'],
            'zero solto' => ['0'],
            'vazio' => [''],
            'nulo' => [null],
            'curto alfanumérico' => ['12ABC'],
            'longo' => ['113781170001200'],
            'DV com letra' => ['12ABC34501DEAB'],
            'caractere inválido' => ['12*ABC34501DE3'],
        ];
    }

    #[DataProvider('validos')]
    public function testValido(string|int $cnpj): void
    {
        self::assertTrue(Cnpj::valido($cnpj));
    }

    #[DataProvider('invalidos')]
    public function testInvalido(string|int|null $cnpj): void
    {
        self::assertFalse(Cnpj::valido($cnpj));
    }

    public function testNormalizar(): void
    {
        self::assertSame('12ABC34501DE35', Cnpj::normalizar('12.abc.345/01de-35'));
        self::assertSame('00000000000191', Cnpj::normalizar('191'));
        self::assertSame('00000000000191', Cnpj::normalizar(191));
        self::assertSame('00000000000191', Cnpj::normalizar('1-91'));
        self::assertSame('11378117000120', Cnpj::normalizar('11.378.117/0001-20'));
        self::assertSame('12ABC', Cnpj::normalizar('12abc'), 'alfanumérico curto não é completado');
        self::assertSame('', Cnpj::normalizar(null));
    }

    public function testFormatar(): void
    {
        self::assertSame('11.378.117/0001-20', Cnpj::formatar('11378117000120'));
        self::assertSame('12.ABC.345/01DE-35', Cnpj::formatar('12abc34501de35'));
        self::assertSame('00.000.000/0001-91', Cnpj::formatar(191));
        self::assertSame('12ABC', Cnpj::formatar('12abc'));
    }

    public function testCalcularDv(): void
    {
        self::assertSame('35', Cnpj::calcularDv('12ABC34501DE'));
        self::assertSame('20', Cnpj::calcularDv('113781170001'));
        self::assertSame('91', Cnpj::calcularDv('000000000001'));
        $this->expectException(\InvalidArgumentException::class);
        Cnpj::calcularDv('123');
    }

    public function testValidarLocalMesmoFormatoDaApi(): void
    {
        self::assertSame(
            ['cnpj' => '12ABC34501DE35', 'valido' => true, 'formatado' => '12.ABC.345/01DE-35', 'alfanumerico' => true, 'erro' => null],
            Cnpj::validarLocal('12.ABC.345/01DE-35'),
        );
        self::assertSame(
            ['cnpj' => '12ABC34501DE36', 'valido' => false, 'formatado' => null, 'alfanumerico' => false, 'erro' => 'Dígitos verificadores inválidos'],
            Cnpj::validarLocal('12.ABC.345/01DE-36'),
        );
        self::assertSame('CNPJ zerado é inválido', Cnpj::validarLocal('00000000000000')['erro']);
        self::assertSame('CNPJ vazio', Cnpj::validarLocal('')['erro']);
        self::assertSame('CNPJ deve ter 14 caracteres (recebido 5)', Cnpj::validarLocal('12ABC')['erro']);
        self::assertFalse(Cnpj::validarLocal('11378117000120')['alfanumerico']);
    }
}
