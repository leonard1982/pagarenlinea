<?php
// /var/www/pagarenlinea/config.php

// ====== AJUSTES GENERALES ======
date_default_timezone_set('America/Montevideo');
mb_internal_encoding('UTF-8');

// ====== ENTORNO ======
const APP_ENV = 'PRODUCCION'; // Cambiar a 'PRODUCCION' cuando corresponda. DESARROLLO

const ENVIRONMENT_CONFIG = [
    'DESARROLLO' => [
        'sistarbanc' => [
            'spe_url'     => 'https://spftest.sistarbanc.com.uy/spfe/servlet/PagoEmpresa',
            'url_retorno' => 'https://www.datosdynamica.net/pagarenlinea/retorno.php',
        ],
        'db' => [
            'host' => '127.0.0.1',
            'user' => 'dynamica',
            'pass' => '.Uruguay2020',
            'name' => 'centrode_dynamica',
        ],
        'id_empresa' => 1,
        'log_dir'    => __DIR__ . '/log',
        'id_sucursal'=> 2,
    ],
    'PRODUCCION' => [
        'sistarbanc' => [
            'spe_url'     => 'https://spf.sistarbanc.com.uy/spfe/servlet/PagoEmpresa',
            'url_retorno' => 'https://www.datosdynamica.net/pagarenlinea/retorno.php',
        ],
        'db' => [
            'host' => '127.0.0.1',
            'user' => 'dynamica',
            'pass' => '.Uruguay2020',
            'name' => 'centrode_dynamica',
        ],
        'id_empresa' => 397,
        'log_dir'    => __DIR__ . '/log',
        'id_sucursal'=> 418,
    ],
];

if (!array_key_exists(APP_ENV, ENVIRONMENT_CONFIG)) {
    throw new RuntimeException('APP_ENV inválido: ' . APP_ENV);
}

$envConfig = ENVIRONMENT_CONFIG[APP_ENV];

define('SPE_URL_TEST', $envConfig['sistarbanc']['spe_url']);
define('SPE_URL_RETORNO', $envConfig['sistarbanc']['url_retorno']);
define('ID_EMPRESA_FIJO', $envConfig['id_empresa']);
define('DB_HOST', $envConfig['db']['host']);
define('DB_USER', $envConfig['db']['user']);
define('DB_PASS', $envConfig['db']['pass']);
define('DB_NAME', $envConfig['db']['name']);
define('APP_LOG_DIR', $envConfig['log_dir']);
define('ID_SUCURSAL_FIJO', $envConfig['id_sucursal']);

// ====== PARAMS DE INTEGRACIÓN (TEST) ======
const ID_ORGANISMO  = 'DYNAMICA';
const TIPO_SERVICIO = 'SERV';
const BANCO_BROU    = '009'; // Ajustar si usas otro

// ====== RUTAS DE CLAVES/CERTIFICADO ======
const PRIVKEY_PATH  = '/var/www/pagarenlinea/keys/privkey.pem';
const PRIVKEY_PASS  = ''; // si tu privada tiene pass, colócala aquí
const CERT_PATH     = '/var/www/pagarenlinea/keys/cert_test.pem'; // certificado X.509 del par
const PUBKEY_PATH   = '/var/www/pagarenlinea/keys/pubkey.pem';    // opcional: pública exportada

// Usar cadena CONCATENADA (sin separadores) según SPE
const SPE_JOINER = ''; // '' = sin separadores (recomendado por SPE)

define('SPE_WS_USER', 'dynamica');
define('SPE_WS_PASS', '7692a20fec92af0aa5729d796b019d27c83c9955407994630a0cdd7702ca2329'); 

