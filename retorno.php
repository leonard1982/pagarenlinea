<?php
// /var/www/pagarenlinea/retorno.php
require __DIR__ . '/config.php';

// Polyfill getallheaders (nginx/CGI)
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_') === 0) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }
}

// Log tolerante
if (!function_exists('safe_log')) {
    function safe_log($title, $data = [], $idTransaccion = 'sin_id') {
        try {
            if (function_exists('write_log_txt')) write_log_txt($title,$data,$idTransaccion);
            elseif (function_exists('write_log')) write_log($title,$data,$idTransaccion);
            else error_log($title.' ['.$idTransaccion.']: '.print_r($data,true));
        } catch (Throwable $e) { error_log('safe_log FAILED: '.$e->getMessage()); }
    }
}

// Captura request
$idTransaccion =
    isset($_POST['idTransaccion']) ? (string)$_POST['idTransaccion'] :
    (isset($_GET['idTransaccion']) ? (string)$_GET['idTransaccion'] : '');

$idTransaccion_sanit = preg_replace('/[^A-Za-z0-9_\-]/', '', $idTransaccion) ?: 'sin_id';

$payload = [
    '_METHOD'     => $_SERVER['REQUEST_METHOD'] ?? '',
    '_URI'        => $_SERVER['REQUEST_URI'] ?? '',
    '_REMOTE_IP'  => $_SERVER['REMOTE_ADDR'] ?? '',
    '_HEADERS'    => getallheaders() ?: [],
    'POST'        => $_POST,
    'GET'         => $_GET,
    'RAW_INPUT'   => @file_get_contents('php://input'),
];

// Endpoint simple para polling desde el navegador
if (isset($_GET['poll'])) {
    $txPoll = null;
    if ($idTransaccion) {
        try {
            $txPoll = buscarTransaccionPorId($idTransaccion);
        } catch (Throwable $e) {
            safe_log('RETORNO poll EXCEPTION', ['err'=>$e->getMessage()], $idTransaccion_sanit);
        }
    }
    $estado = $txPoll['estadoSistarbanc'] ?? '';
    $estadoNorm = strtoupper(trim((string)$estado));
    $pagada = ($estadoNorm === 'PAGADA');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'     => true,
        'estado' => (string)$estado,
        'pagada' => $pagada,
    ]);
    exit;
}

safe_log('RETORNO urlVuelta (Browser → Empresa)', $payload, $idTransaccion_sanit);

// Buscar datos de la transacción para mostrar al usuario
$tx = null;
try 
{
    if ($idTransaccion)
    {
        // Usa tu propia función/consulta. Mantengo el nombre genérico.
        $tx = buscarTransaccionPorId($idTransaccion);
    }
}
catch (Throwable $e)
{
    safe_log('RETORNO lookup EXCEPTION', ['err'=>$e->getMessage()], $idTransaccion_sanit);
}

// Datos seguros para la vista
$factura   = htmlspecialchars($tx['factura']  ?? '', ENT_QUOTES, 'UTF-8');
$importe   = htmlspecialchars($tx['importe']  ?? '', ENT_QUOTES, 'UTF-8');
$moneda    = htmlspecialchars($tx['moneda']   ?? '', ENT_QUOTES, 'UTF-8');
$cuenta    = htmlspecialchars($tx['idCuenta'] ?? '', ENT_QUOTES, 'UTF-8');

?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Validando tu pago…</title>
<style>
  :root { --ok:#16a34a; --fg:#0f172a; --muted:#64748b; --bg:#f8fafc; --card:#ffffff; }
  *{box-sizing:border-box} body{margin:0;background:var(--bg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
  .wrap{min-height:100dvh;display:grid;place-items:center;padding:24px}
  .card{width:min(720px,92vw);background:var(--card);border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 10px 30px rgba(2,6,23,.06);padding:28px}
  .card.paid{border-color:#22c55e;box-shadow:0 16px 40px rgba(22,163,74,.25), 0 0 0 2px rgba(34,197,94,.15)}
  .paid-title{color:#15803d}
  .paid-text{color:#166534}
  .paid-badge{background:#dcfce7;border-color:#4ade80;color:#166534;font-size:13px;letter-spacing:.3px;text-transform:uppercase}
  .head{display:flex;gap:12px;align-items:center;margin-bottom:12px}
  .badge{background:#ecfdf5;color:var(--ok);border:1px solid #86efac;padding:4px 10px;border-radius:999px;font-weight:600;font-size:12px}
  h1{margin:0;font-size:22px;color:var(--fg)}
  p{margin:.5rem 0;color:var(--muted)}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px}
  .item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px}
  .label{font-size:12px;color:#475569;margin:0 0 4px}
  .val{font-size:16px;color:#0f172a;margin:0;font-weight:600}
  .footer{margin-top:18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between}
  .btn{appearance:none;border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:10px 14px;color:#0f172a;text-decoration:none;font-weight:600}
  .hint{font-size:13px;color:#475569}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="head">
      <span class="badge" id="estadoBadge">En validación</span>
      <h1 id="estadoTitulo">Estamos confirmando tu pago con el banco</h1>
    </div>
    <p id="estadoTexto">Tu operación fue registrada correctamente. En unos instantes validaremos el resultado con la entidad financiera. <b>Puedes cerrar esta ventana;</b> te notificaremos apenas se confirme.</p>
    <div class="grid">
      <div class="item"><p class="label">ID de Transacción</p><p class="val"><?= htmlspecialchars($idTransaccion ?: 'No disponible', ENT_QUOTES, 'UTF-8') ?></p></div>
      <div class="item"><p class="label">Cuenta/Cliente</p><p class="val"><?= $cuenta ?: '—' ?></p></div>
      <div class="item"><p class="label">Factura</p><p class="val"><?= $factura ?: '—' ?></p></div>
      <div class="item"><p class="label">Importe</p><p class="val"><?= ($moneda ? $moneda.' ' : '') . (number_format($importe) ?: '—') ?></p></div>
    </div>
    <div class="footer">
      <!--<a class="btn" href="javascript:window.close()">Cerrar ventana</a>
      <p class="hint">También te llegará confirmación por correo/WhatsApp una vez aprobada.</p>-->
    </div>
  </div>
</div>
<script>
(() => {
  const idTransaccion = <?= json_encode($idTransaccion ?: "") ?>;
  if (!idTransaccion) return;

  const badge = document.getElementById("estadoBadge");
  const titulo = document.getElementById("estadoTitulo");
  const texto = document.getElementById("estadoTexto");
  const card = document.querySelector(".card");

  const poll = async () => {
    try {
      const url = `${window.location.pathname}?idTransaccion=${encodeURIComponent(idTransaccion)}&poll=1`;
      const res = await fetch(url, { cache: "no-store" });
      if (!res.ok) return;
      const data = await res.json();
      const estado = (data && data.estado) ? String(data.estado) : "";
      const pagada = !!(data && data.pagada);

      if (pagada) {
        badge.textContent = "Pago confirmado";
        badge.classList.add("paid-badge");
        if (card) card.classList.add("paid");
        titulo.classList.add("paid-title");
        texto.classList.add("paid-text");
        titulo.textContent = "Tu pago fue confirmado";
        texto.textContent = "La transaccion quedo registrada como PAGADA. Puedes cerrar esta ventana.";
        clearInterval(timer);
      } else if (estado) {
        badge.textContent = `Estado: ${estado}`;
      }
    } catch (_) {
      // silenciar errores de red
    }
  };

  const timer = setInterval(poll, 2500);
  poll();
})();
</script>
</body>
</html>
