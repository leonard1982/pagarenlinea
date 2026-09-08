<?php
// ================================================
// ws_confirmacion.php
// Servicio SOAP (con WSDL) para confirmación de pagos SPE → DYNAMICA
// + consulta de facturas pendientes (inicio banco)
// ================================================

require __DIR__ . '/config.php';

// Seguridad opcional por IP
// $ipsPermitidas = ['200.40.xx.yy', '200.40.zz.tt'];
// if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $ipsPermitidas, true)) {
//     http_response_code(403);
//     exit('Forbidden');
// }

//validamos la seguridad del servicio web
// 1) Exigir HTTPS (TLS)
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(400);
    echo 'Se requiere HTTPS (TLS) para este servicio.';
    exit;
}

// 2) Leer usuario/clave enviados por el cliente
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW']   ?? '';

// Algunos servidores pasan el header en HTTP_AUTHORIZATION.
// Si viene ahí, lo parseamos:
if ($user === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'];
    if (stripos($auth, 'basic ') === 0) {
        $decoded = base64_decode(substr($auth, 6));
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            [$user, $pass] = explode(':', $decoded, 2);
        }
    }
}

// 3) Validar contra lo configurado
if ($user !== SPE_WS_USER || $pass !== SPE_WS_PASS) {
    header('WWW-Authenticate: Basic realm="SPEConfirmacion"');
    http_response_code(401);
    echo 'Credenciales incorrectas.';
    exit;
}
//******************************************** */

// Log RAW solo si es POST SOAP
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = file_get_contents('php://input');
    if (function_exists('write_log')) {
        write_log('CONFIRMACION ONLINE RAW (SOAP)', [
            '_REMOTE_IP' => $_SERVER['REMOTE_ADDR'] ?? '',
            'RAW'        => $raw,
        ], 'sin_id');
    }
}

// Util: comparación de importes con tolerancia
if (!function_exists('mismoImporte')) {
    function mismoImporte($a, $b, float $tol = 0.01): bool {
        return abs((float)$a - (float)$b) < $tol;
    }
}

// Util: escape seguro (usa dbMysql->escape si existe, si no, sanea básico)
if (!function_exists('esc_str_basico')) {
    function esc_str_basico(string $s): string {
        // Sanea caracteres de control y comillas más comunes
        $s = str_replace(["\0", "\r", "\n"], '', $s);
        return addslashes($s);
    }
}

function fAsignarSerieNumeroCAEVentas($IdVentas, $tablaVentas = "Ventas", $db = null)
{
    
    $Numero  = "";
    $Serie   = "";
    $estado  = 1;
    $mensaje = "Correcto";

    // =========================
    // 1) Validaciones iniciales
    // =========================
    if (empty($IdVentas))
    {
        return array(
            "estado"  => 6,
            "numero"  => $Numero,
            "serie"   => $Serie,
            "mensaje" => "Parámetros no encontrados o incorrectos"
        );
    }

    // Validar nombre de tabla
    if (!preg_match('/^[A-Za-z0-9\-_]+$/', $tablaVentas))
    {
        return array(
            "estado"  => 6,
            "numero"  => $Numero,
            "serie"   => $Serie,
            "mensaje" => "Parámetros no encontrados o incorrectos"
        );
    }

    try {

        // ===============================
        // 2) Obtener datos de la venta
        // ===============================
        if($tablaVentas == "Recibos") {
            $sql = "
                SELECT
                    r.Importe,
                    if((select c.TipoDoc from Clientes c where c.idcliente=r.IdCliente)=2,111,101) as IdTipoDoc,
                    r.NumeroRecibo,
                    r.Serie
                FROM `".$tablaVentas."` r
                WHERE r.IdRecibo = '".(int)$IdVentas."'
            ";
        } else {
            $sql = "
                SELECT
                    TotalVenta,
                    IdTipoDoc,
                    Numero,
                    Serie
                FROM `".$tablaVentas."`
                WHERE IdVentas = '".(int)$IdVentas."'
            ";
        }

        $fileName = date('dmY_His') . '_sql_1_obtener_datos_cae.txt';
        $logDir   = '/var/www/pagarenlinea/log';
        $filePath = $logDir . '/' . $fileName;
        $logData  = $sql;
        file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);
        
        $rsVenta = $db->consulta($sql);
        
        if (!$rsVenta || mysqli_num_rows($rsVenta) == 0) {
            return array(
                "estado"  => 5,
                "numero"  => $Numero,
                "serie"   => $Serie,
                "mensaje" => "Registro no encontrado en la tabla ".$tablaVentas
            );
        }

        $reVenta = mysqli_fetch_row($rsVenta);
        $TotalVenta = $reVenta[0];
        $IdTipoDoc  = $reVenta[1];
        $Numero     = $reVenta[2];
        $Serie      = $reVenta[3];

        // =====================================================
        // 3) Validar saldo cero (excepto tipos permitidos)
        // =====================================================
        if ($TotalVenta == 0 && ($IdTipoDoc != 181 && $IdTipoDoc != 124 && $IdTipoDoc != 182)) {
            return array(
                "estado"  => 2,
                "numero"  => $Numero,
                "serie"   => $Serie,
                "mensaje" => "No se puede concluir un documento con saldo cero"
            );
        }

        // ===============================
        // 4) Obtener contador
        // ===============================
        $sql = "
            SELECT Ultimo, Serie
            FROM Contadores
            WHERE IdEmpresa = " . ID_EMPRESA_FIJO . "
            AND IdTipoDoc = '".(int)$IdTipoDoc."'
            AND Sucursal = 0
        ";

        $fileName = date('dmY_His') . '_sql_2_obtener_datos_cae.txt';
        $logDir   = '/var/www/pagarenlinea/log';
        $filePath = $logDir . '/' . $fileName;
        $logData  = $sql;
        file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);
        
        $rsContador = $db->consulta($sql);
        
        if (!$rsContador || mysqli_num_rows($rsContador) == 0) {
            return array(
                "estado"  => 3,
                "numero"  => $Numero,
                "serie"   => $Serie,
                "mensaje" => "No se encontró contador configurado"
            );
        }

        $reContador = mysqli_fetch_row($rsContador);
        $Serie  = $reContador[1];
        $Numero = (int)$reContador[0] + 1;
        
        // Actualizar contador
        $sql = "
            UPDATE Contadores
            SET Ultimo = ".$Numero."
            WHERE IdEmpresa = " . ID_EMPRESA_FIJO . "
            AND IdTipoDoc = '".(int)$IdTipoDoc."'
            AND Sucursal = 0
        ";
        $db->consulta($sql);

        $fileName = date('dmY_His') . '_sql_3_obtener_datos_cae.txt';
        $logDir   = '/var/www/pagarenlinea/log';
        $filePath = $logDir . '/' . $fileName;
        $logData  = $sql;
        file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);
        
        // Actualizar registro con serie y número
        if($tablaVentas == "Recibos") {
            $sql = "
                UPDATE `".$tablaVentas."`
                SET Serie = '".$Serie."', NumeroRecibo = '".$Numero."'
                WHERE IdRecibo = '".(int)$IdVentas."'
            ";
            $db->consulta($sql);
        } else {
            $sql = "
                UPDATE `".$tablaVentas."`
                SET Serie = '".$Serie."', Numero = '".$Numero."'
                WHERE IdVentas = '".(int)$IdVentas."'
            ";
            $db->consulta($sql);
        }

        $fileName = date('dmY_His') . '_sql_4_obtener_datos_cae.txt';
        $logDir   = '/var/www/pagarenlinea/log';
        $filePath = $logDir . '/' . $fileName;
        $logData  = $sql;
        file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);

        // ==============================================
        // 5) CAE vencido: salto a NumeroFinal + 1
        // ==============================================
        $sql = "
            SELECT NumeroFinal
            FROM Cae
            WHERE IdEmpresa = " . ID_EMPRESA_FIJO . "
            AND Tipo = '".(int)$IdTipoDoc."'
            AND NumeroInicial <= ".$Numero."
            AND NumeroFinal >= ".$Numero."
            AND FechaVto <= '".date('Y-m-d')."'
            ORDER BY IdCae DESC
            LIMIT 1
        ";

        $fileName = date('dmY_His') . '_sql_5_obtener_datos_cae.txt';
        $logDir   = '/var/www/pagarenlinea/log';
        $filePath = $logDir . '/' . $fileName;
        $logData  = $sql;
        file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);
        
        $rsCaeVencido = $db->consulta($sql);
        
        if ($rsCaeVencido && mysqli_num_rows($rsCaeVencido) > 0) {
            $reCae = mysqli_fetch_row($rsCaeVencido);
            $Numero = (int)$reCae[0] + 1;
            
            // Actualizar contador nuevamente
            $sql = "
                UPDATE Contadores
                SET Ultimo = ".$Numero."
                WHERE IdEmpresa = " . ID_EMPRESA_FIJO . "
                AND IdTipoDoc = '".(int)$IdTipoDoc."'
                AND Sucursal = 0
            ";
            $db->consulta($sql);

            $fileName = date('dmY_His') . '_sql_6_obtener_datos_cae.txt';
            $logDir   = '/var/www/pagarenlinea/log';
            $filePath = $logDir . '/' . $fileName;
            $logData  = $sql;
            file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);

            // Actualizar registro con nuevo número
            if($tablaVentas == "Recibos") {
                $sql = "
                    UPDATE `".$tablaVentas."`
                    SET Serie = '".$Serie."', NumeroRecibo = '".$Numero."'
                    WHERE IdRecibo = '".(int)$IdVentas."'
                ";
                $db->consulta($sql);
            } else {
                $sql = "
                    UPDATE `".$tablaVentas."`
                    SET Serie = '".$Serie."', Numero = '".$Numero."'
                    WHERE IdVentas = '".(int)$IdVentas."'
                ";
                $db->consulta($sql);
            }

            $fileName = date('dmY_His') . '_sql_7_obtener_datos_cae.txt';
            $logDir   = '/var/www/pagarenlinea/log';
            $filePath = $logDir . '/' . $fileName;
            $logData  = $sql;
            file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);
        }

        return array(
            "estado"  => 1,
            "numero"  => $Numero,
            "serie"   => $Serie,
            "mensaje" => "Correcto"
        );

    } catch (Throwable $e) {
        return array(
            "estado"  => 6,
            "numero"  => $Numero,
            "serie"   => $Serie,
            "mensaje" => "Error: ".$e->getMessage()
        );
    }
}

