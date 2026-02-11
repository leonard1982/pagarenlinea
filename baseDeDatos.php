<?php
require_once __DIR__ . '/config.php';

$vhost = DB_HOST;
$vuser = DB_USER;
$vpass = DB_PASS;
$vbd   = DB_NAME;

function checkPuerto($dominio,$puerto){
    
    $starttime = microtime(true);
    $file      = @fsockopen ($dominio, $puerto, $errno, $errstr, 10);
    $stoptime  = microtime(true);
    $status    = 0;
  
    if (!$file){    
        $status = -1;  // Sitio caído
    } else {
        fclose($file);
        $status = ($stoptime - $starttime) * 1000;
        $status = floor($status);
    }
     
    if ($status <> -1) {
        return true;
    } else {
        return false;
    }
     
}

class dbMysql{

	private $conexion;
	private $servidor;
	private $usuario;
	private $password;
	private $puerto;
	private $db;
	private $resultado;
	
	public function __construct($servidor,$usuario,$password,$db,$puerto=3306){

		$this->servidor=$servidor;
		$this->usuario=$usuario;
		$this->password=$password;
		$this->db=$db;
		$this->puerto=$puerto;
		$this->conexion = mysqli_connect($this->servidor.":".$this->puerto, $this->usuario, $this->password,$this->db);
		$this->conexion->set_charset('utf8');
	}
	
	public function consulta($sql){
		
		return $this->resultado = mysqli_query($this->conexion,$sql);

	}	

	public function escape(string $s): string {
    	return mysqli_real_escape_string($this->conexion, $s);
	}	
	
	public function __destruct(){

		$this->conexion->close();
	}
}

class dbFirebirdPDO{

	private $conexion;
	private $servidor;
	private $db;
	private $resultado;
	
	public function __construct($servidor,$db){

		$this->servidor = $servidor;
		$this->db       = $db;
		$this->conexion = new PDO("firebird:dbname=".$this->servidor.":".$db, "SYSDBA", "masterkey");
	}
	
	public function consulta($sql){
		
		return $this->resultado = $this->conexion->query($sql);

	}	
	
	public function __destruct(){

	}
}

class dbSqlite{

	private $ruta;
	private $conexion;
	private $idConsulta;
	
	public function __construct($ruta){
		$this->ruta=$ruta;
		$this->conexion=sqlite_open($ruta,'777',$error);
		if (!$this->$conexion)die($error);
	}
	public function consulta($query){
		$this->idConsulta=sqlite_query($this->conexion,$query);	
	}	
	public function arrayConsulta(){
		return sqlite_fetch_array($this->idConsulta);
	}
	public function consultarArray($ruta,$query){
		return sqlite_array_query($ruta,$query,SQLITE_ASSOC);
	}
}

class dbFirebird{

	private $servidor;
	private $usuario;
	private $password;
	private $ruta;
	private $conexion;
	private $idConsulta;
	
	public function __construct($ruta,$ip){

		$this->servidor = $ip;
		$this->usuario  = "SYSDBA";
		$this->password = "masterkey";
		$this->ruta     = $ruta;
		
		$this->conexion = ibase_connect($this->servidor.":".$this->ruta,$this->usuario,$this->password);
	}
	public function consulta($query){
			return $this->idConsulta = ibase_query($this->conexion,$query);
	}
	
	public function consulta_retorno($query){
			return $this->idConsulta = ibase_prepare($this->conexion,$query) or die(ibase_errmsg());
	}
}

class dbFirebird2{

	private $servidor;
	private $usuario;
	private $password;
	private $ruta;
	private $conexion;
	private $idConsulta;
	
	public function __construct($ruta){

		$this->servidor = "localhost";
		$this->usuario  = "SYSDBA";
		$this->password = "masterkey";
		$this->ruta     = $ruta;
		
		$this->conexion = ibase_connect($this->servidor.":".$this->ruta,$this->usuario,$this->password);
	}
	public function consulta($query){
			return $this->idConsulta = ibase_query($this->conexion,$query);
	}
	
	public function consulta_retorno($query){
			return $this->idConsulta = ibase_prepare($this->conexion,$query) or die(ibase_errmsg());
	}
}
?>
