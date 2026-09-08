<?php
/** Un intento remoto por reactivacion registrada; evidencia permanente fuera de la web. */
class SistarbancMigrateWorker
{
    private $dir;
    public function __construct($dir){$this->dir=$dir;}
    private function save($path,$data){$tmp=$path.'.tmp';if(file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE))===false || !rename($tmp,$path))throw new RuntimeException('No se pudo conservar evidencia');}
    public function run($events,callable $lookup,callable $send,$limit=3){
        umask(0077);if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true))throw new RuntimeException('Sin directorio');
        $lock=fopen($this->dir.'/worker.lock','c');if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))return ['locked'=>true];
        $stats=['confirmed'=>0,'unconfirmed'=>0,'discarded'=>0,'recovered'=>0,'processed'=>0];
        try {
            foreach(glob($this->dir.'/*.processing.json')?:[] as $path){
                $r=json_decode(file_get_contents($path),true)?:[];$r['status']='INTERRUMPIDO_SIN_CONFIRMACION';$r['closed_at']=date('c');
                $this->save(str_replace('.processing.json','.done.json',$path),$r);unlink($path);$stats['recovered']++;
            }
            foreach($events as $event){
                if(($event['resultado']??'')!=='REACTIVADA' || !ctype_digit((string)($event['empresa']??'')) || !preg_match('/^[0-9]{1,64}$/D',(string)($event['transaccion']??'')))continue;
                $key=hash('sha256',$event['empresa'].'|'.$event['transaccion']);$base=$this->dir.'/'.$key;
                if(is_file($base.'.done.json'))continue;
                if($stats['processed'] >= $limit)break;
                $record=['event'=>$event,'started_at'=>date('c'),'status'=>'EN_PROCESO'];
                $this->save($base.'.processing.json',$record);
                try {
                    $company=$lookup($event);
                    if(!$company){$record['status']='DESCARTADO_NO_ELEGIBLE';$stats['discarded']++;}
                    else {
                        $record['company']=['IdEmpresa'=>$company['IdEmpresa'],'Rut'=>$company['Rut'],'EmpresaInvoicy'=>$company['EmpresaInvoicy']];
                        $start=microtime(true);$record['response']=$send($company);$record['seconds']=round(microtime(true)-$start,3);
                        $ok=!empty($record['response']['success']);$record['status']=$ok?'CONFIRMADO':'SIN_CONFIRMACION';$stats[$ok?'confirmed':'unconfirmed']++;
                    }
                }catch(Throwable $e){$record['status']='ERROR_SIN_CONFIRMACION';$record['error']=$e->getMessage();$stats['unconfirmed']++;}
                $record['closed_at']=date('c');$this->save($base.'.done.json',$record);unlink($base.'.processing.json');$stats['processed']++;
            }
        } finally {flock($lock,LOCK_UN);fclose($lock);}
        return $stats;
    }
}
