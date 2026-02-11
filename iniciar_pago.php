<?php
// /var/www/pagarenlinea/iniciar_pago.php

require __DIR__ . '/config.php';
require_once __DIR__ . '/baseDeDatos.php';

// ===== Helpers =====
function b64url_decode($s) {
  $s = strtr($s, '-_', '+/');
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4 - $pad);
  return base64_decode($s, true);
}

function pick_first_nonempty($arr, $fallback='0') {
  if (!is_array($arr)) $arr = [$arr];
  foreach ($arr as $v) {
    $v = trim((string)$v);
    if ($v !== '') return $v;
  }
  return $fallback;
}

function sum_numbers($arr) {
  if (!is_array($arr)) $arr = [$arr];
  $sum = 0.0;
  foreach ($arr as $v) {
    $v = str_replace(',', '.', trim((string)$v));
    if ($v === '') continue;
    if (!is_numeric($v)) continue;
    $sum += (float)$v;
  }
  return $sum;
}

function normalize_moneda($arr, $fallback='UYU') {
  $m = strtoupper(substr(pick_first_nonempty($arr, $fallback), 0, 3));
  return preg_match('/^[A-Z]{3}$/', $m) ? $m : $fallback;
}

function join_facturas($arr) {
  if (!is_array($arr)) $arr = [$arr];
  $norm = [];
  foreach ($arr as $v) {
    $v = strtoupper(trim((string)$v));
    if ($v === '') continue;
    $v = preg_replace('/[^A-Z0-9\-]/', '', $v);
    if ($v !== '') $norm[] = $v;
  }
  if (empty($norm)) return 'FAC-TEST-001';
  $joined = implode('-', $norm);
  return substr($joined, 0, 50);
}

