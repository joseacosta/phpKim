<?php
namespace phpKimaldiServer;

/**
 *Esta clase controla el proceso de cabeceras de cliente entrantes y completa  el Handshake de WebSockets
 *tambien codifica y descodifica tramas de formato websocket
 *
 *@author Jose Acosta
 */
class WsServerManager
{
	
	
	//--------------------------------------------------------------------------
	//completa el handshake usando HTTP con el objeto conexion dado y la cabecera request recibida
	
	/**
	 * Completa el HandShake con un cliente que solicita la conexion WebSocket, toma para ello la cabecera Request que env�a el cliente
	 * en HTTP y un objeto de conexion React\Socket\Connection para completar el proceso, precisa un tercer parametro que e sla Ip propia del servidor
	 * necesario para el campo WebSocket-Origin: de la cabecera HTTP Request
	 * 
	 * @param string  $receved_header cabecera HTTP Request de petici�n de Handshake que env�a el nuevo cliente
	 * @param React\Socket\Connection $conn objeto que encapsula la conexion con el cliente entrante
	 * @param string $iphost Direccion Ip propia del servidor
	 */
	function perform_handshaking($receved_header,$conn, $iphost)
	{
		$headers = array();
	
		//separacion lineas
		$lines = preg_split("/\r\n/", $receved_header);
	
		//desglose de la peticion HTTP
		foreach($lines as $line)
		{
			//trim por la derecha
			$line = chop($line);
			
			if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
			{
				$headers[$matches[1]] = $matches[2];
			}
		}

		
		$secKey = $headers['Sec-WebSocket-Key'];
	
		//la cadena que vemos arriba es una cadena magica
		$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
		
		//cabecera de hand shaking
		$upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
					"Upgrade: websocket\r\n" .
					"Connection: Upgrade\r\n" .
					"WebSocket-Origin: $iphost\r\n" .
					"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
	
		//lo mandamos a la conexion adecuada(objeto recibido por param) 
		$conn->write($upgrade);
	}
	
	//--------------------------------------------------------------------------

	/**
	 * Determina si un mensaje es una cabecera Request HTTP de apertura de conexion Websocket (Handshake)
	 * 
	 * @param string $mensaje
	 * @return boolean Devuelve el true en caso de ser cabecera HTTP de petici�n de HandShake Websocket y false en caso negativo
	 */
	function mensajeEsDeApertura($mensaje)
	{
		//ojo al matiz stripos es igual a strpos pero insensible a mayusculas
		if( stripos( $mensaje, "Upgrade: websocket") > -1 )
		{
			return true;
		}
		else
		{
			return false;
		}
	
	}
	
	//--------------------------------------------------------------------------
	/**
	 * Descodifica una trama en formato WebSocket codificada y la devuelve descodificada 
	 * 
	 * @param string $text la trama en formato WebSocket codificada
	 * @return string devuelve la cadena del mensaje contenido en la trama websocket codificada
	 */
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
	/**
	 * Codifica o enmascara un mensaje y lo envuelve en una trama codificada con formato adecuado para el protocolo WebSocket
	 * 
	 * @param string $text el mensaje que se pretende incrustar en una trama de protocolo WebSocket
	 * @return string Devuelve una trama completa, cumpliendo las directrices del protocolo WebSocket
	 */
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
	
	
}