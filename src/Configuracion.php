<?php

/**
 * 
 * Esta clase contiene todas las variables que son configurables en la aplicacion
 * se obtiene asi un acceso centralizado a ellas, cada una es un atributo estatico de la clase
 * 
 * 
 * @author Jose Acosta
 *
 */
class Configuracion
{
	//conexion con la electronica
	public static $ipElectronica = "192.168.123.10";
	public static $timeoutConnElectronica = 5;
	public static $timeoutNodeTimeOut = 3;
	public static $hotResetAutomatico = true;//al conectar realizar un hot reset, necesario para que algunas funcionalidades de la placa se establezcan
	
	//parametros de servidor WebSocket 
	//usando $ip = shell_exec("ifconfig eth0 | grep 'inet addr:' | cut -d: -f2 | awk '{ print $1}'") DISTRIBUCION EN ESPAÑOL usar cadena 'Direc. inet:'
	//se podria obtener de forma dinamica la ip de este nodo
	//public static $ipLocalServer = '192.168.0.145';
	public static $ipLocalServer = '192.168.1.128';
	public static $puertoEscuchaServer = 12000;
	

	
	//definiciones de base de datos, solo para el caso eventual de acceso a un servicio de BD
	//public static $bdServer = '192.168.0.145';
	public static $bdServer = "localhost";
	public static $bdUsuario = "root";
	public static $bdPass = "";
	public static $baseDatos = "kimalditest";
	
	
	//con esta directiva activada el servidor mandara a cada cliente tramas de diagnostico para ser monitorizadas en el mismo navegador
	//puede que en produccion produzcan un trafico innecesario
	public static $debug_client_mode = true;
	
	public static $debug_log_mode = true;
	
}