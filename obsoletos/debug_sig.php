<?php
// /var/www/sistarbanc/debug_sig.php
require __DIR__ . '/config.php';

$idCuenta     = isset($_GET['id'])    ? trim($_GET['id'])    : 'CLI-TEST-001';
$idFactura    = isset($_GET['fact'])  ? trim($_GET['fact'])  : 'FAC-TEST-001';
$importeTotal = isset($_GET['total']) ? trim($_GET['total']) : '988.00';
$importeGrav  = isset($_GET['gravado']) ? trim($_GET['gravado']) : '400.00';
$moneda       = isset($_GET['moneda']) ? strtoupper(substr(trim($_GET['moneda']),0,3)) : 'UYU';
$esCF         = (isset($_GET['cf']) ? $_GET['cf'] === '1' : true);

$idBanco       = BANCO_BROU;
$idTransaccion = date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

$importe         = monto_to_spe($importeTotal);
$importeGravado  = monto_to_spe($importeGrav);
$consumidorFinal = consumidor_final_flag($esCF);

$params = [
  'idBanco'        => $idBanco,
  'idTransaccion'  => $idTransaccion,
  'idOrganismo'    => ID_ORGANISMO,
  'tipoServicio'   => TIPO_SERVICIO,
  'idCuenta'       => $idCuenta,
  'idFactura'      => $idFactura,
  'importe'        => $importe,
  'moneda'         => $moneda,
  'importeGravado' => $importeGravado,
  'consumidorFinal'=> $consumidorFinal,
];

try {
  $firma = generar_firma($params);
} catch (Throwable $e) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "ERROR FIRMA: " . $e->getMessage();
  exit;
}

$ver = verificar_firma_local($params, $firma);
header('Content-Type: text/plain; charset=utf-8');
echo "Local verify: " . ($ver['ok'] ? "OK" : "FALLA") . " - " . $ver['msg'] . "\n\n";
echo "stringToSign:\n" . spe_string_to_sign($params) . "\n\n";
echo "firmaBase64:\n" . $firma . "\n\n";
echo "payload_post:\n";
print_r($params + ['urlVuelta' => 'https://www.datosdynamica.net/sistarbanc/retorno.php', 'firma' => $firma]);
