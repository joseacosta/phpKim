<?php 

/**
 * Esta clase genera tramas de bytes con formato adecuado al protocolo de tramas de Kimaldi, en concreto las que se usan 
 * para comunicaciones por TCP, puede calcular los checksums adecuados y desglosar sus campos para ser analizados por otros 
 * procesos.
 * Tambien incorpora m�todos que devuelven las tramas a nivel de Byte de las instrucciones principales que acepta la placa BioMax2
 *
 * @author Jose Acosta
 */
class KimaldiFrameGenerator
{
	
	private $STX;
	private $EXT;
	
	
	
	
	function __construct() 
	{ 
		$this->STX = chr(0x02);
		$this->EXT = chr(0x03);
	}
	
	
	
	//---------------------------------------------------------------------
	
	function createFrame($opc, $data=array(), $argType='number')
	{ 
		
		
		$opc = strval(dechex($opc));
		$opc = str_pad($opc, 2 , 0 , STR_PAD_LEFT);
		
		//los datos que intervienen en crc (fuera primer y ultimo char(byte))')
		$data_crc = $this->get_data_crc($opc, $data, $argType);
		
		
		
		
		$crc =  $this->get_crc($data_crc);
		
		//echo "\n<br>crc en decimal:".$crc;
		
		//el ahora crc es un numero decimal...en formato hex, OJO en mayusculas y debe ocupar dos caracteres pad 0
		$crcHex = dechex($crc);
		$crcHex = strtoupper($crcHex);
		$crcHex = str_pad($crcHex, 2 , 0 , STR_PAD_LEFT);
		
		//echo "\n<br>crc en hexadecimal:".$crcHex;
		
		return $this->STX . $data_crc . $crcHex . $this->EXT;
		
	}
	
	
	//------------------------------------------------------------------------
	
	//tomando opc y data(NA mas argumentos) nos dice cuales seran lso caracteres, ya con valores hexa que intervendran en el crc
	//recibe byte de operacion (2char representando numero hexa) y $data qeue son los argumentos
	function get_data_crc ($operation, $data, $argType)
	{
	
		//la cadena es la operacion en si, usamos cadenas para esto aunque nos refiramos a numeros
		$str_operation = $operation;
		
		
		//este es el NA!!!
		//ajuste para que el hexadecimal se exprese con 4 char (un hexa de 16 bits, 4 cifras)
		$length_hex = dechex(count($data));
		$length_hex = str_pad($length_hex, 4 , 0 , STR_PAD_LEFT);
		
		//echo "<br>\nla longitud en hexa del data,(NA)".$length_hex;
		//echo "<br>\nlse hacalculado mirando el data (ARG):".$data;
		
		$data_hex = $this->byte_to_hex ($data,$argType);
		
		//echo "<br>\neste es su valor en hex:".$data_hex;
		
		//echo "<br>el data en hexa...".$data_hex;
		
		return strtoupper( $str_operation . $length_hex . $data_hex );
	}
	
	//-------------------------------------------------
	//esta funcion hace al final la suma y el modulo
	function get_crc ($data_crc)
	{
		$crc = 0;
		
		for($x=0; $x < strlen($data_crc); $x++)
		{
			
			$crc += ord($data_crc[$x]);
			
			
			//echo  '\n<br>caracter:'.$data_crc[$x].'tiene valor ascii: '.(ord($data_crc[$x])).' llevamos sumados:'.$crc;
		}
		
		//por fin;
		//modulo 256
		$crc = $crc%256;
		
		return $crc;
	}
	
	//------------------------------------------------------------------------------
	//informacion en bytes pasadas a caracteres que representen hexadecimales
	function byte_to_hex ($charBytes,$argType)
	{
        $data_hex = '';
        
        for($x=0; $x < count($charBytes); $x++)
        {
        	
        	if($argType == "char")
        	{	
        		$dataNuevo = dechex(  ord($charBytes[$x])  );
        	}
        	else 
        	{
        		$dataNuevo = $charBytes[$x];
        	}
        		
        	$dataNuevo = str_pad($dataNuevo, 2 , 0 , STR_PAD_LEFT);
        	
            $data_hex .= $dataNuevo;
        }
        
        return $data_hex;
        
	}
	
	//------------------------------------------------------------------------------------
	
	
	function desglosaTrama($trama)
	{
		
		$stx = substr( $trama, 0, 1 );
	
		$opc = substr( $trama, 1, 2 );
	
		$na = substr( $trama, 3, 4 );
		$valorNA = hexdec($na);
	
		$arg = substr( $trama, 7, $valorNA*2 );
	
		$crc = substr( $trama, -3, 2);
		
		$etx= substr( $trama, -1 );
	
	
		echo "\n\nSTX =".$stx;
		echo "\nOPC =".$opc;
		echo "\nNA =".$na. " valor dec =".$valorNA;
		echo "\nARG =".$arg;
		echo "\nCRC =".$crc;
		echo "\nETX =".$etx."\n\n";
		
		$tramaDesglosada = array("OPC" => $opc, "NA" => $na, "ARG" => $arg, "CRC" => $crc);
		
		return $tramaDesglosada;
		
	}
	
	//----------------------------------------------------
	
	function dameOpcTrama($trama)
	{
		$opc = substr( $trama, 1, 2 );
		return $opc;
	}
	
	//------------------------------------------------------------------------------
	
	/*
	 * 
	 * 
	 */
	
	
	//-------------------------------------------------------------------------------------
	
	
	function tramaHotReset()
	{
		$trama = $this->createFrame(0x01);
		return $trama;
	}
	
	//------------------------------------
	
	function tramaTestNodeLink()
	{
		$trama = $this->createFrame(0x00);
		return $trama;
	}
	
	//--------------------------------------
	
	function tramaActivateDigitalOutput($args)
	{
		$trama = $this->createFrame(0x30, $args);
		return $trama;
	}
	
	//--------------------------------------
	
	function tramaSwitchDigitalOutput($args)
	{
		$trama = $this->createFrame(0x31, $args);
		return $trama;
	}
	
	//--------------------------------------
	
	function tramaActivateRelay($args)
	{
		$trama = $this->createFrame(0x40, $args);
		return $trama;
	}
	
	//--------------------------------------
	
	function tramaSwitchRelay($args)
	{
		$trama = $this->createFrame(0x41, $args);
		return $trama;
	}
	
	//-----------------------------------------

	
	function tramaTxDigitalInput()
	{
		$trama = $this->createFrame(0x60);
		return $trama;
	}
	
	
	
	
}






