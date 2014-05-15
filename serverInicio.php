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
	
	//override metodo
	function OnKey($key)
	{
		echo "\nEvento onKey lanzado!!! key:".$key;
	}
	
}


//-------------------------------------------------------------------------


$servKimaldi = new miServidorKimaldi();

try 
{
	$servKimaldi->OpenPortTCP("192.168.123.10");
}
catch ( UnexpectedValueException $e) 
{
	echo "\n\nEXCEP CAPTURADA APERTURA TCP___:".$e."\n";	
	
}


//loop sin fin
$servKimaldi->Run();
