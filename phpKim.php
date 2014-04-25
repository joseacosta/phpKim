<?php 

//sera asbtract class 
class KimalPHP
{
	
	public $phase;
	protected  $esperandoRespuestaInmediada = false; 
	private $STX;
	private $EXT;
	
	
	
	
	
	function __construct() 
	{ 
		$this->phase=1;
		
		$this->STX = chr(0x02);
		$this->EXT = chr(0x03);
	}
	
/*
	//ev Arranque de la electronica
	abstract protected function OnReset($data);
	
	
	//ev  Lectura de tarjeta
	abstract protected function OnReadCard($card_id);
	
	
	//ev  presion de tecla
	abstract protected function OnKeyPress($key);
	
	
	//el LED y el beeper cuando finaliza la temporizacion general el evento
	//Salidas Digitales (la electronica necesita estar bien configurada)
	abstract protected function OnTempLED($color, $time); //time??
	
	//el LED y el beeper cuando finaliza la temporizacion genera el evento
	//Salidas Digitales (la electronica necesita estar bien configurada)
	abstract protected function OnTempBeeper($beeper, $time); //time?
	
	
	//esto es cosecha propia, evento con OPC 0x40 es MUY MUY parecido arl de arriba, NECESITA AUN ser gestionado en elmetodo process_events
	//la activacion de un relay cuando finaliza la temporizacion genera el evento salidas de Relé
	abstract protected function OnTempRelay($numRelay, $time);
	
	//evento deentrada digital
	abstract protected function OnDigitalInput($status);

	//cualquier otro...comodin
	abstract protected function OnDefault($data);

*/	
	//-----------------------------------------------------
	
	
	public function OpenPortTCP ($rAddress, $remotePort=1001)
	{
		
		//crea conecta y pon a la escucha un socket con la electronica: IP:puerto
	
	}
	
	
	
	//----------------------------------------------------------
	
	public function ActivateDigitalOutput ($numout, $time)
	{
		
		//vigila que la phase/estado sea el adecuada antes de continuar
		
		
		if (numout < 0 || numout > 3 || time < 0 || time > 254)
		{
		 	echo 'numout o tiempo de digital output incorrecto';
	        return false;
		}
		
		#el 3 es un beeper interno, puedeque no acepte masde hex 0x19 decimas desegundo de pitido (25 decimas de segundo?)
		if (numout == 3 and time > hexdec("19") )
		{
			echo 'problemas con el numout o con el numout 3 y tiempo excesivo';
			return false;
		}
		
		        
		
		$data = chr(numout) + chr(time);
		
		//opc 0x30 ojo que es hex
		return $this->processCommand("30", data);
	
	}
	
	//---------------------------------------------------------------------
	
	private function processCommand($opc, $data)
	{
		//no existe aun
		$respuesta = $this->sendCommand($opc, $data);
		
		
		#aqui se genera el frame
		$elFrame = $this->createFrame($opc, $data);
		
		#en este metodo (por fin) se manda el frame por el socket
		$this->sendFrame(elFrame);
		
		//hay respuestas de comandos yeventos que comparten opc
		//podriamos estar tratando tramas como eventos sin serlo, 
		//esta variable podria en vez de un booleano contener el numero opc de trama esperada, seria quizas mas preciso
		$this->esperandoRespuestaInmediada = True;
		

	}
	
	//---------------------------------------------------------------------
	
	function createFrame($opc, $data, $argType='number')
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
		
		echo "<br>\nla longitud en hexa del data,(NA)".$length_hex;
		echo "<br>\nlse hacalculado mirando el data (ARG):".$data;
		
		$data_hex = $this->byte_to_hex ($data,$argType);
		
		echo "<br>\neste es su valor en hex:".$data_hex;
		
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
	

	
}