// -----------------------------------------
// Clase del servicio SOAP
// -----------------------------------------
class SPEConfirmacionService
{
    // =====================================================
    // MÉTODO: CONFIRMAR PAGO (inicio empresa)
    // =====================================================
    public function confirmarPagoBanco($request)
    {
        $p = is_object($request) ? (array)$request : (array)$request;
        $idFactura = (string)($venta['idFactura'] ?? '');
        $importe   = (string)($venta['importe'] ?? 0);
        $moneda    = (string)($venta['moneda'] ?? '');
        $fechaPago = (string)($venta['fechaPago'] ?? '');

        /*
        $idTransaccion = (string)($p['idTransaccion'] ?? 'sin_id');

        // Log de entrada estructurado
        if (function_exists('write_log')) {
            write_log('CONFIRMACION ONLINE (SPE → Empresa)', [
                'params'  => $p,
                '_SERVER' => $_SERVER,
            ], $idTransaccion);
        }

        // 1) Validar existencia en BD (usa config.php)
        $venta = buscarTransaccionPorId($idTransaccion);
        if (!$venta) {
            if (function_exists('write_log')) {
                write_log('CONF-ERROR', ['motivo' => 'No existe idTransaccion'], $idTransaccion);
            }
            return ['codigoRespuesta' => '01', 'descripcion' => 'Transacción no encontrada'];
        }

        // 2) Validar datos recibidos (importe, moneda, idCuenta)
        //    - idCuenta solo se compara si ambos lados lo tienen (puede venir null de la BD


        $importeOk = mismoImporte($venta['importe'] ?? 0, $p['importe'] ?? 0);
        $monedaOk  = (string)($venta['moneda'] ?? '') === (string)($p['moneda'] ?? '');
        $idCuentaOk = true;
        $idCuentaBd = $venta['idCuenta'] ?? null;
        $idCuentaRx = $p['idCuenta'] ?? null;
        if ($idCuentaBd !== null && $idCuentaRx !== null) {
            $idCuentaOk = (string)$idCuentaBd === (string)$idCuentaRx;
        }

        if (!$importeOk || !$monedaOk || !$idCuentaOk) {
            if (function_exists('write_log')) {
                write_log('CONF-ERROR', [
                    'motivo'   => 'Datos no coinciden',
                    'esperado' => [
                        'importe'=>$venta['importe'] ?? null,
                        'moneda'=>$venta['moneda'] ?? null,
                        'idCuenta'=>$venta['idCuenta'] ?? null
                    ],
                    'recibido' => [
                        'importe'=>$p['importe'] ?? null,
                        'moneda'=>$p['moneda'] ?? null,
                        'idCuenta'=>$p['idCuenta'] ?? null
                    ],
                ], $idTransaccion);
            }
            return ['codigoRespuesta' => '01', 'descripcion' => 'Datos no coinciden'];
        }

        // Lógica de confirmación según codigoRespuesta del banco
        $codigoBanco = (string)($p['codigoRespuesta'] ?? '99');

        if ($codigoBanco === '00') {
            // Pago aprobado → marcar pagada con helpers del config.php
            $ok = marcarTransaccionPagada((int)$venta['id'], [
                'idTransaccion'      => $idTransaccion,
                'codigoAutorizacion' => $p['codigoAutorizacion'] ?? null,
                'importe'            => $p['importe'] ?? null,
                'moneda'             => $p['moneda'] ?? null,
                'idCuenta'           => $p['idCuenta'] ?? null,
                'origen'             => 'SPE',
            ]);

            $resp = $ok
                ? ['codigoRespuesta' => '00', 'descripcion' => 'OK']
                : ['codigoRespuesta' => '01', 'descripcion' => 'No se pudo registrar el pago'];

        } else {
            // Rechazada / error → registrar rechazo
            marcarTransaccionRechazada((int)$venta['id'], [
                'idTransaccion'   => $idTransaccion,
                'codigoRespuesta' => $codigoBanco,
                'importe'         => $p['importe'] ?? null,
                'moneda'          => $p['moneda'] ?? null,
                'idCuenta'        => $p['idCuenta'] ?? null,
                'origen'          => 'SPE',
            ]);

            $resp = ['codigoRespuesta' => '01', 'descripcion' => 'Rechazada o inválida'];
        }

        if (function_exists('write_log')) {
            write_log('CONFIRMACION ONLINE – RESPUESTA', $resp, $idTransaccion);
        }
        */

        $ok = true;
        $resp = $ok
                ? ['codigoRespuesta' => '00', 'descripcion' => 'OK']
                : ['codigoRespuesta' => '01', 'descripcion' => 'No se pudo registrar el pago'];
        return $resp;
    }

