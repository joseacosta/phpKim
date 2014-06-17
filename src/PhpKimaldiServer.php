<?php

require_once 'Configuracion.php';
require_once 'KimaldiFrameGenerator.php';
require_once 'WsServerManager.php';

require __DIR__.'/../vendor/autoload.php';



/**
 *Esta clase controla una coleccion de conexiones de cliente, valiéndose del protocolo websocket,
 *procesa nuevas conexiones entrantes
 *
 *Establece una conexión con un único terminal de Kimaldi. Concretamente el modelo Biomax2 o, alternativamente, KBio2
 *Maneja los eventos de nuevos datos disponibles en las conexiones, incorpora métodos para mandar tramas de instrucciones hacia la electronica
 *y traduce las lecturas desde la electronica en llamadas a funciones manejadoras de eventos.
 *Implementa el patrón reactor valiendose de la librería ReactPHP, heredando de la clase React\Socket\Server.
 *
 *Está diseñada para servir, a su vez de clase padre para otras que implementen sus métodos de evento y use sus funciones de instrucción con la electrónica.
 *Se inspira en la clase BioNet de la libreria de Kimaldi para el framework .NET
 *
 *@author Jose Acosta
 */
class PhpKimaldiServer extends React\Socket\Server
{
	
	/**
	 * Valor booleano que determina si las tramas de debug son mandadas a los clientes, el constructor la carga desde configuracion
	 * @var bool
	 */
	protected $debug_client_mode;
	
	/**
	 * Valor booleano que determina si el se mandan mensajes de monitorización por stdout, con potencial de ser guardado en un log por supervisor
	 * @var bool
	 */
	protected $debug_log_mode;
	
	/**
	 * Es responsable del codificado de las tramas websocket, tambien completa handshakes con nuevos clientes
	 * @var WsServerManager
	 */
	protected $miWsManager;
	
	/**
	 * Encapsula la formación de tramas a nivel de byte para comunicarse con la electronica
	 * @var KimaldiFrameGenerator
	 */
	protected $generadorTramas;
	
	/**
	 * Instancia de una Clase que implemente React\EventLoop\LoopInterface de React. 
	 * Controla el Loop de eventos y mantiene activo el proceso del servidor
	 * 
	 * @var React\EventLoop\LoopInterface
	 */
	protected $miLoop;
	
	/**
	 * Colección de conexiones de cliente, son ibjetos tipo #Connection de React\Socket\Connection
	 * @var SplObjectStorage
	 */
	protected $conns;
	
	/**
	 * Conexion TCP que se establece con la electrónica 
	 * @var React\Socket\Connection
	 */
	protected $conexElectronica;
	
	/**
	 * Coleccion de opcs que determinan tramas de eventos, cada uno ira asociado a un handler
	 * @var array
	 */
	protected $opcColectEvent;
	
	
	/**
	 * Coleccion con numeros de devolucion de metodos inspirados en los codigos que emite la clase BioNet de las librerias Kimaldi_Net, 
	 * con esta coleccion, se evita el uso de numeros magicos
	 * @var array
	 */
	protected $codColectMethodReturn;
	
	/**
	 * almacena el cliente que mando el ultimo mensaje al servidor y que, eventualmente producira un bloqueo en espera de respuesta
	 * @var React\Socket\Connection
	 */
	protected $conClienteActivoBloqueaRespuesta;
	
	
	/**
	 * Tiempo de espera maximo para establecer conexion con la electronica, el constructor la carga desde configuracion
	 * @var int
	 */
	protected $timeoutConnElectronica;
	
	
	/**
	 * tiempo de espera maximo para recibir una respuesta esperada de la electronica, una vez cumplido se dispara el evento NodeTimeOut
	 * @var int
	 */
	protected $timeoutNodeTimeOut;
	
	/**
	 * Dirección de ip de la electronica (normalmente 192.168.123.10), el constructor la carga desde configuracion
	 * @var string
	 */
	public $dirIPElectronica;
	
	/**
	 * Puerto de escucha de la electronica (normalmente 1001 para TCP), el constructor la carga desde configuracion
	 * @var int
	 */
	public $puertoElectronica;
	
	/**
	 * Dirección de ip de este servidor de sockets (ip local), el constructor la carga desde configuracion
	 * @var string
	 */
	public $ipLocal;
	
