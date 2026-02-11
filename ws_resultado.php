<?php
// /var/www/pagarenlinea/ws_resultado.php
require __DIR__ . '/config.php'; // aquí tienes tu DB y helpers (safe_log, etc.)

class SPEHandler {
    public function resultadoTransaccion($params) {
        // Compatibilidad: stdClass o array
        $p = is_object($params) ? get_object_vars($params) : (array)$params;

        $req = [
            'idTransaccion'      => trim($p['idTransaccion']      ?? ''),
            'idCuenta'           => trim($p['idCuenta']           ?? ''),
            'codigoRespuesta'    => trim($p['codigoRespuesta']    ?? ''),
            'codigoAutorizacion' => trim($p['codigoAutorizacion'] ?? ''),
            'importe'            => (string)($p['importe']            ?? ''),
            'importeDevolucion'  => (string)($p['importeDevolucion']  ?? '0'),
            'moneda'             => trim($p['moneda']             ?? ''),
            '_remote_ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        safe_log('SPE SOAP IN', $req, $req['idTransaccion'] ?: 'sin_id');

        try {
            // 1) Buscar transacción que TÚ generaste al iniciar el pago
            $tx = buscarTransaccionPorId($req['idTransaccion']); // usa tu función real

            if (!$tx) {
                safe_log('SPE SOAP ERROR: tx no existe', $req, $req['idTransaccion']);
                return '99';
            }

            // 2) Validaciones obligatorias: idCuenta, importe y moneda deben coincidir
            $importeEsperado = normalizarImporte($tx['importe']); // por ej. "98800"
            if ($tx['idCuenta'] !== $req['idCuenta'] ||
                $importeEsperado !== normalizarImporte($req['importe']) ||
                strtoupper($tx['moneda']) !== strtoupper($req['moneda'])) {

                safe_log('SPE SOAP ERROR: datos no coinciden', ['tx'=>$tx,'req'=>$req], $req['idTransaccion']);
                return '12'; // código de “datos no coinciden”
            }

            // 3) Procesar resultado
            if ($req['codigoRespuesta'] === '00') {
                marcarTransaccionPagada($tx['id'], [
                    'codigo_autorizacion' => $req['codigoAutorizacion'],
                    'importe'             => $req['importe'],
                    'moneda'              => $req['moneda'],
                    'importe_devolucion'  => $req['importeDevolucion'],
                    'ip_sistarbanc'       => $req['_remote_ip'],
                ]);
                safe_log('SPE SOAP OK: aprobada', $req, $req['idTransaccion']);
                return '00';
            } else {
                marcarTransaccionRechazada($tx['id'], [
                    'codigo_respuesta'    => $req['codigoRespuesta'],
                    'codigo_autorizacion' => $req['codigoAutorizacion'],
                    'ip_sistarbanc'       => $req['_remote_ip'],
                ]);
                safe_log('SPE SOAP OK: rechazada', $req, $req['idTransaccion']);
                return '10'; // rechazo
            }

        } catch (Throwable $e) {
            safe_log('SPE SOAP EXCEPTION', ['err'=>$e->getMessage()], $req['idTransaccion']);
            return '98';
        }
    }
}

// Helpers
function normalizarImporte($v){ return preg_replace('/\D/', '', (string)$v); }

// Servidor SOAP sin WSDL
$server = new SoapServer(null, ['uri' => 'urn:spe.resultado']);
$server->setClass(SPEHandler::class);
$server->handle();

?>