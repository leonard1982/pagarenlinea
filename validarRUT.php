<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/baseDeDatos.php';


$rut = isset($_POST['rut']) ? trim($_POST['rut']) : '';

$respuesta = [
    'ok'              => false,
    'empresa'         => null,
    'tiene_facturas'  => false,
    'facturas'        => [],
    'total_pendiente' => 0,
    'message'         => ''
];

try {
    if ($rut === '') {
        $respuesta['message'] = 'Falta el RUT.';
        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Conexión (variables vienen de baseDeDatos.php)
    $conexion = new dbMysql($vhost, $vuser, $vpass, $vbd);

    // 1) Empresa por RUT
    $vsqlEmp = "SELECT nombrefantasia, razonsocial
                FROM Clientes
                WHERE Documento='".$rut."' and IdEmpresa='".ID_EMPRESA_FIJO."' LIMIT 1";

    $coEmp = $conexion->consulta($vsqlEmp);

    if ($coEmp && ($re = mysqli_fetch_array($coEmp))) {
        // Datos empresa
        $respuesta['ok'] = true;
        $respuesta['empresa'] = [
            'id_empresa'      => 397,
            'rut'             => $rut,
            'razon_social'    => $re['razonsocial'],
            'nombre_fantasia' => $re['nombrefantasia']
        ];
        $respuesta['message'] = 'Empresa encontrada.';

        // 2) Facturas pendientes (empresa emisora fija: 397)
        $vsqlFac = "SELECT
                        DATE_FORMAT(v.Fecha, '%d/%m/%Y') AS Fecha,
                        v.Serie,
                        v.Numero,
                        v.Moneda,
                        v.TotMntAPagar,
                        v.Saldo as Saldo,
                        v.Enlace_Pdf,
                        v.IdVentas,
                        if(c.TipoDoc<>2, 'S','N') as cf,
                        ( (v.TotalVenta - v.TotMntNoGra) -  v.Iva) as TotalGravado2,
                        v.FechaVto,
                        t.TipoDoc,
                        v.IdVentas,
                        (CASE
                            WHEN IFNULL(v.Iva, 0) <= 0 THEN 0
                            WHEN IFNULL(v.Saldo, 0) <= 0 THEN 0
                            ELSE ROUND(
                                GREATEST(
                                    v.Saldo - (v.Saldo * (v.Iva / NULLIF(v.TotMntAPagar, 0))),
                                    0
                                ),
                                2
                            )
                        END) AS TotalGravado
                    FROM Ventas v
                    INNER JOIN TipoDoc t 
                        on v.IdTipoDoc=t.IdTipoDoc
                    INNER JOIN Clientes c
                        ON c.idcliente = v.IdCliente
                       AND c.IdEmpresa = v.IdEmpresa
                    WHERE v.VC='V'
                      AND v.Saldo > 0
                      AND v.TV='CREDITO'
                      AND c.Documento = '".$rut."'
                      AND v.IdTipoDoc IN (101,111)
                      AND v.IdEmpresa = '".ID_EMPRESA_FIJO."'
                      AND v.Estado in('CFE Autorizado.','CFE Firmado.','CFE Enviado.')
                      AND v.Moneda IN ('UYU') 
                    ORDER BY v.Fecha DESC";


                    //para buscar cuentas pendientes para pruebas
                    //SELECT DATE_FORMAT(v.Fecha, '%d/%m/%Y') AS Fecha, v.Serie, v.Numero, v.Moneda, v.TotMntAPagar, v.Saldo, v.IdVentas, c.cf, (v.TotalVenta-v.TotMntNoGra) as TotalGravado, c.Documento FROM Ventas v inner join Clientes c on v.IdCliente=c.idcliente WHERE v.VC='V' AND v.Saldo > 0 AND v.TV='CREDITO' AND v.IdTipoDoc IN (101,111) AND v.IdEmpresa = 397 AND v.Estado in('CFE Autorizado.','CFE Firmado.','CFE Enviado.') AND v.Moneda IN ('UYU') ORDER BY v.Fecha DESC

        $coFac = $conexion->consulta($vsqlFac);

        $facturas = [];
        $total = 0.0;
        $TotalGrabado = 0.0;

        if ($coFac) {
            while ($re = mysqli_fetch_assoc($coFac))
            {
                $saldo    = (float)$re['Saldo'];
                $FechaVto = date_create($re['FechaVto']);
                $FechaVto = date_format($FechaVto, 'Ymd');
                $TipoDoc  = (string)($re['TipoDoc'] ?? '');
                $TipoDoc  = str_replace('-', '', $TipoDoc);
                $TipoDoc  = substr($TipoDoc, 0, 2);
                $TotalGrabado += (float)$re['TotalGravado'];
                $Fecha    = (string)$re['Fecha'];
                $IdVentas = (int)$re['IdVentas'];

                 $facturas[] = [
                    'idCuenta'         => (int)$rut,
                    'idFactura'        => (string)($TipoDoc . '_' . $re['Serie'] . (string)($re['Numero']) ?? ''),
                    'importe'          => (float)($re['Saldo'] ?? 0),
                    'moneda'           => (string)($re['Moneda'] ?? ''),
                    // OJO: el SELECT creó "TotalGravado", no "TotMntGravado"
                    'importeGravado'   => (float)($re['TotalGravado'] ?? 0),
                    'consumidorFinal'  => (string)($re['cf'] ?? ''),
                    'fechaVenc'        => (string)$FechaVto,
                    'Serie'            => (string)$re['Serie'],
                    'Numero'           => (string)$re['Numero'],
                    'Fecha'            => $Fecha,
                    'TipoDoc'          => (string)$re['TipoDoc'] ?? '',
                    'IdVentas'         => (int)$IdVentas ?? '',
                    'TotMntAPagar'     => (float)($re['TotMntAPagar'] ?? 0),
                    'pdf_disponible'   => trim((string)($re['Enlace_Pdf'] ?? '')) !== '',
                ];

                $total += $saldo; // sumar SALDO
            }
        }

        $respuesta['facturas'] = $facturas;
        $respuesta['total_pendiente'] = $total;
        $respuesta['tiene_facturas'] = count($facturas) > 0;
        $respuesta['totalGravado'] = $TotalGrabado;


        if (!$respuesta['tiene_facturas']) {
            $respuesta['message'] = 'La empresa no tiene facturas pendientes.';
        }

    } else {
        $respuesta['message'] = 'No existe una empresa con ese RUT.';
    }

    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $respuesta['ok'] = false;
    $respuesta['message'] = 'Error: ' . $e->getMessage();
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
}
?>
