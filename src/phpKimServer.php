<?php

require_once 'phpKim.php';

require __DIR__.'/../vendor/autoload.php';


class phpKimServer extends React\Socket\Server
{
	
	protected $miKimal;
	protected $miLoop;
	protected $timerTimeout;
	protected $conns;
	protected $conexElectronica;
	
	protected $opcColectEvent;
	
	protected $codColectMethodReturn;
	
	protected  $conClienteActivoBloqueaRespuesta;
	
	protected $timeoutConnElectronica = 3;
	protected $timeoutNodeTimeOut = 3;
	
	protected $peticionesEsperanRespuesta;
	
	public $dirIPElectronica;
	public $puertoElectronica;
	
	//public $ipLocal = '192.168.0.145';
	public $ipLocal = '127.0.0.1';
	public $puertoEscucha = 12000;
	
	
	public $electronicaConectada = false;
	
	
	
	
	
	//#########CONSTRUCTOR
	public function __construct() 
	{
		$this->miLoop = React\EventLoop\Factory::create();
		
		
		//constructor del padre 
		parent::__construct($this->miLoop);
		
		$this->opcColectEvent=array("81" => "procesaOnTrack",
							        "80" => "procesaOnKey",
							        "60" => "procesaOnDigitalInput");
		
		
		$this->codColectMethodReturn=array("EJECUCION_OK" => 0,
											"NO_CANAL_COM_ELECTRONICA" => 1,
				                            "NO_SETUP_ELECTRONICA" => 2,
											"OPC_RECHAZADO_ELECTRONICA" => 3,
											"VALOR_PARAM_INCORRECTO" => 6,
											"PUERTO_COM_YA_ABIERTO" => 251,
											"ERR_APERTURA_PUERTO_COM" => 253);
									
		
		//INSTANCIA de clase generadora de tramas protocolo Kimaldi
		$this->miKimal = new KimalPHP();
		
		$this->conns = new \SplObjectStorage();
		

	}

	//--------------------------------------------------------------------------
	
	//el puerto de la electronica es por defecto el 1001
	public function OpenPortTCP($dirIPElectronica, $puertoElectronica = "1001")
	{	
			
		$this->dirIPElectronica = $dirIPElectronica;
		$this->puertoElectronica = $puertoElectronica;
		
		//stream que conecta con la electronica, ojo al cuarto parametro que es el timeout para conectar
		$clientStreamElectronica = stream_socket_client("tcp://".$this->dirIPElectronica.":".$this->puertoElectronica, $errno, $errorMessage, $this->timeoutConnElectronica );
		
		//un timeout para lectura  cuando hacemos bloqueos leyendo uan respuesta de ella
		stream_set_timeout($clientStreamElectronica, $this->timeoutNodeTimeOut);
		
		if ($clientStreamElectronica === false)
		{
			throw new UnexpectedValueException("Problema en la conexion con electronica, dir tcp://".$this->dirIPElectronica.":".$this->puertoElectronica."; msg: ".$errorMessage." errno: ".$errno);
		}
		
		//objeto react/connection que contiene el stream que conecto con la electronica
		//notese que le pasamos el mismo loop que para todas las conexiones, posibilita escuchar sus eventos derecepcion
		$this->conexElectronica = new React\Socket\Connection($clientStreamElectronica, $this->miLoop);
		
		echo "\n\nConexion establecida con la electronica";
		return true;
		
	}
	
	//--------------------------------------------------------------------------
	
	public function ClosePort()
	{
		$this->conexElectronica->close();
		
		$this->conexElectronica = null;
		
		echo "\n\nfuncion closePort";
	}
	

	//---------------------------------------------------------------------------
	
	
	protected function iniciaEventos()
	{
		
	
		//manejador para evento on data de la conexion de electronica
		$this->conexElectronica->on('data', [$this,'onDataElectronica']);
		//manejador para evento on end de la conexion de electronica
		$this->conexElectronica->on('end', [$this, 'onFinalizacionElectronica']);
		$this->conexElectronica->on('close', [$this, 'onFinalizacionElectronica']);
		
		
		//manejador de evento del servidor en general, basicamente, evento de nueva conexion entrante
		$this->on('connection', [$this,'onConexionEntrante']);
		
		
	}
	
	//--------------------------------------------------------------------------
	
