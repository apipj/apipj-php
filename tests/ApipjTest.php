<?php

declare(strict_types=1);

namespace Apipj\Tests;

use Apipj\Apipj;
use Apipj\ApipjException;
use PHPUnit\Framework\TestCase;

final class ApipjTest extends TestCase
{
    private const CNPJ = '11378117000120';

    private TransporteFalso $http;

    protected function setUp(): void
    {
        $this->http = new TransporteFalso();
    }

    /** @param array<string, mixed> $esperado */
    private function lanca(callable $f, array $esperado): ApipjException
    {
        try {
            $f();
        } catch (ApipjException $e) {
            foreach ($esperado as $prop => $valor) {
                self::assertSame($valor, $e->{$prop}, $prop);
            }
            return $e;
        }
        self::fail('Esperava ApipjException');
    }

    public function testConsultarNormalizaMandaChaveEUserAgentEGuardaLimites(): void
    {
        $this->http->responder(TransporteFalso::documento(self::CNPJ, 'LEADS2B S/A', [
            'X-RateLimit-Limit' => '10', 'X-RateLimit-Remaining' => '9', 'X-RateLimit-Reset' => '6',
            'X-Quota-Limit' => '50000', 'X-Quota-Remaining' => '49999', 'X-Quota-Reset' => '1793502000',
        ]));
        $api = $this->http->cliente();
        $doc = $api->consultar('11.378.117/0001-20');

        self::assertSame('LEADS2B S/A', $doc['razao_social']);
        self::assertSame(['/v1/cnpj/' . self::CNPJ], $this->http->caminhos());
        $cab = $this->http->requisicoes[0]['cabecalhos'];
        self::assertSame('Bearer apipj_live_teste', $cab['Authorization']);
        self::assertStringStartsWith('apipj-php/' . Apipj::VERSAO, $cab['User-Agent']);
        self::assertSame(10.0, $this->http->requisicoes[0]['timeout']);
        self::assertSame([
            'por_minuto' => ['limite' => 10, 'restante' => 9, 'reset' => 6],
            'mensal' => ['limite' => 50000, 'restante' => 49999, 'reset' => 1793502000],
        ], $api->limites);
    }

    public function testFormatoSimplificadoEAlfanumericoNormalizado(): void
    {
        $this->http->responder(TransporteFalso::json(200, ['cnpj' => '12.ABC.345/01DE-35', 'nome' => 'X']));
        $doc = $this->http->cliente()->consultar('12.abc.345/01de-35', 'simplificado');
        self::assertSame('X', $doc['nome']);
        self::assertSame(['/v1/cnpj/12ABC34501DE35?formato=simplificado'], $this->http->caminhos());
    }

    public function testCompletaZerosAEsquerda(): void
    {
        $this->http->responder(TransporteFalso::documento('00000000000191', 'BANCO DO BRASIL SA'), TransporteFalso::documento('00000000000191'));
        $api = $this->http->cliente();
        self::assertSame('BANCO DO BRASIL SA', $api->consultar(191)['razao_social']);
        $api->consultar('191');
        self::assertSame(['/v1/cnpj/00000000000191', '/v1/cnpj/00000000000191'], $this->http->caminhos());
    }

    public function testCnpjInvalidoNaoFazRequisicao(): void
    {
        $api = $this->http->cliente();
        $this->lanca(fn () => $api->consultar('11.378.117/0001-21'), ['status' => 400, 'erro' => 'invalid_cnpj', 'cnpj' => '11378117000121']);
        $this->lanca(fn () => $api->consultar('12.ABC.345/01DE-36'), ['status' => 400, 'erro' => 'invalid_cnpj']);
        $this->lanca(fn () => $api->consultar('00000000000000'), ['status' => 400, 'erro' => 'invalid_cnpj']);
        $this->lanca(fn () => $api->consultar(''), ['status' => 400, 'erro' => 'invalid_cnpj']);
        $this->lanca(fn () => $api->consultar(self::CNPJ, 'xml'), ['status' => 400, 'erro' => 'validation_error']);
        self::assertSame([], $this->http->requisicoes);
    }

    public function testSemChaveLanca401LocalELeAmbienteNaHora(): void
    {
        $antes = getenv('APIPJ_KEY');
        try {
            putenv('APIPJ_KEY');
            unset($_ENV['APIPJ_KEY'], $_SERVER['APIPJ_KEY']);
            $api = $this->http->cliente(['chave' => null]);
            $e = $this->lanca(fn () => $api->consultar(self::CNPJ), ['status' => 401, 'erro' => 'unauthorized']);
            self::assertStringContainsString('https://apipj.com.br/criar-conta', $e->getMessage());
            self::assertSame([], $this->http->requisicoes);

            putenv('APIPJ_KEY=apipj_live_do_ambiente');
            $this->http->responder(TransporteFalso::documento(self::CNPJ));
            $api->consultar(self::CNPJ);
            self::assertSame('Bearer apipj_live_do_ambiente', $this->http->requisicoes[0]['cabecalhos']['Authorization']);
        } finally {
            putenv($antes === false ? 'APIPJ_KEY' : "APIPJ_KEY=$antes");
        }
    }

