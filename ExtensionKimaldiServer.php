<?php

//php htdocs/phpkimaldi/testReact/ExtensionKimaldiServer.php
//php C:\xampp\htdocs\pyt\phpKimServer\phpKim\ExtensionKimaldiServer.php


//si no utilizamos el /vendor/autoload que ha generado composer, podemos usar los includes tradicionales
/*
require_once 'src/Configuracion.php';
require_once 'src/PhpKimaldiServer.php';
*/

require __DIR__.'/vendor/autoload.php';


//extendemos la clase libremente
Class ExtensionKimaldiServer extends KimaldiServerNamespace\PhpKimaldiServer
{
	
	private $conexionDB;
	
	
	
	
	//#########CONSTRUCTOR
	//IMPORTANTE, si queremos hacer override del constructor es necesario que llamemos siempre primero al constructor del padre
	public function __construct()
	{
		//llamar constructor del padre IMPORTANTE
		parent::__construct();
		
		//inicializamos la conexion con la base de datos
		$this->conexionDB = new mysqli(KimaldiServerNamespace\Configuracion::$bdServer, KimaldiServerNamespace\Configuracion::$bdUsuario, KimaldiServerNamespace\Configuracion::$bdPass, KimaldiServerNamespace\Configuracion::$baseDatos);
		
	}
	
	
	//--------------------------------------------------------
	//override metodo de evento OnTrack
	function OnTrack($track)
	{
		
		echo "\nEvento onTrack lanzado!!! track:".$track;
		
		$this->informaClientesResultadoLecturaTarjeta($track);
		

		//###Primero controlamos si el nodo tiene asociada una taquilla en la bd y guardo su id
		$result = $this->conexionDB->query("SELECT id_taquillas 
											from taquillas 
											where ip_nodo = '".$this->ipLocal."'");
		
		if ($result->num_rows == 0)
		{
			echo "\nLa direcci�n de este nodo no controla ninguna taquilla en la Base de Datos: ".$this->ipLocal."\n\n";
			return;
		}
		
		//buscamos en primer row,ya que taquillas tiene ip_nodo UNIQUE
		$row = $result->fetch_assoc();
		$id_taqui = $row['id_taquillas'];
		
		//----------
		
		
		//###Segundo controlamos, mirando el numero de tarjeta, cual es el empleado que la posee
		$result = $this->conexionDB->query("SELECT id_empleados 
											from empleados 
											where num_tarjeta = '".$track."'");
		
		if ($result->num_rows == 0)
		{
			echo "\nNo existe empleado con numero de tarjeta equivalente a track: ".$track."\n\n";
			
			//MODO SUPER restrictivo, una tarjeta err�nea hace saltar la alarma del rel� num 2 (indice 1)
			//comentar siguientes dos lineas para evitarlo
			//$this->ActivateRelay(1, 0xA);
			//echo "\nModo restrictivo, tarjeta no autorizada, dispara Alarma\n";
			
			return;
		}	
		
		//buscamos en primer row,ya que empleados tiene num_tarjeta UNIQUE
		$row = $result->fetch_assoc();
		$id_emp = $row['id_empleados'];
		
		//----------
		
		
		
		//###Tercero, controlamos, mirando el id de empleado y el id de taquilla, si el empleado es POSEEDOR de esa taquilla
		//segun la logica de la base de datos
		$result = $this->conexionDB->query("SELECT id_empleados 
											from empleados 
											where num_tarjeta = '".$track."' and id_empleados = (select id_empleados from taquillas where id_taquillas=".$id_taqui.")");
		
		if ($result->num_rows == 0)
		{
			echo "\nEl empleado con id ".$id_emp." y num_tarjeta ".$track." NO es poseedor de la taquilla que controla este Nodo\n nodo con IP: ".$this->ipLocal.", controla taquilla con id:".$id_taqui."\n\n";
			return;
		}
		
		
		
		
		//###Cuarto, por fin!!! producimos apertura de taquilla, rel� 0,  5 decimas de segundo (son numeros hexa)
		echo "\nEmpleado y Tarjeta autenticados para la taquilla de este nodo, se procede a la apertura de puerta\n\n";
		$resultadoActivateRelay = $this->ActivateRelay(0, 5);
		
		//que codigo responde ActivateRelay??, ha de ser == 0
		if($resultadoActivateRelay > 0)
		{
			echo "\nErr. Se intento abrir puerta pero ActivateRelay() no tuvo exito\n\n";
			return;
		}
		
		echo "\nACCESO PERMITIDO!!!\n\n";
		
		$query = "insert into accesos (id_taquillas, id_empleados, fecha_hora, num_tarjeta)
				  values(".$id_taqui.",".$id_emp.",'".date("Y-m-d H:i:s")."','".$track."')";
				
				
	    if ( $this->conexionDB->query($query) ) 
	    {
		    echo "\nOntrack produjo registro de acceso en Base de Datos\n\n";
		} 
		else 
		{
		    echo "\nProblema de insercion de registro acceso en Base de Datos\n\n";
		    return;
		}
		 
		
		
	}
	//--------------------------------------------------------
	//override metodo de evento OnDigitalInputBoolean
	function OnDigitalInputBoolean($din0, $din1, $din2, $din3)
	{
		
		echo "\nEvento OnDigitalInputBoolean lanzado!!!\n";
		
		//el DIN-0 variable $din0, esta asociado a la apertura o cierre de puerta
		//(su activacion implica cierre)
		if($din0)
		{	
			//con broadcast informamos a todo cliente que estuviera conectado a este server/nodo
			$this->broadcastClientFunction( "clientePuertaCerrada", array() );
		}
		else 
		{	
			//con broadcast informamos a todo cliente que estuviera conectado a este server/nodo
			$this->broadcastClientFunction( "clientePuertaAbierta", array() );
		}
		
	}
	
	//--------------------------------------------------------
	//override metodo de evento de respuesta
	function AnsTestNodeLink()
	{
		echo "\nEvento de respuesta AnsTestNodeLink lanzado!!!";
		
		//la func response solo responde al cliente que mando la trama que origino la esta respuesta
		$this->responseClientFunction( "clienteAnsTestNodeLink", array() );
	}
	
	//--------------------------------------------------------
	//override metodo de error NodeTimeOut
	function NodeTimeOut()
	{
		echo "\nEvento de respuesta AnsTestNodeLink lanzado!!!";
	
		//la func response solo responde al cliente que mando la trama que origino este nodetimeout
		$this->responseClientFunction( "clienteNodeTimeOut", array() );
	}
	
	//--------------------------------------------------------
	//esta funcion espera ser llamada por el cliente cuando desee evaluar la conexion
	//con la electronica, puede disparar el evento NodeTimeOut o simplemente informar de que 
	function serverEvaluaConnElectronica()
	{
		//hacemos un TestNodeLink, evaluamos el valor que devuelve la funcion 1 = puerto conn TCP no abierta
		//notese que en caso de evento NodeTimeOut el servidor tiene implementado codigo para responder al cliente consecuentemente
		
		$valor = $this->TestNodeLink();
		
		if ($valor > 0)
		{
			$this->responseClientFunction( "clienteEvaluaConnElectronica", array($valor) );
		}
		
	}
	
	//--------------------------------------------------------
	
	function informaClientesResultadoLecturaTarjeta($mensaje)
	{
		
		//con broadcast informamos a todo cliente que estuviera conectado a este server/nodo
		//llamamos a una funcion del cliente por su nombre
		$this->broadcastClientFunction( "mensajeEntranteLecturaTarjeta", array($mensaje) );
		
	}

	//--------------------------------------------------------
	function functionTest($argumento1, $argumento2)
	{
		
		echo "\ncliente ha llamado a la funcion del servidor llamada nuevaFuncion!!! con argumentos:".$argumento1." y ".$argumento2;
		
		echo "\nllamaremos a un a funcion imitando a la API bioNet y veremos que devuelve\n";
		
		//buzzer int
		$result = $this->ActivateDigitalOutput(3, 1);
		
		echo "\nLa devolucion fue el valor:".$result."\n";
		
		//devolvemos
		echo "\nActuamos en consecuencia la tratamos de mandar json para el otro lado\n";
		
		//la func response solo responde al cliente que mando la instruccion de llamar a esta funcion
		$this->responseClientFunction( "funcionCliente", array("valorarg3", "valorarg4") );
		
	}
	
	
	
}


//-------------------------------------------------------------------------
//RUN RUN RUN 
//instancia de la clase ya extendida
$servKimaldi = new ExtensionKimaldiServer();


//conexion con la electronica, por defecto va atener siempre ala ip fija usada aqui
$valorconexion = $servKimaldi->OpenPortTCP( KimaldiServerNamespace\Configuracion::$ipElectronica,  KimaldiServerNamespace\Configuracion::$puertoElectronica );

//probando conexion con uart2 de la electronica en la raspberrry, OJO a la IP local del serv... servira 127.0.0.1?
//cuidado tb con el puerto configurado en 53r2n3t, ip de electronica, es el propio host, y el puerto esta conf a 4000
//$valorconexion = $servKimaldi->OpenPortTCP( KimaldiServerNamespace\Configuracion::$ipLocalServer,  KimaldiServerNamespace\Configuracion::$puertoElectronica); 
	
echo "\nValor de conexion devuelto por openporttcp:".$valorconexion."\n";

//RUNRUNRUNRUNRUNRUNRUNRUNRUNRUNRUNRUNRUNRUN#############################################
//loop sin fin, ninguna instruccion mas alla de este punto se ejecutara
$servKimaldi->Run();
