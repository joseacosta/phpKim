<?php

require_once 'Configuracion.php';
require_once 'KimalPHP.php';
require_once 'WsServerManager.php';

require __DIR__.'/../vendor/autoload.php';




class phpKimServer extends React\Socket\Server
{
	//TODO manejar el envio opcional de mensajes debug
	protected $debug = true;
	protected $debugToClient = true;
	
	
	protected $miWsManager;
	protected $miKimal;
	protected $miLoop;

	protected $conns;
	protected $conexElectronica;
	
	//opcs que determinan tramas de eventos, cada uno ira asociado a un handler
	protected $opcColectEvent;
	
	//coleccion con numeros de devolucion de funciones al estilo de las librerias Kimaldi_Net, con esto evitaremos usar numeros magicos
	protected $codColectMethodReturn;
	
	//almacena el cliente que mando el ultimo mensaje al servidor y que, eventualmente producira un bloqueo en espera de respuesta
	protected $conClienteActivoBloqueaRespuesta;
	
	//tiempo de espera maximo para establecer conexion con la electronica
	protected $timeoutConnElectronica;
	
	//tiempo de espera maximo para recibir una respuesta esperada de la electronica, una vexz cumplido se dispara el evento NodeTimeOut
	protected $timeoutNodeTimeOut;
	
	//parametros de ip y puerto de la electronica, config de electronica por defecto para TCP = 192.168.123.10:1001
	public $dirIPElectronica;
	public $puertoElectronica;
	
	//parametros de ip y puerto del servidor
	public $ipLocal;
	public $puertoEscucha;
	
	
	public $electronicaConectada = false;
	
	
	
	
	
	//#########CONSTRUCTOR
	public function __construct() 
	{
		
		$this->miLoop = React\EventLoop\Factory::create();
		
		
		//constructor del padre 
		parent::__construct($this->miLoop);
		
		
		
		$this->ipLocal = Configuracion::$ipLocalServer;
		$this->puertoEscucha = Configuracion::$puertoEscuchaServer;
		$this->timeoutConnElectronica = Configuracion::$timeoutConnElectronica;
		$this->timeoutNodeTimeOut = Configuracion::$timeoutNodeTimeOut;
		
		
		$this->opcColectEvent=array("81" => "procesaOnTrack",
							        "80" => "procesaOnKey",
							        "60" => "procesaOnDigitalInput",
									"30" => "desambiguacionDigitalOutput",
									"40" => "desambiguacionRelay",
									"01" => "procesaAnsHotReset",
									"00" => "procesaAnsTestNodeLink",
									"31" => "procesaAnsSwitchDigitalOutput",
									"41" => "procesaAnsSwitchRelay",
									"11" => "procesaAnsWriteDisplay");
		
		
		$this->codColectMethodReturn=array("EJECUCION_OK" => 0,
											"NO_CANAL_COM_ELECTRONICA" => 1,
				                            "NO_SETUP_ELECTRONICA" => 2,
											"OPC_RECHAZADO_ELECTRONICA" => 3,
											"VALOR_PARAM_INCORRECTO" => 6,
											"PUERTO_COM_YA_ABIERTO" => 251,
											"ERR_APERTURA_PUERTO_COM" => 253);
									
		
		//INSTANCIA de clase generadora de tramas con protocolo Kimaldi
		$this->miKimal = new KimalPHP();
		
		//INSTANCIA de clase generadora de tramas con protocolo Kimaldi
		$this->miWsManager = new WsServerManager();
	
		
		$this->conns = new \SplObjectStorage();
		

	}

	//--------------------------------------------------------------------------
	
