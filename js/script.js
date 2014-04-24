

function inicio()
{
	/*
	 implementar el sorteo de color con js y no php
	 
	 <?php 
	$colours = array('007AFF','FF7000','FF7000','15E25F','CFC700','CFC700','CF1100','CF00BE','F00');
	$user_colour = array_rand($colours);
	?>
	
	 */
	
	
	//objeto websoket.
	var wsUri = "ws://192.168.0.145:10000"; 	
	websocket = new WebSocket(wsUri); 
	
	//ev conexion exitosa
	websocket.onopen = function(ev) 
	{ 
		$('#message_box').append("<div class=\"system_msg\">Connected!</div>"); //notify user
	}
	
	websocket.onerror	= function(ev){$('#message_box').append("<div class=\"system_error\">Error Conexión ws - "+ev.data+"</div>");}; 
	websocket.onclose 	= function(ev){$('#message_box').append("<div class=\"system_msg\">Conexion cerrada</div>");}; 
	
	
	
	
	//###########################################################################################
	
	/*
	 * eventos ws
	 * 
	 */						
		
	
	//#### Ev mensaje recibido via ws
	websocket.onmessage =   function(ev) 
							{
								var msg = JSON.parse(ev.data); //PHP sends Json data
								var type = msg.type; //message type
								var umsg = msg.message; //message text
								var uname = msg.name; //user name
								var ucolor = msg.color; //color
						
								if(type == 'usermsg') 
								{
									$('#message_box').append("<div><span class=\"user_name\" style=\"color:#"+ucolor+"\">"+uname+"</span> : <span class=\"user_message\">"+umsg+"</span></div>");
								}
								if(type == 'system')
								{
									$('#message_box').append("<div class=\"system_msg\">"+umsg+"</div>");
								}
						
							     $('#message_box').scrollTop($('#message_box').prop("scrollHeight"));		
						
								$('#message').val(''); //reset text
							};
	
							
	/*
	 * 	fin eventos ws
	 * 
	 */	
							
	//###########################################################################################
							
	/*
	 * 
	 * 
	 * BOTONES TEST
	 * 
	 * 
	 */						

	$('#send-btn').click(   function()
							{ 	
								var mymessage = $('#message').val(); //get message text
								
								
								
								if(mymessage == ""){ //emtpy message?
									alert("introduzca mensaje");
									return;
								}
								
								//prepare json data
								var msg = 
								{
									tipo:"mensaje",
									message: mymessage,
									name: "noname",
									color : "007AFF" //'<?php echo $colours[$user_colour]; ?>' //el sorteo de colores deberia manejarse con javascript no php
								};
								
								//convert and send data to server
								websocket.send(JSON.stringify(msg));
								
							});
	
	

	$('#sendCloseRelayTest').click(function()
									{ 
										
										var msg = 
										{
											tipo: "cmd",
											comando: "generico",
											opc: 0x40,
											arg: [0x00,0x05],
											color : "black"
										};
										
										//convert and send data to server
										websocket.send(JSON.stringify(msg));
										
									});
	
	
	
	
	
	
    $('#sendResetTest').click(function()
    							{ 
		
									
									
									var msg = 
									{
										tipo: "cmd",
										comando: "generico",
										opc: 0x01,
										arg: [],
										color : "000000"
									};
									
									//convert and send data to server
									websocket.send(JSON.stringify(msg));
									
								});
	

    
    $('#sendLed1Test').click(function()
									{ 
										
										var msg = 
										{
											tipo: "cmd",
											comando: "generico",
											opc: 0x30,
											arg: [0x00,0x05],
											color : "black"
										};
										
										//convert and send data to server
										websocket.send(JSON.stringify(msg));
										
									});
    
    $('#sendLed2Test').click(function()
									{ 
										
										var msg = 
										{
											tipo: "cmd",
											comando: "generico",
											opc: 0x30,
											arg: [0x01,0x05],
											color : "black"
										};
										
										//convert and send data to server
										websocket.send(JSON.stringify(msg));
										
									});
    
    
    
    $('#sendBeepTest').click(function()
								{ 
									
									var msg = 
									{
										tipo: "cmd",
										comando: "generico",
										opc: 0x30,
										arg: [0x03,0x01],
										color : "black"
									};
									
									//convert and send data to server
									websocket.send(JSON.stringify(msg));
									
								});
    
    /*
     * 
     * FIN BOTONES TEST
     * 
     * 
     */
    
    //###########################################################################################
    
    /*
     * 
     * 
     * BOTONES GENERICOS
     * 
     */
    
    $('#btnActivateRelay').click(function()
								{ 	
					
							    	var arg1 = $('#relayNum').val(); 
							    	var arg2 = $('#relayTime').val();
									
									var msg = 
									{
										tipo: "cmd",
										comando: "generico",
										opc: 0x40,
										arg: [arg1,arg2],
										color : "black"
									};
									
									//convert and send data to server
									websocket.send(JSON.stringify(msg));
									
								});
    
    
    $('#btnSwitchRelayOn').click(function()
			{ 	

		    	var arg1 = $('#relayNum').val(); 
		    	var arg2 = 0x01;
				
				var msg = 
				{
					tipo: "cmd",
					comando: "generico",
					opc: 0x41,
					arg: [arg1,arg2],
					color : "black"
				};
				
				//convert and send data to server
				websocket.send(JSON.stringify(msg));
				
			});
    
    
    $('#btnSwitchRelayOff').click(function()
			{ 	

		    	var arg1 = $('#relayNum').val(); 
		    	var arg2 = 0x00;
				
				var msg = 
				{
					tipo: "cmd",
					comando: "generico",
					opc: 0x41,
					arg: [arg1,arg2],
					color : "black"
				};
				
				//convert and send data to server
				websocket.send(JSON.stringify(msg));
				
			});
    
    
    
    
    $('#btnActivateDigitalOut').click(function()
			{ 	

		    	var arg1 = $('#outputNum').val(); 
		    	var arg2 = $('#outputTime').val();
				
				var msg = 
				{
					tipo: "cmd",
					comando: "generico",
					opc: 0x30,
					arg: [arg1,arg2],
					color : "black"
				};
				
				//convert and send data to server
				websocket.send(JSON.stringify(msg));
				
			});

    $('#btnSwitchDigOutOn').click(function()
			{ 	

		    	var arg1 = $('#outputNum').val(); 
		    	var arg2 = 0x01;
				
				var msg = 
				{
					tipo: "cmd",
					comando: "generico",
					opc: 0x31,
					arg: [arg1,arg2],
					color : "black"
				};
				
				//convert and send data to server
				websocket.send(JSON.stringify(msg));
				
			});
    
    
    $('#btnSwitchDigOutOff').click(function()
			{ 	

		    	var arg1 = $('#outputNum').val(); 
		    	var arg2 = 0x00;
				
				var msg = 
				{
					tipo: "cmd",
					comando: "generico",
					opc: 0x31,
					arg: [arg1,arg2],
					color : "black"
				};
				
				//convert and send data to server
				websocket.send(JSON.stringify(msg));
				
			});
	
	

    $('#btnTXDigitalInput').click(function()
			{ 	

				var msg = 
				{
					tipo: "cmd",
					comando: "generico",
					opc: 0x60,
					arg: [],
					color : "black"
				};
				
				//convert and send data to server
				websocket.send(JSON.stringify(msg));
				
			});
    
	/*
	 * 
	 * 
	 * FIN BOTONES GENERICOS
	 * 
	 * 
	 * 
	 */
    
}