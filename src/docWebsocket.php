<!DOCTYPE html>
<html>
<head>

	<meta charset='UTF-8' />
	
	
	
	
	
	<script src="js/jquery-2.1.1.min.js"></script>
	<script src="js/script.js"></script>
	
	<link href="css/estilo.css" rel="stylesheet" type="text/css">
	

	
	<script language="javascript" type="text/javascript">  
	
	$(document).ready(	function()
						{
							inicio();
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
				
				<input type="text" name="comando" id="comando" placeholder="comando" maxlength="80" style="width:60%" />
				<button id="send-cmd-btn">SendComando</button>
				
				<br/>
				<br/>
				
				
				<fieldset>
	            <legend>Test Comandos</legend>
				
					<button id="sendResetTest">HotReset</button>
					
					<button id="sendCloseRelayTest">Temp Close Relay</button>
					
					<button id="sendBeepTest">Beep Interno</button>
						
					<button id="sendLed1Test">Led Verde</button>
					
					<button id="sendLed2Test">Led Rojo</button>
				
				</fieldset>
				
				
				
				<br/>
				
				
				
				<fieldset class="grupoi">
	            <legend>Relay</legend>
	            	
	            	Relay Number (00-03):
	            	<input type="text" id="relayNum"  maxlength="4"  />
	            	
	            	<br/>
	            	Relay Time (00-FF):
	            	<input type="text" id="relayTime"  maxlength="4"  />
	            	
	            	<br/><br/>
	            	
	            	<button id="btnActivateRelay">ActivateRelay</button>
	            	
	            	<br/>
	            	
	            	<button id="btnSwitchRelayOn">SwitchRelayOn</button>
	            	<button id="btnSwitchRelayOff">SwitchRelayOff</button>
	            	
	            </fieldset>
				
				
			
				
				
				
				<fieldset class="grupod">
	            <legend>Digital Out</legend>
	            	
	            	Num Output (00-03):
	            	<input type="text" id="outputNum"  maxlength="4"  />
	            	
	            	<br/>
	            	Out Time (00-FF):
	            	<input type="text" id="outputTime"  maxlength="4"  />
	            	
	            	<br/><br/>
	            	
	            	<button id="btnActivateDigitalOut">ActivateDigitalOutput</button>
	            	
	            	<br/>
	            	
	            	<button id="btnSwitchDigOutOn">SwitchDigOutOn</button>
	            	<button id="btnSwitchDigOutOff">SwitchDigOutOff</button>
	            	
	            </fieldset>
	            
	            
	            <div class='cleaner'><div>
	            
	            
	            <fieldset class="grupoi">
	            <legend>Display</legend>
	            	
	            	Linea:
	            	<input type="text" id="textLineaUno"  maxlength="20"  />
	            	
	            	<br/>
	            	Linea:
	            	<input type="text" id="textLineaDos"  maxlength="20"  />
	            	
	            	<br/><br/>
	            	<button id="btnWriteDisplay">WriteDisplay</button>
	            	<button id="btnClearDisplay">ClearDisplay</button>
	            	
	            	<br/>
	            	
	            	<button id="btnSwitchBackLightOn">SwitchBackLightOn</button>
	            	<button id="btnSwitchBackLightOff">SwitchBackLightOff</button>
	            	
	            </fieldset>
	            
	            
	            
	            
	            <fieldset class="grupod">
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
			
				<div class='cleaner'><div>
			
		
			</div>
	
	</div>

</body>


</html>