	protected function onDataElectronica($data)
	{
		echo "\ntrama de electronica recibida con data: ".$data."\n\n";
		
		$desgloseTrama = array();
		
		
		//la electronica manda periodicamente una ack, ASCII 6
		if (ord($data) != 6)
		{
			$desgloseTrama = $this->miKimal->desglosaTrama($data);
			$this->evaluaEvento( $desgloseTrama["OPC"] , $desgloseTrama["ARG"]);
		} 
		
		$mensaje_electronica = $this->mask(json_encode(array('tipo'=>'userMsg', 'name'=>'Ev', 'message'=>$data, 'color'=>'black')));
		$this->mandarTodosUsuarios($mensaje_electronica); //send data
	}
	
	//--------------------------------------------------------------------------
	
	protected function onFinalizacionElectronica($conn)
	{
		echo "\n\ndesconectada electronica, IP:".$conn->getRemoteAddress();
	
		//TODO muy posiblemente aqui habra que manejar un evento emulando la API de Kimaldi_Net
		
		//$this->miLoop->stop();
		
		//fuera referencia
		$this->conexElectronica = null;
	}
	
	
	//--------------------------------------------------------------------------
	
	
	protected function onConexionEntrante($connCliente)
	{
	
		$this->conns->attach($connCliente);
	
		echo "\nnueva conexion entrante.....\n";
	
		//a la nueva conexion hay que darle sus manejadores de eventos
		$connCliente->on('data',  [$this, 'onDataCliente']);
		$connCliente->on('end',  [$this, 'onFinalizacionCliente']);
	
	}
		
	//--------------------------------------------------------------------------
	
	
	protected function onDataCliente($data, $connCliente)
	{
					//se guarda aqui el cliente que manda algo, si se produce (o no) una lectura de respuesta (se lee bloqueando)
				    //se tendra registrado que conexion de cliente debe recibirla (en este punto no hay concurrencia).
					//TODO evaluar si esta es la linea mas conveniente para hacerlo, si es necesario des-setearlo (=null) en algun momento            
					$this->conClienteActivoBloqueaRespuesta = $connCliente;
		
					//al conectar un cliente, este evento ya salta, vamosa ver si esta mandando peticion de conexion websocket
					if($this->mensajeEsDeApertura($data))
					{
											
							echo "\nparece ser cabecera de apertura websocket\n\n";
											
							$this->perform_handshaking($data, $connCliente, $this->ipLocal);
											
							//handshake completado, no seguimos
							return;
											
					}
							
					echo $connCliente->getRemoteAddress().": escribio trama WebSocket RAW:\n".$data."\n\n";
							

					$received_text = $this->unmask($data); //unmask data
										
							
					echo $connCliente->getRemoteAddress().": escribio trama WebSocket desenmascarada:\n".$received_text."\n\n";
							
							
					$tst_msg = json_decode($received_text); //json decode
							
					//TODO aqui busca en la variable $received_text un cliente que se va cerrando el socket manda los caracteres ascii
					//"♥Ú" por lo tanto ya no deberiamos seguir, se que el primer caracter es ascii 3 del segundo no se
					//algo asi... if (ord($received_text[0]) == 3 && ord($received_text[1]) == ?)
					
					
					
					//el usuario manda un frame codificado como json
					if(isset($tst_msg->tipo) && $tst_msg->tipo == 'frame')
					{
							
						$opc = $tst_msg->opc;
						$argumentos = $tst_msg->arg;
						$tipoArgumento = $tst_msg->argType;
							
						echo "\nusuario manda comando, opc:#".dechex($opc)."#, numArg:#".count($argumentos)."#\n";
							
											
						if($tipoArgumento == "char")
						{
							//la coleccion de argumentos la tratamos como array, si
							//el tipo es char es que estamos recibiendo una cadena y no se trata exactamente igual
							//la convertimos a array de caracteres
							$argumentos = str_split ($argumentos);
						}
							
							
						$trama = $this->miKimal->createFrame($opc, $argumentos, $tipoArgumento);
							
							
						echo "\n trama generada #".$trama."#\n";
							
						
							
						$this->manda_comando_electronica($trama);
							
							
							
					}
					//el usuario manda funcion para ser llamada por su nombre y array con argumentos
					elseif (isset($tst_msg->tipo) && $tst_msg->tipo == 'func')
					{
						$funcName = $tst_msg->funcName;
						$argList = $tst_msg->args;
						
						if ( method_exists($this, $funcName) )
						{	
						    //**************************
							call_user_func_array(array($this, $funcName), $argList);
						}
						else
						{
							echo "\nel cliente con ip: ".$connCliente->getRemoteAddress()." realiza llamada al metodo del servidor con nombre\"".$funcName."\" funcion INEXISTENTE\n";
						}
						
					}
					elseif (isset($tst_msg->tipo) && $tst_msg->tipo == 'userMsg')//el usuario manda mensage normal (es solo texto plano)
					{
						$ipRemitente =	$connCliente->getRemoteAddress();
											
						$user_name = $tst_msg->name; //sender name
						$user_message = $tst_msg->message; //message text
						$user_color = $tst_msg->color; //color
							
						$response_text = $this->mask(json_encode(array('tipo'=>'userMsg', 'name'=>$ipRemitente, 'message'=>$user_message, 'color'=>$user_color)));
							
											
						$this->mandarTodosUsuarios($response_text, $ipRemitente);
							
					}
					else 
					{
						echo "\nel cliente con ip: ".$connCliente->getRemoteAddress()." envio trama JSON sin formato adecuado (campo tipo)\n";
					}
					
					
	}
	
	
	
