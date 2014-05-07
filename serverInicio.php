<?php

//php htdocs/phpkimaldi/testReact/serverInicio.php

require_once 'src/phpKimServer.php';

$servKimaldi = new phpKimServer();

try 
{
	$servKimaldi->OpenPortTCP("192.168.123.10");
}
catch ( UnexpectedValueException $e) 
{
	echo "\n\nEXCEP CAPTURADA :::".$e."\n";	
	
}

//$servKimaldi->TxDigitalInput();

//loop sin fin
$servKimaldi->Run();
