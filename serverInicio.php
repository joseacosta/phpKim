<?php
//php htdocs/phpkimaldi/testReact/serverInicio.php

require_once 'src/phpKimServer.php';

//extendemos la clase libremente
Class miServidorKimaldi extends phpKimServer
{
	
	//override metodo
	function OnTrack($track)
	{
		echo "\nEvento onTrack lanzado!!! track:".$track;
		
		$this->TestNodeLink();
		
		//funciona perfect
		/*
		$sql = new mysqli('localhost','root','inntelia','prueba');
		$query = "INSERT INTO `empleados` (`nombre`) VALUES (".$track.")";
				
				
	    if ( $sql->query($query) ) 
	    {
		    echo "A new entry has been added with the `id` of {$sql->insert_id}.";
		} 
		else 
		{
		    echo "There was a problem:<br />$query<br />{$sql->error}";
		}
		 
		// quizas se deberia dejar abierta la conn... habria que evaluarlo
		$sql->close();
		*/
		
		
	}
	//--------------------------------------------------------
	//override metodo de evento
	function OnDigitalInputBoolean($din1, $din2, $din3, $din4)
	{
		echo "\nEvento OnDigitalInputBoolean lanzado!!!\n";
		
		$this->responseClientFunction( "manejaEventoDin", array($din1, $din2, $din3, $din4) );
	}
	
	//--------------------------------------------------------
	//override metodo de evento
	function OnKey($key)
	{
		echo "\nEvento onKey lanzado!!! key:".$key;
	}
	
	//--------------------------------------------------------
	
	function nuevaFuncion($argumento1, $argumento2)
	{
		
		echo "\ncliente ha llamado a la funcion del servidor llamada nuevaFuncion!!! con argumentos:".$argumento1." y ".$argumento2;
		
		echo "\nllamaremos a un a funcion imitando a la API bioNet y veremos que devuelve\n";
		
		
		$result = $this->ActivateRelay(1, 1);
		
		echo "\nla devolucion fue el valor:".$result."\n";
		
		//devolvemos
		echo "\nla tratamos de mandar json para el otro lado\n";
		//JOYA############
		$this->responseClientFunction( "funcionCliente", array("valorarg3", "valorarg4") );
		
	}
	
	
}


//-------------------------------------------------------------------------

//instancia de la clase ya extendida
$servKimaldi = new miServidorKimaldi();

try 
{
	$servKimaldi->OpenPortTCP("192.168.123.10");
	//$servKimaldi->OpenPortTCP("127.0.0.1");
}
catch ( UnexpectedValueException $e) 
{
	echo "\n\nEXCEP CAPTURADA APERTURA TCP___:".$e."\n";	
	
}


//loop sin fin
$servKimaldi->Run();
