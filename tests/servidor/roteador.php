<?php

// Servidor falso para o teste do CurlTransporte (php -S 127.0.0.1:PORTA tests/servidor/roteador.php).
// Usa um arquivo de contador em sys_get_temp_dir() para simular "falha na 1a vez, sucesso depois".

declare(strict_types=1);

$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$contador = sys_get_temp_dir() . '/apipj-teste-' . getenv('APIPJ_TESTE_ID') . '-' . md5((string) $caminho);
$vez = (int) @file_get_contents($contador) + 1;
file_put_contents($contador, (string) $vez);

header('Content-Type: application/json; charset=utf-8');

$json = static function (int $status, array $corpo, array $cab = []): void {
    http_response_code($status);
    foreach ($cab as $n => $v) {
        header("$n: $v");
    }
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

switch (true) {
    case $caminho === '/v1/cnpj/11378117000120':
        $json(200, [
            'cnpj' => '11378117000120',
            'razao_social' => 'LEADS2B S/A',
            'eco' => [
                'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
                'query' => $_SERVER['QUERY_STRING'] ?? '',
            ],
        ], ['X-RateLimit-Limit' => '10', 'X-RateLimit-Remaining' => '9', 'X-Quota-Limit' => '50000', 'X-Quota-Remaining' => '49990']);
        break;
    case $caminho === '/v1/cnpj/99999998999918':
        $json(404, ['status' => 'ERROR', 'message' => 'CNPJ não encontrado', 'code' => 'not_found']);
        break;
    case $caminho === '/v1/cnpj/00000000000191':
        // 1a vez: limite por minuto com Retry-After: 1; depois responde
        if ($vez === 1) {
            $json(429, ['status' => 'ERROR', 'message' => 'Limite por minuto', 'code' => 'rate_limited', 'retry_after' => 1], ['Retry-After' => '1']);
        } else {
            $json(200, ['cnpj' => '00000000000191', 'razao_social' => 'BANCO DO BRASIL SA']);
        }
        break;
    case $caminho === '/v1/cnpj/34028316000103':
        $json(429, ['status' => 'ERROR', 'message' => 'Cota mensal esgotada', 'code' => 'quota_exceeded', 'upgrade_url' => 'https://apipj.com.br/precos', 'reset' => 1793502000]);
        break;
    case $caminho === '/v1/cnpj/60746948000112':
        usleep(1_500_000); // para o teste de tempo esgotado
        $json(200, ['cnpj' => '60746948000112']);
        break;
    case $caminho === '/redireciona/v1/cnpj/11378117000120':
        http_response_code(302);
        header('Location: /v1/cnpj/11378117000120');
        break;
    default:
        $json(404, ['erro' => 'rota desconhecida']);
}