	/**
	 * Puerto de escucha de este servidor de sockets (normalmente 12000), el constructor la carga desde configuracion
	 * @var int
	 */
	public $puertoEscucha;
	
	/**
	 * Valor booleano que determina en cada momento si la conexion con la electronica se mantiene activa
	 * @var bool
	 */
	public $electronicaConectada = false;
	
	
	
	
	
	/**
	 * El constructor de esta clase sobreescribe al de su clase padre React\Socket\Server
	 * 
	 * Llama al constructor de la clase padre, pasandole una instancia del Loop de de eventos com parametro
	 * Carga automáticamente valores importantes desde la clase Configuracion, instancia objetos e inicializa
	 * colecciones de codigos OPC y codigos de devolución de métodos
	 * 
	 */
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
	/**
	 * Abre un puerto de comunicacion con la electrónica vía TCP
	 * 
	 * @param string $dirIPElectronica Dirección IP de la elctronica, por defecto tiene configuración IP fija 192.168.123.10
	 * @param string $puertoElectronica Opcional, el puerto de la electronica es por defecto el 1001
	 * @return multitype:
	 */
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
	/**
	 * Cierra el puerto de comunicación TCP con la electrónica
	 */
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
	/**
	 * Enlaza los eventos lanzados por el servidor de sockets, tipo React\Socket\Server (onConnection)
	 * tambien los eventos emitidos por la conexión de la electronica ('data', 'error', 'close')
	 * Todos adquieren un manejador.
	 */
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
	/**
	 * Maneja el evento de nuevos datos disponibles en el socket abierto con la electronica
	 * 
	 * @param unknown $data el buffer de datos nuevos listos para leer en el socket de la electronica
	 */
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
	/**
	 * Maneja el evento de finalización de conexión con la elctrónica, el socket queda cerrado de forma efectiva
	 * Lanza el evento TCPClose, de forma indirecta
	 * 
	 * @param React\Socket\Connection $conn recibe el objeto de conexion de electronica que cerró la conexion
	 */
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
	/**
	 * Maneja el evento de error TCP de conexión con la electrónica
	 * Lanza el evento TcpError
	 *
	 * @param unknown $error Objeto que encapsula información relativa al error
	 */
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
	/**
	 * Maneja el evento onConnection (Nueva conexion de cliente entrante) del Servidor de sockets clase React\Socket\Server, del que hereda esta misma clase
	 * 
	 * Enlaza los correspondientes eventos de la nueva conexion 'data' y 'end' con los manejadores de evento 'onDataCliente' y 'onFinalizacionCliente' respectivamente
	 * agrega la nueva conexion a la coleccion de clientes $conns
	 * 
	 * @param React\Socket\Connection $connCliente
	 */
	protected function onConexionEntrante($connCliente)
	{
	
		$this->conns->attach($connCliente);
	
		$this->controlEchoDebugServer("Nueva conexion entrante cliente@".$connCliente->getRemoteAddress().".....");
		
		//a la nueva conexion hay que darle sus manejadores de eventos
		$connCliente->on('data',  [$this, 'onDataCliente']);
		$connCliente->on('end',  [$this, 'onFinalizacionCliente']);
	
	}
		
