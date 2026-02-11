<?php
// /var/www/pagarenlinea/post_probe_matrix.php
require __DIR__ . '/config.php';

date_default_timezone_set('America/Montevideo');

// ====== FIX: helper para post + log ======
function http_post_logged(string $url, array $data, string $tag, string $idTx) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    // Log TXT
    $logPath = build_log_path("matrix_probe_$tag", $idTx, 'txt');
    $lines = [];
    $lines[] = str_repeat('=', 100);
    $lines[] = "MATRIX PROBE [$tag] @ " . date('c');
    $lines[] = "HTTP: " . ($info['http_code'] ?? 'N/A');
    $lines[] = "URL : " . $url;
    $lines[] = "CURL ERROR: " . $err;
    $lines[] = "--- POST DATA ---";
    foreach ($data as $k => $v) $lines[] = sprintf('%-18s: %s', $k, $v);
    $lines[] = "--- RESPONSE ---";
    $lines[] = (string)$resp;
    file_put_contents($logPath, implode(PHP_EOL, $lines) . PHP_EOL);

    // Log JSON estructurado
    $jsonPath = build_log_path("matrix_probe_$tag", $idTx, 'json');
    $bundle = [
        'timestamp' => date('c'),
        'tag'       => $tag,
        'http'      => ['code' => $info['http_code'] ?? null, 'info' => $info, 'error' => $err],
        'request'   => $data,
        'response'  => $resp,
    ];
    file_put_contents($jsonPath, json_encode($bundle, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

    return [$logPath, $jsonPath, $info['http_code'] ?? null];
}

// ====== Parámetros base (mismos de tu prueba) ======
$base = [
    'idBanco'        => '009',
    'idOrganismo'    => ID_ORGANISMO, // 'DYNAMICA'
    'tipoServicio'   => TIPO_SERVICIO, // 'SERV'
    'idCuenta'       => 'CLI-0001',
    'importe'        => '98800',
    'moneda'         => 'UYU',
    'importeGravado' => '40000',
    'urlVuelta'      => 'https://www.datosdynamica.net/pagarenlinea/retorno.php',
];

// Vamos a fijar un idTransaccion reproducible para que la firma sea estable
$idTxFijo = '20251008114903141403';

// ====== Variantes a testear ======
$variantes = [
    // A) Tu caso exacto (1, con guion)
    ['tag'=>'A_CF1_FAC-','consumidorFinal'=>'1','idFactura'=>'FAC-123'],
    // B) consumidorFinal = S (algunas instalaciones lo usan)
    ['tag'=>'B_CFS_FAC-','consumidorFinal'=>'S','idFactura'=>'FAC-123'],
    // C) consumidorFinal = 1 y idFactura sin guion
    ['tag'=>'C_CF1_FAC','consumidorFinal'=>'1','idFactura'=>'FAC123'],
    // D) consumidorFinal = S e idFactura sin guion
    ['tag'=>'D_CFS_FAC','consumidorFinal'=>'S','idFactura'=>'FAC123'],
];

// ====== Ejecutar cada variante ======
$url = SPE_URL_TEST;
$salida = [];

foreach ($variantes as $v) {
    // armar params
    $params = $base + [
        'idTransaccion'  => $idTxFijo,
        'idFactura'      => $v['idFactura'],
        'consumidorFinal'=> $v['consumidorFinal'],
    ];

    // Firmar (recalcula string con el campo consumidorFinal como lo espera esa variante)
    try {
        // NOTA: si consumidorFinal == 'S' mapeamos directo; si fuera '1', ya es texto '1'
        // Para forzar 'S', sobreescribimos el valor después de firmar? NO. Debe formar parte del string.
        // Así que firmamos exactamente con el valor presente en $params.
        $firma = generar_firma($params);
    } catch (Throwable $e) {
        $salida[] = "{$v['tag']}: ERROR FIRMA - " . $e->getMessage();
        continue;
    }

    $post = $params + ['firma' => $firma];

    // Enviar y loguear
    [$logTxt, $logJson, $code] = http_post_logged($url, $post, $v['tag'], $idTxFijo);
    $salida[] = "{$v['tag']}: HTTP $code | TXT: $logTxt | JSON: $logJson";
}

// Salida por pantalla
header('Content-Type: text/plain; charset=utf-8');
echo "MATRIX PROBE done @ " . date('c') . "\n\n";
echo implode("\n", $salida), "\n";
?>