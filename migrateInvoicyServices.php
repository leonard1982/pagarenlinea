<?php
class Services{
	function __construct(){
		ini_set ('soap.wsdl_cache_enabled', 0);
		header('Content-Type: text/html; charset=UTF-8');
	}

	/*
	public function soapConnect($url,$array,$metodo){
		$soap 	  = new SoapClient($url);
		$response   = $soap->$metodo($array);
		return $response;
	}
	*/
	public function soapConnect($url, $array, $metodo, $reintentos = 3, $espera = 5)
	{
		$intento = 0;
		while ($intento < $reintentos) {
			try {
				$soap = new SoapClient($url);
				$response = $soap->$metodo($array);
				return $response; // Éxito
			} catch (Exception $e) {
				$intento++;
				if ($intento >= $reintentos) {
					throw new Exception("Error al conectar al servicio SOAP: " . $e->getMessage());
				}
				sleep($espera); // Espera antes de reintentar
			}
		}
	}

	public function urlTypes(){
		$types['method'] = 'Execute';
		$types['envio'] = 'https://appuypruebas.migrate.info/InvoiCy/aws_emissionfactura.aspx?WSDL';
		$types['consulta'] = "https://appuypruebas.migrate.info/InvoiCy/aws_consultafactura.aspx?WSDL";
		$types['anulacion'] = 'https://appuypruebas.migrate.info/InvoiCy/aws_anulacion.aspx?WSDL';
		$types['recibido'] = 'https://appuypruebas.migrate.info/InvoiCy/aws_consultarecibidos.aspx?WSDL';
		$types['descarga'] = 'https://appuypruebas.migrate.info/InvoiCy/aws_descargarecibidos.aspx?WSDL';
		$types['aceptacion'] = 'https://appuypruebas.migrate.info/InvoiCy/aws_aceptacionrecibidos.aspx?WSDL';

		return $types;
	}

