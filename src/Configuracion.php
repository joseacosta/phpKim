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
	public static $conexionElectronicaAuto = true;
	public static $timeoutConnElectronica = 5;
	public static $timeoutNodeTimeOut = 3;
	
	//parametros de servidor WebSocket
	public static $ipLocalServer = '192.168.0.145';
	public static $puertoEscuchaServer = 12000;
	

	
	//definiciones de base de datos, solo para el caso eventual de acceso a un servicio de BD
	public static $bdServer = "localhost";
	public static $usuario = "root";
	public static $pass = "";
	public static $baseDatos = "practica_agenda";
	
}