<?php


class WsServerManager
{
	
	
	//--------------------------------------------------------------------------
	//completa el handshakeusando HTTP con el objeto conexion dado y la cabecera request recibida
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
	/*
	 * Determina si un mensaje es una peticion HTTP de apertura de conexion Websocket (Handshake)
	 * 
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
	//descodifica la trama websoket recibida
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
	//codifica (enmascara) mensaje en formato de trama de websocket
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