	private $pk = 'TTetUPl8o/2ubDbXweCkgFGrCw7Px';
	public function getPK(){
		return $this->pk;
	}

public function envio($pk,$data,$item,$empCod)
{
		$content = "<CFE>
		<CFEItem>
			<IdDoc>
				<CFETipoCFE>$data[1]</CFETipoCFE>
				<CFESerie>$data[2]</CFESerie>
				<CFENro>$data[3]</CFENro>
				<CFEImpresora>$data[4]</CFEImpresora>
				<CFEImp>$data[5]</CFEImp>
				<CFEImpCantidad>$data[6]</CFEImpCantidad>
				<CFEFchEmis>$data[7]</CFEFchEmis>
				<CFEPeriodoDesde>$data[8]</CFEPeriodoDesde>
				<CFEPeriodoHasta>$data[9]</CFEPeriodoHasta>
				<CFEMntBruto>$data[10]</CFEMntBruto>
				<CFEFmaPago>$data[11]</CFEFmaPago>
				<CFEFchVenc>$data[12]</CFEFchVenc>
				<CFETipoTraslado>$data[13]</CFETipoTraslado>
				<CFEAdenda>$data[14]</CFEAdenda>
				<CFEAdendaImagen>".utf8_encode(base64_encode($data[15]))."</CFEAdendaImagen>
				<CFEEfectivo>V$data[16]</CFEEfectivo>
				<CFEPesoTotal>$data[17]</CFEPesoTotal>
				<CFECambio>$data[18]</CFECambio>
				<CFEAcimaUI>$data[19]</CFEAcimaUI>
				<CFENumReferencia>$data[20]</CFENumReferencia>
				<CAEApodo>$data[21]</CAEApodo>
				<CFEImpFormato>$data[22]</CFEImpFormato>
				<CFEIdCompra>$data[23]</CFEIdCompra>
				<CFEIdCompraApodo>$data[24]</CFEIdCompraApodo>
				<CFEExpClaVenta>$data[25]</CFEExpClaVenta>
				<CFEExpModVenta>$data[26]</CFEExpModVenta>
				<CFEExpViaTransporte>$data[27]</CFEExpViaTransporte>
				<CFETpoOperacion>$data[28]</CFETpoOperacion>
				<CFEQrCode>$data[29]</CFEQrCode>
				<CFEDatosAvanzados>$data[30]</CFEDatosAvanzados>
				<CFERepImpresa>$data[31]</CFERepImpresa>
				<CFEInfAdicional>$data[32]</CFEInfAdicional>
				<CFECobranzaPropia>$data[123]</CFECobranzaPropia>
			</IdDoc>
			<Emisor>
				<EmiRznSoc>$data[33]</EmiRznSoc>
				<EmiComercial>$data[34]</EmiComercial>
				<EmiGiroEmis>$data[35]</EmiGiroEmis>
				<EmiTelefono>$data[36]</EmiTelefono>
				<EmiTelefono2>$data[37]</EmiTelefono2>
				<EmiCorreoEmisor>$data[38]</EmiCorreoEmisor>
				<EmiSucursal>$data[39]</EmiSucursal>
				<EmiDomFiscal>$data[40]</EmiDomFiscal>
				<EmiCiudad>$data[41]</EmiCiudad>
				<EmiDepartamento>$data[42]</EmiDepartamento>
				<EmiInfAdicional>$data[43]</EmiInfAdicional>
			</Emisor>
			<Receptor>
				<RcpTipoDocRecep>$data[44]</RcpTipoDocRecep>
				<RcpTipoDocDscRecep>$data[45]</RcpTipoDocDscRecep>
				<RcpCodPaisRecep>$data[46]</RcpCodPaisRecep>
				<RcpDocRecep>$data[47]</RcpDocRecep>
				<RcpRznSocRecep>$data[48]</RcpRznSocRecep>
				<RcpDirRecep>$data[49]</RcpDirRecep>
				<RcpCiudadRecep>$data[50]</RcpCiudadRecep>
				<RcpDeptoRecep>$data[51]</RcpDeptoRecep>
				<RcpCP>$data[52]</RcpCP>
				<RcpCorreoRecep>$data[53]</RcpCorreoRecep>
				<RcpInfAdiRecep>$data[54]</RcpInfAdiRecep>
				<RcpDirPaisRecep>$data[55]</RcpDirPaisRecep>
				<RcpDstEntregaRecep>$data[56]</RcpDstEntregaRecep>
				<RcpEmlArchivos>$data[57]</RcpEmlArchivos>
			</Receptor>
			<Mandante>
				<MndTipDoc>$data[58]</MndTipDoc>
				<MndTipDocDsc>$data[59]</MndTipDocDsc>
				<MndCodPais>$data[60]</MndCodPais>
				<MndNroDocumento>$data[61]</MndNroDocumento>
				<MndRazSocial>$data[62]</MndRazSocial>
				<MndEncriptar>$data[63]</MndEncriptar>
			</Mandante>
			<Totales>
				<TotTpoMoneda>$data[64]</TotTpoMoneda>
				<TotTpoCambio>$data[65]</TotTpoCambio>
				<TotMntNoGrv>$data[66]</TotMntNoGrv>
				<TotMntExpoyAsim>$data[67]</TotMntExpoyAsim>
				<TotMntImpuestoPerc>$data[68]</TotMntImpuestoPerc>
				<TotMntIVaenSusp>$data[69]</TotMntIVaenSusp>
				<TotMntNetoIvaTasaMin>$data[70]</TotMntNetoIvaTasaMin>
				<TotMntNetoIVATasaBasica>$data[71]</TotMntNetoIVATasaBasica>
				<TotMntNetoIVAOtra>$data[72]</TotMntNetoIVAOtra>
				<TotIVATasaMin>$data[73]</TotIVATasaMin>
				<TotIVATasaBasica>$data[74]</TotIVATasaBasica>
				<TotMntIVATasaMin>$data[75]</TotMntIVATasaMin>
				<TotMntIVATasaBasica>$data[76]</TotMntIVATasaBasica>
				<TotMntIVAOtra>$data[77]</TotMntIVAOtra>
				<TotMntTotal>$data[78]</TotMntTotal>
				<TotMntTotRetenido>$data[79]</TotMntTotRetenido>
				<TotMntCreditoFiscal>$data[80]</TotMntCreditoFiscal>";

	/* Estos es solo para RESGUARDOS
       *****************************  */

	$tipo = $data[1];
	if($tipo == "182" or $tipo == "182"){

	$content .= "
	            <RetencPercepTot>
					<RetencPercepTotItem>
						<RetPercCodRet>$data[81]</RetPercCodRet>
						<RetPercValRetPerc>$data[82]</RetPercValRetPerc>
						<RetPercValCreditoFiscal>$data[83]</RetPercValCreditoFiscal>
					</RetencPercepTotItem>
				</RetencPercepTot>";
		}



	// ******************************************************************************


	$content .= "
	                <TotMontoNF>$data[84]</TotMontoNF>
				<TotMntPagar>$data[85]</TotMntPagar>
  			</Totales>
  				<Detalle>
  			<CodBarras>
				<CodBarRedPagos>
					<CodBarRedPagCodEmpresa>$data[86]</CodBarRedPagCodEmpresa>
					<CodBarRedPagNroCliente>$data[87]</CodBarRedPagNroCliente>
					<CodBarRedPagFchVencimiento>$data[88]</CodBarRedPagFchVencimiento>
					<CodBarRedPagMoneda>$data[89]</CodBarRedPagMoneda>
					<CodBarRedPagImpMinimo>$data[90]</CodBarRedPagImpMinimo>
					<CodBarRedPagImpTotal>$data[91]</CodBarRedPagImpTotal>
				</CodBarRedPagos>
				<CodBarAbitab>
					<CodBarAbtCodEmpresa>$data[92]</CodBarAbtCodEmpresa>
					<CodBarAbtNroCliente>$data[93]</CodBarAbtNroCliente>
					<CodBarAbtFchVencimiento>$data[94]</CodBarAbtFchVencimiento>
					<CodBarAbtMoneda>$data[95]</CodBarAbtMoneda>
					<CodBarAbtImporte>$data[96]</CodBarAbtImporte>
					<CodBarAbtCuota>$data[97]</CodBarAbtCuota>
					<CodBarAbtMora>$data[98]</CodBarAbtMora>
					<CodBarAbtCuenta>$data[99]</CodBarAbtCuenta>
				</CodBarAbitab>
			</CodBarras>";
			

			foreach($item as $itens){
	$content.="
	                <Item>
					<CodItem>";    // ojo,  Cerre la Tab, de lo contrario quitar el / y agregar de nuevo el resto del codigo.

				 /* En CodItemItem, se establecen los códigos internos de cada producto.
           			Para solucionar el caso de la funcionalidad Asu, se consulta si $itens[0] es EAN O DUM y se procede a
		   			agregar un segundo grupo de código.-       */
 				
	$content.="
					<CodItemItem>
							<IteCodiTpoCod>INT1</IteCodiTpoCod>
							<IteCodiCod>$itens[24]</IteCodiCod>
					</CodItemItem>";
		
		/* Este segundo código EAN o DUN no importa si es ASU o no, si existe, lo agrega al xml.
		   Tal como esta diseñado solo puede agregar el código interno siempre y un segundo código
		   cuando existe, no más de 2 códigos de items por Items.  */
		
				
		if ($itens[27]=='GTIN8' or $itens[27]=='GTIN12' or $itens[27]=='GTIN13' or $itens[27]=='GTIN14'){
    		$content .= "
        		<CodItemItem>
            		<IteCodiTpoCod>$itens[27]</IteCodiTpoCod>
            		<IteCodiCod>$itens[28]</IteCodiCod>
        		</CodItemItem>";
		}			
				

				
		$content.="									
				   </CodItem>";
				
	     //         Aqui arriba del CodItem, va el segundo código  

	$content.="
	                <IteIndFact>$itens[2]</IteIndFact>
					<IteIndAgenteResp>$itens[3]</IteIndAgenteResp>
					<IteNomItem>$itens[4]</IteNomItem>
					<IteDscItem>$itens[5]</IteDscItem>
					<IteCantidad>$itens[6]</IteCantidad>
					<IteUniMed>$itens[7]</IteUniMed>
					<ItePrecioUnitario>$itens[8]</ItePrecioUnitario>
					<IteDescuentoPct>$itens[9]</IteDescuentoPct>
					<IteDescuentoMonto>$itens[10]</IteDescuentoMonto>";

			/*  ESTA PARTE ES SOLO TAB DESCUENTO Y RECARGO, LO QUITO DEL TODO LUEGO VEMOS.-
					<SubDescuento>
						<SubDescuentoItem>
							<SubDescDescTipo>$itens[11]</SubDescDescTipo>
							<SubDescDescVal>$itens[12]</SubDescDescVal>
						</SubDescuentoItem>
					</SubDescuento>
					<IteRecargoPct>$itens[13]</IteRecargoPct>
					<IteRecargoMnt>$itens[14]</IteRecargoMnt>
					<SubRecargo>
						<SubRecargoItem>
							<SubRecaRecargoTipo>$itens[15]</SubRecaRecargoTipo>
							<SubRecaRecargoVal>$itens[16]</SubRecaRecargoVal>
						</SubRecargoItem>
					</SubRecargo>
		*/


	//  para RESGUARDO Items
	if($tipo == "182"){
	  $content.="
	           <RetencPercep>
					<RetencPercepItem>
							<IteRetPercCodRet>$itens[18]</IteRetPercCodRet>
							<IteRetPercDscRet>$itens[19]</IteRetPercDscRet>
							<IteRetPercTasa>$itens[20]</IteRetPercTasa>
							<IteRetPercMntSujetoaRet>$itens[21]</IteRetPercMntSujetoaRet>
							<IteRetPercValRetPerc>$itens[15]</IteRetPercValRetPerc>
							<IteRetPerc>$itens[22]</IteRetPerc>
					</RetencPercepItem>
				</RetencPercep>";
	}

	$content.="<IteMontoItem>$itens[23]</IteMontoItem>
				</Item>";
				}

	// Arriba termina el bucle.-

$content .="
             </Detalle>";

/* Esta parte del XML Ccontiene parece descuento general se vera mas adelnte si se incorpora
  ******************************************************************************************


			<SubTotInfo>
				<STIItem>
					<SubTotNroSTI>$data[100]</SubTotNroSTI>
					<SubTotGlosaSTI>$data[101]</SubTotGlosaSTI>
					<SubTotOrdenSTI>$data[102]</SubTotOrdenSTI>
					<SubTotValSubtotSTI>$data[103]</SubTotValSubtotSTI>
				</STIItem>
			</SubTotInfo>
			*/
 if ($data[109]>0){
$content .="
			<DscRcgGlobal>
				<DRGItem>
					<DscRcgNroLinDR>$data[104]</DscRcgNroLinDR>
					<DscRcgTpoMovDR>$data[105]</DscRcgTpoMovDR>
					<DscRcgTpoDR>$data[106]</DscRcgTpoDR>
					<DscRcgCodDR>$data[107]/Recargo</DscRcgCodDR>
					<DscRcgGlosaDR>$data[108]</DscRcgGlosaDR>
					<DscRcgValorDR>$data[109]</DscRcgValorDR>
					<DscRcgIndFactDR>$data[110]</DscRcgIndFactDR>
				</DRGItem>
			</DscRcgGlobal>";
			}




	/* MEDIOS DE PAGO.-	(Se agrega solo si es definido)*/
	
	if ($data[111]>0){
$content .="
			<MediosPago>
				<MediosPagoItem>
					<MedPagNroLinMP>$data[111]</MedPagNroLinMP>
					<MedPagCodMP>$data[112]</MedPagCodMP>
					<MedPagGlosaMP>$data[113]</MedPagGlosaMP>
					<MedPagOrdenMP>$data[114]</MedPagOrdenMP>
					<MedPagValorPago>$data[115]</MedPagValorPago>
				</MediosPagoItem>
			</MediosPago>";
		}


   /* Esto corresponde cuando los CFE son notas de credito.  Relaciona documentos.
      **************************************************************************** */

if ($tipo==102 or 
	$tipo==112 or 
	$tipo==122 or 
	$tipo==103 or 
	$tipo==113 or 
	$tipo==123 or 
	$tipo==202 or 
	$tipo==212 or 
	$tipo==222 or 
	$tipo==203 or 
	$tipo==213 or 
	$tipo==223 or
    $data[123]==1)
	{
	
		//****************************//
		if(isset($data[999]))
		{
			if($data[999])
			{
				$content .="<Referencia>";
						
				for($ve=0;$ve<count($data[116]);$ve++)
				{
				$content .="
							<ReferenciaItem>
								<RefNroLinRef>".$data[116][$ve]."</RefNroLinRef>
								<RefIndGlobal>".$data[117][$ve]."</RefIndGlobal>
								<RefTpoDocRef>".$data[118][$ve]."</RefTpoDocRef>
								<RefSerie>".$data[119][$ve]."</RefSerie>
								<RefNroCFERef>".$data[120][$ve]."</RefNroCFERef>
								<RefRazonRef>".$data[121][$ve]."</RefRazonRef>
								<RefFechaCFEref>".$data[122][$ve]."</RefFechaCFEref>
								<RefMontoRef>".(isset($data[124][$ve]) ? $data[124][$ve] : "")."</RefMontoRef>
								<RefTpoMonedaRef>".(isset($data[125][$ve]) ? $data[125][$ve] : "")."</RefTpoMonedaRef>
								<RefTipCambioRef>".(isset($data[126][$ve]) ? $data[126][$ve] : "")."</RefTipCambioRef>
							</ReferenciaItem>";
				}
				$content .="</Referencia>";
			}
			else
			{
				$content .="
						<Referencia>
							<ReferenciaItem>
								<RefNroLinRef>$data[116]</RefNroLinRef>
								<RefIndGlobal>$data[117]</RefIndGlobal>
								<RefTpoDocRef>$data[118]</RefTpoDocRef>
								<RefSerie>$data[119]</RefSerie>
								<RefNroCFERef>$data[120]</RefNroCFERef>
								<RefRazonRef>$data[121]</RefRazonRef>
								<RefFechaCFEref>$data[122]</RefFechaCFEref>
								<RefMontoRef>".(isset($data[124]) ? $data[124] : "")."</RefMontoRef>
								<RefTpoMonedaRef>".(isset($data[125]) ? $data[125] : "")."</RefTpoMonedaRef>
								<RefTipCambioRef>".(isset($data[126]) ? $data[126] : "")."</RefTipCambioRef>
							</ReferenciaItem>
						</Referencia>";
			}
		}
		else
		{
			$content .="
						<Referencia>
							<ReferenciaItem>
								<RefNroLinRef>$data[116]</RefNroLinRef>
								<RefIndGlobal>$data[117]</RefIndGlobal>
								<RefTpoDocRef>$data[118]</RefTpoDocRef>
								<RefSerie>$data[119]</RefSerie>
								<RefNroCFERef>$data[120]</RefNroCFERef>
								<RefRazonRef>$data[121]</RefRazonRef>
								<RefFechaCFEref>$data[122]</RefFechaCFEref>
								<RefMontoRef>".(isset($data[124]) ? $data[124] : "")."</RefMontoRef>
								<RefTpoMonedaRef>".(isset($data[125]) ? $data[125] : "")."</RefTpoMonedaRef>
								<RefTipCambioRef>".(isset($data[126]) ? $data[126] : "")."</RefTipCambioRef>
							</ReferenciaItem>
						</Referencia>";
		}
		//***************************//
	
	}



$content .="
            </CFEItem>
	        </CFE>";
		$clave = md5($pk.$content);
		$arr = array( 'Xmlrecepcao' => "
			<EnvioCFE>
				<Encabezado>
					<EmpCodigo>$empCod</EmpCodigo>
					<EmpPK>RUdYwvzP62niXHmI7cfPoA==</EmpPK>
					<EmpCK>$clave</EmpCK>
				</Encabezado>
				$content
			</EnvioCFE>");


/*	// QUITAR ESTAS 3 LINEAS   */

	 $file = fopen("CFE_xml.txt", "w");
     fwrite($file, $content);
     fclose($file);



		return $arr;
	}
}

?>