	//el puerto de la electronica es por defecto el 1001
	public function OpenPortTCP($dirIPElectronica, $puertoElectronica = "1001")
	{	
		//la conexion con la electronica ya esta abierta, debe cerrarse primero la conexion
		if($this->conexElectronica != null)
		{
			return $this->codColectMethodReturn["PUERTO_COM_YA_ABIERTO"];
		}
		
		$this->dirIPElectronica = $dirIPElectronica;
		$this->puertoElectronica = $puertoElectronica;
		
		
		echo "\n\nConectando con electronica tcp://".$this->dirIPElectronica.":".$this->puertoElectronica.".......";
		
		//stream que conecta con la electronica, ojo al cuarto parametro que es el timeout para conectar
		$clientStreamElectronica = stream_socket_client("tcp://".$this->dirIPElectronica.":".$this->puertoElectronica, $errno, $errorMessage, $this->timeoutConnElectronica );
		
		//un timeout para lectura  cuando hacemos bloqueos leyendo uan respuesta de ella
		//esta funcion produce BLOQUEO
		stream_set_timeout($clientStreamElectronica, $this->timeoutNodeTimeOut);
		
		//TODO,que tal si aqui lanzamos un evento como el nodeTimeOut pero que sea de establecimiento y no de tiempo de respuesta superado
		if ($clientStreamElectronica === false)
		{
			//devolvemos codigo de error de apertura de comunicacion
			return $this->codColectMethodReturn["ERR_APERTURA_PUERTO_COM"];
		}
		
		//objeto react/connection que contiene el stream que conecto con la electronica
		//notese que le pasamos el mismo loop que para todas las conexiones, posibilita escuchar sus eventos derecepcion
		$this->conexElectronica = new React\Socket\Connection($clientStreamElectronica, $this->miLoop);
		
		echo "\n\nConexion establecida con la electronica!!!";
		
		return $this->codColectMethodReturn["EJECUCION_OK"];
		
	}
	
	//--------------------------------------------------------------------------
	
	public function ClosePort()
	{
		echo "\n\nEjecutando funcion closePort";
		
		if($this->conexElectronica == null)
		{
			echo "\n\nERROR se intento cerrar el socket con la electronica pero este NO se encuentra abierto\n";
			return $this->codColectMethodReturn["NO_CANAL_COM_ELECTRONICA"];
		}
		
		$this->conexElectronica->close();
		
		$this->conexElectronica = null;

		return $this->codColectMethodReturn["EJECUCION_OK"];
	}
	

	//---------------------------------------------------------------------------
	
	
	protected function inicializaEventos()
	{
		//manejador de evento del servidor en general, basicamente, evento de nueva conexion entrante
		$this->on('connection', [$this,'onConexionEntrante']);
		
		
		if ($this->conexElectronica == null)
		{
			echo "\n\n ERROR el socket con la electronica NO se encuentra abierto, no es posible inicializar sus eventos\n";
			return;
		}
	
		//manejador para evento on data de la conexion de electronica
		$this->conexElectronica->on('data', [$this,'onDataElectronica']);
		$this->conexElectronica->on('error', [$this, 'onErrorConexionElectronica']);
		$this->conexElectronica->on('close', [$this, 'onFinalizacionElectronica']);
		
		//$this->conexElectronica->on('end', [$this,'onFinalizacionElectronica']);
		
			
		
	}
	
	//--------------------------------------------------------------------------
	
	protected function onDataElectronica($data)
	{
		echo "\nTrama de electronica recibida con data: ".$data."\n\n";
		
		$desgloseTrama = array();
		
		
		//la electronica manda periodicamente una ack, ASCII 6
		if (ord($data) != 6)
		{
			$desgloseTrama = $this->miKimal->desglosaTrama($data);
			$this->evaluaEvento( $desgloseTrama["OPC"] , $desgloseTrama["ARG"]);
		} 
		
		$mensaje_electronica = $this->miWsManager->mask(json_encode(array('tipo'=>'userMsg', 'name'=>'Ev', 'message'=>$data, 'color'=>'black')));
		
		$this->mandarTodosUsuarios($mensaje_electronica); //send data
	}
	
	//--------------------------------------------------------------------------
	
	protected function onFinalizacionElectronica($conn)
	{
		echo "\n\ndesconectada electronica, IP:".$conn->getRemoteAddress()."\n\n";
	
		$this->procesaTCPClose();
		
		//la vida puede seguir...
		//$this->miLoop->stop();
		
		//fuera referencia
		$this->conexElectronica = null;
	}
	
	
	//--------------------------------------------------------------------------
	
	protected function onErrorConexionElectronica($error)
	{
		echo "\n\nError TCP:";
		var_dump($error);
	
		echo "\n\n";
		
		$this->procesaTCPError($error);
	
		//fuera referencia
		$this->conexElectronica = null;
	}
	