	//--------------------------------------------------------------------------
	

	protected function onFinalizacionCliente($connCli)
	{
		echo "desconectado cliente WS con IP:".$connCli->getRemoteAddress();
	
		//fuera de la lista de conexiones de cliente
		$this->conns->detach($connCli);
	}
	
	
	
	//--------------------------------------------------------------------------
	
	public function Run()
	{
		
		//llamamos a nuestra funcion de inicio de eventos
		$this->iniciaEventos();
		
		//ojito que si no le pones ip del servidor en el que estamos como segundo parametro, SOLO funcionara en local
		$this->listen($this->puertoEscucha, $this->ipLocal); 
		
		return $this->miLoop->run();

	}
	
	//-------------------------------------------*****************
	
	function broadcastClientFunction($nombreFunc, $argList)
	{
		
		$mensaje_broadcast_clientes = $this->mask(json_encode(array('tipo'=>'func', 'funcName'=>$nombreFunc, 'args'=>$argList )));
		$this->mandarTodosUsuarios($mensaje_broadcast_clientes);

	}
	
	//-------------------------------------------*****************
	
	function responseClientFunction($nombreFunc, $argList=array(), $ipClienteConcreto=null)
	{
		//preparamos JSON para llmar a func de cliente
		$mensaje_response_cliente = $this->mask(json_encode(array('tipo'=>'func', 'funcName'=>$nombreFunc, 'args'=>$argList )));
		
		
		if($ipClienteConcreto != null)
		{
			//TODO implementar funcion que busque entre las conexionse de cliente uno con la ip buscada (FACIL)
		    //$cliente = $this->dameClientePorIP($ipClienteConcreto);
		    //$cliente->write($mensaje_response_cliente);
		}
		elseif($this->conClienteActivoBloqueaRespuesta == null)
		{
			echo "\n no es posible responder a cliente que ocasiono bloqueo, no definido\n";
			return;
		}
		else 
		{
			echo "\n response con valor".$mensaje_response_cliente."\n al cliente ".$this->conClienteActivoBloqueaRespuesta->getRemoteAddress()."\n";
			$this->conClienteActivoBloqueaRespuesta->write($mensaje_response_cliente);
		}
		
		
	}
	
	
	//---------------------------------------------------------------------------
	
	function mandarTodosUsuarios($msg) 
	{
		
		
		foreach ($this->conns as $conUsu)
		{
				
			$conUsu->write($msg);
	
		}
		
	}
	
	//--------------------------------------------------------------------------
	function mensajeEsDeApertura($mensaje)
	{
		
		if( strpos( $mensaje, "Upgrade: websocket") > -1  )
		{
			return true;
		}
		else
		{
			return false;
		}
		
	}
	//--------------------------------------------------------------------------
	
