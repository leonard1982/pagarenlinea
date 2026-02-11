<?php
// /var/www/pagarenlinea/enviar_prueba_sistarbanc.php
// Ejecuta el mismo POST que haría tu formulario y guarda la respuesta completa del SPE

date_default_timezone_set('America/Montevideo');

// ==================== CONFIGURACIÓN ====================
$url = 'https://spftest.sistarbanc.com.uy/spfe/servlet/PagoEmpresa';

// Firma pegada (Base64 sin saltos ni espacios)
$firma = 'fpcHG7PpYvfshe0Z6eiBpSNaxvmLhGsSVMsfDwDSS4aZprFJtYn3p8wHQtoZdJwHf2izsMTYxq7skruF5W09ST5kr40l4wzGltiqzfVt13/6kQs0zAtf40OpkVJrjd2Yru9/a+wkKY4SBhP9Q8jWLIzrdCNqwLKW9JEZ9AsqUimGMDOc8oVc9icv7aYCMvt34Uh804/w8xMiss9pSZatcv0yurjuMmQvvT4mIQyvZPaOjzoMsOqBn7LMZ9DZ6k9fuljJigbBPfMRpldWD5nFp/GzwsBJl6JyYJbuWqbpEO6CM3RIIl0+lw3aAzn/J3i6QrkmY65XDq/szg3LL8dkjT2FLTS8XB6Twm5ipAUScmfaFXCArk1SOtOVQdiG9D5NPlQea7X2H4IS7kTRKzV1UuiZUeyXbutYRrcIq6jmAhUp/o7KxDF520fFi90cq6w6OA5YiaUnybviVf2HSCMj6BnK0ImdJbDu+oueUoPAwADyK/U7RXxuFoFclU1xZzoK';

// Datos quemados del ejemplo
$data = [
    'idBanco'        => '009',
    'idTransaccion'  => '20251008114903141403',
    'idOrganismo'    => 'DYNAMICA',
    'tipoServicio'   => 'SERV',
    'idCuenta'       => 'CLI-0001',
    'idFactura'      => 'FAC-123',
    'importe'        => '98800',
    'moneda'         => 'UYU',
    'importeGravado' => '40000',
    'consumidorFinal'=> '1',
    'urlVuelta'      => 'https://www.datosdynamica.net/pagarenlinea/retorno.php',
    'firma'          => $firma
];

// ==================== CURL ====================
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($data),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$curlInfo = curl_getinfo($ch);
$error    = curl_error($ch);
curl_close($ch);

// ==================== LOG ====================
$logDir = '/var/www/pagarenlinea/log/' . date('Y/m/d');
@mkdir($logDir, 0775, true);
$stamp = date('Ymd_His');
$logFile = "$logDir/{$stamp}_probe_sistarbanc.txt";

$log = [];
$log[] = str_repeat('=', 80);
$log[] = "SISTARBANC PROBE @ " . date('c');
$log[] = str_repeat('-', 80);
$log[] = "URL: $url";
$log[] = "HTTP CODE: " . ($curlInfo['http_code'] ?? 'N/A');
$log[] = "CURL ERROR: $error";
$log[] = "--- POST DATA ---";
foreach ($data as $k => $v) {
    $log[] = sprintf('%-18s: %s', $k, $v);
}
$log[] = "--- RESPONSE HEADERS + BODY ---";
$log[] = $response;
$log[] = str_repeat('=', 80);
file_put_contents($logFile, implode(PHP_EOL, $log));

echo "<pre>";
echo "✅ Solicitud enviada a Sistarbanc (TEST)\n";
echo "Archivo de log: $logFile\n\n";
echo htmlspecialchars($response);
echo "</pre>";
?>