    public function testChaveNaoApareceAoInspecionar(): void
    {
        $api = $this->http->cliente(['chave' => 'apipj_live_segredo123']);
        self::assertStringNotContainsString('segredo123', print_r($api, true));
        ob_start();
        var_dump($api);
        self::assertStringNotContainsString('segredo123', (string) ob_get_clean());
    }

    public function testBaseUrlAceitaBarraEV1NoFim(): void
    {
        self::assertSame('https://x.teste', (new Apipj(baseUrl: 'https://x.teste/v1/', transporte: $this->http))->baseUrl);
        self::assertSame('https://x.teste', (new Apipj(baseUrl: 'https://x.teste/', transporte: $this->http))->baseUrl);
    }

    public function test404NotFoundViraNull(): void
    {
        $this->http->responder(TransporteFalso::erro(404, 'not_found', 'CNPJ não encontrado'));
        self::assertNull($this->http->cliente()->consultar('99999998999918'));
        self::assertCount(1, $this->http->requisicoes);
    }

    public function test404SemCorpoDaApiLanca(): void
    {
        $this->http->responder(['status' => 404, 'corpo' => '<h1>404</h1>', 'cabecalhos' => ['content-type' => 'text/html']]);
        $this->lanca(fn () => $this->http->cliente()->consultar(self::CNPJ), ['status' => 404, 'erro' => 'not_found']);
    }

    public function test400E401DoServidorLancamSemRepetir(): void
    {
        $this->http->responder(TransporteFalso::erro(400, 'invalid_cnpj', 'Dígitos verificadores inválidos', ['cnpj' => self::CNPJ]));
        $e = $this->lanca(fn () => $this->http->cliente()->consultar(self::CNPJ), ['status' => 400, 'erro' => 'invalid_cnpj', 'cnpj' => self::CNPJ]);
        self::assertSame('Dígitos verificadores inválidos', $e->getMessage());
        self::assertSame(400, $e->getCode());

        $this->http->responder(TransporteFalso::erro(401, 'unauthorized', 'Chave de API inválida ou revogada'));
        $this->lanca(fn () => $this->http->cliente()->consultar(self::CNPJ), ['status' => 401, 'erro' => 'unauthorized']);
        self::assertCount(2, $this->http->requisicoes);
        self::assertSame([], $this->http->esperas);
    }

    public function test429QuotaExceededLancaNaHoraComLinksDeUpgrade(): void
    {
        $this->http->responder(TransporteFalso::erro(429, 'quota_exceeded', 'Cota mensal esgotada', ['upgrade_url' => 'https://apipj.com.br/precos', 'reset' => 1793502000], ['Retry-After' => '86400']));
        $e = $this->lanca(fn () => $this->http->cliente()->consultar(self::CNPJ), [
            'status' => 429, 'erro' => 'quota_exceeded', 'upgradeUrl' => 'https://apipj.com.br/precos', 'reset' => 1793502000, 'retryAfter' => null,
        ]);
        self::assertStringContainsString('https://apipj.com.br/painel?assinar=dev', $e->getMessage());
        self::assertStringContainsString('https://apipj.com.br/painel?assinar=pro', $e->getMessage());
        self::assertCount(1, $this->http->requisicoes, 'quota_exceeded não repete');
        self::assertSame([], $this->http->esperas);
    }

    public function test429RateLimitedEsperaRetryAfterERepete(): void
    {
        $this->http->responder(
            TransporteFalso::erro(429, 'rate_limited', 'Limite por minuto', ['retry_after' => 9], ['Retry-After' => '3']),
            TransporteFalso::documento(self::CNPJ),
        );
        $doc = $this->http->cliente()->consultar(self::CNPJ);
        self::assertSame(self::CNPJ, $doc['cnpj']);
        self::assertCount(2, $this->http->requisicoes);
        self::assertSame([3.0], $this->http->esperas, 'usa o cabeçalho Retry-After');
    }

