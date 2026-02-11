<?php
// /var/www/pagarenlinea/post_probe_hypotheses.php
require __DIR__ . '/config.php';

date_default_timezone_set('America/Montevideo');

function build_string_to_sign(array $params, array $order): string {
    $vals = [];
    foreach ($order as $k) {
        if (!array_key_exists($k, $params)) {
            throw new RuntimeException("Falta parámetro en orden: $k");
        }
        $v = trim((string)$params[$k]);
        if (strpos($v, '|') !== false)  { throw new RuntimeException("Valor inválido (|) en $k"); }
        if (preg_match('/[\r\n]/', $v)) { throw new RuntimeException("Valor inválido (CR/LF) en $k"); }
        $vals[] = $v;
    }
    return implode('|', $vals);
}

function sign_with_order(array $params, array $order): string {
    $stringToSign = build_string_to_sign($params, $order);
    $priv = @openssl_pkey_get_private('file://' . PRIVKEY_PATH, PRIVKEY_PASS);
    if (!$priv) throw new RuntimeException('No se pudo cargar la clave privada');
    $ok = openssl_sign($stringToSign, $sig, $priv, OPENSSL_ALGO_SHA256);
    openssl_free_key($priv);
    if (!$ok) throw new RuntimeException('openssl_sign falló');
    return rtrim(str_replace(["\r","\n"], '', base64_encode($sig)));
}

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

    $txtPath = build_log_path("hypo_probe_$tag", $idTx, 'txt');
    $lines = [];
    $lines[] = str_repeat('=', 100);
    $lines[] = "HYPOTHESIS PROBE [$tag] @ " . date('c');
    $lines[] = "HTTP: " . ($info['http_code'] ?? 'N/A');
    $lines[] = "URL : " . $url;
    $lines[] = "CURL ERROR: " . $err;
    $lines[] = "--- POST DATA ---";
    foreach ($data as $k => $v) $lines[] = sprintf('%-18s: %s', $k, $v);
    $lines[] = "--- RESPONSE ---";
    $lines[] = (string)$resp;
    file_put_contents($txtPath, implode(PHP_EOL, $lines) . PHP_EOL);

    $jsonPath = build_log_path("hypo_probe_$tag", $idTx, 'json');
    $bundle = [
        'timestamp' => date('c'),
        'tag'       => $tag,
        'http'      => ['code' => $info['http_code'] ?? null, 'info' => $info, 'error' => $err],
        'request'   => $data,
        'response'  => $resp,
    ];
    file_put_contents($jsonPath, json_encode($bundle, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

    return [$txtPath, $jsonPath, $info['http_code'] ?? null];
}

// ====== Base “quemada” (igual a tu prueba) ======
$baseParams = [
    'idBanco'        => '009',
    'idOrganismo'    => ID_ORGANISMO,          // 'DYNAMICA'
    'tipoServicio'   => TIPO_SERVICIO,         // 'SERV'
    'idCuenta'       => 'CLI-0001',
    'idFactura'      => 'FAC-123',
    'importe'        => '98800',
    'moneda'         => 'UYU',                 // variante: '858'
    'importeGravado' => '40000',               // variante: '0'
    'consumidorFinal'=> '1',                   // confirmado por tus pruebas
    'urlVuelta'      => 'https://www.datosdynamica.net/pagarenlinea/retorno.php',
];

$idTxFijo   = '20251008114903141403';         // fijo para reproducibilidad
$speUrl     = SPE_URL_TEST;

// Orden “estándar” (10 campos, sin urlVuelta)
$ORDER_STD = [
    'idBanco','idTransaccion','idOrganismo','tipoServicio','idCuenta',
    'idFactura','importe','moneda','importeGravado','consumidorFinal'
];

// Orden “con urlVuelta” (11 campos; hipótesis frecuente)
$ORDER_URL = [
    'idBanco','idTransaccion','idOrganismo','tipoServicio','idCuenta',
    'idFactura','importe','moneda','importeGravado','consumidorFinal','urlVuelta'
];

// ====== Definición de hipótesis ======
$hypos = [
    // H1: estándar (baseline de control)
    ['tag'=>'H1_STD',      'mut'=>function($p){ return $p; },                           'order'=>$ORDER_STD],
    // H2: moneda numérica 858
    ['tag'=>'H2_NUMCUR',   'mut'=>function($p){ $p['moneda']='858'; return $p; },       'order'=>$ORDER_STD],
    // H3: importeGravado = 0
    ['tag'=>'H3_GRAV0',    'mut'=>function($p){ $p['importeGravado']='0'; return $p; }, 'order'=>$ORDER_STD],
    // H4: 858 + gravado=0
    ['tag'=>'H4_CUR_GR0',  'mut'=>function($p){ $p['moneda']='858'; $p['importeGravado']='0'; return $p; }, 'order'=>$ORDER_STD],
    // H5: idFactura sin guion
    ['tag'=>'H5_FAC_NODASH','mut'=>function($p){ $p['idFactura']=str_replace('-','',$p['idFactura']); return $p; }, 'order'=>$ORDER_STD],
    // H6: idCuenta sin guion
    ['tag'=>'H6_CTA_NODASH','mut'=>function($p){ $p['idCuenta']=str_replace('-','',$p['idCuenta']); return $p; },   'order'=>$ORDER_STD],
    // H7: incluir urlVuelta en string firmado
    ['tag'=>'H7_ADD_URL',  'mut'=>function($p){ return $p; },                           'order'=>$ORDER_URL],
    // H8: incluir urlVuelta + 858 + grav=0 + sin guiones (combo “agresivo”)
    ['tag'=>'H8_ALL',      'mut'=>function($p){
                                $p['moneda']='858'; $p['importeGravado']='0';
                                $p['idFactura']=str_replace('-','',$p['idFactura']);
                                $p['idCuenta'] =str_replace('-','',$p['idCuenta']);
                                return $p;
                            }, 'order'=>$ORDER_URL],
];

$results = [];
foreach ($hypos as $h) {
    $tag = $h['tag'];
    // construir params para esta hipótesis
    $params = $baseParams;
    $params['idTransaccion'] = $idTxFijo;
    $params = $h['mut']($params);

    try {
        // firmar con el orden propuesto
        $firma = sign_with_order($params, $h['order']);
        $post  = $params + ['firma' => $firma];

        // enviar
        [$txt, $json, $code] = http_post_logged($speUrl, $post, $tag, $idTxFijo);
        $results[] = "$tag: HTTP $code | $txt | $json";

    } catch (Throwable $e) {
        $txt = build_log_path("hypo_probe_$tag", $idTxFijo, 'txt');
        file_put_contents($txt, "ERROR [$tag]: " . $e->getMessage() . PHP_EOL);
        $results[] = "$tag: ERROR firma/exec -> $txt";
    }
}

// salida
header('Content-Type: text/plain; charset=utf-8');
echo "HYPOTHESIS PROBE done @ " . date('c') . "\n\n";
echo implode("\n", $results), "\n";
?>