	//--------------------------------------------------------------------------
	/**
	 * Maneja el evento de nuevos datos disponibles en uno de los sockets de la coleccion de clientes conectados al servidor
	 * 
	 * Controla el Handshake de la conexion entrante valiendose de $miWsManager (si $data) resulta ser una peticion de establecimeinto de conexion
	 * Realiza el desenmascarado de los datos en formato WebSocket valiéndose igualmente de $miWsManager.
	 * Tambien manda tramas de debug o información hacia el resto de clientes, para que monitoricen conexiones de otros clientes.
	 * 
	 * @param unknown el buffer de datos nuevos listos para leer en el socket del cliente
	 * @param React\Server\Connection $connCliente conexin del cliente que origina la nueva lectura
	 */
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
	/**
	 * Maneja el evento de cierre de conexion con un cliente,
	 * elimina el cliente concreto de la coleccion de clientes $conns
	 * 
	 * @param React\Server\Connection $connCli la conexion que resulta cerrada por el cliente
	 */
	protected function onFinalizacionCliente($connCli)
	{
		$this->controlEchoDebugServer( "Desconectado cliente WS con IP:".$connCli->getRemoteAddress() );
	
		//fuera de la lista de conexiones de cliente
		$this->conns->detach($connCli);
	}
	
	
	
	
	//-------------------------------------------*****************
	/**
	 * Envia un mensaje JSON siguiendo el protocolo jsonKimaldiProtocol a Todos los clientes que mantengan conexion con el servidor
	 * 
	 * Las clase del cliente jsKimaldiClient espera este tipo de instrucciones, cuando esta conectada
	 * puede usarse para mandar respuestas generales (Eventos espontaneos emitidos por la electronica) 
	 * a todos los clientes conectados que permancezcan a la escucha
	 * 
	 * 
	 * @param string $nombreFunc nombre textual de la funcion que sera invocada en el lado de cada cliente 
	 * @param array $argList parametros que recibira la funcion que sera invocada en el lado de cada cliente
	 */
	function broadcastClientFunction($nombreFunc, $argList)
	{
		
		$mensaje_broadcast_clientes = $this->miWsManager->mask(json_encode(array('tipo'=>'func', 'funcName'=>$nombreFunc, 'args'=>$argList, 'server'=>$this->ipLocal )));
		
		$this->controlEchoDebugServer( "Broadcast todos los clientes, datos con valor: ".$mensaje_response_cliente );
			
		$this->mandarTodosUsuarios($mensaje_broadcast_clientes);

	}
	
	//-------------------------------------------*****************
	/*Con la siguiente funcion No hay riesgo de concurrencia, porque la respuesta de la electronica a una trama, la espera cada cliente ocasionando un bloqueo,
	si se usa en otro contexto, p. ej. Un evento emitido de forma espontanea desde la electronica, el mensaje sera transmitido al ultimo cliente del que se recibio alguna orden
	alternativamente se le puede dar como argumento la ip del cliente concreto al que deseamos responder
	*/
	
	/**
	 * Envia un mensaje JSON siguiendo el protocolo jsonKimaldiProtocol al ultimo cliente que mando una isntruccion al servidor
	 *
	 * Las clase del cliente jsKimaldiClient espera este tipo de instrucciones, cuando esta conectada
	 * puede usarse para respuestas inmediatas (prefijo Ans) a peticiones de un cliente concreto
	 *
	 *
	 * @param string $nombreFunc nombre textual de la funcion que sera invocada en el lado del cliente
	 * @param array $argList parametros que recibira la funcion que sera invocada en el lado del cliente
	 * @param string $ipClienteConcreto Opcional, si deseamos realizar el response a un cliente concreto del que se conoce su IP
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
	/**
	 * Funcion de uso interno de esta clase, envía un mensaje de cadena sin estructura concreta hacia todos
	 * los clientes que mantengan conexion con el servidor
	 * 
	 * @param string $msg mensaje que sera enviado a todos y cada uno de los clientes conectados, via WebSocket.
	 */
	protected function mandarTodosUsuarios($msg) 
	{
		
		
		foreach ($this->conns as $conUsu)
		{
				
			$conUsu->write($msg);
	
		}
		
	}
	
	//--------------------------------------------------------------------------
	
