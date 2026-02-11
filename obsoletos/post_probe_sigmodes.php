<?php
// /var/www/pagarenlinea/post_probe_sigmodes_compat.php
// Compat: sin arrow functions, sin type hints, con manejo de errores y log
require __DIR__ . '/config.php';
date_default_timezone_set('America/Montevideo');

// Muestra errores (solo testing)
ini_set('display_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function build_string_to_sign($p) {
  $order = array('idBanco','idTransaccion','idOrganismo','tipoServicio','idCuenta','idFactura','importe','moneda','importeGravado','consumidorFinal');
  $vals = array();
  foreach ($order as $k) {
    if (!array_key_exists($k, $p)) throw new RuntimeException("Falta: $k");
    $v = trim((string)$p[$k]);
    if (strpos($v,'|')!==false || preg_match('/[\r\n]/',$v)) throw new RuntimeException("Carácter inválido en $k");
    $vals[] = $v;
  }
  return implode('|', $vals);
}

function base64url($b) {
  return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
}

function sign_std($data) {
  $priv = openssl_pkey_get_private('file://' . PRIVKEY_PATH, PRIVKEY_PASS);
  if (!$priv) throw new RuntimeException('privkey no cargada');
  $ok = openssl_sign($data, $sig, $priv, OPENSSL_ALGO_SHA256);
  openssl_free_key($priv);
  if (!$ok) throw new RuntimeException('openssl_sign falló (STD)');
  return base64_encode($sig);
}

function sign_prehash($data) {
  $digest = hash('sha256', $data, true);
  $priv = openssl_pkey_get_private('file://' . PRIVKEY_PATH, PRIVKEY_PASS);
  if (!$priv) throw new RuntimeException('privkey no cargada');
  // “doble-hash”: algunas integraciones legadas lo esperan
  $ok = openssl_sign($digest, $sig, $priv, OPENSSL_ALGO_SHA256);
  openssl_free_key($priv);
  if (!$ok) throw new RuntimeException('openssl_sign falló (PREHASH)');
  return base64_encode($sig);
}

function sign_rsa_hash_encrypt($data) {
  $digest = hash('sha256', $data, true);
  $priv = openssl_pkey_get_private('file://' . PRIVKEY_PATH, PRIVKEY_PASS);
  if (!$priv) throw new RuntimeException('privkey no cargada');
  // PKCS#1 v1.5 “encrypt” del digest (no estándar, pero a veces lo usan)
  $ok = openssl_private_encrypt($digest, $sig, $priv, OPENSSL_PKCS1_PADDING);
  openssl_free_key($priv);
  if (!$ok) throw new RuntimeException('private_encrypt falló (RSA_HASH_ENCRYPT)');
  return base64_encode($sig);
}

function sign_std_base64url($data) {
  $priv = openssl_pkey_get_private('file://' . PRIVKEY_PATH, PRIVKEY_PASS);
  if (!$priv) throw new RuntimeException('privkey no cargada');
  $ok = openssl_sign($data, $sig, $priv, OPENSSL_ALGO_SHA256);
  openssl_free_key($priv);
  if (!$ok) throw new RuntimeException('openssl_sign falló (BASE64URL)');
  return base64url($sig);
}

function http_post_logged($url, $data, $tag, $idTx) {
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => http_build_query($data),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER         => true,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_TIMEOUT        => 30,
  ));
  $resp = curl_exec($ch);
  $info = curl_getinfo($ch);
  $err  = curl_error($ch);
  curl_close($ch);

  $txt = build_log_path("sigm_probe_".$tag, $idTx, 'txt');
  $lines = array();
  $lines[] = str_repeat('=', 100);
  $lines[] = "SIG-MODE PROBE [$tag] @ " . date('c');
  $lines[] = "HTTP: " . (isset($info['http_code']) ? $info['http_code'] : 'N/A');
  $lines[] = "URL : " . $url;
  $lines[] = "CURL ERROR: " . $err;
  $lines[] = "--- POST DATA ---";
  foreach ($data as $k=>$v) $lines[] = sprintf('%-18s: %s', $k, $v);
  $lines[] = "--- RESPONSE ---";
  $lines[] = (string)$resp;
  file_put_contents($txt, implode(PHP_EOL,$lines).PHP_EOL);

  $json = build_log_path("sigm_probe_".$tag, $idTx, 'json');
  file_put_contents($json, json_encode(array(
    'timestamp'=>date('c'),
    'tag'=>$tag,
    'http'=>array('code'=>isset($info['http_code'])?$info['http_code']:null,'info'=>$info,'error'=>$err),
    'request'=>$data,
    'response'=>$resp,
  ), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

  return array($txt,$json, isset($info['http_code'])?$info['http_code']:null);
}

try {
  // Parámetros “quemados” (idénticos a los del SPE test)
  $params = array(
    'idBanco'        => '009',
    'idTransaccion'  => '20251008114903141403', // fijo para reproducibilidad
    'idOrganismo'    => ID_ORGANISMO,           // 'DYNAMICA'
    'tipoServicio'   => TIPO_SERVICIO,          // 'SERV'
    'idCuenta'       => 'CLI-0001',
    'idFactura'      => 'FAC-123',
    'importe'        => '98800',
    'moneda'         => 'UYU',
    'importeGravado' => '40000',
    'consumidorFinal'=> '1',
    'urlVuelta'      => 'https://www.datosdynamica.net/pagarenlinea/retorno.php',
  );

  $speUrl = SPE_URL_TEST;
  $idTx   = $params['idTransaccion'];
  $stdStr = build_string_to_sign($params);

  $modes = array(
    array('tag'=>'STD',              'fn'=>'sign_std'),
    array('tag'=>'PREHASH',          'fn'=>'sign_prehash'),
    array('tag'=>'RSA_HASH_ENCRYPT', 'fn'=>'sign_rsa_hash_encrypt'),
    array('tag'=>'BASE64URL',        'fn'=>'sign_std_base64url'),
  );

  $out = array();
  foreach ($modes as $m) {
    $fn = $m['fn'];
    try {
      $firma = $fn($stdStr);
      $post  = $params;
      $post['firma'] = $firma;

      list($txt,$json,$code) = http_post_logged($speUrl, $post, $m['tag'], $idTx);
      $out[] = $m['tag'].": HTTP ".$code." | TXT: ".$txt." | JSON: ".$json;
    } catch (Throwable $e) {
      $txt = build_log_path("sigm_probe_".$m['tag'], $idTx, 'txt');
      file_put_contents($txt, "ERROR [".$m['tag']."]: ".$e->getMessage().PHP_EOL);
      $out[] = $m['tag'].": ERROR -> ".$txt;
    }
  }

  header('Content-Type: text/plain; charset=utf-8');
  echo "SIG-MODES PROBE (compat) done @ ".date('c')."\n\n".implode("\n",$out)."\n";

} catch (Throwable $e) {
  // Captura de error global para 500
  $txt = build_log_path("sigm_probe_FATAL", 'sin_id', 'txt');
  file_put_contents($txt, "FATAL: ".$e->getMessage()."\n".$e->getTraceAsString()."\n");
  header('Content-Type: text/plain; charset=utf-8', true, 500);
  echo "ERROR 500 capturado: ".$e->getMessage()."\nLog: ".$txt."\n";
}
?>