// ====== LOGGING ======
// Directorio base para logs
function log_dir_base(): string {
    return APP_LOG_DIR;
}
function ensure_dir(string $dir): string {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}
// Construye ruta YYYY/MM/DD + nombre archivo
function build_log_path(string $prefix, string $idTransaccion = 'sin_id', string $ext = 'txt'): string {
    $base = log_dir_base();
    $y = date('Y'); $m = date('m'); $d = date('d');
    $dir = "$base/$y/$m/$d";
    ensure_dir($dir);
    $stamp = date('Ymd_His');
    return "$dir/{$stamp}_{$idTransaccion}_{$prefix}.{$ext}";
}
// Log TXT (legible)
function write_log_txt(string $title, array $data = [], ?string $idTransaccion = null): string {
    $file = build_log_path('debug', $idTransaccion ?: 'sin_id', 'txt');
    $lines = [];
    $lines[] = str_repeat('=', 100);
    $lines[] = $title . ' @ ' . date('c');
    $lines[] = str_repeat('-', 100);
    foreach ($data as $k => $v) {
        if (is_array($v) || is_object($v)) { $v = print_r($v, true); }
        $lines[] = sprintf('%-20s: %s', $k, (string)$v);
    }
    $lines[] = str_repeat('=', 100) . PHP_EOL;
    file_put_contents($file, implode(PHP_EOL, $lines), FILE_APPEND);
    return $file;
}
// Log JSON (higienizado/para enviar a banco)
function write_log_json(string $prefix, array $payload, ?string $idTransaccion = null): string {
    $file = build_log_path($prefix, $idTransaccion ?: 'sin_id', 'json');
    file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $file;
}
// Enmascara valores (para enviar a terceros sin exponer datos)
function mask_middle(string $s): string {
    $len = strlen($s);
    if ($len <= 4) return str_repeat('*', $len);
    return substr($s,0,2) . str_repeat('*', $len - 4) . substr($s,-2);
}

// ====== FORMATEADORES SPE ======
// “importe” e “importeGravado”: enteros decimales en texto (dos decimales sin separador)
function monto_to_spe($monto): string {
    $s = str_replace(',', '.', (string)$monto);
    if (!is_numeric($s)) throw new InvalidArgumentException('Importe inválido');
    return (string) (int) round(((float)$s) * 100, 0);
}
// consumidorFinal: "1" = persona natural (CI), "0" = empresa (RUT)
function consumidor_final_flag($esConsumidorFinal): string {
    return $esConsumidorFinal ? '1' : '0';
}

// ====== STRING A FIRMAR (ORDEN ESTRICTO) ======
function spe_string_to_sign_strict(array $params) {
    $order = [
        'idBanco','idTransaccion','idOrganismo','tipoServicio','idCuenta',
        'idFactura','importe','moneda','importeGravado','consumidorFinal'
    ];
    $vals = [];
    foreach ($order as $k) {
        if (!array_key_exists($k, $params)) {
            throw new RuntimeException("Falta parámetro obligatorio: $k");
        }
        $v = trim((string)$params[$k]);

        // Seguridad: sin CR/LF y sin pipes (por las dudas)
        if (preg_match('/[\r\n]/', $v))  { throw new RuntimeException("Valor inválido (CR/LF) en $k"); }
        if (strpos($v, '|') !== false)   { throw new RuntimeException("Valor inválido (|) en $k"); }

        $vals[] = $v;
    }
    // IMPORTANTE: concatenar SIN separadores (SPE_JOINER = '')
    return implode(SPE_JOINER, $vals);
}

// ====== FIRMA RSA-SHA256 (texto crudo) ======
function generar_firma(array $params): string {
    $stringToSign = spe_string_to_sign_strict($params);

    $priv = @openssl_pkey_get_private('file://' . PRIVKEY_PATH, PRIVKEY_PASS);
    if (!$priv) throw new RuntimeException('No se pudo cargar la clave privada.');

    $ok = openssl_sign($stringToSign, $signature, $priv, OPENSSL_ALGO_SHA256);
    openssl_free_key($priv);
    if (!$ok) throw new RuntimeException('openssl_sign falló.');

    $firmaBase64 = rtrim(str_replace(["\r","\n"], '', base64_encode($signature)));

    // Log de depuración
    write_log_txt('DEBUG FIRMA', [
        'stringToSign' => $stringToSign,
        'firmaBase64'  => $firmaBase64,
        'hex'          => bin2hex($stringToSign),
    ], $params['idTransaccion'] ?? 'sin_id');

    return $firmaBase64;
}

