<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require '/var/www/plugin/gestion_empresas/config/app.php';
require '/var/www/plugin/gestion_empresas/config/database.php';
require '/var/www/plugin/gestion_empresas/helpers/Db.php';
require '/var/www/plugin/gestion_empresas/helpers/MigrateInvoicyService.php';
require __DIR__.'/SistarbancMigrateWorker.php';
function sistarEvents(){
    foreach(glob('/var/www/pagarenlinea/log/reactivacion_dinamica_*.jsonl')?:[] as $path){
        $f=fopen($path,'r');if(!$f)continue;
        try {while(($line=fgets($f))!==false){if(substr($line,-1)!=="\n")continue;$event=json_decode($line,true);if(is_array($event))yield $event;}}
        finally{fclose($f);}
    }
}
$db=Db::conn();$service=new MigrateInvoicyService();
$lookup=function($ev)use($db){
    $q=$db->prepare("SELECT e.IdEmpresa,e.Rut,e.Habilitada,e.EmpresaInvoicy,e.SucCodSucursal FROM Empresas e JOIN Clientes c ON c.Documento=e.Rut AND c.IdEmpresa=397 WHERE e.IdEmpresa=? AND c.idcliente=?");
    $q->execute([(int)$ev['empresa'],(int)($ev['cliente']??0)]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1 || strtoupper(trim($rows[0]['Habilitada']))!=='SI' || !ctype_digit(trim((string)$rows[0]['EmpresaInvoicy'])))return null;
    $q=$db->prepare("SELECT COUNT(*) FROM Ventas v WHERE v.idTransaccion=? AND (v.IdEmpresa<>397 OR v.IdCliente<>? OR COALESCE(v.EstadoSistarbanc,'')<>'PAGADA' OR COALESCE(v.CodigoAutorizacionSistarbanc,'')='' OR v.FechaPagoSistarbanc IS NULL)");
    $q->execute([$ev['transaccion'],(int)$ev['cliente']]);if((int)$q->fetchColumn()>0)return null;
    $q=$db->prepare("SELECT COUNT(*) FROM Ventas v WHERE v.idTransaccion=? AND EXISTS(SELECT 1 FROM Recibos r JOIN RecibosItems ri ON ri.IdRecibo=r.IdRecibo WHERE r.IdRecibo=? AND r.IdCliente=v.IdCliente AND r.IdEmpresa=397 AND r.idTransaccion=v.idTransaccion AND ri.IdVentas=v.IdVentas AND ri.APagar>0 AND ri.APagar>=v.Saldo)");
    $q->execute([$ev['transaccion'],(int)($ev['recibo']??0)]);$covered=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT COUNT(*) FROM Ventas WHERE idTransaccion=?');$q->execute([$ev['transaccion']]);if($covered===0 || $covered!==(int)$q->fetchColumn())return null;
    $q=$db->prepare("SELECT COUNT(*) FROM Ventas v JOIN Clientes c ON c.idcliente=v.IdCliente AND c.IdEmpresa=v.IdEmpresa WHERE v.IdEmpresa=397 AND c.Documento=? AND v.Saldo>0 AND (v.idTransaccion IS NULL OR v.idTransaccion<>?)");
    $q->execute([$rows[0]['Rut'],$ev['transaccion']]);if((int)$q->fetchColumn()>0)return null;
    return $rows[0];
};
if(($argv[1]??'')==='--check'){
    $ev=['empresa'=>(int)($argv[2]??0),'cliente'=>(int)($argv[3]??0),'recibo'=>(int)($argv[4]??0),'transaccion'=>(string)($argv[5]??'')];
    $company=$lookup($ev);echo json_encode(['read_only'=>true,'eligible'=>(bool)$company,'empresa'=>$company['IdEmpresa']??null]).PHP_EOL;exit;
}
$stats=(new SistarbancMigrateWorker('/var/lib/dynamica/sistarbanc_reactivation'))->run(sistarEvents(),$lookup,function($company)use($service){return $service->updateCompanyState($company,'SI');},3);
echo date('c').' '.json_encode($stats).PHP_EOL;
