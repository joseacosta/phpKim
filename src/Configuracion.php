<?php
namespace KimaldiServerNamespace;

/**
 * Esta clase contiene todas las variables que son configurables en la aplicacion
 * se obtiene asi un acceso centralizado a ellas, cada una es un atributo estatico de la clase
 * 
 * 
 * @author Jose Acosta
 *
 */
class Configuracion
{
	/**
	 * la direccion ip que tiene la electronica, hade conpartir subred con el servidor
	 * @var string
	 */
	public static $ipElectronica = "192.168.123.10";
	
	/**
	 * Tiempo de espera maximo que mantiene el servidor al establecer una conexion con la electronica
	 * @var int
	 */
	public static $timeoutConnElectronica = 5;
	
	/**
	 * Tiempo de espera maximo que mantiene el servidor al mandar una instruccion hacia la electronica aguardando respuesta
	 * @var int
	 */
	public static $timeoutNodeTimeOut = 3;
	
	/**
	 * Determina si la conexion con la electronica se manda una instruccion de HotReset de forma automatica
	 * @var boolean
	 */
	public static $hotResetAutomatico = true;
	
	//Parametros de servidor WebSocket 
	//usando $ip = shell_exec("ifconfig eth0 | grep 'inet addr:' | cut -d: -f2 | awk '{ print $1}'") DISTRIBUCION EN ESPA�OL usar cadena 'Direc. inet:'
	//se podria obtener de forma dinamica la ip de este nodo
	//public static $ipLocalServer = '192.168.0.145';
	
	/**
	 * Direcci�n IP del servidor que permanece a la escucha de nuevas conexiones
	 * @var string
	 */
	public static $ipLocalServer = '192.168.1.128';
	
	/**
	 * Puerto por el que el servidor permanecera a la escucha de nuevas conexiones
	 * @var string
	 */
	public static $puertoEscuchaServer = 12000;
	
	/**
	 * Con esta directiva activada el servidor mandara a cada cliente tramas de diagnostico para ser monitorizadas en el mismo navegador
	 * estos mensajes cumplen con el protocolo jsonKImaldiProtocol. Puede que en produccion produzcan un trafico innecesario
	 * 
	 * @var boolean
	 */
	public static $debug_client_mode = true;
	
	/**
	 * Con esta directiva se establece si el servidor escribira salidas de monitorizacion del proceso por su salida standar (stdout)
	 * con demonio administrador que gestione el proceso de forma adecuado la salida por stdout puede ser almacenada en un archivo de log
	 * si la aplicacion corre en primer plano, los mensajes seran visualizados en la linea de comandos
	 * 
	 * @var unknown
	 */
	public static $debug_log_mode = true;
	
	
	//valores de ejemplos de configuracion custom
	//definiciones de base de datos, solo para el caso eventual de acceso a un servicio de BD
	//public static $bdServer = '192.168.0.145';
	public static $bdServer = "localhost";
	public static $bdUsuario = "root";
	public static $bdPass = "";
	public static $baseDatos = "kimalditest";
	
	
	
	
}