// ====== VERIFICACIÓN LOCAL CON CERT/PUBKEY ======
function spe_verificar_firma(array $params, string $firmaBase64, string $pemPath = CERT_PATH): array {
    $stringToSign = spe_string_to_sign_strict($params);

    $pem = @file_get_contents($pemPath);
    if ($pem === false) return ['ok'=>false,'msg'=>"No se pudo leer PEM: $pemPath",'stringToSign'=>$stringToSign];

    $pub = openssl_pkey_get_public($pem);
    if (!$pub) return ['ok'=>false,'msg'=>'PEM inválido','stringToSign'=>$stringToSign];

    $sig = base64_decode($firmaBase64, true);
    if ($sig === false) {
        openssl_free_key($pub);
        return ['ok'=>false,'msg'=>'firma base64 inválida','stringToSign'=>$stringToSign];
    }

    $vr = openssl_verify($stringToSign, $sig, $pub, OPENSSL_ALGO_SHA256);
    openssl_free_key($pub);

    if ($vr === 1) return ['ok'=>true ,'msg'=>'OK','stringToSign'=>$stringToSign];
    if ($vr === 0) return ['ok'=>false,'msg'=>'NO coincide','stringToSign'=>$stringToSign];
    return               ['ok'=>false,'msg'=>'Error OpenSSL','stringToSign'=>$stringToSign];
}

// ====== DIAGNÓSTICO DE PAR CLAVE/CERT ======
function keypair_diagnostics(): array {
    $out = [
        'privkey_path' => PRIVKEY_PATH,
        'cert_path'     => CERT_PATH,
        'pubkey_path'   => PUBKEY_PATH,
        'pair_match'    => null,
        'md5_modulus'   => ['priv'=>null,'cert'=>null,'pub'=>null],
    ];
    // Privada
    $privPem = @file_get_contents(PRIVKEY_PATH);
    if ($privPem) {
        $priv = openssl_pkey_get_private($privPem, PRIVKEY_PASS);
        if ($priv) {
            $det = openssl_pkey_get_details($priv);
            if ($det && isset($det['rsa']['n'])) {
                $out['md5_modulus']['priv'] = md5($det['rsa']['n']);
            }
            openssl_free_key($priv);
        }
    }
    // Cert -> pública
    $certPem = @file_get_contents(CERT_PATH);
    if ($certPem) {
        $cert = openssl_x509_read($certPem);
        if ($cert) {
            $pub = openssl_pkey_get_public($certPem);
            if ($pub) {
                $det = openssl_pkey_get_details($pub);
                if ($det && isset($det['rsa']['n'])) {
                    $out['md5_modulus']['cert'] = md5($det['rsa']['n']);
                }
                openssl_free_key($pub);
            }
            openssl_x509_free($cert);
        }
    }
    // pubkey.pem (opcional)
    $pubPem = @file_get_contents(PUBKEY_PATH);
    if ($pubPem) {
        $pub = openssl_pkey_get_public($pubPem);
        if ($pub) {
            $det = openssl_pkey_get_details($pub);
            if ($det && isset($det['rsa']['n'])) {
                $out['md5_modulus']['pub'] = md5($det['rsa']['n']);
            }
            openssl_free_key($pub);
        }
    }

    $p = $out['md5_modulus']['priv'];
    $c = $out['md5_modulus']['cert'];
    $out['pair_match'] = ($p && $c && $p === $c);
    return $out;
}

// ====== BUNDLE JSON COMPLETO PARA ENVIAR AL BANCO ======
function save_debug_bundle(array $params, string $firmaBase64, ?array $localVerify, string $speUrl, string $urlVuelta): string {
    // Clonar y enmascarar payload para terceros
    $maskParams = $params;
    if (isset($maskParams['idCuenta']))  $maskParams['idCuenta']  = mask_middle($maskParams['idCuenta']);
    if (isset($maskParams['idFactura'])) $maskParams['idFactura'] = mask_middle($maskParams['idFactura']);

    $bundle = [
        'timestamp'     => date('c'),
        'environment'   => [
            'php_version' => PHP_VERSION,
            'server'      => php_uname(),
        ],
        'keypair'       => keypair_diagnostics(),
        'spe'           => [
            'endpoint'   => $speUrl,
            'urlVuelta'  => $urlVuelta,
        ],
        'stringToSign'  => spe_string_to_sign_strict($params),
        'payload_post'  => $maskParams + ['firma' => mask_middle($firmaBase64)],
        'local_verify'  => $localVerify,
        'notes'         => [
            'importe_formato'        => 'dos decimales sin separador (centavos)',
            'orden_campos_string'    => 'idBanco|idTransaccion|idOrganismo|tipoServicio|idCuenta|idFactura|importe|moneda|importeGravado|consumidorFinal',
            'firma_algoritmo'        => 'RSA-SHA256 sobre texto crudo + Base64',
        ],
    ];

    $file = write_log_json('bundle', $bundle, $params['idTransaccion'] ?? 'sin_id');
    return $file;
}

