<?php

declare(strict_types=1);

namespace Apipj;

/**
 * Transporte HTTP padrão do cliente, com a extensão curl (sem dependências).
 *
 * Qualquer callable com a mesma assinatura de `__invoke` pode substituí-lo (opção `transporte`
 * do construtor de {@see Apipj}), por exemplo para testes ou para usar outro cliente HTTP.
 *
 * Não segue redirecionamentos: a chave de API nunca vai para outro endereço.
 */
final class CurlTransporte
{
    private ?\CurlHandle $handle = null;

    /**
     * @param string                $url        URL completa
     * @param array<string, string> $cabecalhos nome => valor
     * @param float                 $timeout    segundos por tentativa (0 = sem limite)
     *
     * @return array{status: int, cabecalhos: array<string, string>, corpo: string}
     *         cabeçalhos com nomes em minúsculas
     *
     * @throws ApipjException status 0, `network_error` ou `timeout`
     */
    public function __invoke(string $url, array $cabecalhos, float $timeout): array
    {
        if (!\function_exists('curl_init')) {
            throw new ApipjException(0, 'curl_unavailable', 'A extensão curl do PHP não está habilitada (ext-curl)');
        }
        if ($this->handle === null) {
            $h = curl_init();
            if ($h === false) {
                throw new ApipjException(0, 'network_error', 'Não foi possível iniciar o curl');
            }
            $this->handle = $h;
        } else {
            curl_reset($this->handle);
        }
        $ch = $this->handle;

        $linhas = [];
        foreach ($cabecalhos as $nome => $valor) {
            $linhas[] = $nome . ': ' . $valor;
        }
        $recebidos = [];
        $ms = $timeout > 0 ? (int) ceil($timeout * 1000) : 0;
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $linhas,
            CURLOPT_TIMEOUT_MS => $ms,
            CURLOPT_CONNECTTIMEOUT_MS => $ms > 0 ? min($ms, 10000) : 10000,
            CURLOPT_ENCODING => '',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$recebidos): int {
                $partes = explode(':', $linha, 2);
                if (\count($partes) === 2) {
                    $recebidos[strtolower(trim($partes[0]))] = trim($partes[1]);
                } elseif (str_starts_with($linha, 'HTTP/')) {
                    $recebidos = []; // nova resposta (100 Continue, proxy): descarta os cabeçalhos anteriores
                }
                return \strlen($linha);
            },
        ]);

        $corpo = curl_exec($ch);
        if ($corpo === false || !\is_string($corpo)) {
            $errno = curl_errno($ch);
            $msg = curl_error($ch);
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new ApipjException(0, 'timeout', sprintf('Sem resposta da API em %s s', rtrim(rtrim(number_format($timeout, 3, '.', ''), '0'), '.')));
            }
            throw new ApipjException(0, 'network_error', 'Falha de rede ao chamar a API: ' . ($msg !== '' ? $msg : "curl $errno"));
        }

        return [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'cabecalhos' => $recebidos,
            'corpo' => $corpo,
        ];
    }
}
