
// define la clase jsKimClient
function jsKimClient() 
{
	//that=this;
	
	this.conectado = false;
	this.conectandose = false;
	
	//TODO manejar la recepcion y muestra opcional de mensajes debug, igual mandar json de debug deberia ser iniciativa gestionada solo por el servidor
	this.debugServer = true;
	
	this.debugClient;
	
	

	//------------------------------------------------------------------
	
	this.connectServerWs = function(dir, puerto)
	{   
		if(this.conectado || this.conectandose)
		{
			alert("el cliente que intenta conectar con ws://"+dir+":"+puerto+" ya se encuentra conectado, o esta en proceso, con "+this.wsUri);
			return false;
		}
		
		this.conectandose = true;
		
		this.wsUri = "ws://"+dir+":"+puerto;
		this.serverUri = dir;
		this.websocket = new WebSocket(this.wsUri);
		
		//cuando aludimos a "this" dentro del ambito de un evento de websocket o de click de boton HTML
		//siempre estamos aludiendo al objeto websocket (en si) o al boton HTML, por lo tanto se pierde
		//la referencia this de la instancia de esta clase, para remediarlo guardaremos la referencia del objeto
		//al que debe hacer referencia "this" en una variable, y nos servimos de la funcion call para llamar a la funcion que deseemos
		//dandole un conexto concreto para "this"
		var that = this;
		
		//otro enfoque que use antes
		//this.websocket.referenciaThis = this;
		
		$('#message_box').append("<div class=\"system_msg\">Abriendo conexion con "+this.wsUri+".......</div>");
		
		//manejadores de eventos del ws que acabamos de crear
		this.websocket.onopen = function(ev) 
								{  
									//TODO DEPENDIENTE DE HTML!!!
									$('#message_box').append("<div class=\"system_msg\">Conectado!!! "+that.wsUri+"</div>");
									that.conectado = true;
									that.conectandose = false;
									that.onOpenWebsocket.call(that, ev);
									that.addReadyClientToCollection.call(that);
								};
								
		//TODO DEPENDIENTEsss DE HTML!!!
		this.websocket.onerror	= function(ev)
								  {
									$('#message_box').append("<div class=\"system_error\">Error Conexión "+that.wsUri+ " - "+ev.data+"</div>"); 
									that.conectado = false; 
									that.conectandose = false;
									that.onErrorWebsocket.call(that, ev); 
								  }; 
								  
		this.websocket.onclose 	= function(ev)
								  {
									$('#message_box').append("<div class=\"system_msg\">Conexion cerrada "+that.wsUri+"</div>"); 
									that.conectado = false;  
									that.conectandose = false;
									that.onCloseWebsocket.call(that, ev);
								  }; 
								  
		this.websocket.onmessage = function(ev){ that.onServerMessage.call(that, ev); };
	
	}
	
	//-------------------------------------------------------------------
	//es llamada en el .onOpen del websocket
	this.onOpenWebsocket = function(ev)
	{
		//implementar en clase heredera
	}
	//-------------------------------------------------------------------
	//es llamada en el .onError del websocket
	this.onErrorWebsocket = function(ev)
	{
		//implementar en clase heredera
	}
	//-------------------------------------------------------------------
	//es llamada en el .onClose del websocket
	this.onCloseWebsocket = function(ev)
	{
		//implementar en clase heredera
	}
	
	
	//-------------------------------------------------------------------
	//se llama en el .onOpen del websocket, esta pensada para ser implementada por el objeto de coleccion de clientes, manejara cada evento de conexion para controlar cuando esten todas listas
	this.addReadyClientToCollection = function(ev)
	{
		//implementada en objeto jsKimCientCollection
	}
	//---------------------------------------------------------------------
	
	this.onServerMessage = function(ev)
	{
		
		var msg = JSON.parse(ev.data); //PHP sends Json data
		var tipo = msg.tipo; 
		
		

		//***
		if(tipo == 'userMsg') 
		{
			var umsg = msg.message; //mensaje en si
			var uname = msg.name; //concepto del mensaje
			var ucolor = msg.color; //el servidor puede elegir color para el mensaje
			
			//TODO DEPENDIENTE DE HTML!!!
			$('#message_box').append("<div><span class=\"user_name\" style=\"color:#"+ucolor+"\">"+uname+"</span> : <span class=\"user_message\">"+umsg+"</span></div>");
			
		}
		else if(tipo == 'func')
		{
			var funcName = msg.funcName;
			var args = msg.args;
			var serverIp= msg.server;
			
			//alert("recibido, el servidor "+serverIp+", llama a la funcion con nombre "+funcName+" pasando "+args.length+" argumentos");
			
			this.callPorNombre(funcName, args);
		}	
		else if(tipo == 'debugMsg')
		{   
			var servmsg = msg.message;
			var serverIp= msg.server;
			//TODO DEPENDIENTE DE HTML INSERTAR MEJOR NUEVO DIV DE DEBUG AL PRINCIPIO SI NO EXISTE!!!
			$('#message_debug').append("<div class=\"system_msg\">server@"+msg.server+" debug: "+servmsg+"</div>");
			$('#message_box').append("<div class=\"system_msg\">server@"+msg.server+" debug: "+servmsg+"</div>");
		}
		else if(tipo == 'system')
		{
			//TODO DEPENDIENTE DE HTML!!!
			$('#message_box').append("<div class=\"system_msg\">"+umsg+"</div>");
		}
		else
		{
			alert("mensaje recibido sin formato JSON adecuado: "+ev.data);
		}

		//TODO DEPENDIENTE DE HTML!!!
	    $('#message_box').scrollTop($('#message_box').prop("scrollHeight"));		
	    //TODO DEPENDIENTE DE HTML!!!
		$('#message').val(''); //reset text
		
	}
	//-------------------------------------------------------------------
	//se le pasa un objeto jQuery se vincula con una funcion que hayamos implementado en la clase
	//acepta el nombre de la funcion (cadena)
	//Notese que el objeto JQuery puede ser toda una coleccion a la que se enlacara el manejador de click
	//el sender es en cualquier caso recuperable
	this.registerJqueryClickHandlerByName = function(objJquery, nombreFunc)
	{
		var laFuncion = this[nombreFunc];
		
		if (typeof laFuncion != "function")
		{
			alert(" Err. el nombre "+nombreFunc+" no parece pertenecer ser una funcion v�lida (llamada registerButtonClickHandlerByName, clase: "+this.constructor.name+")");
			return false;
		}
		
		
		if(!objJquery instanceof jQuery)
		{
			alert ("elemento \""+idBoton+"\" no parece ser un objeto jquery valido");
			return false
		}
	
		
		//El ev.target (dentro del conexto del click de jQuery) sera el SENDER que espera recibir como argumento nuestro handler de evento
		//para emular el comportamiento de la palabra reservada this en otros lenguajes cuando se maneja un evento
		//vamosa hacer que el .click del boton llame al handler usando call para darle el contexto de "this" que nosotros queramos
		//en otro caso "this" se referiria al propio elemento pulsado
		var that = this;
		
		objJquery.click( function(event){ laFuncion.call( that, $(event.target) ); } );
	}
	
	//-------------------------------------------------------------------
	//se le pasa el id de un boton html y se vincula con una funcion que hayamos implementado en la clase
	//acepta el nombre de la funcion (cadena)
	this.registerButtonClickHandlerByName = function(idBoton, nombreFunc)
	{
		var laFuncion = this[nombreFunc];
		
		if (typeof laFuncion != "function")
		{
			alert(" Err. el nombre "+nombreFunc+" no parece pertenecer ser una funcion v�lida (llamada registerButtonClickHandlerByName, clase: "+this.constructor.name+")");
			return false;
		}
		
		if($('#'+idBoton).length == 0)
		{
			alert ("elemento con id \""+idBoton+"\" inexistente, o todavia no cargado");
			return false
		}
		if($('#'+idBoton).length > 1)
		{
			alert ("elementos con id \""+idBoton+"\" existe duplicidad en el uso de id, deber�a ser �nico");
			return false
		}
		
		//El objeto jquery aludido por su id sera el SENDER que espera recibir como argumento nuestro handler de evento
		//para emular el comportamiento de la palabra reservada this en otros lenguajes cuando se maneja un evento
		//vamosa hacer que el .click del boton llame al handler usando call para darle el contexto de "this" que nosotros queramos
		//en otro caso "this" se referiria al propio elemento pulsado
		var that = this;
		var sender = $('#'+idBoton);
		
		$('#'+idBoton).click( function(){laFuncion.call(that, sender ); } );
	}
	
	//-------------------------------------------------------------------
	//se le pasa el id de un boton html y se vincula con una funcion que hayamos implementado en la clase
	//acepta el propio objeto de la funcion en s�
	this.registerButtonClickHandler = function(idBoton, laFuncion)
	{
		
		if (typeof laFuncion != "function")
		{
			alert("Err. el handler de click con no parece ser una funcion v�lida (llamada registerButtonClickHandlerByName, clase: "+this.constructor.name+")");
			return false;
		}
		
		if($('#'+idBoton).length == 0)
		{
			alert ("Err. elemento con id \""+idBoton+"\" inexistente, o todavia no cargado");
			return false
		}
		if($('#'+idBoton).length > 1)
		{
			alert ("Err. mas de un elemento con id \""+idBoton+"\" existe duplicidad en el uso de id, deber�a ser �nico");
			return false
		}
		
		var that = this;
		var sender = $('#'+idBoton);
		
		$('#'+idBoton).click( function(){ laFuncion.call(that, sender); } );
	}
	
	//-------------------------------------------------------------------
	//Permite llamar a una funcion (en la clase receptora definida en el servidor) por su nombre, pasandole, ademas un array de argumentos
	//se sirve para ello del protocolo definido como interfaz entre el cliente browser y el servidor
	this.callFuncServer = function(funcServerName, args)
	{
		if(!this.conectado)
		{
			alert("Err. cliente no conectado con el servidor ws con URI:"+this.wsUri+" (llamada callFuncServer, clase: "+this.constructor.name+")");
			return;
		}
		
		var msg = 
		{
			tipo:"func",
			funcName: funcServerName,
			args: args
		};
		
		//mandar JSON que llamara funcion del servidor por nombre
		this.websocket.send(JSON.stringify(msg));
	
	}
	
	//-------------------------------------------------------------------
	
	//Permite llamar a una funcion (de esta misma clase, en el cliente) por su nombre, pasandole, ademas un array de argumentos
	this.callPorNombre = function(nombre, args)
	{  
		var laFuncion = this[nombre];
											
		if (typeof laFuncion != "function")
		{
			alert("Err. "+nombre+", no parece ser una funcion v�lida (llamada callPorNombre, clase: "+this.constructor.name+")");
			return false;
		}
										
		return laFuncion.apply(this, args);
	}
	
	
	
	
}


//-------------------------------------------------------------------
//agregado a clase jsKimClient y no a una instancia, ni al prototype , este metodo es accesible sin instanciar
//y es el equivalente a una funcion ESTATICA DE jsKimClient
//posibilita que una clase dada herede de esta usando el patron "Pseudo-Classic"
jsKimClient.extender = function($heredera)
						{
							$heredera.prototype = new jsKimClient();
							
							//despues del codigo anterior, el constructor del prototipo de la clase heredera apunta al cnstructor de la clase madre
							//un hack para solventarlo
							$heredera.prototype.constructor = $heredera;
							
					    }










