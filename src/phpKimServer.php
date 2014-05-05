<?php

require_once 'phpKim.php';

require __DIR__.'/../vendor/autoload.php';



Class phpKimServer extends React\Socket\Server
{
	
	protected $miKimal;
	protected $miLoop;
	protected $conns;
	protected $conexElectronica;
	
	protected $dirIPElectronica = "192.168.123.10";
	protected $puertoElectronica= "1001";
	
	protected $ipLocal = '192.168.0.145';
	protected $puertoEscucha = 10000;
	
	
	
	
	//#########CONSTRUCTOR
	public function __construct() 
	{
		$this->miLoop = React\EventLoop\Factory::create();
		
		//constructor del padre 
		parent::__construct($this->miLoop);
		
		
		//INSTANCIA de clase generadora de tramas protocolo Kimaldi
		$this->miKimal = new KimalPHP();
		
		$this->conns = new \SplObjectStorage();
		
		//stream que conecta con la electronica
		$clientStreamElectronica = stream_socket_client("tcp://".$this->dirIPElectronica.":".$this->puertoElectronica);
		//objeto react/connection que contiene el stream que conecto con la electronica
		$this->conexElectronica = new React\Socket\Connection($clientStreamElectronica, $this->miLoop);
		
		//agregamos el socket de electronica a la coleccion
		$this->conns->attach($this->conexElectronica);
		
		//llamamos a nuestra funcion de inicio de eventos
		$this->iniciaEventos();
	}

	
	//--------------------------------------------------------------------------
	
	
	protected function iniciaEventos()
	{
		
		//manejador para evento on data de la conexion de electronica, datos y finalizacion
		$this->conexElectronica->on('data', [$this,'onDataElectronica']);
		$this->conexElectronica->on('end', [$this, 'onFinalizacionElectronica']);
		
		
		//manejador de evento del servidor en general, basicamente, evento de nueva conexion entrante
		$this->on('connection', [$this,'onConexionEntrante']);
		
		
	}
	
	//--------------------------------------------------------------------------
	
	protected function onDataElectronica($data)
	{
		echo "\nevento de electronica localizado con data: ".$data."\n\n";
		
		$mensaje_electronica = $this->mask(json_encode(array('type'=>'usermsg', 'name'=>'Ans', 'message'=>$data, 'color'=>'black')));
		$this->mandarTodosUsuarios($mensaje_electronica); //send data
	}
	
	//--------------------------------------------------------------------------
	
	protected function onFinalizacionElectronica($conn)
	{
		echo "desconectada electronica, IP:".$conn->getRemoteAddress();
	
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
		
					//al conectar un cliente, este evento ya salta, vamosa ver si esta mandando peticion de conexion websocket
					if($this->mensajeEsDeApertura($data))
					{
											
							echo "\nparece ser cabecera de apertura websocket\n\n";
											
							$this->perform_handshaking($data, $connCliente, $this->ipLocal);
											
							//handshake completado, no seguimos
							return;
											
					}
							
					echo $connCliente->getRemoteAddress().": escribio trama RAW:\n".$data."\n\n";
							

					$received_text = $this->unmask($data); //unmask data
										
							
					echo $connCliente->getRemoteAddress().": escribio trama desenmascarada:\n".$received_text."\n\n";
							
							
					$tst_msg = json_decode($received_text); //json decode
							
					//el usuario manda un comando
					if(isset($tst_msg->tipo) && $tst_msg->tipo == 'cmd')
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
							
						//notifica que un usuario manda una trama puede joder la performance un poco
						/*
							$response = mask(json_encode(array('type'=>'system', 'message'=>$ip.' send->'.$trama)));
							send_message($response);
						*/
							
						$this->manda_comando_electronica($trama);
							
							
							
					}
					
					else //el usuario manda mensage normal (es solo texto plano)
					{
							$ipRemitente =	$connCliente->getRemoteAddress();
											
							$user_name = $tst_msg->name; //sender name
							$user_message = $tst_msg->message; //message text
							$user_color = $tst_msg->color; //color
							
							$response_text = $this->mask(json_encode(array('type'=>'usermsg', 'name'=>$ipRemitente, 'message'=>$user_message, 'color'=>$user_color)));
							
											
							$this->mandarTodosUsuarios($response_text, $ipRemitente);
							
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
		
		//ojito que si no le pones ip del servidor en el que estamos como segundo parametro, SOLO funcionara en local
		$this->listen($this->puertoEscucha, $this->ipLocal); 
		
		$exito = $this->miLoop->run();
		
		if($exito)
		{
			echo "Socket servidor con ip a la escucha en puerto.\n";
		}
		else 
		{
			echo "error loop->run";	
		}
		
	}
	
	
	//--------------------------------------------------------------------------
	
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
	
	function manda_comando_electronica($trama)
	{
	
		if ($this->conexElectronica == null)
		{
			echo "\n\n ERROR el socket con la electronica NO se encuentra abierto, no e sposible mandar tramas\n";
			return;
		}
		
		$this->conexElectronica->write($trama);
	
	}
	
	//--------------------------------------------------------------------------
	//manda mensaje a todos los sockets clientes de la lista, evitandoelsocket de electronica
	//recibe pues json codificado paraprotocolo ws
	function send_message($msg)
	{
		echo "\n\n nos disponemos a mandar este mensaje:\n###".$msg."###\n";
		global $clients;
		
		foreach($clients as $changed_socket)
		{
			if ($changed_socket == $this->socketElectronica)
			{
				echo "\n\n evitaremos mandar al socket electronica este mensaje:\n###".$msg."###\n";
				continue;
			}
	
			@socket_write($changed_socket,$msg,strlen($msg));
		}
		return true;
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
		
		//la cadena que vemos arriba es una cadena mágica
		$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
		//hand shaking header
		$upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
				"Upgrade: websocket\r\n" .
				"Connection: Upgrade\r\n" .
				"WebSocket-Origin: $iphost\r\n" .
				"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
		
		$conn->write($upgrade);
	}
	




}