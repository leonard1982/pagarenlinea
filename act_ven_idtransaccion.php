<?php
// /var/www/pagarenlinea/act_ven_idtransaccion.php
header('Content-Type: application/json; charset=utf-8');
require_once 'baseDeDatos.php';

$respuesta = ['ok' => false, 'message' => ''];

try {
    $raw = file_get_contents('php://input');
    $in  = json_decode($raw, true);
    if (!is_array($in)) { $respuesta['message']='Body JSON inválido'; echo json_encode($respuesta,JSON_UNESCAPED_UNICODE); exit; }

    $idTransaccion = isset($in['idTransaccion']) ? trim((string)$in['idTransaccion']) : '';
    $idventas      = isset($in['idventas']) && is_array($in['idventas']) ? $in['idventas'] : [];
    $overwrite     = !empty($in['overwrite']);

    if ($idTransaccion === '') { $respuesta['message']='idTransaccion requerido'; echo json_encode($respuesta,JSON_UNESCAPED_UNICODE); exit; }
    $idTransaccion = strtoupper($idTransaccion);
    if (!preg_match('/^[A-Z0-9\-]{1,50}$/', $idTransaccion)) { $respuesta['message']='idTransaccion inválido'; echo json_encode($respuesta,JSON_UNESCAPED_UNICODE); exit; }

    $ids = [];
    foreach ($idventas as $v) { $n=(int)$v; if ($n>0) $ids[]=$n; }
    $ids = array_values(array_unique($ids));
    if (empty($ids)) { $respuesta['message']='idventas vacío'; echo json_encode($respuesta,JSON_UNESCAPED_UNICODE); exit; }

    $conexion = new dbMysql($vhost, $vuser, $vpass, $vbd);
    $idsIn = implode(',', $ids); // solo números

    if ($overwrite) {
        $vsql = "UPDATE Ventas
                 SET idTransaccion = '".$idTransaccion."', EstadoSistarbanc = 'EN_PROCESO' 
                 WHERE IdVentas IN ($idsIn)";
    } else {
        $vsql = "UPDATE Ventas
                 SET idTransaccion = '".$idTransaccion."', EstadoSistarbanc = 'EN_PROCESO' 
                 WHERE IdVentas IN ($idsIn)
                   AND (idTransaccion IS NULL OR idTransaccion='')";
    }

    $ok = $conexion->consulta($vsql);
    if (!$ok) { $respuesta['message']='No se pudo actualizar Ventas'; echo json_encode($respuesta,JSON_UNESCAPED_UNICODE); exit; }

    $vsqlCount = "SELECT COUNT(*) AS c FROM Ventas
                  WHERE IdVentas IN ($idsIn) AND idTransaccion='".$idTransaccion."'";
    $co = $conexion->consulta($vsqlCount);
    $updated = 0;
    if ($co && ($rw = mysqli_fetch_array($co))) { $updated = (int)$rw['c']; }

    echo json_encode(['ok'=>true,'updated'=>$updated,'ids'=>$ids], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'message'=>'Error: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>