	/**
	 * Procesa el envio de una trama a la electronica produce un bloqueo de lectura esperando y evaluando la respuesta
	 * controla cosas como cual es el cliente actual que espera respuesta, emite eventos de error de trama y NodeTimeOut, ademas, las respuestas
	 * recibidas se propagan luego como cualquier otro evento (son los llamados entonces eventos de respuesta prefijo "Ans")
	 * 
	 * @param string $trama trama de bytes con formato de protocolo Kimaldi que contienen una instruccion concreta
	 * @param string $opcRespuestaEsperado (Opcional)
	 * 
	 * @return int: Un valor numerico contenido normalmente en la coleccion $codColectMethodReturn que coincide con los valores que
	 * emiten los metodos de la clase BioNet de Kimaldi, describen el resultado de la operación siendo el valor 0 el valor de operacion correcta
	 */
	
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
	/**
	 * Recibe un OPC y un ARG evalua el tipo de trama recibida por la electronica y llama a una funcion de esta clase
	 * enlazada con el tipo de OPC, el mapeo OPC => funcion se hace en la coleccion $opcColectevent
	 * 
	 * @param unknown $opc valor que codifica un campo OPC de trama de protocolo Kimaldi
	 * @param unknown $arg  valor que codifica un campo ARG de trama de protocolo Kimaldi
	 */
	function evaluaEvento($opc, $arg)
	{
		
		if( array_key_exists($opc, $this->opcColectEvent) )
		{
			//llamamos al metodo que tenga asociado el opc esta definido en el array $this->opcColectEvent
			//con la siguiente estructura $this->opcColectEvent cadenaOpc => cadenaNombreMetodo
			
			//$this->{$this->opcColectEvent[$opc]}($arg);
			
			$nombreFuncion = $this->opcColectEvent[$opc];
			
			//alternativamente a call_user_func para pasar parametros como array...
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
	
	/**
	 * Recibe una trama de bytes en el formato de Kimaldi
	 * desde el objeto $generadorTramas y lo manda hacia la electronica
	 * la trama realiza un reset en caliente de la electronica,  la respuesta de la electronica invoca al manejador de eventos
	 * AnsHotReset()
	 * 
	 * @return int Un valor numerico contenido normalmente en la coleccion $codColectMethodReturn que coincide con los valores que
	 * emiten los metodos de la clase BioNet de Kimaldi, describen el resultado de la operación siendo el valor 0 el valor de operacion correcta
	 *este valor se recibe y se propaga desde la funcion $this->manda_comando_electronica
	 */
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
	/**
	 * Recibe una trama de bytes en el formato de Kimaldi
	 * desde el objeto $generadorTramas y lo manda hacia la electronica, la respuesta de la electronica invoca al manejador de eventos
	 * AnsTestNodeLink()
	 * 
	 * Manda una trama de test, esperando respuesta, sirve para comprobar la comunicacion con la 
	 * electrónica de forma fehaciente
	 *
	 * @return int Un valor numerico contenido normalmente en la coleccion $codColectMethodReturn que coincide con los valores que
	 * emiten los metodos de la clase BioNet de Kimaldi, describen el resultado de la operación siendo el valor 0 el valor de operacion correcta
	 *este valor se recibe y se propaga desde la funcion $this->manda_comando_electronica
	 */
	public function TestNodeLink()
	{
		$trama = $this->generadorTramas->tramaTestNodeLink();
		$this->controlEchoDebugServer( "Enviando trama TestNodeLink: ".$trama );
	
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	
	//-------------------------------------
	/**
	 * Recibe una trama de bytes en el formato de Kimaldi
	 * desde el objeto $generadorTramas y lo manda hacia la electronica
	 * La trama activa una salida digital , durante un tiempo determinado, la respuesta de la electronica invoca al manejador de eventos
	 * AnsActivateDigitalOutput()
	 * 
	 * @param unknown $numOut numero de salida digital (numeradas de 0 a 3 en el caso de la Biomax2) valor hexadecimal
	 * @param unknown $tTime numero de decimas de segundo de la activacion valor hexadecimal
	 * 
	 * @return int Un valor numerico contenido normalmente en la coleccion $codColectMethodReturn que coincide con los valores que
	 * emiten los metodos de la clase BioNet de Kimaldi, describen el resultado de la operación siendo el valor 0 el valor de operacion correcta
	 * este valor se recibe y se propaga desde la funcion $this->manda_comando_electronica
	 */
	public function ActivateDigitalOutput($numOut, $tTime)
	{
		$trama = $this->generadorTramas->tramaActivateDigitalOutput( array($numOut, $tTime) );
		$this->controlEchoDebugServer("Enviando trama ActivateDigitalOutput: ".$trama);
		
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	//--------------------------------
	/**
	 * Recibe una trama de bytes en el formato de Kimaldi
	 * desde el objeto $generadorTramas y lo manda hacia la electronica
	 * La trama Cambia el estado de una salida digital, la respuesta de la electronica invoca al manejador de eventos
	 * AnsSwitchDigitalOutput()
	 * 
	 * @param unknown $numOut Numero de salida digital (numeradas de 0 a 3 en el caso de la Biomax2) valor hexadecimal
	 * @param boolean $mode Determina si la salida adquirirá estado activad o inactivado
	 * 
	 * @return int Un valor numerico contenido normalmente en la coleccion $codColectMethodReturn que coincide con los valores que
	 * emiten los metodos de la clase BioNet de Kimaldi, describen el resultado de la operación siendo el valor 0 el valor de operacion correcta
	 * este valor se recibe y se propaga desde la funcion $this->manda_comando_electronica
	 */
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
	/**
	 * Recibe una trama de bytes en el formato de Kimaldi
	 * desde el objeto $generadorTramas y lo manda hacia la electronica
	 * La trama activa un rele , durante un tiempo determinado, la respuesta de la electronica invoca al manejador de eventos
	 * AnsActivateRelay()
	 *
	 * @param unknown $numRelay numero de rele (numerados de 0 a 3 en el caso de la Biomax2) valor hexadecimal
	 * @param unknown $tTime numero de decimas de segundo de la activacion valor hexadecimal
	 *
	 * @return int Un valor numerico contenido normalmente en la coleccion $codColectMethodReturn que coincide con los valores que
	 * emiten los metodos de la clase BioNet de Kimaldi, describen el resultado de la operación siendo el valor 0 el valor de operacion correcta
	 * este valor se recibe y se propaga desde la funcion $this->manda_comando_electronica
	 */
	public function ActivateRelay($numRelay, $tTime)
	{
		$trama = $this->generadorTramas->tramaActivateRelay( array($numRelay, $tTime) );
		$this->controlEchoDebugServer( "Enviando trama ActivateRelay: ".$trama );
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	//--------------------------------
	/**
	 * Recibe una trama de bytes en el formato de Kimaldi
	 * desde el objeto $generadorTramas y lo manda hacia la electronica
	 * La trama Cambia el estado de un rele, la respuesta de la electronica invoca al manejador de eventos
	 * AnsSwitchRelay()
	 *
	 * @param unknown $numOut Numero de rele (numerados de 0 a 3 en el caso de la Biomax2) valor hexadecimal
	 * @param boolean $mode Determina si el rele adquirira un estado de abierto o cerrado
	 *
	 * @return int Un valor numerico contenido normalmente en la coleccion $codColectMethodReturn que coincide con los valores que
	 * emiten los metodos de la clase BioNet de Kimaldi, describen el resultado de la operación siendo el valor 0 el valor de operacion correcta
	 * este valor se recibe y se propaga desde la funcion $this->manda_comando_electronica
	 */
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
    /**
	 * Recibe una trama de bytes en el formato de Kimaldi
	 * desde el objeto $generadorTramas y lo manda hacia la electronica.
	 * 
	 * La trama solicita a la electronica que lance la trama de evento de entradas digitales que acaba invocando el manejador de evento OnDigitalInput, 
	 * la trama es idéntica a las que genera de forma espontanea la electronica cuando hay cambios en dichas entradas
	 * permite testear y actualizar el estado actual
	 */
	public function TxDigitalInput()
	{
		$trama = $this->generadorTramas->tramaTxDigitalInput();
		$this->controlEchoDebugServer( "Enviando trama TxDigitalInput: ".$trama );
		//-----------*******
		$valorRes = $this->manda_comando_electronica($trama);
		return $valorRes;
	}
	
	
	//---------------------------------------
	/**
	 * Recibe una trama de bytes en el formato de Kimaldi
	 * desde el objeto $generadorTramas y lo manda hacia la electronica
	 * La trama Manda una cadena de texto para que la electrónica la muestre en su display, se produce, además,
	 * una retroiluminación momentánea del Display. 
	 * 
	 * La respuesta de la electronica invoca al manejador de eventos
	 * AnsSwitchRelay()
	 *
	 * @param string $Text Texto a mostrar en la pantalla de los terminales que dispongan de ella, los primeros 20 caracteres corresponderán a la primera línea
	 * los últimos 20 caracteres corresponderán a la segunda.
	 *
	 * @return int Un valor numerico contenido normalmente en la coleccion $codColectMethodReturn que coincide con los valores que
	 * emiten los metodos de la clase BioNet de Kimaldi, describen el resultado de la operación siendo el valor 0 el valor de operacion correcta
	 * este valor se recibe y se propaga desde la funcion $this->manda_comando_electronica
	 */
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
	
	
	//implementar OnKey en la clase que hereda
	/**
	 * Es invocada al recibir 
	 * al recibir una trama de evento de pulsacion de tecla,
	 * llama a su vez a la funcion OnKey que debe ser implementada en una clase que hereda, 
	 * le proporciona el parametro $key que es es el codigo ASCII en hexadecimal, de la tecla pulsada
	 * 
	 * 
	 * @param unknown $arg campo ARG de la trama de bytes recibida, la traduce como el valor $key que es es el codigo ASCII en hexadecimal, de la tecla pulsada
	 */
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
	//implementar OnTrack en la clase que hereda 
	/**
	 * Es invocada al recibir
	 * al recibir una trama de evento de lectura de tarjeta,
	 * llama a su vez a la funcion OnTrack que debe ser implementada en una clase que hereda,
	 * le proporciona el parametro $track que es la cadena de caracteres del codigo leido en la tarjeta
	 *
	 *
	 * @param unknown $arg campo ARG de la trama de bytes recibida, la traduce como el valor $track que es la cadena de caracteres del codigo leido en la tarjeta
	 */
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
	//implementar OnDigitalInput en la clase que hereda (En este caso se pueden usar DOS MANEJADORES DE EVENTOS alternativos, ambos seran llamados si es posible)
	/**
	 * Es invocada al recibir
	 * al recibir una trama de evento de lectura de entradas digitales,
	 * 
	 * En este caso, se pueden usar DOS MANEJADORES DE EVENTOS alternativos, ambos seran llamados si es posible,
	 * ambos seran invocados si se implementan en una clase que hereda, son OnDigitalInput y OnDigitalInputBoolean,
	 * a la primera le proporciona el parametro $arg que es un entero hexadecimal que pasado a binario determina el estado de las entradas
	 * a la segunda le proporciona cuatro parametros booleanos ($din1, $din2, $din3, $din4) que determinan el estado de las entradas
	 *
	 *
	 * @param unknown $arg campo ARG de la trama de bytes recibida, determina en su forma binaria el estado de las entradas digitales
	 */
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
	
	
	/**
	 * Es invocada al recibir una trama de respuesta de reset en caliente, enviada como consecuencia
	 * del uso del método HotReset()
	 * llama a su vez a la funcion AnsHotReset() que debe ser implementada en una clase que hereda,
	 */
	private function procesaAnsHotReset()
	{
		if ( method_exists($this, "AnsHotReset") )
			$this->AnsHotReset();
		else
			$this->controlEchoDebugServer( "Evento de respuesta AnsHotReset lanzado, pero no esta definido ni implementado");
		
	}
	
	//--------------------------------------------------------------------
	/**
	 * Es invocada al recibir una trama de respuesta de test de conexion, enviada como consecuencia
	 * del uso del método TestNodeLink()
	 * llama a su vez a la funcion AnsTestNodeLink() que debe ser implementada en una clase que hereda,
	 */
	private function procesaAnsTestNodeLink()
	{
		if ( method_exists($this, "AnsTestNodeLink") )
			$this->AnsTestNodeLink();
		else
			$this->controlEchoDebugServer( "Evento de respuesta AnsTestNodeLink lanzado, pero no esta definido ni implementado");
	}
	
	//--------------------------------------------------------------------
	/**
	 * Es invocada al recibir una trama de respuesta de activacion temporal de salidas digitales, enviada como consecuencia
	 * del uso del método ActivateDigitalOutput()
	 * llama a su vez a la funcion AnsActivateDigitalOutput() que debe ser implementada en una clase que hereda,
	 */
	private function procesaAnsActivateDigitalOutput()
	{
		if ( method_exists($this, "AnsActivateDigitalOutput") )
			$this->AnsActivateDigitalOutput();
		else
			$this->controlEchoDebugServer( "Evento de respuesta AnsActivateDigitalOutput lanzado, pero no esta definido ni implementado");
	}
	
	//--------------------------------------------------------------------
	/**
	 * Es invocada al recibir una trama de respuesta de cambio de estado de salidas digitales, enviada como consecuencia
	 * del uso del método SwitchDigitalOutput()
	 * llama a su vez a la funcion AnsSwitchDigitalOutput() que debe ser implementada en una clase que hereda,
	 */
	private function procesaAnsSwitchDigitalOutput()
	{
		if ( method_exists($this, "AnsSwitchDigitalOutput") )
			$this->AnsSwitchDigitalOutput();
		else
			$this->controlEchoDebugServer( "Evento de respuesta AnsSwitchDigitalOutput lanzado, pero no esta definido ni implementado");
	}
	
	//--------------------------------------------------------------------
	/**
	 * Es invocada al recibir una trama de respuesta de cambio de activacion temporal de rele, enviada como consecuencia
	 * del uso del método ActivateRelay()
	 * llama a su vez a la funcion AnsActivateRelay() que debe ser implementada en una clase que hereda,
	 */
	private function procesaAnsActivateRelay()
	{
		if ( method_exists($this, "AnsActivateRelay") )
			$this->AnsActivateRelay();
		else
			$this->controlEchoDebugServer( "Evento de respuesta AnsActivateRelay lanzado, pero no esta definido ni implementado");
	}
	
	//--------------------------------------------------------------------
	/**
	 * Es invocada al recibir una trama de respuesta de cambio de cambio de estado de rele, enviada como consecuencia
	 * del uso del método SwitchRelay()
	 * llama a su vez a la funcion AnsSwitchRelay() que debe ser implementada en una clase que hereda,
	 */
	private function procesaAnsSwitchRelay()
	{
		if ( method_exists($this, "AnsSwitchRelay") )
			$this->AnsSwitchRelay();
		else
			$this->controlEchoDebugServer( "Evento de respuesta AnsSwitchRelay lanzado, pero no esta definido ni implementado");
	}

	//--------------------------------------------------------------------
	/**
	 * Es invocada al recibir una trama de respuesta de escritura de texto en display, enviada como consecuencia
	 * del uso del método WriteDisplay()
	 * llama a su vez a la funcion AnsWriteDisplay() que debe ser implementada en una clase que hereda,
	 */
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
	
	/**
	 * Es invocada al detectarse una trama con OPC 0x30
	 * este valor es ambiguo, ya que puede tratarse de una trama de respuesta AnsActivateDigitalOutput, respuesta a una trama de activacion de salida digital
	 * o puede tratarse de un evento de fin de la activacion temporal de salida digital, OnStatusDigitalOutput. Ambas se pueden identificar por el criterio del campo ARG de la trama.
	 * Una vez llevada a cambio la desambiguacion, propaga la orden a cualquiera de los dos eventos potenciales
	 * 
	 * 
	 * @param unknown $arg campo ARG de la trama de bytes recibida, puede contener el numero de salida que acabo la temporalizacion, en caso de OnStatusDigitalOutput
	 * o valor 0 en caso de AnsActivateDigitalOutput
	 */
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
	/**
	 * Es invocada al detectarse una trama con OPC 0x40
	 * este valor es ambiguo, ya que puede tratarse de una trama de respuesta AnsActivateRelay, respuesta a una trama de activacion de rele
	 * o puede tratarse de un evento de fin de la activacion temporal de rele, OnStatusDigitalRelay. Ambas se pueden identificar por el criterio del campo ARG de la trama.
	 * Una vez llevada a cambio la desambiguacion, propaga la orden a cualquiera de los dos eventos potenciales
	 *
	 * @param unknown $arg campo ARG de la trama de bytes recibida, puede contener el numero de rele que acabo la temporalizacion, en caso de OnStatusRelay
	 * o valor 0 en caso de AnsActivateRelay
	 */
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
	/**
	 * Es invocada cuando se pierde la comunicación TCP con la electrónica y el cierre de socket es efectivo
	 * llama a su vez a la funcion TCPClose() que debe ser implementada en una clase que hereda,
	 */
	private function procesaTCPClose()
	{
		if ( method_exists($this, "TCPClose") )
			$this->TCPClose();
		else
			$this->controlEchoDebugServer( "Evento de error TCPClose lanzado, pero no esta definido ni implementado");
	}

	//------------------------------------------------
	//implementar TCPError() en clase heredera
	/**
	 * Es invocada cuando se produce algun error de sistema en la comunicacion TCP
	 * llama a su vez a la funcion TCPError() que debe ser implementada en una clase que hereda,
	 */
	private function procesaTCPError($error)
	{
		if ( method_exists($this, "TCPError") )
			$this->TCPError($error);
		else
			$this->controlEchoDebugServer( "Evento de error TCPError lanzado, pero no esta definido ni implementado, error capturado:\n".$error);
	}
	
	//------------------------------------------------
	//implementar FrameDelay(); en clase heredera
	/**
	 * Es invocada cuando se produce cuando la electronica recibe una instruccion pero
	 * no puede atenderla por estar ocupada.
	 * llama a su vez a la funcion FrameDelay() que debe ser implementada en una clase que hereda,
	 */
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
	/**
	 * Es invocada cuando Se ha agotado el Timeout de comunicaciones con la 
	 * electrónica entre una instrucción y la recepción de su respuesta. (el num de segundos es configurable en Configuracion.php).
	 * llama a su vez a la funcion NodeTimeOut() que debe ser implementada en una clase que hereda,
	 */
	private function procesaNodeTimeOut()
	{
		if ( method_exists($this, "NodeTimeOut") )
			$this->NodeTimeOut();
		else
			$this->controlEchoDebugServer( "Evento de error NodeTimeOut lanzado, pero no esta definido ni implementado");
	}
	
    //------------------------------------------------
	//implementar ErrOpCode() en clase heredera
	/**
	 * Es invocada cuando la electronica recibe trama pero no admite la instruccion, normalmente, OPC no valido
	 * llama a su vez a la funcion ErrOpCode() que debe ser implementada en una clase que hereda,
	 */
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
	/**
	 * Es invocada cuando la electronica recibe trama pero tiene una estructura erronea
	 * normalmente lo ocasionaun CRC no valido, las tramas que no cumplan, hasta cierto punto el formato del protocolo Kimaldi
	 * no obtienen respuesta alguna de la electronica, y no disparara ningun evento de error.
	 * 
	 * llama a su vez a la funcion ErrOpCode() que debe ser implementada en una clase que hereda,
	 */
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
	/**
	 * Mandara tramas de diagnostico para ser monitorizadas en el mismo navegador, la clase jsKimaldiClient las procesa y muestra automaticamente
	 * en un pequeño panel de control, si esta activado su propio modo debug.
	 * 
	 * Puede que en produccion produzcan un trafico innecesario, en desarrollo, proporcionan información util sobre el proceso del lado del servidor
	 * estos mensajes suelen ser mandados automaticamente en el negocio normal de esta aplicación, si no se desea su envio se puede determinar en la clase Configuracion
	 * 
	 * Los mensajes de texto, seran encapsulados en el protocolo jsonKimaldiProtocol (tipo:debug)
	 * 
	 * @param string $texto mensaje de debug que recibira el cliente 
	 * @param React\Socket\Connection|null $connClient parametro clase Connection de ReactPHP para enviarlo a un cliente concreto, el valor null produce un broadcast a todos los clientes
	 */
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
	/**
	 * Control de mensajes del servidor por la salida estandar stdout, agrega timestamp al comienzode linea
	 * 
	 * @param string $msg el mensaje que se emitira
	 * @param boolean $agregaSaltoLinea el mensaje se incluye on saltos de linea y timestamp o es textual
	 */ 
	public function controlEchoDebugServer($msg, $agregaSaltoLinea = true)
	{
		
		$msg = preg_replace('/[^(\x20-\x7F)]*/','', $msg);
		
		if($agregaSaltoLinea)
			echo "\n\n".microtime(true).": ".$msg."\n\n";
		else
			echo $msg;
		
	}
	
	//---------------------------------------------------------------------------
	/**
	 * Esta funcion pone en marcha el loop que mantiene el proceso del servidor, antes inicializa el manejo de algunos eventos, 
	 * se sirve para ello del metodo inicializaEventos() y coloca el socket en modo escucha para admiscion de nuevos clientes.
	 *
	 * Este proceso lo mantiene un loop que se mantiene de forma indefinida, ninguna instruccion que se realice despues la función Run() se ejecutará
	 * a menos que el loop se pare expresamente. Consultar documentacion de React\EventLoop\LoopInterface 
	 */
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