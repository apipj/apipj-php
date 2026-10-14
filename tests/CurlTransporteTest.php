<?php

declare(strict_types=1);

namespace Apipj\Tests;

use Apipj\Apipj;
use Apipj\ApipjException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Testa o transporte curl de verdade contra um servidor PHP embutido (php -S) em 127.0.0.1.
 */
#[RequiresPhpExtension('curl')]
final class CurlTransporteTest extends TestCase
{
    /** @var resource|null */
    private static $processo = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $porta = self::portaLivre();
        $id = bin2hex(random_bytes(4));
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:$porta", __DIR__ . '/servidor/roteador.php'];
        $env = array_merge(getenv(), ['APIPJ_TESTE_ID' => $id]);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        if (!\is_resource($proc)) {
            self::markTestSkipped('Não foi possível subir o servidor PHP embutido');
        }
        self::$processo = $proc;
        self::$base = "http://127.0.0.1:$porta";
        for ($i = 0; $i < 100; $i++) {
            $s = @fsockopen('127.0.0.1', $porta, $errno, $errstr, 0.1);
            if ($s !== false) {
                fclose($s);
                return;
            }
            usleep(50_000);
        }
        self::fail('O servidor PHP embutido não subiu');
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$processo)) {
            proc_terminate(self::$processo);
            proc_close(self::$processo);
        }
    }

    private static function portaLivre(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $nome = stream_socket_get_name($s, false);
        fclose($s);
        return (int) substr((string) strrchr((string) $nome, ':'), 1);
    }

    private function cliente(array $opcoes = []): Apipj
    {
        return new Apipj(...array_merge(['chave' => 'apipj_live_curl', 'baseUrl' => self::$base, 'esperaBase' => 0.05], $opcoes));
    }

    public function testConsultaComCabecalhosELimites(): void
    {
        $api = $this->cliente();
        $doc = $api->consultar('11.378.117/0001-20', 'simplificado');
        self::assertSame('LEADS2B S/A', $doc['razao_social']);
        self::assertSame('Bearer apipj_live_curl', $doc['eco']['authorization']);
        self::assertStringStartsWith('apipj-php/', $doc['eco']['user_agent']);
        self::assertSame('application/json', $doc['eco']['accept']);
        self::assertSame('formato=simplificado', $doc['eco']['query']);
        self::assertSame(10, $api->limites['por_minuto']['limite']);
        self::assertSame(49990, $api->limites['mensal']['restante']);
    }

    public function test404ViraNull(): void
    {
        self::assertNull($this->cliente()->consultar('99999998999918'));
    }

    public function testRetryAfterLidoDoCabecalhoPeloCurl(): void
    {
        $inicio = microtime(true);
        $doc = $this->cliente()->consultar('00000000000191');
        self::assertSame('BANCO DO BRASIL SA', $doc['razao_social']);
        self::assertGreaterThanOrEqual(0.9, microtime(true) - $inicio, 'esperou o Retry-After de 1 s');
    }

    public function testQuotaExceeded(): void
    {
        try {
            $this->cliente()->consultar('34028316000103');
            self::fail('Esperava quota_exceeded');
        } catch (ApipjException $e) {
            self::assertSame('quota_exceeded', $e->erro);
            self::assertSame(1793502000, $e->reset);
            self::assertStringContainsString('assinar=pro', $e->getMessage());
        }
    }

    public function testTempoEsgotado(): void
    {
        try {
            $this->cliente(['timeout' => 0.3, 'tentativas' => 1])->consultar('60746948000112');
            self::fail('Esperava timeout');
        } catch (ApipjException $e) {
            self::assertSame(0, $e->status);
            self::assertSame('timeout', $e->erro);
        }
    }

    public function testNaoSegueRedirecionamento(): void
    {
        try {
            $this->cliente(['baseUrl' => self::$base . '/redireciona', 'tentativas' => 1])->consultar('11378117000120');
            self::fail('Esperava erro em vez de seguir o redirecionamento');
        } catch (ApipjException $e) {
            self::assertSame(302, $e->status);
        }
    }

    public function testFalhaDeConexao(): void
    {
        $porta = self::portaLivre();
        try {
            $this->cliente(['baseUrl' => "http://127.0.0.1:$porta", 'tentativas' => 2])->status();
            self::fail('Esperava network_error');
        } catch (ApipjException $e) {
            self::assertSame(0, $e->status);
            self::assertSame('network_error', $e->erro);
        }
    }
}