    public function test429RateLimitedSemCabecalhoUsaCorpoEDepoisDasTentativasLanca(): void
    {
        $limite = TransporteFalso::erro(429, 'rate_limited', 'Limite por minuto', ['retry_after' => 2]);
        $this->http->responder($limite, $limite, $limite);
        $this->lanca(fn () => $this->http->cliente()->consultar(self::CNPJ), ['status' => 429, 'erro' => 'rate_limited', 'retryAfter' => 2.0]);
        self::assertCount(3, $this->http->requisicoes, 'no máximo 3 tentativas');
        self::assertSame([2.0, 2.0], $this->http->esperas);
    }

    public function test5xxRepeteComBackoff(): void
    {
        $this->http->responder(
            TransporteFalso::erro(503, 'dataset_unavailable', 'Base indisponível'),
            ['status' => 502, 'corpo' => 'Bad Gateway'],
            TransporteFalso::documento(self::CNPJ),
        );
        $doc = $this->http->cliente()->consultar(self::CNPJ);
        self::assertSame(self::CNPJ, $doc['cnpj']);
        self::assertCount(3, $this->http->requisicoes);
        self::assertSame([1.0, 2.0], $this->http->esperas);
    }

    public function test5xxEsgotadoLancaComBackoff124(): void
    {
        $this->http->sempre(fn () => ['status' => 500, 'corpo' => '']);
        $this->lanca(fn () => $this->http->cliente(['tentativas' => 4])->consultar(self::CNPJ), ['status' => 500, 'erro' => 'server_error']);
        self::assertCount(4, $this->http->requisicoes);
        self::assertSame([1.0, 2.0, 4.0], $this->http->esperas);
    }

    public function testFalhaDeRedeRepeteELanca(): void
    {
        $rede = new ApipjException(0, 'network_error', 'Falha de rede');
        $this->http->responder($rede, TransporteFalso::documento(self::CNPJ));
        self::assertNotNull($this->http->cliente()->consultar(self::CNPJ));
        self::assertSame([1.0], $this->http->esperas);

        $this->http->sempre(fn () => new ApipjException(0, 'timeout', 'Sem resposta'));
        $this->lanca(fn () => $this->http->cliente(['tentativas' => 2])->consultar(self::CNPJ), ['status' => 0, 'erro' => 'timeout']);
    }

    public function testTentativasUmNaoRepete(): void
    {
        $this->http->responder(TransporteFalso::erro(503, 'dataset_unavailable', 'x'));
        $this->lanca(fn () => $this->http->cliente(['tentativas' => 1])->consultar(self::CNPJ), ['status' => 503]);
        self::assertCount(1, $this->http->requisicoes);
    }

    public function testRespostaNaoJsonLancaInvalidResponse(): void
    {
        $this->http->responder(['status' => 200, 'corpo' => '<html>']);
        $this->lanca(fn () => $this->http->cliente()->consultar(self::CNPJ), ['status' => 200, 'erro' => 'invalid_response']);
    }

    public function testValidarStatusEPlanosNaoMandamChave(): void
    {
        $this->http->responder(
            TransporteFalso::json(200, ['cnpj' => '12ABC34501DE35', 'valido' => true, 'formatado' => '12.ABC.345/01DE-35', 'alfanumerico' => true, 'erro' => null]),
            TransporteFalso::json(200, ['status' => 'OK', 'dataset_month' => '2026-09', 'rows' => 73366147]),
            TransporteFalso::json(200, ['plans' => []]),
        );
        $api = $this->http->cliente(['chave' => null]);
        self::assertTrue($api->validar('12.abc.345/01de-35')['valido']);
        self::assertSame('2026-09', $api->status()['dataset_month']);
        self::assertSame(['plans' => []], $api->planos());
        self::assertSame(['/v1/valida/12ABC34501DE35', '/v1/status', '/v1/plans'], $this->http->caminhos());
        foreach ($this->http->requisicoes as $r) {
            self::assertArrayNotHasKey('Authorization', $r['cabecalhos']);
        }
    }

    public function testValidarVazioNaoChamaApi(): void
    {
        $v = $this->http->cliente()->validar('');
        self::assertFalse($v['valido']);
        self::assertSame('CNPJ vazio', $v['erro']);
        self::assertSame([], $this->http->requisicoes);
    }

    public function testUsoMandaChave(): void
    {
        $this->http->responder(TransporteFalso::json(200, ['plan' => 'free', 'limits' => ['rpm' => 10, 'monthly_quota' => 50000], 'used' => 3, 'remaining' => 49997]));
        $u = $this->http->cliente()->uso();
        self::assertSame('free', $u['plan']);
        self::assertSame(['/v1/usage'], $this->http->caminhos());
        self::assertSame('Bearer apipj_live_teste', $this->http->requisicoes[0]['cabecalhos']['Authorization']);
    }
}
