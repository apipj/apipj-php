<?php

declare(strict_types=1);

namespace Apipj\Tests;

use Apipj\ApipjException;
use PHPUnit\Framework\TestCase;

final class ConsultarVariosTest extends TestCase
{
    private TransporteFalso $http;

    protected function setUp(): void
    {
        $this->http = new TransporteFalso();
    }

    private static function cnpjDaUrl(string $url): string
    {
        return (string) preg_replace('#^.*/v1/cnpj/([A-Z0-9]+).*$#', '$1', $url);
    }

    public function testPulaInvalidosEDuplicadosAntesDeChamar(): void
    {
        $this->http->sempre(function (string $url): array {
            $c = self::cnpjDaUrl($url);
            return $c === '99999998999918'
                ? TransporteFalso::erro(404, 'not_found', 'CNPJ não encontrado')
                : TransporteFalso::documento($c);
        });
        $lista = ['11.378.117/0001-20', '00000000000191', '11378117000120', 'invalido', '12.ABC.345/01DE-36', 191, '99999998999918'];
        $r = iterator_to_array($this->http->cliente()->consultarVarios($lista));

        self::assertSame(
            ['/v1/cnpj/11378117000120', '/v1/cnpj/00000000000191', '/v1/cnpj/99999998999918'],
            $this->http->caminhos(),
            'inválidos e duplicados (inclusive 191 = 00000000000191) não chamam a API',
        );
        // o PHP converte chaves numéricas sem zero à esquerda em int: o CNPJ em string está sempre em ['cnpj']
        self::assertSame(['11378117000120', '00000000000191', 'INVALIDO', '12ABC34501DE36', '99999998999918'], array_values(array_column($r, 'cnpj')));
        self::assertSame(array_map('strval', array_keys($r)), array_values(array_column($r, 'cnpj')));
        self::assertSame('11378117000120', $r['11378117000120']['documento']['cnpj']);
        self::assertNull($r['11378117000120']['erro']);
        self::assertNull($r['INVALIDO']['documento']);
        self::assertInstanceOf(ApipjException::class, $r['INVALIDO']['erro']);
        self::assertSame('invalid_cnpj', $r['12ABC34501DE36']['erro']->erro);
        self::assertNull($r['99999998999918']['documento'], '404 vira documento null');
        self::assertNull($r['99999998999918']['erro'], '404 não é erro');
    }

    public function testFormatoInvalidoLancaNaChamada(): void
    {
        $this->expectException(ApipjException::class);
        $this->http->cliente()->consultarVarios(['11378117000120'], 'xml');
    }

    public function testRespeitaRpmDoPlanoLidoDosCabecalhos(): void
    {
        $this->http->sempre(fn (string $url): array => TransporteFalso::documento(self::cnpjDaUrl($url), 'X', ['X-RateLimit-Limit' => '2']));
        $lista = ['11378117000120', '00000000000191', '12ABC34501DE35', '34028316000103', '60746948000112'];
        $n = 0;
        foreach ($this->http->cliente()->consultarVarios($lista) as $item) {
            self::assertNull($item['erro']);
            $n++;
        }
        self::assertSame(5, $n);
        $t = array_map(static fn (array $r): float => $r['t'] - 1000.0, $this->http->requisicoes);
        // 2 por minuto: duas no instante 0, duas em 60 s, uma em 120 s
        self::assertSame([0.0, 0.0, 60.0, 60.0, 120.0], $t);
    }

    public function testRpmDoUsuarioMenorQueOPlano(): void
    {
        $this->http->sempre(fn (string $url): array => TransporteFalso::documento(self::cnpjDaUrl($url), 'X', ['X-RateLimit-Limit' => '200']));
        iterator_to_array($this->http->cliente()->consultarVarios(['11378117000120', '00000000000191', '12ABC34501DE35'], rpm: 1));
        $t = array_map(static fn (array $r): float => $r['t'] - 1000.0, $this->http->requisicoes);
        self::assertSame([0.0, 60.0, 120.0], $t);
    }

    public function testRateLimitedNoLoteEsperaERepete(): void
    {
        $this->http->responder(
            TransporteFalso::documento('11378117000120'),
            TransporteFalso::erro(429, 'rate_limited', 'Limite por minuto', [], ['Retry-After' => '5']),
            TransporteFalso::documento('00000000000191'),
        );
        $r = iterator_to_array($this->http->cliente()->consultarVarios(['11378117000120', '00000000000191']));
        self::assertNotNull($r['00000000000191']['documento']);
        self::assertSame([5.0], $this->http->esperas);
        self::assertCount(3, $this->http->requisicoes);
    }

    public function testErroDeUmCnpjSaiNoItemEOLoteContinua(): void
    {
        $falha = TransporteFalso::erro(503, 'dataset_unavailable', 'Base indisponível');
        $this->http->responder($falha, $falha, $falha, TransporteFalso::documento('00000000000191'));
        $r = iterator_to_array($this->http->cliente()->consultarVarios(['11378117000120', '00000000000191']));
        self::assertSame('dataset_unavailable', $r['11378117000120']['erro']->erro);
        self::assertNotNull($r['00000000000191']['documento']);
    }

    public function testQuotaExceededInterrompeOLoteDepoisDeEntregarOsAnteriores(): void
    {
        $this->http->responder(
            TransporteFalso::documento('11378117000120'),
            TransporteFalso::erro(429, 'quota_exceeded', 'Cota mensal esgotada'),
        );
        $entregues = [];
        try {
            foreach ($this->http->cliente()->consultarVarios(['11378117000120', '00000000000191', '12ABC34501DE35']) as $c => $item) {
                $entregues[] = $c;
            }
            self::fail('Esperava quota_exceeded');
        } catch (ApipjException $e) {
            self::assertSame('quota_exceeded', $e->erro);
        }
        self::assertSame(['11378117000120'], $entregues);
        self::assertCount(2, $this->http->requisicoes, 'não chama a API depois da cota esgotada');
    }

    public function testAceitaGerador(): void
    {
        $this->http->sempre(fn (string $url): array => TransporteFalso::documento(self::cnpjDaUrl($url)));
        $fonte = (static function () {
            yield '11378117000120';
            yield '00.000.000/0001-91';
        })();
        self::assertCount(2, iterator_to_array($this->http->cliente()->consultarVarios($fonte)));
    }
}