    // =====================================================
    // MÉTODO: CONSULTAR PENDIENTES (inicio banco)
    // =====================================================
    public function consultarPendientes($request)
    {
        $p = is_object($request) ? (array)$request : (array)$request;
        $ident = trim((string)($p['identificador'] ?? '')); // RUT/Cédula
        $idCliente = (int)($p['identificador'] ?? 0);


        if ($ident === '') {
            return ['codigoRespuesta' => '99', 'descripcion' => 'Identificador vacío'];
        }

        // Log de entrada
        if (function_exists('write_log')) {
            write_log('CONSULTAR PENDIENTES (SPE → Empresa)', [
                'params'  => $p,
                '_SERVER' => $_SERVER,
            ], 'sin_id');
        }

        $debugSteps = ['Inicio confirmación de venta: ' . $idTransaccion];
        try {
            // Conexión
            require_once __DIR__ . '/baseDeDatos.php';
            $db = new dbMysql($vhost, $vuser, $vpass, $vbd);

            // Escape
            if (method_exists($db, 'escape')) {
                $identEsc = $db->escape($ident);
            } else {
                $identEsc = esc_str_basico($ident);
            }

            // SQL EXACTO indicadо por ti
            $sql = "
                SELECT
                    v.IdVentas,
                    v.Serie,
                    v.Numero,
                    v.Moneda,
                    v.TotMntAPagar,
                    v.Saldo,
                    if(c.TipoDoc<>2, 'S','N') as cf,
                    ( (v.TotalVenta - v.TotMntNoGra) -  v.Iva) as TotMntGravado,
                    v.FechaVto,
                    t.TipoDoc
                FROM Ventas v
                INNER JOIN Clientes c
                    ON c.idcliente = v.IdCliente
                   AND c.IdEmpresa = v.IdEmpresa
                INNER JOIN TipoDoc t 
                    on v.IdTipoDoc=t.IdTipoDoc
                WHERE v.IdEmpresa = " . ID_EMPRESA_FIJO . "
                  AND c.documento = '{$identEsc}'
                  AND v.Saldo > 0
                  AND v.VC='V'
                  AND v.TV='CREDITO'
                  AND v.IdTipoDoc IN (101,111)
                  AND v.Estado in('CFE Autorizado.','CFE Firmado.','CFE Enviado.') 
                  AND v.Moneda IN ('UYU') 
                ORDER BY v.Fecha DESC
            ";

            $rs = $db->consulta($sql);
            $facturas = [];
            $total    = 0.0;

            if ($rs) {
                while ($re = mysqli_fetch_assoc($rs)) 
                {
                    $FechaVto = date_create($re['FechaVto']);
                    $FechaVto = date_format($FechaVto, 'Ymd');
                    $TipoDoc  = (string)($re['TipoDoc'] ?? '');
                    $TipoDoc  = str_replace('-', '', $TipoDoc);
                    $TipoDoc  = substr($TipoDoc, 0, 2);
            
                    $facturas[] = [
                        'idCliente'        => (int)$idCliente,
                        'idFactura'        => (string)($TipoDoc . '_' . $re['Serie'] . (string)($re['Numero']) ?? ''),
                        'descripcion'      => (string)($re['TipoDoc'] . '_' . $re['Serie'] . (string)($re['Numero']) ?? ''),
                        
                        // Importe de la factura a pagar, incluyendo dos decimales sin separador (ej: 123456 = 1234.56)
                        'importe'          => str_replace(['.', ','], '', number_format((float)($re['Saldo'] ?? 0), 2, '', '')),

                        'moneda'           => (string)($re['Moneda'] ?? ''),
                        'fechaVencimiento' => (string)$FechaVto,
                        'consumidorFinal'  => (string)($re['cf'] ?? ''),
                        
                        // Importe gravado con dos decimales sin separador (ej: 123456 = 1234.56)
                        'importeGravado'   => str_replace(['.', ','], '', number_format((float)($re['TotMntGravado'] ?? 0), 2, '', '')),
                    ];

                    $total += (float)($re['Saldo'] ?? 0);
                }
            }

            if (empty($facturas)) {
                return [
                    'codigoRespuesta' => '01',
                    'descripcion'     => 'Sin facturas pendientes'
                ];
            }

            $resp = [
                'codigoRespuesta' => '00',
                'descripcion'     => 'OK',
                'facturas'        => ['factura' => $facturas]
            ];

            if (function_exists('write_log')) {
                write_log('CONSULTAR PENDIENTES – RESPUESTA', $resp, 'sin_id');
            }
            return $resp;

        } catch (Throwable $e) {
            if (function_exists('write_log')) {
                write_log('consultarPendientes EXCEPTION', ['err'=>$e->getMessage(), 'req'=>$p], 'sin_id');
            }
            return ['codigoRespuesta'=>'99','descripcion'=>'Error interno'];
        }
    }

    // =====================================================
    // MÉTODO: CONFIRMAR PAGO EMPRESA (callback banco → empresa)
    // =====================================================
    public function confirmarPagoEmpresa($request)
    {
        $p = is_object($request) ? (array)$request : (array)$request;

        $logDir = APP_LOG_DIR;
        ensure_dir($logDir);

        $fileName = date('dmY_His') . '_response.txt';
        $filePath = $logDir . '/' . $fileName;

        // Guardar exactamente lo que llega
        $logData  = "====================================\n";
        $logData .= "CONFIRMAR PAGO EMPRESA\n";
        $logData .= "Fecha: " . date('Y-m-d H:i:s') . "\n";
        $logData .= "====================================\n";
        $logData .= json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n\n";

        file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);

        // Campos esperados (camelCase)
        $idTransaccion      = trim((string)($p['idTransaccion']      ?? ''));
        $idCuenta           = trim((string)($p['idCuenta']           ?? ''));
        $codigoRespuestaRx  = trim((string)($p['codigoRespuesta']    ?? '99'));
        $codigoAutorizacion = trim((string)($p['codigoAutorizacion'] ?? ''));
        $importeRx          = (string)($p['importe'] ?? '0');   // viene sin separador (p.ej. "123456" => 1234.56)
        $monedaRx           = trim((string)($p['moneda']  ?? ''));
        $importeDevolucion  = (string)($p['importeDevolucion'] ?? '0'); // opcional

        // Log RAW (request) a tabla VentasSistarbancEmpresa
        try {
            require_once __DIR__ . '/baseDeDatos.php';
            $db = new dbMysql($vhost, $vuser, $vpass, $vbd);

            // Serializamos el request tal cual (para auditoría)
            $requestRaw = is_array($p) ? json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : (string)$p;
            $reqEsc = method_exists($db,'escape') ? $db->escape($requestRaw) : esc_str_basico($requestRaw);
            $idTransEsc = method_exists($db,'escape') ? $db->escape($idTransaccion) : esc_str_basico($idTransaccion);
            $idCuentaEsc= method_exists($db,'escape') ? $db->escape($idCuenta)      : esc_str_basico($idCuenta);

            $sqlInsLog = "
                INSERT INTO VentasSistarbancEmpresa (idTransaccion, idCuenta, request)
                VALUES ('{$idTransEsc}', '{$idCuentaEsc}', '{$reqEsc}')
            ";
            $db->consulta($sqlInsLog);

            // Obtener el id recién insertado
            $sqlSelLog = "
                SELECT idLog
                FROM VentasSistarbancEmpresa
                WHERE idTransaccion = '{$idTransEsc}'
                AND idCuenta = '{$idCuentaEsc}'
                ORDER BY idLog DESC
                LIMIT 1
            ";

            if ($rsx = $db->consulta($sqlSelLog))
            {
                if($rex = mysqli_fetch_assoc($rsx))
                {
                    $idLog = $rex['idLog'] ?? null;
                }
            }

        } catch (Throwable $e) {
            // En caso de no poder registrar log, seguimos la lógica igual
            $idLog = null;
        }

        // Validaciones mínimas
        if ($idTransaccion === '') {
            $resp = ['codigoRespuesta'=>'01','descripcion'=>'idTransaccion vacío'];
            // Log de respuesta
            $respRaw = json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $respEsc = method_exists($db,'escape') ? $db->escape($respRaw) : esc_str_basico($respRaw);

            $sqlUpd = "
                UPDATE VentasSistarbancEmpresa
                SET response = '".$respEsc."'
                WHERE idLog = ".((int)$idLog)."
            ";
            $db->consulta($sqlUpd);
            //--------------------------------
            return $resp;
        }