// ===== Funciones SPE =====
// Requiere tu clase dbMysql desde baseDeDatos.php (ya usada en tu ejemplo)
/*
if (!function_exists('buscarTransaccionPorId')) {
    function buscarTransaccionPorId(string $idTransaccion) : ?array {
        try {
            // Conexión (mismas credenciales que tu ejemplo)
            require_once __DIR__ . '/baseDeDatos.php';
            $db = new dbMysql($vhost, $vuser, $vpass, $vbd);

            // Escapar valor (modo simple; ajusta a tu wrapper si tienes método específico)
            $idTx = trim($idTransaccion);
            $idTx = str_replace(["\0", "\r", "\n"], '', $idTx);

            // Trae la venta por idTransaccion y calcula idCuenta (cf del cliente) si está disponible
            $sql = "
                SELECT
                    v.IdVentas,
                    v.Serie,
                    v.Numero,
                    v.Moneda,
                    v.TotMntAPagar,
                    v.Saldo,
                    v.IdCliente,
                    v.IdCliente as IdCuenta,
                    v.IdEmpresa,
                    v.idTransaccion,
                    (SELECT c.cf
                       FROM Clientes c
                      WHERE c.idcliente = v.IdCliente
                        AND c.IdEmpresa  = v.IdEmpresa
                      LIMIT 1) AS idCuenta
                FROM Ventas v
                WHERE v.idTransaccion = '".$idTx."'
                LIMIT 1";

            $rs = $db->consulta($sql);
            if ($rs && ($re = mysqli_fetch_assoc($rs))) {
                // Estructura compatible con el WS que te dejé
                return [
                    'id'            => (int)$re['IdVentas'],
                    'idCuenta'      => $re['idCuenta'] ?: null,                     // puede ser null si no aplica
                    'factura'       => trim(($re['Serie'] ?? '').'-'.($re['Numero'] ?? '')),
                    'importe'       => (string)($re['Saldo'] ?? ''),                 // lo usual es cobrar el saldo
                    'importe_total' => (string)($re['TotMntAPagar'] ?? ''),          // por si quieres comparar contra total
                    'moneda'        => (string)($re['Moneda'] ?? ''),
                    'idCliente'     => (int)$re['IdCliente'],
                    'idEmpresa'     => (int)$re['IdEmpresa'],
                    'idTransaccion' => (string)($re['idTransaccion'] ?? ''),
                ];
            }

            return null;
        } catch (Throwable $e) {
            // Log y null para que el caller decida
            if (function_exists('safe_log')) {
                safe_log('buscarTransaccionPorId EXCEPTION', ['err'=>$e->getMessage(), 'idTransaccion'=>$idTransaccion], $idTransaccion);
            }
            return null;
        }
    }
}
*/