	function manda_comando_electronica($trama, $opcRespuestaEsperado=null)
	{
		


		if ($this->conexElectronica == null)
		{
			//TODO La funcion que haya terminado llegando aqui (ej HotReset)
			//debe acabar recibiendo y devolviendo (ambas) el valor 1 (=no se ha establecido canal de comunicacion)
			
			echo "\n\n ERROR el socket con la electronica NO se encuentra abierto, no e sposible mandar tramas\n";
			return;
		}
		
		//sacamos el stream con el que hicimos, le obje conexion , estaria bien que fuera una porpiedad de clase
		$streamElectronica = $this->conexElectronica->stream;
		
		
		
		stream_set_read_buffer($streamElectronica, 0);
		stream_set_write_buffer($streamElectronica, 0);
		
		
		//el metodo write del objeto COnnection nunca mandara mientras se quiera leer justo despues con bloqueo, 
		//quizas use una especie de buffer, jugar set_stream_blocking() no resulta
		//$this->conexElectronica->write($trama);
		
		//hay que sustituirla por el fwrite al stream directamenre
		fwrite($streamElectronica, $trama);
		
		echo "\nMandada trama electronica, se procede a bloqueo esperando lectura...:";
		
		
		//en estas condiciones la lectura del stream bloquea que es lo que queremos
		$bufer = fread($streamElectronica, 4096);
		
		//ojo al array meta,a este stream al crearse EN OpenTCPPort se le puso un timeout de escritura
		$meta = stream_get_meta_data($streamElectronica);
		
		//*****************aqui se lanzaria el evento timeout se definiria en la clase heredera aqui habria un "procesaTimeOut()"
		if ($meta['timed_out'])
		{
			echo "\n\nTIMEOUT\n\n";
			$mensaje_electronica = $this->mask(json_encode(array('tipo'=>'userMsg', 'name'=>'Event', 'message'=>"TIMEOUT", 'color'=>'black')));
		
			//manejar la conn de cliente activo en cada momento es una decision importante, no deja de ser un solo hilo, y una iteracion del loop
			//estamso aqui porque un cliente acabo llamando a esta funcion indirectamente, en el punto de entrada se ha guardado tal conexion
			$this->conClienteActivoBloqueaRespuesta->write($mensaje_electronica);
			
			//por devolver algo, estamos en timeout
			return false;
		}
		else 
		{
			//***************** pues bien hay lectura post-bloqueo
			//aqui se deberian ir mandando los eventos Ans... definirian en la clase heredera aqui habria un "procesaAns...()", antes, esta funcion propagara el valor 0
			//a la funcion que la haya llamado
			//si no se recibe ans con el opc esperado (un evento se entromete, puede ocurrir) ( p ej opc FF o FE) deberiamos devolver codigos numericos diferentes de 0
			echo "\nlectura post-bloqueo!!!#".$bufer."#\n\n\n";
			$mensaje_electronica = $this->mask(json_encode(array('tipo'=>'userMsg', 'name'=>'AnsPers', 'message'=>$bufer, 'color'=>'black')));
			$this->conClienteActivoBloqueaRespuesta->write($mensaje_electronica);
			//******************* lo dicho arriba, vigila lo que devuelves, de momento devolvere un 0, todo OK
			return $this->codColectMethodReturn["EJECUCION_OK"];
		}
		
		
		
	
	}
	
	
	//--------------------------------------------------------------------------
	//desenmascara la trama websoket recibida
	function unmask($text)
	{
		$length = ord($text[1]) & 127;
		if($length == 126) {
			$masks = substr($text, 4, 4);
			$data = substr($text, 8);
		}
		elseif($length == 127) {
			$masks = substr($text, 10, 4);
			$data = substr($text, 14);
		}
		else {
			$masks = substr($text, 2, 4);
			$data = substr($text, 6);
		}
		$text = "";
		for ($i = 0; $i < strlen($data); ++$i) {
			$text .= $data[$i] ^ $masks[$i%4];
		}
		return $text;
	}
	
	
	//--------------------------------------------------------------------------
	//codifica mensaje en formato de trama de websocket para mandarla a lsos clientes browser
	function mask($text)
	{
		$b1 = 0x80 | (0x1 & 0x0f);
		$length = strlen($text);
	
		if($length <= 125)
			$header = pack('CC', $b1, $length);
		elseif($length > 125 && $length < 65536)
		$header = pack('CCn', $b1, 126, $length);
		elseif($length >= 65536)
		$header = pack('CCNN', $b1, 127, $length);
		return $header.$text;
	}
	
	//--------------------------------------------------------------------------
	//completa el handshakeusando HTTP con el objeto conexion dado y la cabecera request recibida
	function perform_handshaking($receved_header,$conn, $iphost)
	{
		$headers = array();
		
		$lines = preg_split("/\r\n/", $receved_header);
		
		foreach($lines as $line)
		{
			$line = chop($line);
			if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
			{
				$headers[$matches[1]] = $matches[2];
			}
		}
	
		$secKey = $headers['Sec-WebSocket-Key'];
		
		//la cadena que vemos arriba es una cadena mÃ¡gica
		$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
		//hand shaking header
		$upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
				"Upgrade: websocket\r\n" .
				"Connection: Upgrade\r\n" .
				"WebSocket-Origin: $iphost\r\n" .
				"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
		
		$conn->write($upgrade);
	}
	
	//-------------------------------------------------------------------
	