        try {
            // Cargar ventas involucradas por idTransaccion
            $idTransEsc = method_exists($db,'escape') ? $db->escape($idTransaccion) : esc_str_basico($idTransaccion);
            $sqlVentas = "SELECT v.IdVentas, v.Moneda, v.Saldo, v.TotMntAPagar, v.EstadoSistarbanc, v.TCambio, v.IdCliente, 
            (select e.IdMedioPagoSistarbanc from Empresas e where e.IdEmpresa=v.IdEmpresa limit 1) as IdMedioPagoSistarbanc, 
            (Select m.IdCuentaBanco from MediosDePago m where m.IdMedios=(select e.IdMedioPagoSistarbanc from Empresas e where e.IdEmpresa=v.IdEmpresa limit 1) limit 1) as IdCuentaBanco 
            FROM Ventas v WHERE v.idTransaccion = '{$idTransEsc}'";
            $rs = $db->consulta($sqlVentas);

            $ventas = [];
            $sumaImporteBD = 0.0;
            $monedaBD = null;
            $tCambio = 1.0;
            $idCliente = 0;
            $IdMedioPagoSistarbanc = 0;
            $IdCuentaBanco = 0;

            if ($rs) {
                while ($re = mysqli_fetch_assoc($rs))
                {
                    $ventas[] = $re;
                    // Se usa TotMntAPagar o regla de negocio según tu flujo; aquí asumimos Suma de Saldo
                    $sumaImporteBD += (float)($re['Saldo'] ?? 0);
                    if ($monedaBD === null && !empty($re['Moneda'])) {
                        $monedaBD = (string)$re['Moneda'];
                    }

                    $tCambio = $re['TCambio'] !== null ? (float)$re['TCambio'] : 1.0;
                    $idCliente = $re['IdCliente'] !== null ? (int)$re['IdCliente'] : 0;
                    $IdCuentaBanco = $re['IdCuentaBanco'] !== null ? (int)$re['IdCuentaBanco'] : 0;
                    $IdMedioPagoSistarbanc = $re['IdMedioPagoSistarbanc'] !== null ? (int)$re['IdMedioPagoSistarbanc'] : 0;
                }
            }
            $debugSteps[] = 'Ventas encontradas: ' . count($ventas);

            if (empty($ventas))
            {
                $debugSteps[] = 'No se encontraron ventas para ' . $idTransEsc;
                $resp = ['codigoRespuesta'=>'01','descripcion'=>'Transacción no encontrada'];
                // Log de respuesta
                $respRaw = json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $respEsc = method_exists($db,'escape') ? $db->escape($respRaw) : esc_str_basico($respRaw);

                $sqlUpd = "
                    UPDATE VentasSistarbancEmpresa
                    SET response = '".$respEsc."'
                    WHERE idLog = ".((int)$idLog)."
                ";
                $db->consulta($sqlUpd);
                //--------------------------------
                return $resp;
            }

            // Normalizar importe recibido (string sin separador -> decimal)
            $importeNum = (float)substr_replace($importeRx, '.', max(0, strlen($importeRx)-2), 0);
            // Validar moneda e importe solo si el banco dice "00"
            $debugSteps[] = 'Procesando respuesta ' . $codigoRespuestaRx;
            if ($codigoRespuestaRx === '00')
            {
                $debugSteps[] = 'Validando moneda e importe';
                $monedaOk  = ((string)$monedaBD === (string)$monedaRx);
                $importeOk = mismoImporte($sumaImporteBD, $importeNum, 0.01);

                $fileName = date('dmY_His') . '_importes.txt';
                $filePath = $logDir . '/' . $fileName;
                $logData  = 'Importe BD: ' . $sumaImporteBD . ' - Importe RX: ' . $importeNum . "\n";
                file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);

                if (!$monedaOk || !$importeOk)
                {
                    $importeNum = (float)$importeRx;
                    $importeOk = mismoImporte($sumaImporteBD, $importeNum, 0.01);
                }

                if (!$monedaOk || !$importeOk)
                {
                    $debugSteps[] = 'Datos no coinciden (moneda/importe)';
                    // Marcamos ERROR_VALIDACION a todas las ventas de la transacción
                    $sqlUpdErr = "
                        UPDATE Ventas
                        SET EstadoSistarbanc = 'ERROR_VALIDACION'
                        WHERE idTransaccion = '{$idTransEsc}'
                    ";
                    $db->consulta($sqlUpdErr);

                    $resp = ['codigoRespuesta'=>'01','descripcion'=>'Datos no coinciden (moneda/importe)'];
                    // Log de respuesta
                    $respRaw = json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    $respEsc = method_exists($db,'escape') ? $db->escape($respRaw) : esc_str_basico($respRaw);

                    $sqlUpd = "
                        UPDATE VentasSistarbancEmpresa
                        SET response = '".$respEsc."'
                        WHERE idLog = ".((int)$idLog)."
                    ";
                    $db->consulta($sqlUpd);
                    //--------------------------------
                    return $resp;
                }

                // Marcar PAGADA (todas las ventas involucradas)
                $codAutEsc = method_exists($db,'escape') ? $db->escape($codigoAutorizacion) : esc_str_basico($codigoAutorizacion);
                $sqlUpdOk = "
                    UPDATE Ventas
                    SET EstadoSistarbanc = 'PAGADA',
                        CodigoAutorizacionSistarbanc = NULLIF('{$codAutEsc}',''),
                        FechaPagoSistarbanc = NOW()
                    WHERE idTransaccion = '{$idTransEsc}'
                ";
                $db->consulta($sqlUpdOk);

                $debugSteps[] = 'Validación OK - marca PAGADA';
                $resp = ['codigoRespuesta'=>'00','descripcion'=>'La cuenta ha sido pagada'];
                // Log de respuesta
                $respRaw = json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $respEsc = method_exists($db,'escape') ? $db->escape($respRaw) : esc_str_basico($respRaw);

                $sqlUpd = "
                    UPDATE VentasSistarbancEmpresa
                    SET response = '".$respEsc."'
                    WHERE idLog = ".((int)$idLog)."
                ";
                $db->consulta($sqlUpd);
                //--------------------------------

                //Generamos el recibo de pago

                // Fechas
                $Fecha      = date('Y-m-d');        // Fecha de emisión
                $FechaCaja  = $Fecha;        // Fecha de caja activa

                // Serie y numeración
                $Serie            = 'A';
                $Serie_A          = '';
                $NumeroNC         = 0;
                $NumeroRecibo     = 0;
                $NumeroRecibo_A   = 0;

                // Cliente
                $IdCliente = $idCliente;

                // Anulación (no se usa)
                $Anulado   = null;
                $Anulado_A = null;

                // Moneda y cambio
                $Moneda  = $monedaBD;        // ej: 'UYU'
                $TCambio = $tCambio;

                // Importes
                $Importe = (float)$sumaImporteBD;
                $Saldo   = 0;

                // Valores fijos
                $VC      = 'V';
                $Grabado = 0;              // normalmente 1 = grabado
                $cob     = 0;              // normalmente 1 = cobrado

                // Transferencias (no se usa)
                $TransferenciaCheque = 0;

                // Caja y banco
                $IdCaja         = 0;
                $IdMedios       = $IdMedioPagoSistarbanc; // PagoOnline

                // Empresa / sucursal
                $IdSucursal = ID_SUCURSAL_FIJO;//2 demo, pero en producción es 418

                // Estado y metadata
                $Estado       = null;
                $Avisos       = null;
                $Enlace_Pdf   = null;
                $Enlace_Pdf_A = null;

                // Adenda
                $Adenda = 'No de Transacción: '.$idTransEsc; // texto descriptivo de la transacción

                // Verificar si ya existe un recibo con este idTransaccion
                $sqlCheckRecibo = "
                    SELECT IdRecibo
                    FROM Recibos
                    WHERE idTransaccion = '{$idTransEsc}'
                    LIMIT 1
                ";

                $IdRecibo = null;
                $IdReciboItems = array();
                $ArrayTipo = array();
                $ArraySer  = array();
                $ArrayNum  = array();
                $ArrayFec  = array();
                $ArrayMonto= array();

                if ($rsCheck = $db->consulta($sqlCheckRecibo)) {
                    if ($reCheck = mysqli_fetch_assoc($rsCheck)) {
                        $IdRecibo = $reCheck['IdRecibo'] ?? null;
                    }
                }

                // Si NO existe el recibo, lo insertamos
                if (empty($IdRecibo)) {
                    $sqlInsertRecibo = "
                        INSERT INTO Recibos SET
                            Fecha = '".$Fecha."',
                            FechaCaja = '".$FechaCaja."',
                            Serie = '".$Serie."',
                            NumeroNC = '".$NumeroNC."',
                            NumeroRecibo = '".$NumeroRecibo."',
                            NumeroRecibo_A = '".$NumeroRecibo_A."',
                            IdCliente = '".$IdCliente."',
                            Moneda = '".$Moneda."',
                            TCambio = ".$TCambio.",
                            Importe = ".$Importe.",
                            Saldo = ".$Importe.",
                            VC = '".$VC."',
                            Grabado = '".$Grabado."',
                            cob = '".$cob."',
                            TransferenciaCheque = '".$TransferenciaCheque."',
                            IdCaja = '".$IdCaja."',
                            IdCuentaBanco = '".$IdCuentaBanco."',
                            IdMedios = '".$IdMedios."',
                            IdEmpresa = '".ID_EMPRESA_FIJO."',
                            IdSucursal = '".$IdSucursal."',
                            Adenda = '".$Adenda."',
                            idTransaccion = '".$idTransEsc."'
                    ";

                    $fileName = date('dmY_His') . '_sql_recibo.txt';
                    $filePath = $logDir . '/' . $fileName;
                    $logData  = $sqlInsertRecibo;
                    file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);

                    if (!$db->consulta($sqlInsertRecibo))
                    {
                        //throw new Exception('No se pudo insertar el recibo');
                    }

                    // Obtener el IdRecibo recién insertado
                    $sqlSelRecibo = "
                        SELECT IdRecibo
                        FROM Recibos
                        WHERE idTransaccion = '{$idTransEsc}'
                        ORDER BY IdRecibo DESC
                        LIMIT 1
                    ";

                    if ($rsRecibo = $db->consulta($sqlSelRecibo)) {
                        if ($reRecibo = mysqli_fetch_assoc($rsRecibo)) {
                            $IdRecibo = $reRecibo['IdRecibo'] ?? null;
                        }
                    }
                }