	//--------------------------------------------------------------------------
	
	
	protected function onConexionEntrante($connCliente)
	{
	
		$this->conns->attach($connCliente);
	
		echo "\nNueva conexion entrante cliente@".$connCliente->getRemoteAddress().".....\n";
	
		//a la nueva conexion hay que darle sus manejadores de eventos
		$connCliente->on('data',  [$this, 'onDataCliente']);
		$connCliente->on('end',  [$this, 'onFinalizacionCliente']);
	
	}
		
	//--------------------------------------------------------------------------
	
	
	protected function onDataCliente($data, $connCliente)
	{
					//se guarda aqui el cliente que manda algo, dado que al final el I/O acaba en un unico proceso, aqui van todo sen orden y en fila
					//siempre que se quiera responder solo al cliente conreto se podra si se produce (o no) una lectura de respuesta (se lee bloqueando)
				    //se consigue asi sincronica en la comunicacion con la electronica (gracias al bloqueo)
					//TODO evaluar si esta es la linea mas conveniente para hacerlo, si es necesario des-setearlo (=null) en algun momento            
					$this->conClienteActivoBloqueaRespuesta = $connCliente;
		
					//al conectar un cliente, este evento ya salta, vamosa ver si esta mandando peticion de conexion websocket
					if($this->miWsManager->mensajeEsDeApertura($data))
					{			
							echo "\nMensaje parece ser cabecera de apertura websocket\n\n";
											
							$this->miWsManager->perform_handshaking($data, $connCliente, $this->ipLocal);

							echo "\nCompletado HandShake cliente@".$connCliente->getRemoteAddress()."\n";
							
							//handshake completado, no seguimos
							return;				
					}
					
					//---
							
					//echo "Cliente@".$connCliente->getRemoteAddress().": escribio trama WebSocket RAW:\n".$data."\n\n";
							

					$received_text = $this->miWsManager->unmask($data); 
										
							
					echo "\nCliente@".$connCliente->getRemoteAddress().": escribio trama WebSocket valor desenmascarado:\n".$received_text."\n\n";
							
							
					$tst_msg = json_decode($received_text); //json decode
							
					//TODO aqui busca en la variable $received_text un cliente que se va (cerrando el socket) manda los caracteres ascii
					//03 seguido de algún otro por lo tanto ya no deberiamos seguir, se que el primer caracter es ascii 3 del segundo no se
					//algo asi... if (ord($received_text[0]) == 3 && ord($received_text[1]) == ?)
					
					
					
					//el usuario manda un frame codificado como json
					if(isset($tst_msg->tipo) && ($tst_msg->tipo == 'frame' || $tst_msg->tipo == 'cmd'))
					{
							
						$opc = $tst_msg->opc;
						$argumentos = $tst_msg->arg;
						$tipoArgumento = $tst_msg->argType;
							
						echo "\nusuario manda comando, opc:#".dechex($opc)."#, numArg:#".count($argumentos)."#\n";
							
											
						if($tipoArgumento == "char")
						{
							//la coleccion de argumentos la tratamos en nuestra clase generadora de tramas como array, si
							//el tipo es char es que estamos recibiendo una cadena y no se trata exactamente igual
							//la convertimos a array de caracteres
							$argumentos = str_split ($argumentos);
						}
							
							
						$trama = $this->miKimal->createFrame($opc, $argumentos, $tipoArgumento);
							
							
						echo "\n trama generada #".$trama."#\n";
							
						
							
						$this->manda_comando_electronica($trama);
							
							
							
					}
					//el usuario manda funcion para ser llamada por su nombre aqui en el servidor y un array con argumentos
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
							
						$response_text = $this->miWsManager->mask(json_encode(array('tipo'=>'userMsg', 'name'=>$ipRemitente, 'message'=>$user_message, 'color'=>$user_color)));
							
											
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
		echo "\nDesconectado cliente WS con IP:".$connCli->getRemoteAddress()."\n";
	
		//fuera de la lista de conexiones de cliente
		$this->conns->detach($connCli);
	}
	
	
	
	
	//-------------------------------------------*****************
	
	function broadcastClientFunction($nombreFunc, $argList)
	{
		
		$mensaje_broadcast_clientes = $this->miWsManager->mask(json_encode(array('tipo'=>'func', 'funcName'=>$nombreFunc, 'args'=>$argList, 'server'=>$this->ipLocal )));
		
		echo "\nBroadcast todos los clientes, datos con valor".$mensaje_response_cliente."\n al cliente ".$ipClienteConcreto."\n";
			
		$this->mandarTodosUsuarios($mensaje_broadcast_clientes);

	}
	
