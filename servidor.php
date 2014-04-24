<?php

//php htdocs/phpkimaldi/testReact/servidor.php

require_once 'phpKim.php';




require __DIR__.'/vendor/autoload.php';




//INSTANCIA#######################################
$miKimal = new KimalPHP();

$tramaReset = $miKimal->createFrame(0x01, "");

$dataRele = chr(3) . chr(5);
$tramaCierraRele = $miKimal->createFrame(0x40, $dataRele);

/*
 * 
 * 
 */


$loop = React\EventLoop\Factory::create();

$socket = new React\Socket\Server($loop);

$conns = new \SplObjectStorage();


//stream que conecta con la electronica
$client = stream_socket_client('tcp://192.168.123.10:1001');

//quizas aqui se deberia determinarsi $cliente que es un stream_socket... es valido, habra forma???


//objeto react/connection que contiene el stream que conecto con la electronica
$conex = new React\Socket\Connection($client, $loop);
//$conex->pipe(new React\Stream\Stream(STDOUT, $loop));
$conns->attach($conex);







$conex->on('data', function ($data) 
				   {
						
					    echo "\nevento de electronica localizado con data: ".$data."\n\n";
								
					    $mensaje_electronica = mask(json_encode(array('type'=>'usermsg', 'name'=>'Ans', 'message'=>$data, 'color'=>'black')));
					    mandarTodosUsuarios($mensaje_electronica); //send data
						
				   });







$socket->on('connection', function ($conn) use ($conns, $conex, $tramaCierraRele, $miKimal) 
						  {
						  	
							    $conns->attach($conn);
							    
								echo "\nnueva conexion entrante.....\n";
							    
							
							    $conn->on('data',   function ($data) use ($conns, $conn, $conex, $tramaCierraRele, $miKimal) 
												    {
												        
												    	//al conectar un cliente, este evento ya salta, vamosa ver si esta mandando peticion de conexion websocket
												    	if(mensajeEsDeApertura($data))
												    	{
												    		
												    		echo "\nparece ser cabecera de apertura websocket\n\n";
												    		
												    		perform_handshaking($data, $conn, '192.168.0.145');
												    		
												    		//hadshake completado, no seguimos
												    		return;
												    		
												    	}
												    	
												    	echo $conn->getRemoteAddress().": escribio trama RAW:\n".$data."\n\n";
												    	
												    	
												    	
												    	
												    	$received_text = unmask($data); //unmask data
												    		

												    	echo $conn->getRemoteAddress().": escribio trama desenmascarada:\n".$received_text."\n\n";
												    	
												    	
												    	$tst_msg = json_decode($received_text); //json decode
												    	
												    	//el usuario manda un comando
												    	if(isset($tst_msg->tipo) && $tst_msg->tipo == 'cmd')
												    	{
												    	
												    		$opc = $tst_msg->opc;
												    		$argumentos = $tst_msg->arg;
												    		$tipoArgumento = $tst_msg->argType;
												    			
												    		echo "\nusuario manda comando, opc:#".dechex($opc)."#, numArg:#".count($argumentos)."#\n";
												    			
												    		$arg="";
												    			
															//si se pretende pasar una cadena de carateres como argumentos
															//asi esta bien, si no, hay que pasarle los numerosconcatenados,
															//recibe valores decimales pro que el objeto que genera las tramas, al final los traduce de nuevo a hexa
												    		if($tipoArgumento == "char")
												    		{
												    			$arg = $argumentos;
												    		}	
												    		else
												    		{
												    			foreach ($argumentos as $unarg)
												    			{
												    				//strval(dechex($opc));
												    				$arg .= hexdec($unarg);
												    			}
												    		}
												    		
												    		
												    			
												    			
												    			
												    		$trama = $miKimal->createFrame($opc, $arg, $tipoArgumento);
												    			
												    			
												    		echo "\n trama generada #".$trama."#\n";
												    			
												    		//notifica que un usuario manda una trama puede joder la performance un poco
												    		/*
												    		 $response = mask(json_encode(array('type'=>'system', 'message'=>$ip.' send->'.$trama)));
												    		send_message($response);
												    		*/
												    			
												    		manda_comando_electronica($trama);
												    	
												    	
												    	
												    	}
												    	else //el usuario manda mensage normal
												    	{
												    		$ipRemitente =	$conn->getRemoteAddress();
												    		
												    		$user_name = $tst_msg->name; //sender name
												    		$user_message = $tst_msg->message; //message text
												    		$user_color = $tst_msg->color; //color
												    	
												    		$response_text = mask(json_encode(array('type'=>'usermsg', 'name'=>$ipRemitente, 'message'=>$user_message, 'color'=>$user_color)));
												    	
												    		
												    		mandarTodosUsuarios($response_text, $ipRemitente);
												    	
												    	}
												    	

												        
												    });
							    
							
							    $conn->on('end',    function () use ($conns, $conn) 
												    {
												    	echo "desconectado IP:".$conn->getRemoteAddress();
												    	
												        $conns->detach($conn);
												    });
							    
						 });



echo "Socket server listening on port 10000.\n";
echo "You can connect to it by running: telnet localhost 10000\n";

$socket->listen(10000, '192.168.0.145'); //ojito que si no le pones ip como segundo parametro, SOLO funcionara en   
$loop->run();











/*
 * 
 * 
 * 
 * IMPLEMENTACION FUNCIONES
 * 
 * 
 * 
 */

function mandarTodosUsuarios($msg) 
{
	
	global $conns;
	
	foreach ($conns as $current)
	{
			
		$current->write($msg);

	}
	
}


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


function manda_comando_electronica($trama)
{

	global $conex;
	$conex->write($trama);

}


//manda mensaje a todos los sockets clientes de la lista, evitandoelsocket de electronica
//recibe pues json codificado paraprotocolo ws
function send_message($msg)
{
	echo "\n\n nos disponemos a mandar este mensaje:\n###".$msg."###\n";
	global $clients;
	global $socketElectronica;
	foreach($clients as $changed_socket)
	{
		if ($changed_socket == $socketElectronica)
		{
			echo "\n\n evitaremos mandar al socket electronica este mensaje:\n###".$msg."###\n";
			continue;
		}

		@socket_write($changed_socket,$msg,strlen($msg));
	}
	return true;
}


//Unmask incoming framed message
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

//Encode message for transfer to client.
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

//handshake new client.
function perform_handshaking($receved_header,$conn, $host)
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
			"WebSocket-Origin: $host\r\n" .
			"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
	
	$conn->write($upgrade);
}