if (!function_exists('buscarTransaccionPorId')) {
    function buscarTransaccionPorId(string $idTransaccion) : ?array {
        try {
            require_once __DIR__ . '/baseDeDatos.php';
            $db = new dbMysql($vhost, $vuser, $vpass, $vbd);

            $idTx = trim($idTransaccion);
            $idTx = str_replace(["\0", "\r", "\n"], '', $idTx);
            $idTxEsc = $db->escape($idTx); // ✅ ESCAPE

            // Traer TODAS las ventas que comparten el mismo idTransaccion
            $sql = "
                SELECT
                    v.IdVentas,
                    v.Serie,
                    v.Numero,
                    v.Moneda,
                    v.TotMntAPagar,
                    v.Saldo,
                    v.IdCliente,
                    v.IdEmpresa,
                    v.idTransaccion,
                    (SELECT c.Documento
                       FROM Clientes c
                      WHERE c.idcliente = v.IdCliente
                        AND c.IdEmpresa  = v.IdEmpresa
                      LIMIT 1) AS IdCuenta,
                    (SELECT c.cf
                       FROM Clientes c
                      WHERE c.idcliente = v.IdCliente
                        AND c.IdEmpresa  = v.IdEmpresa
                      LIMIT 1) AS cf,
                    v.EstadoSistarbanc
                FROM Ventas v
                WHERE v.idTransaccion = '".$idTxEsc."'
            ";

            $rs = $db->consulta($sql);
            if (!$rs) {
                if (function_exists('safe_log')) {
                    safe_log('buscarTransaccionPorId SIN_RESULTADOS', ['idTransaccion'=>$idTransaccion], $idTransaccion);
                }
                return null;
            }

            $items = [];
            $moneda = null; $idCliente = null; $idEmpresa = null; $idCuenta = null;
            $sumSaldo = 0.0;
            $sumTotMntAPagar = 0.0;
            $estadoSistarbanc = null;

            while ($re = mysqli_fetch_assoc($rs))
            {
                //echo "Saldo fila: ".$re['Saldo']."\n"; // DEBUG

                if((float)($re['Saldo'] ?? 0) <= 0)
                {
                    $vsql = "select ri.Saldo from Recibos r inner join RecibosItems ri on ri.IdRecibo=r.IdRecibo
where ri.IdVentas = '".$re['IdVentas']."' and r.idTransaccion = '".$idTxEsc."'";

                    $rs2 = $db->consulta($vsql);
                    if ($re2 = mysqli_fetch_assoc($rs2))
                    {   
                        $re['Saldo'] = $re2['Saldo'];
                    }
                }

                $sumSaldo        += (float)($re['Saldo'] ?? 0);
                $sumTotMntAPagar += (float)($re['TotMntAPagar'] ?? 0);

                $estadoActual = trim((string)($re['EstadoSistarbanc'] ?? ''));
                $estadoActualNorm = strtoupper($estadoActual);
                if ($estadoActualNorm === 'PAGADA') {
                    $estadoSistarbanc = $estadoActual;
                } elseif ($estadoSistarbanc === null) {
                    $estadoSistarbanc = $estadoActual;
                }

                $monedaActual    = (string)($re['Moneda'] ?? '');
                $idClienteActual = (int)($re['IdCliente'] ?? 0);
                $idEmpresaActual = (int)($re['IdEmpresa'] ?? 0);
                $idCuentaActual  = $re['IdCuenta'] ?? null;

                if ($moneda   === null) { $moneda   = $monedaActual; }
                if ($idCliente=== null) { $idCliente= $idClienteActual; }
                if ($idEmpresa=== null) { $idEmpresa= $idEmpresaActual; }
                if ($idCuenta === null) { $idCuenta = $idCuentaActual; }

                if ($moneda !== $monedaActual || $idCliente !== $idClienteActual || $idEmpresa !== $idEmpresaActual) {
                    if (function_exists('safe_log')) {
                        safe_log('buscarTransaccionPorId INCOHERENCIA',
                            ['row'=>$re, 'moneda_ref'=>$moneda, 'idCliente_ref'=>$idCliente, 'idEmpresa_ref'=>$idEmpresa],
                            $idTransaccion
                        );
                    }
                    return null;
                }

                $items[] = [
                    'id'     => (int)$re['IdVentas'],
                    'serie'  => (string)($re['Serie'] ?? ''),
                    'numero' => (string)($re['Numero'] ?? ''),
                    'saldo'  => (string)($re['Saldo'] ?? '0'),
                ];
            }

            if (empty($items)) return null;

            $idPrincipal = count($items) === 1 ? $items[0]['id'] : 0;
            $facturasStr = implode(', ', array_map(function($it){
                $s = trim($it['serie'] ?? ''); $n = trim($it['numero'] ?? '');
                return ($s !== '' ? $s.'-' : '').$n;
            }, $items));

            return [
                'id'            => (int)$idPrincipal,               // 0 si hay múltiples
                'idVenta'       => array_column($items, 'id'),
                'factura'       => $facturasStr,                    // informativo
                'importe'       => (string)$sumSaldo,               // suma de SALDO (lo que cobras)
                'importe_total' => (string)$sumTotMntAPagar,        // suma de TotMntAPagar (informativo)
                'moneda'        => (string)$moneda,
                'idCliente'     => (int)$idCliente,
                'idEmpresa'     => (int)$idEmpresa,
                'idTransaccion' => (string)$idTx,
                'idCuenta'      => $idCuenta ?: null,
                'estadoSistarbanc' => (string)($estadoSistarbanc ?? ''),
                'items'         => $items,
            ];
        } catch (Throwable $e) {
            if (function_exists('safe_log')) {
                safe_log('buscarTransaccionPorId EXCEPTION', ['err'=>$e->getMessage(), 'idTransaccion'=>$idTransaccion], $idTransaccion);
            }
            return null;
        }
    }
}

