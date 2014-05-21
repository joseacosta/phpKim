<!DOCTYPE html>
<html>
<head>

<meta charset='UTF-8' />





<script src="js/jquery-2.1.1.min.js"></script>

<script src="js/Base.js"></script>
<script src="js/jsKimClient.js"></script>

<link href="css/estilo.css" rel="stylesheet" type="text/css">



<script language="javascript" type="text/javascript">

$(document).ready(	function()
{
	cliente = new miClase();
	//cliente2 = new miClase();
	
	cliente.connectServerWs("127.0.0.1", "12000");
	//cliente2connectServerWs("127.0.0.1", "12000");
	
	cliente.registerButtonClickHandlerByName("test-protocolo", "handlerBoton");
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