                // Ahora procesamos los items del recibo
                if ($IdRecibo > 0)
                {
                    $sqlVentas = "
                        SELECT v.IdVentas, v.Moneda, v.Saldo, v.TotMntAPagar, v.EstadoSistarbanc, v.TCambio, v.IdCliente, 
                        (select e.IdMedioPagoSistarbanc from Empresas e where e.IdEmpresa=v.IdEmpresa limit 1) as IdMedioPagoSistarbanc, 
                        (Select m.IdCuentaBanco from MediosDePago m where m.IdMedios=(select e.IdMedioPagoSistarbanc from Empresas e where e.IdEmpresa=v.IdEmpresa limit 1) limit 1) as IdCuentaBanco,
                        v.IdTipoDoc, v.Serie, v.Numero, v.Fecha
                        FROM Ventas v 
                        WHERE v.idTransaccion = '{$idTransEsc}'
                    ";

                    if ($rs = $db->consulta($sqlVentas)) 
                    {
                        while ($re = mysqli_fetch_assoc($rs)) 
                        {
                            $IdVentas  = $re['IdVentas'];
                            $SaldoVen  = $re['Saldo'];
                            $APagar    = $re['Saldo'];
                            $IdTipoDoc = $re['IdTipoDoc'];
                            $Serie     = $re['Serie'];
                            $Numero    = $re['Numero'];

                            $ArrayTipo[] = $IdTipoDoc;
                            $ArraySer[]  = $Serie;
                            $ArrayNum[]  = $Numero;
                            $ArrayFec[]  = $re['Fecha'];
                            $ArrayMonto[]= $APagar;

                            // Verificar si ya existe este item en RecibosItems
                            $sqlCheckItem = "
                                SELECT IdReciboItems
                                FROM RecibosItems
                                WHERE IdRecibo = '{$IdRecibo}'
                                AND IdVentas = '{$IdVentas}'
                                AND IdTipoDoc = '{$IdTipoDoc}'
                                AND Saldo = '{$SaldoVen}'
                                LIMIT 1
                            ";

                            $itemExiste = false;
                            if ($rsCheckItem = $db->consulta($sqlCheckItem)) {
                                if ($reCheckItem = mysqli_fetch_assoc($rsCheckItem)) {
                                    $itemExiste = true;
                                    $IdReciboItems[] = $reCheckItem['IdReciboItems'] ?? null;
                                }
                            }

                            // Si NO existe, lo insertamos
                            if (!$itemExiste)
                            {
                                $vsql_detalle = "
                                    INSERT INTO RecibosItems 
                                    SET IdRecibo='{$IdRecibo}', IdVentas='{$IdVentas}', Saldo='{$SaldoVen}', APagar='{$APagar}', IdTipoDoc='{$IdTipoDoc}', Serie='{$Serie}', Numero='{$Numero}'
                                ";

                                $fileName = date('dmY_His') . '_sql_reciboitems.txt';
                                $filePath = $logDir . '/' . $fileName;
                                $logData  = $vsql_detalle;
                                file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);

                                if ($db->consulta($vsql_detalle)) {
                                    $sqlSelReciboItem = "
                                        SELECT IdReciboItems
                                        FROM RecibosItems
                                        WHERE IdRecibo = '{$IdRecibo}'
                                        AND IdVentas = '{$IdVentas}'
                                        ORDER BY IdReciboItems DESC
                                        LIMIT 1
                                    ";

                                    if ($rsItem = $db->consulta($sqlSelReciboItem)) {
                                        if ($reItem = mysqli_fetch_assoc($rsItem)) {
                                            $IdReciboItems[] = $reItem['IdReciboItems'] ?? null;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // Reactivacion local: pago e items persistidos, antes de esperar el CFE de Migrate.
                try {
                    require_once __DIR__ . '/reactivar_empresa_pago.php';
                    sistarbancReactivarEmpresaConLog($db, ID_EMPRESA_FIJO, $IdCliente, $IdRecibo, $idTransaccion, $logDir);
                } catch (Throwable $reactivationError) {
                    error_log('Sistarbanc: fallo de reactivacion local, recibo ' . (int)$IdRecibo);
                }

                $vArrayValores = array();
                $varreglo  = false;

                if(count($IdReciboItems)>1)
                {
                    $varreglo = true;

                    //este es el detalle, si hay mas de un item lo llenamos por este lado
                    $vArrayValores[999] = $varreglo;
                    $vArrayValores[116] = array();		// <RefNroLinRef>
                    $vArrayValores[117] = array();		// <RefIndGlobal>
                    $vArrayValores[118] = array();		// <RefTpoDocRef>
                    $vArrayValores[119] = array();		// <RefSerie>
                    $vArrayValores[120] = array();      // <RefNroCFERef>
                    $vArrayValores[121] = array();		// <RefRazonRef>
                    $vArrayValores[122] = array();		// <RefFechaCFEref>
                    $vArrayValores[124] = array();		// <RefMontoRef>

                    for($vi=0; $vi<count($ArrayTipo);$vi++)
                    {
                        $Tipo = $ArrayTipo[$vi];
                        $Ser  = $ArraySer[$vi];
                        $Num  = $ArrayNum[$vi];
                        $Fec  = $ArrayFec[$vi];
                        $Mont = $ArrayMonto[$vi];

                        $vArrayValores[116][$vi] =$vi+1;						// <RefNroLinRef>
                        $vArrayValores[117][$vi] ='';						// <RefIndGlobal>
                        $vArrayValores[118][$vi] =$Tipo;						// <RefTpoDocRef>
                        $vArrayValores[119][$vi] =$Ser;				    	// <RefSerie>
                        $vArrayValores[120][$vi] =$Num;             		    // <RefNroCFERef>
                        $vArrayValores[121][$vi] ='';						// <RefRazonRef>
                        $vArrayValores[122][$vi] =$Fec;						// <RefFechaCFEref>
                        $vArrayValores[124][$vi] =$Mont;
                        $vArrayValores[125][$vi] = 'UYU'; //RefTpoMonedaRef
                        $vArrayValores[126][$vi] = '1.000'; //RefTipCambioRef
                    }
                }

                $Tipo = '';
                $Ser  = '';
                $Num  = '';
                $Fec  = '';
                $Mont = 0;

                if(count($IdReciboItems)==1)
                {
                    $vArrayValores[999] = $varreglo;

                    $Tipo = $ArrayTipo[0];
                    $Ser  = $ArraySer[0];
                    $Num  = $ArrayNum[0];
                    $Fec  = $ArrayFec[0];
                    $Mont = $ArrayMonto[0];
                }

                $llave   = 0;
                $ReEnvio = 0;
                $TipoDocu= '';

                $sql = "SELECT TipoDoc FROM Clientes WHERE idcliente=".$IdCliente;
                $rsC = $db->consulta($sql);
                if ($rsC) 
                {
                    if ($reC = mysqli_fetch_assoc($rsC)) 
                    {
                        $TipoDocu = $reC['TipoDoc'];
                    }
                }    
                
                $IdTipoDoc = 101;
                if ($TipoDocu  == 2)
                {
                    $IdTipoDoc = 111;      
                }

                $Formato      = '';
                $Copias       = 0;
                $PrecioConIva = 0.0;

                $sql = "SELECT Ultimo, Serie, Formato, Copias, PrecioConIva FROM Contadores WHERE IdEmpresa='".ID_EMPRESA_FIJO."' and Sucursal = 0 and IdTipoDoc=".$IdTipoDoc;
                
                $fileName = date('dmY_His') . '_sql_ultimo_contadores.txt';
                $filePath = $logDir . '/' . $fileName;
                $logData  = $sql;
                file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);
                
                if($rs = $db->consulta($sql))
                {
                    if ($re = mysqli_fetch_assoc($rs)) 
                    {
                        $Serie        = $re['Serie'];
                        $NumeroRecibo = $re['Ultimo']+1;
                        $Copias       = $re['Copias'];
                        $PrecioConIva = $re['PrecioConIva'];   
                        $Formato      = $re['Formato'];

                        $fileName = date('dmY_His') . '_datos_contadores.txt';
                        $filePath = $logDir . '/' . $fileName;
                        $logData  = 'IdRecibo: '.$IdRecibo.' - Serie: '.$Serie.' - NumeroRecibo: '.$NumeroRecibo.' - Copias: '.$Copias.' - PrecioConIva: '.$PrecioConIva.' - Formato: '.$Formato;
                        file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);
                    }
                }
                
                // =====================================================
                // Llamo a la función común de CAE
                // =====================================================
                $estadoCAE = "";
                $mensajeCAE= "";

                if (!empty($IdRecibo))
                {
                    //pasamos $IdRecibo, el nombre de la tabla a afectar, en este caso 'Recibos' y la conexión a la base de datos
                    $resCAE = fAsignarSerieNumeroCAEVentas($IdRecibo, "Recibos", $db);

                    $fileName = date('dmY_His') . '_datos_cae.txt';
                    $filePath = $logDir . '/' . $fileName;
                    $logData  = 'IdRecibo: '.$IdRecibo.' - Resultado CAE: '.json_encode($resCAE, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);

                    // Estado retornado
                    if (isset($resCAE["estado"]))
                    {
                        $estadoCAE = (int)$resCAE["estado"];
                    }

                    // Número asignado
                    if (isset($resCAE["numero"]))
                    {
                        $NumeroRecibo   = $resCAE["numero"]; 
                    }

                    // Serie asignada
                    if (isset($resCAE["serie"]))
                    {
                        $Serie = $resCAE["serie"];
                    }

                    // Mensaje retornado
                    if (isset($resCAE["mensaje"]))
                    {
                        $mensajeCAE = $resCAE["mensaje"];
                    }
                }
                else
                {
                    $estadoCAE  = 6;
                    $mensajeCAE = "Parámetros no encontrados o incorrectos";
                }
                //*********************************************************

                $SerieNumero           = $Serie.$NumeroRecibo;	
                $MontoBruto             = $PrecioConIva;		   
                        
                $msgDesc = '';      
                $msgStat = 0;
                $msgErro = '';
                $impresa = '';
                $idCfe   = $IdRecibo;
                //--------------------------------

                // Set de Datos del cliente   
                $fantasia  = '';
                $razon     = '';		  
                $Direccion = '';	
                $Documento = '';
                $TipoDocu  = '';
                $Giro      = '';
                $Ciudad    = '';
                $Depart    = '';
                $Pais      = '';	  
                $EsCF      = '';	
                $emailEnvio= '';
                $Asu       = '';
                $Sucursales= '';

                //información del emisor
                $EmiNombreFantasia = '';
                $EmiRazonSocial    = '';
                $EmiGiroEmisor     = '';
                $EmiTelefono       = '';
                $EmiTelefono2      = '';
                $EmiCorreoEmisor   = '';
                $EmiSucursal       = '';
                $EmiDomFiscal      = '';
                $EmiCiudad         = '';
                $EmiDepartamento   = '';
                $EmiInfAdicional   = '';
                        
                $sql = "SELECT Clientes.nombrefantasia,
                                Clientes.razonsocial, 
                                Clientes.direccion, 
                                Clientes.Documento,
                                Clientes.TipoDoc, 
                                giros.Giro,
                                Ciudades.Ciudad,
                                Departamentos.Departamento, 
                                Clientes.IdPais,
                                Clientes.cf,
                                Clientes.emailEnvioFE,
                                Clientes.Asu,
                                Clientes.Sucursales                 
                                FROM Clientes left join giros on (Clientes.IdGiro=giros.IdGiro)
                                                left join Ciudades on (Clientes.IdCiudad=Ciudades.IdCiudad)
                                                left join Departamentos on (Clientes.Departamento=Departamentos.IdDepartamento) 
                                                WHERE idcliente=".$IdCliente;
                if($rs2 = $db->consulta($sql))
                {
                    // setear si no existe para que no genere error
                    if ($re2 = mysqli_fetch_assoc($rs2))
                    {	   
                        $fantasia    = $re2["nombrefantasia"];
                        $razon       = $re2["razonsocial"];		   
                        $Direccion   = $re2["direccion"];	
                        $Documento   = $re2["Documento"];
                        $TipoDocu    = $re2["TipoDoc"];
                        $Giro        = $re2["Giro"];
                        $Ciudad      = $re2["Ciudad"];
                        $Depart      = $re2["Departamento"];
                        $Pais	     = $re2["IdPais"]; 
                        $EsCF        = $re2["cf"]; 
                        $emailEnvio  = $re2["emailEnvioFE"];
                        $Asu         = $re2["Asu"];
                        $Sucursales  = $re2["Sucursales"];
                    }

                }    
						
                $sqlEmpresa = "
                    SELECT NombreFantasia,
                           RazonSocial,
                           Domicilio,
                           Ciudad,
                           Departamento,
                           Email,
                           Notas
                    FROM Empresas
                    WHERE IdEmpresa = ".(int)ID_EMPRESA_FIJO."
                    LIMIT 1
                ";
                if ($rsEmpresa = $db->consulta($sqlEmpresa))
                {
                    if ($reEmpresa = mysqli_fetch_assoc($rsEmpresa))
                    {
                        $EmiNombreFantasia = trim((string)($reEmpresa['NombreFantasia'] ?? ''));
                        $EmiRazonSocial    = trim((string)($reEmpresa['RazonSocial'] ?? ''));
                        $EmiDomFiscal      = trim((string)($reEmpresa['Domicilio'] ?? ''));
                        $EmiCiudad         = trim((string)($reEmpresa['Ciudad'] ?? ''));
                        $EmiDepartamento   = trim((string)($reEmpresa['Departamento'] ?? ''));
                        $EmiCorreoEmisor   = trim((string)($reEmpresa['Email'] ?? ''));
                        //$EmiSucursal       = trim((string)($reEmpresa['Sucursales'] ?? ''));
                        $EmiInfAdicional   = trim((string)($reEmpresa['Notas'] ?? ''));
                    }
                }
                
                $CfeIdCompra = '';
                $CfeIdCompraTexto = '';

                $vArrayValores[199] = 'envio';
                $vArrayValores[200] = $IdRecibo;
                $vArrayValores[1]   = $IdTipoDoc;
                $vArrayValores[2]   = $Serie;
                $vArrayValores[3]   = $NumeroRecibo;
                $vArrayValores[4]   = '';
                $vArrayValores[5]   = 'S';
                $vArrayValores[6]   = $Copias;
                $vArrayValores[7]   = $Fecha;
                $vArrayValores[8]   = '';
                $vArrayValores[9]   = '';
                $vArrayValores[10]  = $MontoBruto;
                $vArrayValores[11]  = '1';
                $vArrayValores[12]  = '';
                $vArrayValores[13]  = '';
                $vArrayValores[14]  = $Adenda;
                $vArrayValores[15]  = '';
                $vArrayValores[16]  = '';
                $vArrayValores[17]  = '';
                $vArrayValores[18]  = '';
                $vArrayValores[19]  = '';
                $vArrayValores[20]  = '';
                $vArrayValores[21]  = '';
                $vArrayValores[22]  = $Formato;
                $vArrayValores[23]  = '';
                $vArrayValores[24]  = '';
                $vArrayValores[25]  = '';
                $vArrayValores[26]  = '';
                $vArrayValores[27]  = '';
                $vArrayValores[28]  = '';
                $vArrayValores[29]  = '1';
                $vArrayValores[30]  = '';
                $vArrayValores[31]  = '3';
                $vArrayValores[32]  = '';
                $vArrayValores[33]  = $EmiRazonSocial;    //EmiRznSoc
                $vArrayValores[34]  = $EmiNombreFantasia; //EmiComercial
                $vArrayValores[35]  = $EmiGiroEmisor;     //EmiGiroEmis
                $vArrayValores[36]  = $EmiTelefono;       //EmiTelefono
                $vArrayValores[37]  = $EmiTelefono2;      //EmiTelefono2
                $vArrayValores[38]  = $EmiCorreoEmisor;   //EmiCorreoEmisor
                $vArrayValores[39]  = '';//$EmiSucursal;       //EmiSucursal
                $vArrayValores[40]  = $EmiDomFiscal;      //EmiDomFiscal
                $vArrayValores[41]  = $EmiCiudad;         //EmiCiudad
                $vArrayValores[42]  = $EmiDepartamento;   //EmiDepartamento
                $vArrayValores[43]  = '';//$EmiInfAdicional;   //EmiInfAdicional
                $vArrayValores[44]  = $TipoDocu;
                $vArrayValores[45]  = '';
                $vArrayValores[46]  = $Pais;
                $vArrayValores[47]  = $Documento;
                $vArrayValores[48]  = $razon;
                $vArrayValores[49]  = $Direccion;
                $vArrayValores[50]  = $Ciudad;
                $vArrayValores[51]  = $Depart; //EmiDepartamento
                $vArrayValores[52]  = '';
                $vArrayValores[53]  = $emailEnvio;
                $vArrayValores[54]  = '';
                $vArrayValores[55]  = '';
                $vArrayValores[56]  = '';
                $vArrayValores[57]  = '1';
                $vArrayValores[58]  = '';
                $vArrayValores[59]  = '';
                $vArrayValores[60]  = '';
                $vArrayValores[61]  = '';
                $vArrayValores[62]  = '';
                $vArrayValores[63]  = '';
                $vArrayValores[64]  = $Moneda;
                $vArrayValores[65]  = $TCambio;
                $vArrayValores[66]  = '';
                $vArrayValores[67]  = '';
                $vArrayValores[68]  = '';
                $vArrayValores[69]  = '';
                $vArrayValores[70]  = '';
                $vArrayValores[71]  = '';
                $vArrayValores[72]  = '';
                $vArrayValores[73]  = '';
                $vArrayValores[74]  = '';
                $vArrayValores[75]  = '';
                $vArrayValores[76]  = '';
                $vArrayValores[77]  = '';
                $vArrayValores[78]  = '0.00'; //TotMntTotal
                $vArrayValores[79]  = null;
                $vArrayValores[80]  = '';
                $vArrayValores[81]  = null;
                $vArrayValores[82]  = null;
                $vArrayValores[83]  = null;
                $vArrayValores[84]  = floatval($Importe); // <TotMontoNF>
                $vArrayValores[85]  = floatval($Importe); // <TotMntPagar>
                $vArrayValores[86]  = '';
                $vArrayValores[87]  = '';
                $vArrayValores[88]  = '';
                $vArrayValores[89]  = '';
                $vArrayValores[90]  = '';
                $vArrayValores[91]  = '';
                $vArrayValores[92]  = '';
                $vArrayValores[93]  = '';
                $vArrayValores[94]  = '';
                $vArrayValores[95]  = '';
                $vArrayValores[96]  = '';
                $vArrayValores[97]  = '';
                $vArrayValores[98]  = '';
                $vArrayValores[99]  = '';
                $vArrayValores[100] = '';
                $vArrayValores[101] = '';
                $vArrayValores[102] = '';
                $vArrayValores[103] = '';
                $vArrayValores[104] = '';
                $vArrayValores[105] = '';
                $vArrayValores[106] = '';
                $vArrayValores[107] = '';
                $vArrayValores[108] = '';
                $vArrayValores[109] = 0;
                $vArrayValores[110] = '';
                $vArrayValores[111] = '';
                $vArrayValores[112] = '';
                $vArrayValores[113] = '';
                $vArrayValores[114] = '';
                $vArrayValores[115] = '';

                if (!$varreglo)
                {
                    $vArrayValores[116] = '1';
                    $vArrayValores[117] = '';
                    $vArrayValores[118] = $Tipo;
                    $vArrayValores[119] = $Ser;
                    $vArrayValores[120] = $Num;
                    $vArrayValores[121] = '';
                    $vArrayValores[122] = $Fec;
                    $vArrayValores[124] = $Mont; //RefMontoRef
                    $vArrayValores[125] = $Moneda; //RefTpoMonedaRef
                    $vArrayValores[126] = $TCambio; //RefTipCambioRef
                }

                $vArrayValores[123] = '1';

                // ARRAY DEL ITEM
                $IndFact = 6;
                $glo_array_detalles_itens = [];
                $glo_array_detalles_itens[0][0]   = '';
                $glo_array_detalles_itens[0][1]   = '';
                $glo_array_detalles_itens[0][2]   = $IndFact;
                $glo_array_detalles_itens[0][3]   = '';
                $glo_array_detalles_itens[0][4]   = 'RECIBO';
                $glo_array_detalles_itens[0][5]   = '';
                $glo_array_detalles_itens[0][6]   = '1';
                $glo_array_detalles_itens[0][7]   = 'N/A';
                $glo_array_detalles_itens[0][8]   = floatval($Importe);
                $glo_array_detalles_itens[0][9]   = '';
                $glo_array_detalles_itens[0][10]  = '';
                $glo_array_detalles_itens[0][11]  = '';
                $glo_array_detalles_itens[0][12]  = '';
                $glo_array_detalles_itens[0][13]  = '';
                $glo_array_detalles_itens[0][14]  = '';
                $glo_array_detalles_itens[0][15]  = '';
                $glo_array_detalles_itens[0][16]  = '';
                $glo_array_detalles_itens[0][17]  = '';
                $glo_array_detalles_itens[0][18]  = '';
                $glo_array_detalles_itens[0][19]  = '';
                $glo_array_detalles_itens[0][20]  = '';
                $glo_array_detalles_itens[0][21]  = '';
                $glo_array_detalles_itens[0][22]  = '';
                $glo_array_detalles_itens[0][23]  = floatval($Importe);
                $glo_array_detalles_itens[0][24]  = '0';
                $glo_array_detalles_itens[0][25]  = '0';
                $glo_array_detalles_itens[0][26]  = '';
                $glo_array_detalles_itens[0][27]  = '';

                $normalizeStrings = function ($value) use (&$normalizeStrings) {
                    if (is_array($value)) {
                        $clean = [];
                        foreach ($value as $key => $child) {
                            $clean[$key] = $normalizeStrings($child);
                        }
                        return $clean;
                    }
                    return is_string($value) ? trim($value) : $value;
                };
                $vArrayValores = $normalizeStrings($vArrayValores);
                foreach ($glo_array_detalles_itens as $index => $item) {
                    $glo_array_detalles_itens[$index] = $normalizeStrings($item);
                }
                if (!isset($vArrayValores[4]) || trim((string)$vArrayValores[4]) === '') {
                    $vArrayValores[4] = 'Mostrador';
                }
                $vArrayValores[65] = number_format((float)($vArrayValores[65] ?? 1), 4, '.', '');
                foreach ([84, 85] as $totalIndex) {
                    if (isset($vArrayValores[$totalIndex]) && $vArrayValores[$totalIndex] !== '') {
                        $vArrayValores[$totalIndex] = number_format((float)$vArrayValores[$totalIndex], 2, '.', '');
                    }
                }
                foreach ($glo_array_detalles_itens as $itemIndex => $item) {
                    foreach ([8, 23] as $fieldIndex) {
                        if (isset($item[$fieldIndex]) && $item[$fieldIndex] !== '') {
                            $glo_array_detalles_itens[$itemIndex][$fieldIndex] = number_format((float)$item[$fieldIndex], 2, '.', '');
                        }
                    }
                }

                $migrationScript = APP_ENV === 'PRODUCCION'
                    ? __DIR__ . '/migrateInvoicyServicesPRODUCCION.php'
                    : __DIR__ . '/migrateInvoicyServices.php';
                require_once $migrationScript;
                
                require_once 'Nusoap/nusoap.php';
                    
                        
                $empCod = 28;
                $pk     = 'njFg6MzGqPSHGfgHqR2QCseEIW6hK';
                $sqlCred = "
                    SELECT Empresainvoicy, Clave
                    FROM Empresas
                    WHERE IdEmpresa = " . (int)ID_EMPRESA_FIJO . "
                    LIMIT 1
                ";
                try {
                    $rsCred = $db->consulta($sqlCred);
                    if ($rsCred && ($rowCred = mysqli_fetch_assoc($rsCred))) {
                        $empCod = (int)($rowCred['Empresainvoicy'] ?? $empCod);
                        $pk     = trim((string)($rowCred['Clave'] ?? $pk));
                    }
                } catch (Throwable $credErr) {
                    $debugSteps[] = 'No se pudo cargar credenciales Invoicy: ' . $credErr->getMessage();
                }
                $vArrayValores[199] = 'envio';

                $service = new Services();
                $payloadEnvio = $service->envio($pk,$vArrayValores,$glo_array_detalles_itens,$empCod);
                $soapResponse = $service->soapConnect($service->urlTypes()['envio'],$payloadEnvio,$service->urlTypes()['method']);
                $content = $soapResponse->Xmlretorno ?? '';          // Respuesta del XML
                ensure_dir(APP_LOG_DIR);
                $logFile = APP_LOG_DIR . '/soap_confirmacion_' . date('Ymd_His') . '.txt';
                $requestLog = $payloadEnvio['Xmlrecepcao'] ?? '';
                $responseLog = (string)$content;
                file_put_contents($logFile, "REQUEST:\n$requestLog\n\nRESPONSE:\n$responseLog\n\n", FILE_APPEND | LOCK_EX);
                $reg     = $vArrayValores[200];               // IdVentas
                    
                    
                // CONTROLA EL ERROR Y RECOGE LA INFORMACION DEVUELTA.
                $xml = @simplexml_load_string($content);
                $idCfe = $reg;
                $msgStat = '0';
                $msgDesc = '';
                $impresa = '';
                $msgErro = '';

                if ($xml === false) {
                    $msgDesc = 'XML inválido recibido';
                    $msgErro = 'La respuesta SOAP no tiene formato XML válido.';
                } else {
                    $impresa = $xml->ListaCFE->CFE->CFERepImpressa;
                    $msgDesc = $xml->ListaCFE->CFE->CFEMsgDsc ?? '';
                    $msgStat = $xml->ListaCFE->CFE->CFEStatus ?? '0';

                    if (!empty($xml->ListaCFE->CFE->Erros->ErrosItem->CFEErrDesc)) {
                        foreach ($xml->ListaCFE->CFE->Erros->ErrosItem as $v) {
                            $msgErro .= $v->CFEErrDesc . '<br>';
                        }
                    }
                    if (!empty($xml->ListaCFE->CFE->ErrosDGI->ErrosDGIItem->CFERetDesc)) {
                        foreach ($xml->ListaCFE->CFE->ErrosDGI->ErrosDGIItem as $value) {
                            $msgErro .= $value->CFERetDesc . '<br>';
                        }
                    }
                }

                /* ************************************
                ** Escribo en la Tabla de Ventas. **
                ************************************  */
                        
                                
                if ($msgStat=='1'){$msgDesc= 'CFE Importado.'; }
                if ($msgStat=='2'){$msgDesc= 'CFE Firmado.';   }
                if ($msgStat=='3'){$msgDesc= 'CFE Rechazado.'; }
                if ($msgStat=='4'){$msgDesc= 'CFE Enviado.';   }
                if ($msgStat=='5'){$msgDesc= 'CFE Autorizado.';}
                if ($msgStat=='6'){$msgDesc= 'CFE Anulado.';   }

            
                //   Este IF, actualiza RECIBOS, NÚMERO, SERIE, ESTADO, AVISO Y EL PDF, solo si no hubo error, de lo contrario
                //   no graba nada, se puede intentar de nuevo.
                //   ---------------------------------------------------------------------------------------------------------
        
                //si hay código de respuesta            
                if ($msgStat>0)
                {	
                    if ($msgStat=='3')
                    { 

                        $update_sql_error = "UPDATE Recibos  SET NumeroRecibo='-1' WHERE IdRecibo=".$IdRecibo;

                        $db->consulta($update_sql_error);

                        $fileName = date('dmY_His') . '_error_envio_recibo.txt';
                        $filePath = $logDir . '/' . $fileName;
                        $logData  = $msgErro;
                        file_put_contents($filePath, $logData, FILE_APPEND | LOCK_EX);
                    }

                    $Anulado = $msgStat;
                    $update_sql = "UPDATE Recibos  SET Saldo='0', Estado='".$msgDesc."', Avisos='".$msgErro."', Enlace_Pdf='".$impresa."', Anulado = '".$Anulado."' WHERE IdRecibo=".$IdRecibo;

                    $db->consulta($update_sql);

                    //actualizamos el saldo de las ventas relacionadas
                    $sql_ventas_saldo="Update Ventas set Saldo='0' where idTransaccion = '".$idTransEsc."'";
                    $db->consulta($sql_ventas_saldo);
                }

                if ($msgStat==0)
                {
                    $update_sql = "UPDATE Recibos  SET NumeroRecibo='-1', Estado='Error', Avisos='Error al procesar el CFE desde integracion con Sistarbanc' WHERE IdRecibo=".$IdRecibo;
                    $db->consulta($update_sql);
                }

                return $resp;

            } 
            else
            {
                // Rechazo del banco → marcar ERROR (salvo que ya esté marcado como PAGADA)
                $alreadyPaid = false;
                $sqlVerif = "
                    SELECT 1
                    FROM Ventas
                    WHERE idTransaccion = '{$idTransEsc}'
                      AND UPPER(EstadoSistarbanc) = 'PAGADA'
                    LIMIT 1
                ";
                $rsVerif = $db->consulta($sqlVerif);
                if ($rsVerif && method_exists($rsVerif, 'num_rows') && $rsVerif->num_rows > 0) {
                    $alreadyPaid = true;
                }

                if (!$alreadyPaid) {
                    $sqlUpdErr = "
                        UPDATE Ventas
                        SET EstadoSistarbanc = 'ERROR'
                        WHERE idTransaccion = '{$idTransEsc}'
                    ";
                    $db->consulta($sqlUpdErr);
                }

                $resp = ['codigoRespuesta'=>'01','descripcion'=>'Rechazada o inválida'];
                // Log de respuesta
                $respRaw = json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $respEsc = method_exists($db,'escape') ? $db->escape($respRaw) : esc_str_basico($respRaw);

                $sqlUpd = "
                    UPDATE VentasSistarbancEmpresa
                    SET response = '".$respEsc."'
                    WHERE idLog = ".((int)$idLog)."
                ";
                $db->consulta($sqlUpd);
                //--------------------------------
                return $resp;
            }

        } catch (Throwable $e) {
            $debugSteps[] = 'Exception atrapada en confirmarPagoEmpresa: ' . $e->getMessage();
            $debugFile = APP_LOG_DIR . '/confirmacion_error_' . date('Ymd_His') . '.txt';
            file_put_contents($debugFile, implode(PHP_EOL, $debugSteps) . PHP_EOL, FILE_APPEND);

            $resp = ['codigoRespuesta'=>'99','descripcion'=>'Error interno'];
            // Log de respuesta
            $respRaw = json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $respEsc = method_exists($db,'escape') ? $db->escape($respRaw) : esc_str_basico($respRaw);

            $sqlUpd = "
                UPDATE VentasSistarbancEmpresa
                SET response = '".$respEsc."'
                WHERE idLog = ".((int)$idLog)."
            ";
            $db->consulta($sqlUpd);
            //--------------------------------
            
            return $resp;
        }
    }

    // -----------------------------------------
    // Helper local para registrar el response en la tabla de log
    // -----------------------------------------
    private function wsLogResponse($idLog, array $resp): void
    {
        if (!empty($idLog)) 
        {
            try {
                require_once __DIR__ . '/baseDeDatos.php';
                $db = new dbMysql($vhost, $vuser, $vpass, $vbd);

                $respRaw = json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $respEsc = method_exists($db,'escape') ? $db->escape($respRaw) : esc_str_basico($respRaw);

                $sqlUpd = "
                    UPDATE VentasSistarbancEmpresa
                    SET response = '".$respEsc."'
                    WHERE idLog = ".((int)$idLog)."
                ";

                $db->consulta($sqlUpd);
            } catch (Throwable $e) {
                // silencio: no romper el flujo del WS por fallar el log
            }
        }
    }


}

// -----------------------------------------
// Servidor SOAP con WSDL
// -----------------------------------------
try {
    ini_set('display_errors', '0');

    $wsdl = __DIR__ . '/ws_confirmacion.wsdl';
    $options = [
        'uri'          => 'urn:sistarbanc-spe-confirmacion',
        'cache_wsdl'   => WSDL_CACHE_NONE,
        'trace'        => 0,
        'exceptions'   => true,
        'encoding'     => 'UTF-8',
        'soap_version' => SOAP_1_1
    ];

    $server = new SoapServer($wsdl, $options);
    $server->setClass(SPEConfirmacionService::class);
    $server->handle();

} catch (Throwable $e) {
    if (function_exists('write_log')) {
        write_log('CONFIRMACION ONLINE – EXCEPCION', ['error' => $e->getMessage()], 'sin_id');
    }
    header('Content-Type: text/xml; charset=utf-8');
    $fault = new SoapFault('Server', 'Error interno en el servicio');
    echo $fault->faultstring;
}
?>