if (!function_exists('buscarTransaccionPorIdFactura')) {
    /**
     * Busca una transacción por el identificador de factura (Serie + Numero concatenados).
     * Retorna solo: idVenta, importe (Saldo), moneda, idTransaccion, idCliente, cf
     */
    function buscarTransaccionPorIdFactura(string $idFactura): ?array {
        try {
            require_once __DIR__ . '/baseDeDatos.php';
            $db = new dbMysql($vhost, $vuser, $vpass, $vbd);

            // Limpieza y escape seguro
            $idFac = trim($idFactura);
            $idFac = str_replace(["\0", "\r", "\n"], '', $idFac);
            $idFacEsc = $db->escape($idFac);

            // Buscar la factura por Serie + Numero concatenados
            $sql = "
                SELECT
                    v.IdVentas,
                    v.Saldo        AS importe,
                    v.Moneda,
                    v.idTransaccion,
                    v.IdCliente,
                    (SELECT c.cf
                       FROM Clientes c
                      WHERE c.idcliente = v.IdCliente
                        AND c.IdEmpresa  = v.IdEmpresa
                      LIMIT 1) AS cf
                FROM Ventas v
                WHERE CONCAT(IFNULL(v.Serie,''), IFNULL(v.Numero,'')) = '".$idFacEsc."'
                ORDER BY v.IdVentas DESC
                LIMIT 1
            ";

            $rs = $db->consulta($sql);

            if (!$rs || mysqli_num_rows($rs) === 0) {
                if (function_exists('safe_log')) {
                    safe_log('buscarTransaccionPorIdFactura SIN_RESULTADOS', ['idFactura' => $idFactura], $idFactura);
                }
                return null;
            }

            $re = mysqli_fetch_assoc($rs);

            return [
                'idVenta'       => (int)($re['IdVentas'] ?? 0),
                'importe'       => (string)($re['importe'] ?? '0'),
                'moneda'        => (string)($re['Moneda'] ?? ''),
                'idTransaccion' => (string)($re['idTransaccion'] ?? ''),
                'idCliente'     => (int)($re['IdCliente'] ?? 0),
                'cf'            => (string)($re['cf'] ?? ''),
            ];

        } catch (Throwable $e) {
            if (function_exists('safe_log')) {
                safe_log('buscarTransaccionPorIdFactura EXCEPTION', [
                    'err' => $e->getMessage(),
                    'idFactura' => $idFactura
                ], $idFactura);
            }
            return null;
        }
    }
}



if (!function_exists('marcarTransaccionPagada')) {
    function marcarTransaccionPagada(int $idVenta, array $data = []) : bool {
        // STUB: aquí luego actualizarás Ventas/Recibos/Kardex/etc.
        if (function_exists('safe_log')) {
            $txId = $data['idTransaccion'] ?? ($data['id_tx'] ?? 'sin_id');
            safe_log('TX PAGADA (stub)', ['idVenta'=>$idVenta, 'data'=>$data], $txId);
        }
        return true;
    }
}

if (!function_exists('marcarTransaccionRechazada')) {
    function marcarTransaccionRechazada(int $idVenta, array $data = []) : bool {
        // STUB: aquí luego marcarás estado, motivo, etc.
        if (function_exists('safe_log')) {
            $txId = $data['idTransaccion'] ?? ($data['id_tx'] ?? 'sin_id');
            safe_log('TX RECHAZADA (stub)', ['idVenta'=>$idVenta, 'data'=>$data], $txId);
        }
        return true;
    }
}

?>