	//-------------------------------------------*****************
	/*Responde, de forma exclusiva, al ultimo cliente que mando una instruccion, cualquiera que sea, al servidor
	le transmite el nombre de una funcion callback que se ejecutara en el propio cliente
	si se usa para manejo de eventos de respuesta, (tipo Ans) como respuesta a tramas que manda la electronica como respuesta a una recibida
	No hay riesgo de concurrencia, por que la respuesta de la electronica a una trama, la espera cada cliente ocasionando un bloqueo,
	si se usa en otro contexto, p. ej. Un evento emitido de forma espontanea desde la electronica, el mensaje sera transmitido al ultimo cliente del que se recibio alguna orden
	alternativamente se le puede dar como argumento la ip del cliente concreto al que deseamos responder
	*/
	function responseClientFunction($nombreFunc, $argList=array(), $ipClienteConcreto=null)
	{
		//preparamos JSON para llmar a func de cliente
		$mensaje_response_cliente = $this->miWsManager->mask(json_encode(array('tipo'=>'func', 'funcName'=>$nombreFunc, 'args'=>$argList, 'server'=>$this->ipLocal )));
		
		
		if($ipClienteConcreto != null)
		{
			foreach ($this->conns as $conUsu)
			{	
				if($conUsu->getRemoteAddress() == $ipClienteConcreto)
				{
					echo "\n response a IP cliente concreto, datos con valor".$mensaje_response_cliente."\n al cliente ".$ipClienteConcreto."\n";
					$conUsu->write($mensaje_response_cliente);
					
					return;
				}
				echo "\nSe busco cliente con IP ".$ipClienteConcreto.", pero no se encuentra registrado\n";
			}
		}
		elseif($this->conClienteActivoBloqueaRespuesta == null)
		{
			echo "\nNo es posible responder a cliente que ocasiono bloqueo, no esta definido\n";
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
	//Procesa el envio de una trama a la electronica bloquea esperando y evaluando la respuesta
	//controla cosas como cual el cliente actual que espera respuesta, emite eventos de error de trama y NodeTimeOut, ademas, las respuestas
	//recibidas se propagan luego como cualquier otro evento (son los llamados eventos de respuesta prefijo "Ans")
	function manda_comando_electronica($trama, $opcRespuestaEsperado=null)
	{
		
		if ($this->conexElectronica == null)
		{
			echo "\n\n ERROR el socket con la electronica NO se encuentra abierto, no es posible mandar tramas\n";
			return $this->codColectMethodReturn["NO_CANAL_COM_ELECTRONICA"];
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
		
		
		//en estas condiciones la lectura del stream PRODUCE BLOQUEO que es lo que queremos
		$bufer = fread($streamElectronica, 4096);
		
		//ojo al array meta,a este stream al crearse EN OpenTCPPort se le puso un timeout de escritura
		$meta = stream_get_meta_data($streamElectronica);
		
		//*****************aqui se lanzaria el evento timeout se definiria e implementara en la clase heredera aqui habria un "procesaTimeOut()"
		if ($meta['timed_out'])
		{
			echo "\n\nTIMEOUT\n\n";
			
			//esto es simplemente para el monitor del cliente, se manda solo al que inicio este proceso
			$mensaje_debug= $this->miWsManager->mask(json_encode(array('tipo'=>'debugMsg', 'server'=>$this->ipLocal, 'message'=>" NodeTimeOut")));
			$this->conClienteActivoBloqueaRespuesta->write($mensaje_debug);
			
			//kimaldi_net no parece tener ningun codigo de devolucion para esto, comprobado que acaba devolviendo 0 (func ok)
			//pero va a saltar un evento de error que podra ir implemnetado en la clase heredera, aqui solo se maneja
			$this->procesaNodeTimeOut();
		}
		else 
		{
			//***************** TENEMOS lectura post-bloqueo
			//aqui se deberian ir mandando los eventos Ans... definirian en la clase heredera aqui habria un "procesaAns...()", antes, esta funcion propagara el valor 0
			//a la funcion que la haya llamado
			//si no se recibe ans con el opc esperado (un evento se entromete, puede ocurrir)  deberiamos repetir lectura y bloqueo, (en este caso el tiempo del NodeTimeOut volveria a cero :?)
			echo "\nlectura post-bloqueo!!!#".$bufer."#\n\n\n";
			
			//esto es simplemente para el monitor del cliente, se manda solo al que inicio este proceso
			$mensaje_debug= $this->miWsManager->mask(json_encode(array('tipo'=>'debugMsg', 'server'=>$this->ipLocal, 'message'=>"Respuesta Ans:".$bufer)));
			
			$this->conClienteActivoBloqueaRespuesta->write($mensaje_debug);
			
			$opc = $this->miKimal->dameOpcTrama($bufer);
			
			
			//tramas de rechazo emitidas por la electronica
			if($opc == "FF")
			{
				$this->procesaErrOpCode();
				return $this->codColectMethodReturn["OPC_RECHAZADO_ELECTRONICA"];
			}
			if($opc == "FE")
			{
				$this->procesaFrameError();
				return $this->codColectMethodReturn["VALOR_PARAM_INCORRECTO"];
			}
			//kimaldi_net no parece tener ningun codigo de devolucion para esto, comprobado que acaba devolviendo 0 (func ok)
			//pero va a saltar un evento de error que podra ir implemnetado en la clase heredera, aqui solo se maneja
			if($opc == "FD")
			{
				$this->procesaFrameDelay();
			}
			
		}
		
		//las respuestas Ans normales al fin y al cabo son tratados como eventos (tipo ANS), ya hem,os hecho los controles de sincronizacion que necesitabamos
		//para que sigan el curso normal de un evento, vamos a pasarla a la funcion que trata los eventos espontaneos
		//TODO ojito aqui no deberias propagar el bufer como evento si resulta que este esta vacio, en un node time out p ej
		$this->onDataElectronica($bufer);
		
		//llegado aqui todo ok (algunos errores hacen que se sigapropagando el valor "todo ok" pero aun asi se lanzan eventos de error)
		return $this->codColectMethodReturn["EJECUCION_OK"];
		
	
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
			echo "\nOPC \"".$opc."\" no incluido en lista de eventos/respuestas reconocidos\n";
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
		//Hacer el ->write directamente hacia la electronica hace uqe perdamos TOoDO el control, usar la func manda_comando_electronica
		//controla cosas como el cliente actual que espera respuesta (cola mediante bloqueo), emite eventos de error de trama y NodeTimeOut, ademas, las respuestas 
		//recibidas se propagan luego como cualquier otro evento (son los llamados eventos de respuesta prefijo "Ans")
		
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
	
	
	//---------------------------------------
	
	
	public function WriteDisplay($Text)
	{
		
		//el texto debera tener obligatoriamente 40 caracteres 20 de la primera linea y 20 la segunda
		if(strlen($Text) != 40)
		{
			$Text = substr(  str_pad($Text,40,"*"), 0,  40);
		}
		
		//la coleccion de argumentos la tratamos en nuestra clase generadora de tramas como array, si
		//el tipo es char es que estamos recibiendo una cadena y no se trata exactamente igual
		//la convertimos a array de caracteres
		$arrayText = str_split ($Text);
		
		$trama = $this->miKimal->createFrame(0x11, $arrayText, "char");
		
		echo "\nEnviando trama WriteDisplay: ".$trama. "\n";
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
		
	}
	
	
	
	//-----------------------------------------------------
	
	/*
	 * PREPROCESADO DE EVENTOS ESPONTANEOS DE LA ELECTRONICA
	 * 
	 */
	
	
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
	
	
	//implementar OnDigitalInput en la clase que hereda (Een este caso se pueden usar DOS MANEJADORES DE EVENTOS alternativos, ambos seran llamados si es posible)
	private function procesaOnDigitalInput($arg)
	{
		
		if ( method_exists($this, "OnDigitalInput") )
			$this->OnDigitalInput($arg);
		else
			echo "metodo OnDigitalInput lanzado, pero no esta definido ni implementado\n";
		
		
		 
		//cosecha propia, he ideado otro manejador de evento (alternativo) mucho mas comodo
		if ( method_exists($this, "OnDigitalInputBoolean") )
		{
			
			$numDecimal = hexdec($arg);
			$stringBinario = decbin($numDecimal);
			$stringBinario = str_pad($stringBinario, 4, "0", STR_PAD_LEFT);
			
			$din1 = $din2 = $din3 = $din4 = false;
			
			if($stringBinario[3] == "1")
			{
				$din1 = true;
			}
			if($stringBinario[2] == "1")
			{
				$din2 = true;
			}
			if($stringBinario[1] == "1")
			{
				$din3 = true;
			}
			if($stringBinario[0] == "1")
			{
				$din4 = true;
			}
			
			$this->OnDigitalInputBoolean($din1, $din2, $din3, $din4);
		}	
		else
			echo "metodo OnDigitalInputBoolean lanzado, pero no esta definido ni implementado\n";
		
		
	}
	
	
	
	/*
	 * FIN PREPROCESADO DE EVENTOS ESPONTANEOS
	 * 
	 */
	
	//---------------------------------------------------------------
	
	
	/*
	 * PROCESADO EVENTOS DE RESPUESTA (ANS)
	 * 
	 */
	
	private function procesaAnsHotReset()
	{
		if ( method_exists($this, "AnsHotReset") )
			$this->AnsHotReset();
		else
			echo "evento de respuesta AnsHotReset lanzado, pero no esta definido ni implementado\n";
		
	}
	
	//--------------------------------------------------------------------
	
	private function procesaAnsTestNodeLink()
	{
		if ( method_exists($this, "AnsTestNodeLink") )
			$this->AnsTestNodeLink();
		else
			echo "evento de respuesta AnsTestNodeLink lanzado, pero no esta definido ni implementado\n";
	}
	
	//--------------------------------------------------------------------
	
	private function procesaAnsActivateDigitalOutput()
	{
		if ( method_exists($this, "AnsActivateDigitalOutput") )
			$this->AnsActivateDigitalOutput();
		else
			echo "evento de respuesta AnsActivateDigitalOutput lanzado, pero no esta definido ni implementado\n";
	}
	
	//--------------------------------------------------------------------
	
	private function procesaAnsSwitchDigitalOutput()
	{
		if ( method_exists($this, "AnsSwitchDigitalOutput") )
			$this->AnsSwitchDigitalOutput();
		else
			echo "evento de respuesta AnsSwitchDigitalOutput lanzado, pero no esta definido ni implementado\n";
	}
	
	//--------------------------------------------------------------------
	
	private function procesaAnsActivateRelay()
	{
		if ( method_exists($this, "AnsActivateRelay") )
			$this->AnsActivateRelay();
		else
			echo "evento de respuesta AnsActivateRelay lanzado, pero no esta definido ni implementado\n";
	}
	
	//--------------------------------------------------------------------
	
	private function procesaAnsSwitchRelay()
	{
		if ( method_exists($this, "AnsSwitchRelay") )
			$this->AnsSwitchRelay();
		else
			echo "evento de respuesta AnsSwitchRelay lanzado, pero no esta definido ni implementado\n";
	}

	//--------------------------------------------------------------------
	
	private function procesaAnsWriteDisplay()
	{
		if ( method_exists($this, "AnsWriteDisplay") )
			$this->AnsWriteDisplay();
		else
			echo "evento de respuesta AnsWriteDisplay lanzado, pero no esta definido ni implementado\n";
	}
	
	
	/*
	 * FIN PROCESADO EVENTOS DE RESPUESTA
	 * 
	 */
	
	//--------------------------------------------------------------------
	
	
	
	//---------------------------------------------------------------
	
	
	/*
	* PROCESADO DE EVENTOS AMBIGUOS (ESPONTANEOS O DE RESPUESTA?)
	*
	*/
	
		
	//-----------------------------------------------------
	
	
	//desambiguacion entre OnStatusDigitalOutput o AnsActivateDigitalOutput (OPC 0x30)
	private function desambiguacionDigitalOutput($arg)
	{
		
		echo "evaluando desambiguacionDigitalOutput (OPC 0x30)el criterio es el arg recibido:#".$arg."#\n";
	
		//sin arg deducimos que estra trama d ela electronica es AnsActivateDigitalOutput
		//en otro caso es un evento de fin de temporalizacion OnStatusDigitalOutput
		if(strlen($arg) == 0)
		{
			if ( method_exists($this, "AnsActivateDigitalOutput") )
				$this->AnsActivateDigitalOutput();
			else
				echo "metodo AnsActivateDigitalOutput lanzado, pero no esta definido ni implementado\n";
			
		}
		else //si hay argumentos, obligatoriamente deberian ser dos (dos bytes codificados en hexa, cada uno dos caracteres)
		{
			
			$NumOut = substr( $arg, 0, 2 );
			$status = substr( $arg, 2, 2 );
			
			if ( method_exists($this, "OnStatusDigitalOutput") )
				$this->OnStatusDigitalOutput($NumOut, $status);
			else
				echo "metodo OnStatusDigitalOutput lanzado, pero no esta definido ni implementado\n";
			
		}
		
		
	
	}
	
	
	//-----------------------------------------------------
	
	
	//desambiguacion entre OnStatusRelay o AnsActivateRelay (OPC 0x40)
	private function desambiguacionRelay($arg)
	{
		
		echo "evaluando desambiguacionRelay (OPC 0x40)el criterio es el arg recibido:#".$arg."#\n";
		
		//sin arg deducimos que estra trama d ela electronica es AnsActivateRelay
		//en otro caso es un evento de fin de temporalizacion OnStatusRelay
		if(strlen($arg) == 0)
		{
			if ( method_exists($this, "AnsActivateRelay") )
				$this->AnsActivateRelay();
			else
				echo "metodo AnsActivateRelay lanzado, pero no esta definido ni implementado\n";
				
		}
		else //si hay argumentos, obligatoriamente deberian ser dos (dos bytes codificados en hexa, cada uno dos caracteres)
		{
				
			$NumRelay = substr( $arg, 0, 2 );
			$status = substr( $arg, 2, 2 );
				
			if ( method_exists($this, "OnStatusRelay") )
				$this->OnStatusRelay($numout, $status);
			else
				echo "metodo OnStatusRelay lanzado, pero no esta definido ni implementado\n";
				
		}
		
	
	}
	


	
	/*
	* FIN PROCESADO DE EVENTOS AMBIGUOS 
	*
	*/
	
	//--------------------------------------------------------------------
	
	
   /*
	* PROCESADO EVENTOS ERROR
	*
	*/
	
	private function procesaTCPClose()
	{
		if ( method_exists($this, "TCPClose") )
			$this->TCPClose();
		else
			echo "evento de error TCPClose lanzado, pero no esta definido ni implementado\n";
	}

	//------------------------------------------------

	private function procesaTCPError($error)
	{
		if ( method_exists($this, "TCPError") )
			$this->TCPError($error);
		else
			echo "evento de error TCPError lanzado, pero no esta definido ni implementado, error capturado:\n".$error."\n";
	}
	
	//------------------------------------------------
	
	private function procesaFrameDelay()
	{
		if ( method_exists($this, "TFrameDelay") )
			$this->FrameDelay();
		else
			echo "evento de error FrameDelay lanzado, pero no esta definido ni implementado\n";
	}
	
	//------------------------------------------------
	
	private function procesaNodeTimeOut()
	{
		if ( method_exists($this, "NodeTimeOut") )
			$this->NodeTimeOut();
		else
			echo "evento de error NodeTimeOut lanzado, pero no esta definido ni implementado\n";
	}
	
    //------------------------------------------------
	
	private function procesaErrOpCode()
	{
		if ( method_exists($this, "ErrOpCode") )
			$this->ErrOpCode();
		else
			echo "evento de error ErrOpCode lanzado, pero no esta definido ni implementado\n";
	}
	//------------------------------------------------
	
	private function procesaFrameError()
	{
		if ( method_exists($this, "FrameError") )
			$this->FrameError();
		else
			echo "evento de error FrameError lanzado, pero no esta definido ni implementado\n";
	}
	
   /*
	* FIN PROCESADO EVENTOS DE ERROR
	*
	*/
	
	//--------------------------------------------------------------------------
	
	public function Run()
	{
		
		//llamamos a nuestra funcion de inicio de eventos
		$this->inicializaEventos();
		
		//ojito que si no le pones ip del servidor en el que estamos como segundo parametro, SOLO funcionara en local
		$this->listen($this->puertoEscucha, $this->ipLocal); 
		
		echo "\nServidor de sockets Activo, escucha en: ".$this->ipLocal.":".$this->puertoEscucha."\n"; 
		
		return $this->miLoop->run();

	}

}