	function evaluaEvento($opc, $arg)
	{
		
		if( array_key_exists($opc, $this->opcColectEvent) )
		{
			//llamamos al metodo que tenga asociado el opc esta definido en el array $this->opcColectEvent
			//con la siguiente estructura $this->opcColectEvent cadenaOpc => cadenaNombreMetodo
			
			//$this->{$this->opcColectEvent[$opc]}($arg);
			
			$nombreFuncion = $this->opcColectEvent[$opc];
			
			//alternativamente para pasar parametros como array...
			//call_user_func_array(array($this, "unaFuncion"), array("hola", "caracola"));
			//**************************
			call_user_func(array($this, $nombreFuncion), $arg);
		}
		else 
		{
			echo "\nOPC \"".$opc."\" no incluido en lista de eventos reconocidos\n";
		}
		
	}
	
	
	
	//------------------------------------------------
	/*
	 * 
	 * METOODOS EMULANDO CLASE BIONET
	 * 
	 * 
	 */
	//-------------------------------------------------
	
	
	public function HotReset()
	{
		$trama = $this->miKimal->tramaHotReset();
		echo "\n enviando trama HotReset: ".$trama;
		//******************hacer el ->write directamente hace uqe perdamos TOoDO el control, usa la func manda_comando_electronica
		//hazlo con todas las funciones de abajo, y claro propaga loq ue devuelva manda_comado_electronica() return...
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	//--------------------------------
	
	public function TestNodeLink()
	{
		$trama = $this->miKimal->tramaTestNodeLink();
		echo "\n enviando trama TestNodeLink: ".$trama;
	
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	
	//-------------------------------------
	
	public function ActivateDigitalOutput($numOut, $tTime)
	{
		$trama = $this->miKimal->tramaActivateDigitalOutput( array($numOut, $tTime) );
		echo "\n enviando trama ActivateDigitalOutput: ".$trama;
		
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	//--------------------------------
	
	public function SwitchDigitalOutput($numOut, $mode)
	{
		//pordefecto hex 0x00, valor false
		$hexMode= 0x00;
		
		if($mode)
		{
			$hexMode = 0x01;
		}
		
		$trama = $this->miKimal->tramaSwitchDigitalOutput( array($numOut, $hexMode) );
		echo "\n enviando trama SwitchDigitalOutput: ".$trama;
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	

	//--------------------------------
	
	public function ActivateRelay($numRelay, $tTime)
	{
		$trama = $this->miKimal->tramaActivateRelay( array($numRelay, $tTime) );
		echo "\n enviando trama ActivateRelay: ".$trama;
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	//--------------------------------
	
	public function SwitchRelay($numRelay, $mode)
	{
		//pordefecto hex 0x00, valor false
		$hexMode= 0x00;
	
		if($mode)
		{
			$hexMode = 0x01;
		}
	
		$trama = $this->miKimal->tramaSwitchRelay( array($numRelay, $hexMode) );
		echo "\n enviando trama SwitchRelay: ".$trama;
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	//------------------------------------
	
	public function TxDigitalInput()
	{
		$trama = $this->miKimal->tramaTxDigitalInput();
		echo "\n enviando trama TxDigitalInput: ".$trama;
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	
	//------------------------------------------------
	/*
	 *
	 * EVENTOS EMULANDO CLASE BioNet
	 *
	 *
	 */
	//-------------------------------------------------
	
	//implementar OnKey en la clase que hereda
	private function procesaOnKey($arg)
	{
		$valorCaracter = hexdec( $arg );
		$key = chr($valorCaracter);
		
		if ( method_exists($this, "OnKey") )
			$this->OnKey($key);
		else
			echo "metodo OnKey lanzado, pero no esta definido ni implementado\n";
	}
	
	//-----------------------------------------------------
	
	
	//implementar OnTrack en la clase que hereda
	private function procesaOnTrack($arg)
	{
		$track="";
		
		//notese el incremento de dos en dos
		for($x=0 ; $x<strlen($arg) ; $x=$x+2 )
		{
			$valorCaracter = hexdec( substr($arg, $x, 2) );
			$track .= chr($valorCaracter);
		} 
		
		if ( method_exists($this, "OnTrack") )
			$this->OnTrack($track);
		else 
			echo "metodo OnTrack lanzado, pero no esta definido ni implementado\n";
		
	}
	
	
	//---------------------------------------------------------
	
	
	//implementar OnTrack en la clase que hereda
	private function procesaOnDigitalInput($arg)
	{
		if ( method_exists($this, "OnDigitalInput") )
			$this->OnTrack($arg);
		else
			echo "metodo OnDigitalInput lanzado, pero no esta definido ni implementado\n";
	}

}