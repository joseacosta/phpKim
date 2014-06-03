<!DOCTYPE html>
<html>
<head>

<meta charset='UTF-8' />



<script src="js/jquery-2.1.1.min.js"></script>
<script src="js/jsKimClient.js"></script>
<script src="js/extensionJsKimClient.js"></script>
<script src="js/jsKimClientCollection.js"></script>

<link href="css/estilo.css" rel="stylesheet" type="text/css">



<script language="javascript" type="text/javascript">

$(document).ready(	function()
{
	
	
	
	var cliente = new miClase();
	var cliente2 = new miClase();
	
	
	cliente.registerButtonClickHandlerByName("test-protocolo", "handlerBoton");

	cliente2.registerButtonClickHandlerByName("test-protocolo2", "handlerBoton");

	jsKimClientCollection.onClientCollectionConnected = function(){alert("evento: todos los clientes estan conectados")};
	
	jsKimClientCollection.addClient(cliente);
	jsKimClientCollection.addClient(cliente2);

	cliente2.onOpenWebsocket= function()
	{   
		alert("capturo el onload, mira la consola");
		console.log(this);
	}

	cliente.connectServerWs("192.168.0.145", "12000");
	cliente2.connectServerWs("192.168.0.149", "12000");

	//timepo para conectarse
	//setTimeout(  function(){ jsKimClientCollection.callFuncServerByIp("192.168.0.145", "nuevaFuncion", ["cosa1", "cosa2"]);  }, 3000);
	
	
	//Lo mismo pero usando funcion en lugar de nombre
	//cliente2.registerButtonClickHandler("test-protocolo2", cliente2.handlerBoton);
});

</script>

</head>

<body>

<div class="envoltorio_monitor">

<div class="message_box" id="message_box"></div>

<br/>

<div class="panel">
	

<input type="text" name="message" id="message"  maxlength="80" style="width:60%" />
<button id="send-btn">SendMsg</button>

<br/>
<br/>

<button id="test-protocolo">test Protocolo KimJSON</button>

<button id="test-protocolo2">test Protocol KimJSON2</button>

<br/>
<br/>

<fieldset>

	            <legend>Digital Input</legend>
	            
	            	<center>
		            	<br/>
		            	<div id='casiDIN1' class="casilleroDIN"></div>
		            	<div id='casiDIN2' class="casilleroDIN"></div>
		            	<div id='casiDIN3' class="casilleroDIN"></div>
		            	<div id='casiDIN4' class="casilleroDIN"></div>
		            	<br/>
		            	<br/>
		            	<button id="btnTXDigitalInput">TXDigitalInput</button>
	            	</center>
	            	
</fieldset>


</div>


</div>


</body>


</html>