<?php

declare(strict_types=1);

namespace Apipj\Tests;

use Apipj\Apipj;
use PHPUnit\Framework\TestCase;

/**
 * Testes contra a API de verdade. Opt-in: rode com APIPJ_TESTE_REAL=1 (e APIPJ_KEY para as
 * consultas autenticadas). Cada execução gasta 2 consultas da cota.
 */
final class ApiRealTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('APIPJ_TESTE_REAL') !== '1') {
            self::markTestSkipped('Defina APIPJ_TESTE_REAL=1 para testar contra a API real');
        }
    }

    private function exigirChave(): void
    {
        if ((string) getenv('APIPJ_KEY') === '') {
            self::markTestSkipped('Defina APIPJ_KEY para as consultas autenticadas');
        }
    }

    public function testStatus(): void
    {
        $s = (new Apipj())->status();
        self::assertSame('OK', $s['status']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $s['dataset_month']);
        self::assertGreaterThan(1_000_000, $s['rows']);
    }

    public function testValidarAlfanumerico(): void
    {
        $api = new Apipj();
        self::assertSame(
            ['cnpj' => '12ABC34501DE35', 'valido' => true, 'formatado' => '12.ABC.345/01DE-35', 'alfanumerico' => true, 'erro' => null],
            $api->validar('12.ABC.345/01DE-35'),
        );
        self::assertFalse($api->validar('12.ABC.345/01DE-36')['valido']);
    }

    public function testConsultarBancoDoBrasilComZerosPerdidos(): void
    {
        $this->exigirChave();
        $api = new Apipj();
        $doc = $api->consultar(191);
        self::assertNotNull($doc);
        self::assertSame('BANCO DO BRASIL SA', $doc['razao_social']);
        self::assertNotNull($api->limites);
    }

    public function testConsultarInexistenteViraNull(): void
    {
        $this->exigirChave();
        self::assertNull((new Apipj())->consultar('99999998999918'));
    }

    public function testUso(): void
    {
        $this->exigirChave();
        $u = (new Apipj())->uso();
        self::assertArrayHasKey('plan', $u);
        self::assertArrayHasKey('limits', $u);
        self::assertIsInt($u['remaining']);
    }
}
