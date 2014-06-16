<?php

require_once 'Configuracion.php';
require_once 'KimaldiFrameGenerator.php';
require_once 'WsServerManager.php';

require __DIR__.'/../vendor/autoload.php';




class PhpKimaldiServer extends React\Socket\Server
{
	
	protected $debug_client_mode;
	
	protected $debug_log_mode;
	
	
	protected $miWsManager;
	protected $generadorTramas;
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
		
		
		
		$this->debug_client_mode = Configuracion::$debug_client_mode;
		$this->debug_log_mode = Configuracion::$debug_log_mode;
		
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
		$this->generadorTramas = new KimaldiFrameGenerator();
		
		//INSTANCIA de clase generadora de tramas con protocolo Kimaldi
		$this->miWsManager = new WsServerManager();
	
		
		$this->conns = new \SplObjectStorage();
		

	}

	//--------------------------------------------------------------------------
	//abre un puerto de comunicacion con la electrónica vía TCP
	//segundo parametro opcional, el puerto de la electronica es por defecto el 1001
	public function OpenPortTCP($dirIPElectronica, $puertoElectronica = "1001")
	{	
		//la conexion con la electronica ya esta abierta, debe cerrarse primero la conexion
		if($this->conexElectronica != null)
		{
			return $this->codColectMethodReturn["PUERTO_COM_YA_ABIERTO"];
		}
		
		$this->dirIPElectronica = $dirIPElectronica;
		$this->puertoElectronica = $puertoElectronica;
		
		
		$this->controlEchoDebugServer("Conectando con electronica tcp://".$this->dirIPElectronica.":".$this->puertoElectronica.".......");
		
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
		
		$this->controlEchoDebugServer("Conexion establecida con la electronica!!!");
		
		if(Configuracion::$hotResetAutomatico)
		{
			$this->controlEchoDebugServer("Directiva de configuracion hotResetAutomatico activa");
			$this->HotReset();
			
			$this->controlEchoDebugServer("Electronica activa, HotReset inicial Realizado");
		}
		
		
		
		return $this->codColectMethodReturn["EJECUCION_OK"];
		
	}
	
	//--------------------------------------------------------------------------
	//cierra el puerto de comunicación TCP con la electrónica
	public function ClosePort()
	{
		$this->controlEchoDebugServer("Ejecutando funcion closePort");
		
		if($this->conexElectronica == null)
		{
			
			$this->controlEchoDebugServer("ERROR se intento cerrar el socket con la electronica pero este NO se encuentra abierto");
					
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
			$this->controlEchoDebugServer("ERROR el socket con la electronica NO se encuentra abierto, no es posible inicializar sus eventos");
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
		
		$this->controlEchoDebugServer("Trama de electronica recibida con data: ".$data);
		
		$desgloseTrama = array();
		
		
		//la electronica manda periodicamente una ack, ASCII 6
		if (ord($data) != 6)
		{
			$desgloseTrama = $this->generadorTramas->desglosaTrama($data);
			$this->evaluaEvento( $desgloseTrama["OPC"] , $desgloseTrama["ARG"]);
		} 
		
		//informamos del Evento recibido desde la tarjeta
		//mandar trama debug a todos los clientes, por si quieren monitorizar
		$this->debugFrameToClient("Recibida Trama de Electronica: ".$data, null);
	}
	
	//--------------------------------------------------------------------------
	
	protected function onFinalizacionElectronica($conn)
	{
		$this->controlEchoDebugServer("CONEXION ELECTRONICA FINALIZADA, IP:".$conn->getRemoteAddress());
	
		$this->procesaTCPClose();
		
		//la vida puede seguir...
		//$this->miLoop->stop();
		
		//fuera referencia
		$this->conexElectronica = null;
	}
	
	
	//--------------------------------------------------------------------------
	
	protected function onErrorConexionElectronica($error)
	{
		
		$this->controlEchoDebugServer("\n\nError TCP:", false);
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
	
		$this->controlEchoDebugServer("Nueva conexion entrante cliente@".$connCliente->getRemoteAddress().".....");
		
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
						
							$this->controlEchoDebugServer("Mensaje parece ser cabecera de apertura websocket");						
							
							$this->miWsManager->perform_handshaking($data, $connCliente, $this->ipLocal);

							
							$this->controlEchoDebugServer( "Completado HandShake cliente@".$connCliente->getRemoteAddress() );
							
							//handshake completado, no seguimos
							return;				
					}
					
					//---
							
					//echo "Cliente@".$connCliente->getRemoteAddress().": escribio trama WebSocket RAW:\n".$data."\n\n";
							

					$received_text = $this->miWsManager->unmask($data); 
										
					//un cliente que se va (cerrando el socket) manda un par de caracteres ascii
					//03 EXT seguido de algún otro por lo tanto ya no deberiamos seguir
					if (ord($received_text[0]) == 3 || ord($received_text[1]) == 3)
					{
						//trama WS de fin de comunicacion, a continucacion saldra el evento onFinalizacionCliente
						return;
					}
					
					
					$this->controlEchoDebugServer( "Cliente@".$connCliente->getRemoteAddress().": escribio trama WebSocket valor desenmascarado:\n".$received_text);
							
							
					$tst_msg = json_decode($received_text); //json decode
							
					
					
					
					
					//el usuario manda un frame codificado como json
					if(isset($tst_msg->tipo) && ($tst_msg->tipo == 'frame' || $tst_msg->tipo == 'cmd'))
					{
							
						$opc = $tst_msg->opc;
						$argumentos = $tst_msg->arg;
						$tipoArgumento = $tst_msg->argType;
							
						$this->controlEchoDebugServer( "Usuario manda comando, opc:#".dechex($opc)."#, numArg:#".count($argumentos)."#");
							
											
						if($tipoArgumento == "char")
						{
							//la coleccion de argumentos la tratamos en nuestra clase generadora de tramas como array, si
							//el tipo es char es que estamos recibiendo una cadena y no se trata exactamente igual
							//la convertimos a array de caracteres
							$argumentos = str_split ($argumentos);
						}
							
							
						$trama = $this->generadorTramas->createFrame($opc, $argumentos, $tipoArgumento);
							
							
						$this->controlEchoDebugServer( "Trama generada #".$trama."#");
							
						
							
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
							$this->controlEchoDebugServer( "El cliente con ip: ".$connCliente->getRemoteAddress()." realiza llamada al metodo del servidor con nombre\"".$funcName."\" funcion INEXISTENTE");
						}
						
					}
					elseif (isset($tst_msg->tipo) && $tst_msg->tipo == 'userMsg')//el usuario manda mensage normal (es solo texto plano) comunicacion inter-clientes
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
						$this->controlEchoDebugServer( "El cliente con ip: ".$connCliente->getRemoteAddress()." envio trama sin formato adecuado (se precisa JSON y campo tipo)");
					}
					
					
	}
	
	
	
	//--------------------------------------------------------------------------
	

	protected function onFinalizacionCliente($connCli)
	{
		$this->controlEchoDebugServer( "Desconectado cliente WS con IP:".$connCli->getRemoteAddress() );
	
		//fuera de la lista de conexiones de cliente
		$this->conns->detach($connCli);
	}
	
	
	
	
	//-------------------------------------------*****************
	
	function broadcastClientFunction($nombreFunc, $argList)
	{
		
		$mensaje_broadcast_clientes = $this->miWsManager->mask(json_encode(array('tipo'=>'func', 'funcName'=>$nombreFunc, 'args'=>$argList, 'server'=>$this->ipLocal )));
		
		$this->controlEchoDebugServer( "Broadcast todos los clientes, datos con valor: ".$mensaje_response_cliente );
			
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
					$this->controlEchoDebugServer( "Response a IP cliente concreto, datos con valor".$mensaje_response_cliente."\n al cliente ".$ipClienteConcreto );
					$conUsu->write($mensaje_response_cliente);
					
					return;
				}
				$this->controlEchoDebugServer( "Se busco cliente con IP ".$ipClienteConcreto.", pero no se encuentra registrado");
			}
		}
		elseif($this->conClienteActivoBloqueaRespuesta == null)
		{
			$this->controlEchoDebugServer( "No es posible responder a cliente que ocasiono bloqueo, no esta definido");
			return;
		}
		else 
		{
			$this->controlEchoDebugServer( "Response con valor".$mensaje_response_cliente."\n al cliente ".$this->conClienteActivoBloqueaRespuesta->getRemoteAddress() );
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
			$this->controlEchoDebugServer( "ERROR el socket con la electronica NO se encuentra abierto, no es posible mandar tramas");
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
		
		$this->controlEchoDebugServer( "Mandada trama electronica, se procede a bloqueo esperando lectura...:");
		
		
		//en estas condiciones la lectura del stream PRODUCE BLOQUEO que es lo que queremos
		$bufer = fread($streamElectronica, 4096);
		
		//ojo al array meta,a este stream al crearse EN OpenTCPPort se le puso un timeout de escritura
		$meta = stream_get_meta_data($streamElectronica);
		
		//*****************aqui se lanzaria el evento timeout se definiria e implementara en la clase heredera aqui habria un "procesaTimeOut()"
		if ($meta['timed_out'])
		{
			$this->controlEchoDebugServer( "TIMEOUT");
			
			//esto es simplemente para el monitor del cliente, se manda el mensaje de NodeTimeOut solo al cliente que inicio este proceso y causo este timeout
			$this->debugFrameToClient("...NodeTimeOut", $this->conClienteActivoBloqueaRespuesta);
			
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
			$this->controlEchoDebugServer( "Lectura post-bloqueo!!!#".$bufer."#");
			
			//esto es simplemente para el monitor del cliente, se manda solo al que inicio este proceso, se le informa de que su accion ha tenido una respuesta especifica desde la electronica
			$this->debugFrameToClient("Respuesta Ans:".$bufer, $this->conClienteActivoBloqueaRespuesta);
			
			
			$opc = $this->generadorTramas->dameOpcTrama($bufer);
			
			
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
		
		//las respuestas Ans normales al fin y al cabo son tratados como eventos (tipo ANS), ya hemos hecho los controles de sincronizacion que necesitabamos
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
			$this->controlEchoDebugServer( "OPC \"".$opc."\" no incluido en lista de eventos/respuestas reconocidos");
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
	
	//realiza un reset en caliente de la electrónica
	public function HotReset()
	{
		$trama = $this->generadorTramas->tramaHotReset();
		
		$this->controlEchoDebugServer( "Enviando trama HotReset: ".$trama);
		//Hacer el ->write directamente hacia la electronica hace uqe perdamos TOoDO el control, usar la func manda_comando_electronica
		//controla cosas como el cliente actual que espera respuesta (cola mediante bloqueo), emite eventos de error de trama y NodeTimeOut, ademas, las respuestas 
		//recibidas se propagan luego como cualquier otro evento (son los llamados eventos de respuesta prefijo "Ans")
		
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	//--------------------------------
	//manda una trama de test, esperando respuesta, sirve para comprobar la comunicacion con la 
	//electrónica de forma fehaciente
	public function TestNodeLink()
	{
		$trama = $this->generadorTramas->tramaTestNodeLink();
		$this->controlEchoDebugServer( "Enviando trama TestNodeLink: ".$trama );
	
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	
	//-------------------------------------
	//activa una salida digital (numeradas de 0 a 3 en el caso de la Biomax2), durante 
	//el numero de decimas de segundo determinado en el parametro $tTime (valores hexadecimales)
	public function ActivateDigitalOutput($numOut, $tTime)
	{
		$trama = $this->generadorTramas->tramaActivateDigitalOutput( array($numOut, $tTime) );
		$this->controlEchoDebugServer("Enviando trama ActivateDigitalOutput: ".$trama);
		
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	//--------------------------------
	//Cambia el estado de una salida digital (numeradas de 0 a 3 en el caso de la Biomax2)
	//el parametro $mode es un booleano que determina si la salida adquirirá estado activad o inactivado
	public function SwitchDigitalOutput($numOut, $mode)
	{
		//pordefecto hex 0x00, valor false
		$hexMode= 0x00;
		
		if($mode)
		{
			$hexMode = 0x01;
		}
		
		$trama = $this->generadorTramas->tramaSwitchDigitalOutput( array($numOut, $hexMode) );
		$this->controlEchoDebugServer( "Enviando trama SwitchDigitalOutput: ".$trama );
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	

	//--------------------------------
	//activa un relé (numerados de 0 a 3 en el caso de la Biomax2), durante
	//el numero de decimas de segundo determinado en el parametro $tTime (valores hexadecimales)
	public function ActivateRelay($numRelay, $tTime)
	{
		$trama = $this->generadorTramas->tramaActivateRelay( array($numRelay, $tTime) );
		$this->controlEchoDebugServer( "Enviando trama ActivateRelay: ".$trama );
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	//--------------------------------
	//Cambia el estado de un relé (numerados de 0 a 3 en el caso de la Biomax2)
	//el parametro $mode es un booleano que determina si la salida adquirirá estado cerrado o abierto
	public function SwitchRelay($numRelay, $mode)
	{
		//pordefecto hex 0x00, valor false
		$hexMode= 0x00;
	
		if($mode)
		{
			$hexMode = 0x01;
		}
	
		$trama = $this->generadorTramas->tramaSwitchRelay( array($numRelay, $hexMode) );
		$this->controlEchoDebugServer( "Enviando trama SwitchRelay: ".$trama );
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	//------------------------------------
	//realiza una solicitud de trama que describa el estado de las entradas digitales de la electrónica
	//el resultado es el mismo que recibir un eventop OnDIgitalInput, de forma espontánea desde la electrónica
	public function TxDigitalInput()
	{
		$trama = $this->generadorTramas->tramaTxDigitalInput();
		$this->controlEchoDebugServer( "Enviando trama TxDigitalInput: ".$trama );
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	
	//---------------------------------------
	
	//Manda una cadena de texto para que la electrónica la muestre en su display, los primeros 20 caracteres corresponderán a la primera línea
	//los últimos 20 caracteres corresponderán a la segunda, se produce, además una retroiluminación momentánea del Display
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
		
		$trama = $this->generadorTramas->createFrame(0x11, $arrayText, "char");
		
		$this->controlEchoDebugServer( "Enviando trama WriteDisplay: ".$trama );
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
		
	}
	
	
	
	//-----------------------------------------------------
	
	/*
	 * PREPROCESADO DE EVENTOS ESPONTANEOS DE LA ELECTRONICA
	 * 
	 */
	
	
	
	//Es llamada al recibir una trama de evento de pulsacion de tecla
	//implementar OnKey en la clase que hereda $key es el codigo ASCII en hexadecimal,  de la tecla pulsada
	private function procesaOnKey($arg)
	{
		$valorCaracter = hexdec( $arg );
		$key = chr($valorCaracter);
		
		if ( method_exists($this, "OnKey") )
			$this->OnKey($key);
		else
			$this->controlEchoDebugServer( "Metodo OnKey lanzado, pero no esta definido ni implementado");
	}
	
	//-----------------------------------------------------
	
	//Es llamada al recibir una trama de evento de lectura de tarjeta
	//implementar OnTrack en la clase que hereda $track es la cadena de caracteres del codigo leido en la tarjeta
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
			$this->controlEchoDebugServer( "Metodo OnTrack lanzado, pero no esta definido ni implementado");
		
	}
	
	
	//---------------------------------------------------------
	
	//Es llamada al recibir una trama de evento de entradas digitales
	//implementar OnDigitalInput en la clase que hereda (En este caso se pueden usar DOS MANEJADORES DE EVENTOS alternativos, ambos seran llamados si es posible)
	private function procesaOnDigitalInput($arg)
	{
		
		if ( method_exists($this, "OnDigitalInput") )
			$this->OnDigitalInput($arg);
		else
			$this->controlEchoDebugServer( "Metodo OnDigitalInput lanzado, pero no esta definido ni implementado");
		
		
		 
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
			$this->controlEchoDebugServer( "Metodo OnDigitalInputBoolean lanzado, pero no esta definido ni implementado");
		
		
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
			$this->controlEchoDebugServer( "Evento de respuesta AnsHotReset lanzado, pero no esta definido ni implementado");
		
	}
	
	//--------------------------------------------------------------------
	
	private function procesaAnsTestNodeLink()
	{
		if ( method_exists($this, "AnsTestNodeLink") )
			$this->AnsTestNodeLink();
		else
			$this->controlEchoDebugServer( "Evento de respuesta AnsTestNodeLink lanzado, pero no esta definido ni implementado");
	}
	
	//--------------------------------------------------------------------
	
	private function procesaAnsActivateDigitalOutput()
	{
		if ( method_exists($this, "AnsActivateDigitalOutput") )
			$this->AnsActivateDigitalOutput();
		else
			$this->controlEchoDebugServer( "Evento de respuesta AnsActivateDigitalOutput lanzado, pero no esta definido ni implementado");
	}
	
	//--------------------------------------------------------------------
	
	private function procesaAnsSwitchDigitalOutput()
	{
		if ( method_exists($this, "AnsSwitchDigitalOutput") )
			$this->AnsSwitchDigitalOutput();
		else
			$this->controlEchoDebugServer( "Evento de respuesta AnsSwitchDigitalOutput lanzado, pero no esta definido ni implementado");
	}
	
	//--------------------------------------------------------------------
	
	private function procesaAnsActivateRelay()
	{
		if ( method_exists($this, "AnsActivateRelay") )
			$this->AnsActivateRelay();
		else
			$this->controlEchoDebugServer( "Evento de respuesta AnsActivateRelay lanzado, pero no esta definido ni implementado");
	}
	
	//--------------------------------------------------------------------
	
	private function procesaAnsSwitchRelay()
	{
		if ( method_exists($this, "AnsSwitchRelay") )
			$this->AnsSwitchRelay();
		else
			$this->controlEchoDebugServer( "Evento de respuesta AnsSwitchRelay lanzado, pero no esta definido ni implementado");
	}

	//--------------------------------------------------------------------
	
	private function procesaAnsWriteDisplay()
	{
		if ( method_exists($this, "AnsWriteDisplay") )
			$this->AnsWriteDisplay();
		else
			$this->controlEchoDebugServer( "Evento de respuesta AnsWriteDisplay lanzado, pero no esta definido ni implementado");
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
		
		$this->controlEchoDebugServer( "Evaluando desambiguacionDigitalOutput (OPC 0x30)el criterio es el arg recibido:#".$arg."#");
	
		//sin arg deducimos que estra trama d ela electronica es AnsActivateDigitalOutput
		//en otro caso es un evento de fin de temporalizacion OnStatusDigitalOutput
		if(strlen($arg) == 0)
		{
			if ( method_exists($this, "AnsActivateDigitalOutput") )
				$this->AnsActivateDigitalOutput();
			else
				$this->controlEchoDebugServer( "Metodo AnsActivateDigitalOutput lanzado, pero no esta definido ni implementado");
			
		}
		else //si hay argumentos, obligatoriamente deberian ser dos (dos bytes codificados en hexa, cada uno dos caracteres)
		{
			
			$NumOut = substr( $arg, 0, 2 );
			$status = substr( $arg, 2, 2 );
			
			if ( method_exists($this, "OnStatusDigitalOutput") )
				$this->OnStatusDigitalOutput($NumOut, $status);
			else
				$this->controlEchoDebugServer( "Metodo OnStatusDigitalOutput lanzado, pero no esta definido ni implementado");
			
		}
		
		
	
	}
	
	
	//-----------------------------------------------------
	
	
	//desambiguacion entre OnStatusRelay o AnsActivateRelay (OPC 0x40)
	private function desambiguacionRelay($arg)
	{
		
		$this->controlEchoDebugServer( "Evaluando desambiguacionRelay (OPC 0x40)el criterio es el arg recibido:#".$arg."#");
		
		//sin arg deducimos que estra trama d ela electronica es AnsActivateRelay
		//en otro caso es un evento de fin de temporalizacion OnStatusRelay
		if(strlen($arg) == 0)
		{
			if ( method_exists($this, "AnsActivateRelay") )
				$this->AnsActivateRelay();
			else
				$this->controlEchoDebugServer( "Metodo AnsActivateRelay lanzado, pero no esta definido ni implementado");
				
		}
		else //si hay argumentos, obligatoriamente deberian ser dos (dos bytes codificados en hexa, cada uno dos caracteres)
		{
				
			$NumRelay = substr( $arg, 0, 2 );
			$status = substr( $arg, 2, 2 );
				
			if ( method_exists($this, "OnStatusRelay") )
				$this->OnStatusRelay($numout, $status);
			else
				$this->controlEchoDebugServer( "Metodo OnStatusRelay lanzado, pero no esta definido ni implementado");
				
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
	//implementar TCPClose() en clase heredera
	//es un evento que se lanza cuando se pierde la comunicación TCP con la electrónica y el cierre de socket es efectivo
	private function procesaTCPClose()
	{
		if ( method_exists($this, "TCPClose") )
			$this->TCPClose();
		else
			$this->controlEchoDebugServer( "Evento de error TCPClose lanzado, pero no esta definido ni implementado");
	}

	//------------------------------------------------
	//implementar TCPError() en clase heredera
	//es un evento que se lanza cuando se produce algun error de sistema en la comunicacion TCP 
	private function procesaTCPError($error)
	{
		if ( method_exists($this, "TCPError") )
			$this->TCPError($error);
		else
			$this->controlEchoDebugServer( "Evento de error TCPError lanzado, pero no esta definido ni implementado, error capturado:\n".$error);
	}
	
	//------------------------------------------------
	//implementar FrameDelay(); en clase heredera
	//es un evento que se lanza cuando se produce cuando la electronica recibe una instruccion pero
	//no puede atenderla por estar ocupada
	private function procesaFrameDelay()
	{
		if ( method_exists($this, "TFrameDelay") )
			$this->FrameDelay();
		else
			$this->controlEchoDebugServer( "Evento de error FrameDelay lanzado, pero no esta definido ni implementado");
	}
	
	//------------------------------------------------
	//implementar NodeTimeOut(); en clase heredera
	//es un evento que se lanza cuando Se ha agotado el Timeout de comunicaciones con la 
	//electrónica entre una instrucción y la recepción de su respuesta. (el num de segundos es configurable en Configuracion.php)
	private function procesaNodeTimeOut()
	{
		if ( method_exists($this, "NodeTimeOut") )
			$this->NodeTimeOut();
		else
			$this->controlEchoDebugServer( "Evento de error NodeTimeOut lanzado, pero no esta definido ni implementado");
	}
	
    //------------------------------------------------
	//implementar ErrOpCode() en clase heredera
	//es un evento que se lanza cuando la electronica recibe trama pero no admite la instruccion normalmente OPC no valido
	private function procesaErrOpCode()
	{
		if ( method_exists($this, "ErrOpCode") )
			$this->ErrOpCode();
		else
			$this->controlEchoDebugServer( "Evento de error ErrOpCode lanzado, pero no esta definido ni implementado");
	}
	
	//------------------------------------------------
	//implementar FrameError() en clase heredera
	//es un evento que se lanza cuando la electronica recibe trama pero tiene uns estructura erronea
	private function procesaFrameError()
	{
		if ( method_exists($this, "FrameError") )
			$this->FrameError();
		else
			$this->controlEchoDebugServer( "Evento de error FrameError lanzado, pero no esta definido ni implementado");
	}
	
   /*
	* FIN PROCESADO EVENTOS DE ERROR
	*
	*/
	
	//-------------------------------------------------------------------------
	//mandara tramas de diagnostico para ser monitorizadas en el mismo navegador
	//puede que en produccion produzcan un trafico innecesario, se pueden mandar a un cliente concreto
	//precisa parametro clase Connection de ReactPHP o bien a todos (param1 = null)
	public function debugFrameToClient($texto, $connClient)
	{
		$mensaje_debug= $this->miWsManager->mask(json_encode(array('tipo'=>'debugMsg', 'server'=>$this->ipLocal, 'message'=> $texto)));
		
		if ($connClient == null)
		{
			$this->mandarTodosUsuarios($mensaje_debug);
		}
		else 
		{
			$connClient->write($mensaje_debug);
		}
		
	}
	//---------------------------------------------------------------------------
	
	public function controlEchoDebugServer($msg, $agregaSaltoLinea = true)
	{
		
		$msg = preg_replace('/[^(\x20-\x7F)]*/','', $msg);
		
		if($agregaSaltoLinea)
			echo "\n\n".microtime(true).": ".$msg."\n\n";
		else
			echo $msg;
		
	}
	
	//---------------------------------------------------------------------------
	
	public function Run()
	{
		
		//llamamos a nuestra funcion de inicio de eventos
		$this->inicializaEventos();
		
		//ojito que si no le pones ip del servidor en el que estamos como segundo parametro, SOLO funcionara en local
		$this->listen($this->puertoEscucha, $this->ipLocal); 
		
		
		$this->controlEchoDebugServer("Servidor de sockets Activo, escucha en: ".$this->ipLocal.":".$this->puertoEscucha);
		
		return $this->miLoop->run();

	}

}