// ===== Unicidad de idTransaccion =====
function generarIdTransaccion(): string {
  return date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function reservarIdTransaccionUnico(dbMysql $db, int $maxIntentos = 5): string {
  for ($i = 0; $i < $maxIntentos; $i++) {
    $id = generarIdTransaccion();
    $idEsc = method_exists($db, 'escape') ? $db->escape($id) : addslashes($id);
    $sql = "INSERT INTO TransaccionesSistarbanc (idTransaccion) VALUES ('{$idEsc}')";
    $ok = $db->consulta($sql);
    if ($ok) return $id;
  }
  throw new RuntimeException('No se pudo generar un idTransaccion único tras varios intentos.');
}

/**
 * Obtener o crear un idTransaccion amarrado a las ventas.
 */
function obtenerOcrearIdTransaccionParaVentas(dbMysql $db, array $idVentasArray): string {
  $ids = array_values(
    array_filter(
      array_map(
        function($v) { return (int)trim((string)$v); },
        (array)$idVentasArray
      ),
      function($v) { return $v > 0; }
    )
  );

  if (empty($ids)) {
    return reservarIdTransaccionUnico($db);
  }

  $inList = implode(',', $ids);

  $sqlSel = "SELECT idTransaccion
             FROM Ventas
             WHERE IdVentas IN ($inList)
               AND idTransaccion IS NOT NULL
               AND idTransaccion <> ''
             LIMIT 1";
  $resSel = $db->consulta($sqlSel);

  if ($resSel && method_exists($resSel, 'num_rows') && $resSel->num_rows > 0) {
    $row = $resSel->fetch_assoc();
    return (string)$row['idTransaccion'];
  }

  $nuevoId = reservarIdTransaccionUnico($db);

  $idEsc = method_exists($db, 'escape') ? $db->escape($nuevoId) : addslashes($nuevoId);
  $sqlUpd = "UPDATE Ventas
             SET idTransaccion = '{$idEsc}'
             WHERE IdVentas IN ($inList)";
  $db->consulta($sqlUpd);

  return $nuevoId;
}

// =====================================================
// 1) Entrada unificada (dat o GET) dejando $src CONSOLIDADO
// =====================================================
$dat = $_GET['dat'] ?? null;
$src = [];

// ------------------------------
// 1A) Vía JSON base64url (dat)
// ------------------------------
if ($dat !== null) {
  $json = b64url_decode($dat);
  $obj  = $json !== false ? json_decode($json, true) : null;

  if (is_array($obj)) {

    // Arrays que pueden venir múltiples
    $raw_idventas = $obj['idventas'] ?? [];
    $raw_fact     = $obj['fact']     ?? [];
    $raw_total    = $obj['total']    ?? [];
    $raw_gravado  = $obj['gravado']  ?? [];

    // Escalares
    $raw_idcuenta  = $obj['idcuenta']  ?? '';
    $raw_moneda    = $obj['moneda']    ?? '';
    $raw_cf        = $obj['cf']        ?? '1';
    $raw_fechavenc = $obj['fechavenc'] ?? '';
    $raw_idbanco   = $obj['idBanco']   ?? ''; // ✅ VIENE DEL FRONTEND
    $raw_debug_in  = $obj['debug'] ?? ($_GET['debug'] ?? '0');
    $raw_debug     = $raw_debug_in;

    $src = [
      'idventas'      => $raw_idventas,
      'idcuenta'      => pick_first_nonempty($raw_idcuenta, 'CLI-TEST-001'),
      'idFactura'     => join_facturas($raw_fact),
      'importeTotal'  => number_format(sum_numbers($raw_total),   2, '.', ''),
      'importeGrav'   => number_format(sum_numbers($raw_gravado), 2, '.', ''),
      'moneda'        => normalize_moneda($raw_moneda, 'UYU'),
      'esCF'          => (pick_first_nonempty($raw_cf, '1') === '1'),
      'fechaVenc'     => pick_first_nonempty($raw_fechavenc, ''),
      'debug'         => ($raw_debug === '1'),
      'idBanco'       => pick_first_nonempty($raw_idbanco, ''), // ✅ CONSOLIDADO
    ];

  } else {
    $dat = null;
  }
}

// ------------------------------
// 1B) Fallback vía GET directo
// ------------------------------
if ($dat === null) {

  $raw_idventas = $_GET['idventas'] ?? [];
  $raw_fact     = $_GET['fact']     ?? 'FAC-TEST-001';
  $raw_total    = $_GET['total']    ?? '988.00';
  $raw_gravado  = $_GET['gravado']  ?? '400.00';

  $raw_idcuenta  = $_GET['id']        ?? 'CLI-TEST-001';
  $raw_moneda    = $_GET['moneda']    ?? 'UYU';
  $raw_cf        = $_GET['cf']        ?? '1';
  $raw_fechavenc = $_GET['fechavenc'] ?? '';
  $raw_idbanco   = $_GET['idBanco']   ?? ''; // ✅ SOPORTE GET DIRECTO

  $debug_flag_in = (($_GET['debug'] ?? '0') === '1') ? '1' : '0';
  $raw_debug     = $debug_flag_in;

  $src = [
    'idventas'      => $raw_idventas,
    'idcuenta'      => pick_first_nonempty($raw_idcuenta, 'CLI-TEST-001'),
    'idFactura'     => join_facturas($raw_fact),
    'importeTotal'  => number_format(sum_numbers($raw_total),   2, '.', ''),
    'importeGrav'   => number_format(sum_numbers($raw_gravado), 2, '.', ''),
    'moneda'        => normalize_moneda($raw_moneda, 'UYU'),
    'esCF'          => (pick_first_nonempty($raw_cf, '1') === '1'),
    'fechaVenc'     => pick_first_nonempty($raw_fechavenc, ''),
    'debug'         => ($raw_debug === '1'),
    'idBanco'       => pick_first_nonempty($raw_idbanco, ''), // ✅ CONSOLIDADO
  ];
}

// =====================================================
// 2) “Consolidación” final: SOLO ASIGNAR DESDE $src
// =====================================================
$idVentasArray = $src['idventas'];
$idCuenta      = $src['idcuenta'];
$idFactura     = $src['idFactura'];
$importeTotal  = $src['importeTotal'];
$importeGrav   = $src['importeGrav'];
$moneda        = $src['moneda'];
$esCF          = $src['esCF'];
$fechaVencStr  = $src['fechaVenc'];
$debug         = $src['debug'];

// ✅ Banco recibido del frontend (solo para producción)
$idBancoInput = trim((string)($src['idBanco'] ?? ''));

// Normalizar entorno (por seguridad)
$appEnvNorm = strtoupper(trim((string)APP_ENV));

// En DESARROLLO SIEMPRE 009, en PRODUCCION SIEMPRE el que selecciona el usuario
if ($appEnvNorm === 'DESARROLLO') {
  $idBanco = '009';
} else {
  if ($idBancoInput === '' || !preg_match('/^\d{3}$/', $idBancoInput)) {
    http_response_code(400);
    die('idBanco inválido o no enviado');
  }
  $idBanco = $idBancoInput;
}

// ===== 2.1) DEBUG: mostrar lo que llegó por URL/JSON =====
$showInput = (($_GET['show_input'] ?? '0') === '1');
if ($showInput) {
  header('Content-Type: text/html; charset=utf-8');
  $rawDat = $_GET['dat'] ?? '';
  $jsonDecoded = $rawDat ? json_decode(b64url_decode($rawDat), true) : [];

  ?>
  <!DOCTYPE html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <title>DEBUG entrada iniciar_pago.php</title>
    <style>
      body { font-family: system-ui, Arial, sans-serif; padding: 16px; background: #111; color: #eee; }
      h1 { color: #ffd166; }
      pre { background: #222; padding: 12px; border-radius: 8px; overflow: auto; }
      h2 { color: #8ecae6; }
    </style>
  </head>
  <body>
    <h1>DEBUG – Entrada de iniciar_pago.php</h1>

    <h2>$_GET</h2>
    <pre><?= htmlspecialchars(print_r($_GET, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>

    <h2>DAT (base64 URL)</h2>
    <pre><?= htmlspecialchars($rawDat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>

    <h2>JSON decodificado desde DAT</h2>
    <pre><?= htmlspecialchars(print_r($jsonDecoded, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>

    <h2>$src (datos unificados usados por el backend)</h2>
    <pre><?= htmlspecialchars(print_r($src, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>

    <h2>Banco final</h2>
    <pre><?= htmlspecialchars($idBanco, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>

    <h2>$idVentasArray (solo IDs de venta)</h2>
    <pre><?= htmlspecialchars(print_r($idVentasArray, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>

    <p style="color:#0bf">Fin del debug. No se continúa al SPE.</p>
  </body>
  </html>
  <?php
  exit;
}

// ===== Validaciones =====
if (!is_numeric($importeTotal) || $importeTotal < 0) die('total inválido');
if (!is_numeric($importeGrav) || $importeGrav < 0) die('gravado inválido');
if (!preg_match('/^[A-Z]{3}$/', $moneda)) die('moneda inválida');

// ===== 3) Identificadores =====
$db = new dbMysql($vhost, $vuser, $vpass, $vbd);

//Aquí ya NO usamos BANCO_BROU (porque en producción debe venir del select)
// $idBanco ya quedó asignado arriba

//Aquí ya NO llamamos directo a reservarIdTransaccionUnico,
// sino que lo obtenemos/creamos amarrado a las ventas:
$idTransaccion = obtenerOcrearIdTransaccionParaVentas($db, $idVentasArray);

// ===== 4) Adaptar formatos SPE =====
$importe         = monto_to_spe($importeTotal);
$importeGravado  = monto_to_spe($importeGrav);
$consumidorFinal = consumidor_final_flag($esCF);

// ===== 5) Parámetros SPE =====
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
  'fechaVenc'      => $fechaVencStr,
];

// ===== 6) Firma =====
try {
  $firma = generar_firma($params);
} catch (Throwable $e) {
  write_log_txt(
    'ERROR FIRMA',
    [
      'exception' => $e->getMessage(),
      'params'    => $params,
      'src'       => $src,
      'idventas'  => $idVentasArray
    ],
    $idTransaccion
  );
  http_response_code(500);
  die('Error generando firma: ' . htmlspecialchars($e->getMessage()));
}

// ===== 7) URLs y payload =====
$urlVuelta   = SPE_URL_RETORNO;
$speEndpoint = SPE_URL_TEST; // en config.php ya cambia según APP_ENV

$payloadPost = $params + [
  'urlVuelta' => $urlVuelta,
  'firma'     => $firma,
];

// ===== 8) Logs =====
$localVerify = $debug ? spe_verificar_firma($params, $firma, CERT_PATH) : null;

write_log_txt(
  'INIT POST → SPE',
  [
    'APP_ENV'       => APP_ENV,
    'SPE_URL'       => $speEndpoint,
    'params'        => $params,
    'payload_post'  => $payloadPost,
    'local_verify'  => $localVerify ? ($localVerify['ok'] ? 'OK' : $localVerify['msg']) : 'N/A',
    'stringToSign'  => spe_string_to_sign_strict($params),
    'idventas'      => $idVentasArray,
    'src'           => $src,
    'via'           => ($dat !== null ? 'dat' : 'query')
  ],
  $idTransaccion
);

$bundlePath = save_debug_bundle($params, $firma, $localVerify, $speEndpoint, $urlVuelta);

// ===== 9) HTML de redirección =====
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Sistarbanc – Enviando…</title>
<style>
  body{font-family:system-ui,Arial,sans-serif;padding:24px;background:#f7f7f7;}
  .card{max-width:860px;margin:auto;border:1px solid #e5e5e5;border-radius:12px;padding:24px;background:#fff}
  .muted{color:#666;font-size:14px}
  pre{background:#0b1020;color:#d1e0ff;padding:12px;border-radius:8px;overflow:auto;}
  button{padding:10px 16px;border-radius:8px;border:0;cursor:pointer;background:#d32f2f;color:#fff}
  .row{display:flex;gap:16px;align-items:center;justify-content:space-between}
  .status-wrapper{display:flex;align-items:center;gap:18px;padding:18px;border-radius:12px;background:linear-gradient(135deg,#d32f2f,#b71c1c);color:#fff;margin-bottom:18px}
  .status-text{margin:0;line-height:1.4}
  .status-text .title{font-size:18px;font-weight:600;margin:0}
  .status-text .subtitle{font-size:14px;opacity:.85;margin:6px 0 0}
  .spinner{width:40px;height:40px;border-radius:50%;border:4px solid rgba(255,255,255,.4);border-top-color:#fff;animation:spin 1.1s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div class="card">
  <div class="status-wrapper">
    <div class="spinner" aria-hidden="true"></div>
    <div class="status-text">
      <p class="title">Estamos conectando con el servidor...</p>
      <p class="subtitle">Espere por favor. Lo redirigimos al sitio de pago en breve.</p>
    </div>
  </div>
  <div class="row">
    <h2>Redirigiendo al Servicio de Pagos Electrónicos…</h2>
    <button id="btnSubmitPay" type="button">Ir a pagar</button>
  </div>

  <?php if ($debug): ?>
    <p class="muted">Transacción: <strong><?= htmlspecialchars($idTransaccion) ?></strong></p>
  <?php endif; ?>

  <form id="speForm" method="post" action="<?= htmlspecialchars($speEndpoint) ?>">
    <?php foreach ($params as $k => $v): ?>
      <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>"/>
    <?php endforeach; ?>
    <input type="hidden" name="urlVuelta" value="<?= htmlspecialchars($urlVuelta) ?>"/>
    <input type="hidden" name="firma" value="<?= htmlspecialchars($firma) ?>"/>
    <noscript>
      <p>JavaScript está deshabilitado. Haz clic para continuar.</p>
      <button type="submit">Ir a pagar</button>
    </noscript>
  </form>

  <?php if ($debug): ?>
    <h3>Diagnóstico local</h3>
    <pre><?php
      echo "Local verify: " . ($localVerify['ok'] ? "OK" : "FALLA") . " - " . $localVerify['msg'] . "\n\n";
      echo "stringToSign:\n" . spe_string_to_sign_strict($params) . "\n\n";
      echo "params SPE:\n" . print_r($params, true) . "\n";
      echo "payload_post:\n" . print_r($payloadPost, true) . "\n";
      echo "idventas:\n" . print_r($idVentasArray, true) . "\n";
      echo "bundle JSON:\n" . htmlspecialchars($bundlePath) . "\n";
    ?></pre>
  <?php else: ?>
    <p class="muted">Si no ocurre nada en 1.5 segundos, haz clic en el botón.</p>
    <script>
      setTimeout(()=>document.getElementById('speForm').submit(),1500);
    </script>
  <?php endif; ?>
</div>

<script>
(function(){
  const btn   = document.getElementById('btnSubmitPay');
  const form  = document.getElementById('speForm');
  const idTransaccion = "<?= htmlspecialchars($idTransaccion, ENT_QUOTES) ?>";
  const idventas      = <?= json_encode(array_values((array)$idVentasArray), JSON_UNESCAPED_UNICODE) ?>;

  async function actualizarVentasAntesDePagar(){
    btn.disabled = true;
    const t = btn.textContent;
    btn.textContent = 'Guardando…';
    try{
      const res = await fetch('act_ven_idtransaccion.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({idTransaccion,idventas,overwrite:true})
      });
      const data = await res.json().catch(()=>({}));
      if(!res.ok || data.ok !== true) {
        throw new Error(data.message || 'No se pudo actualizar las ventas');
      }
      form.submit();
    }catch(err){
      alert('Error actualizando ventas: ' + err.message);
      btn.disabled = false;
      btn.textContent = t;
    }
  }

  actualizarVentasAntesDePagar();

  btn.addEventListener('click', e => {
    e.preventDefault();
    actualizarVentasAntesDePagar();
  }, {once:true});
})();
</script>
</body>
</html>
