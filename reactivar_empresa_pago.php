<?php
/** Reactivacion local tras un pago bancario persistido. No realiza llamadas externas. */
function sistarbancReactivarEmpresa($db, $ownerId, $clientId, $receiptId, $transaction)
{
    $ownerId=(int)$ownerId; $clientId=(int)$clientId; $receiptId=(int)$receiptId;
    $out=['evento'=>'REACTIVACION_DINAMICA','cliente'=>$clientId,'recibo'=>$receiptId,'transaccion'=>(string)$transaction,'resultado'=>'NO_CONFIRMADO'];
    if ($ownerId!==397 || $clientId<=0 || $receiptId<=0 || !preg_match('/^[0-9]{1,64}$/D',(string)$transaction)) {
        $out['resultado']='CONTEXTO_NO_APLICABLE'; return $out;
    }
    $tx=$db->escape((string)$transaction);
    $rows=function($sql)use($db){$r=$db->consulta($sql);if($r===false)throw new RuntimeException('Consulta de reactivacion fallida');if($r instanceof PDOStatement)return $r->fetchAll(PDO::FETCH_ASSOC);$a=[];while($v=mysqli_fetch_assoc($r))$a[]=$v;return $a;};
    $paid=$rows("SELECT v.IdVentas FROM Ventas v WHERE v.idTransaccion='$tx'");
    $valid=$rows("SELECT v.IdVentas FROM Ventas v WHERE v.idTransaccion='$tx' AND v.IdEmpresa=$ownerId AND v.IdCliente=$clientId AND v.EstadoSistarbanc='PAGADA' AND COALESCE(v.CodigoAutorizacionSistarbanc,'')<>'' AND v.FechaPagoSistarbanc IS NOT NULL AND EXISTS (SELECT 1 FROM Recibos r JOIN RecibosItems ri ON ri.IdRecibo=r.IdRecibo WHERE r.IdRecibo=$receiptId AND r.IdEmpresa=$ownerId AND r.IdCliente=$clientId AND r.idTransaccion='$tx' AND r.Importe>0 AND ri.IdVentas=v.IdVentas AND ri.APagar>0 AND ri.APagar>=v.Saldo)");
    if (!$paid || count($paid)!==count($valid)) {$out['resultado']='PAGO_NO_PERSISTIDO_COMPLETO';return $out;}
    $companies=$rows("SELECT e.IdEmpresa,e.Habilitada,e.Rut FROM Empresas e JOIN Clientes c ON c.Documento=e.Rut WHERE c.idcliente=$clientId AND c.IdEmpresa=$ownerId");
    if(count($companies)!==1){$out['resultado']='EMPRESA_NO_UNIVOCA';return $out;}
    $id=(int)$companies[0]['IdEmpresa'];$out['empresa']=$id;$out['estado_anterior']=$companies[0]['Habilitada'];
    if($companies[0]['Habilitada']!=='SU'){$out['resultado']='ESTADO_NO_SU';return $out;}
    // El pago confirmado aun puede conservar saldo hasta terminar la emision del CFE.
    // Solo se excluye esta transaccion, cuya autorizacion y todos sus items se verificaron.
    $rut=$db->escape((string)$companies[0]['Rut']);
    $debt="SELECT 1 FROM Ventas d JOIN Clientes dc ON dc.idcliente=d.IdCliente AND dc.IdEmpresa=d.IdEmpresa WHERE d.IdEmpresa=$ownerId AND dc.Documento='$rut' AND d.Saldo>0 AND (d.idTransaccion IS NULL OR d.idTransaccion<>'$tx')";
    if($rows($debt.' LIMIT 1')){$out['resultado']='DEUDA_PENDIENTE';return $out;}
    if(!$db->consulta("UPDATE Empresas SET Habilitada='SI' WHERE IdEmpresa=$id AND Habilitada='SU' AND NOT EXISTS ($debt)"))throw new RuntimeException('No se pudo reactivar empresa');
    $changed=$rows('SELECT ROW_COUNT() AS changed');
    $out['resultado']=(int)$changed[0]['changed']===1?'REACTIVADA':'SIN_CAMBIO_CONCURRENTE';
    $out['migrate']='NO_ENVIADO';
    return $out;
}

function sistarbancReactivarEmpresaConLog($db,$ownerId,$clientId,$receiptId,$transaction,$logDir)
{
    try {$result=sistarbancReactivarEmpresa($db,$ownerId,$clientId,$receiptId,$transaction);}
    catch(Throwable $e){$result=['evento'=>'REACTIVACION_DINAMICA','cliente'=>(int)$clientId,'recibo'=>(int)$receiptId,'transaccion'=>(string)$transaction,'resultado'=>'ERROR','error'=>$e->getMessage()];}
    $result['fecha']=date('c');
    if(@file_put_contents($logDir.'/reactivacion_dinamica_'.date('Ymd').'.jsonl',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL,FILE_APPEND|LOCK_EX)===false)error_log('Sistarbanc: no se pudo guardar log de reactivacion, recibo '.(int)$receiptId);
    return $result;
}
