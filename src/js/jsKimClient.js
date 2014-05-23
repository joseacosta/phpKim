
// define la clase jsKimClient
function jsKimClient() 
{
	//that=this;
	
	this.conectado = false;
	
	

	//------------------------------------------------------------------
	
	this.connectServerWs = function(dir, puerto)
	{   
		
		this.wsUri = "ws://"+dir+":"+puerto
		this.websocket = new WebSocket(this.wsUri);
		
		//cuando aludimos a "this" dentro del ambito de un evento de websocket o de click de boton HTML
		//siempre estamos aludiendo al objeto websocket (en si) o al boton HTML, por lo tanto se pierde
		//la referencia this de la instancia de esta clase, pararemediarlo le agregamos a los objetos
		//que manejen eventos , en el momento de su creacion un atributo que ayude a acceder a la referecnia de esta instancia
		this.websocket.referenciaThis = this;
		
		//manejadores de eventos del ws que acabamos de crear
		this.websocket.onopen = function(ev) 
		{ 
			$('#message_box').append("<div class=\"system_msg\">Conectado "+this.referenciaThis.wsUri+"</div>"); //notify user
			this.referenciaThis.conectado = true;
		}
		
		
		this.websocket.onerror	= function(ev){$('#message_box').append("<div class=\"system_error\">Error ConexiÃ³n "+this.referenciaThis.wsUri+ " - "+ev.data+"</div>"); this.referenciaThis.conectado = false}; 
		this.websocket.onclose 	= function(ev){$('#message_box').append("<div class=\"system_msg\">Conexion cerrada "+this.referenciaThis.wsUri+"</div>"); this.referenciaThis.conectado = false}; 
		this.websocket.onmessage = function(ev){ that.onServerMessage.call(this.referenciaThis, ev); };
	}
	
	//-------------------------------------------------------------------
	
	this.onServerMessage = function(ev)
	{
		
		var msg = JSON.parse(ev.data); //PHP sends Json data
		var tipo = msg.tipo; 
		
		

		//***
		if(tipo == 'userMsg') 
		{
			var umsg = msg.message; //mensaje en si
			var uname = msg.name; //concepto del mensaje
			var ucolor = msg.color; //color
			
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
		else if(tipo == 'debugMsg')//TODO un interesantisimo recurso para el debug proveninete del servidor en un div...
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
	//se le pasa el id de un boton html y se vincula con una funcion que hayamos implementado en la clase
	this.registerButtonClickHandlerByName = function(idBoton, nombreFunc)
	{
		var laFuncion = this[nombreFunc];
		
		if (typeof laFuncion != "function")
		{
			alert(" El nombre "+nombreFunc+" no parece pertenecer ser una funcion válida (llamada registerButtonClickHandlerByName, clase: "+this.constructor.name+")");
			return false;
		}
		
		if($('#'+idBoton).length == 0)
		{
			alert ("elemento con id \""+idBoton+"\" inexistente, o todavia no cargado");
			return false
		}
		if($('#'+idBoton).length > 1)
		{
			alert ("elementos con id \""+idBoton+"\" existe duplicidad en el uso de id, debería ser único");
			return false
		}
		
		sender = $('#'+idBoton);
		
		$('#'+idBoton)[0].referenciaThis = this;
		
		$('#'+idBoton).click( function(){laFuncion.call(this.referenciaThis, sender); } );
	}
	
	//-------------------------------------------------------------------
	this.registerButtonClickHandler = function(idBoton, laFuncion)
	{
		
		if (typeof laFuncion != "function")
		{
			alert(" El handler de click con no parece ser una funcion válida (llamada registerButtonClickHandlerByName, clase: "+this.constructor.name+")");
			return false;
		}
		
		if($('#'+idBoton).length == 0)
		{
			alert ("elemento con id \""+idBoton+"\" inexistente, o todavia no cargado");
			return false
		}
		if($('#'+idBoton).length > 1)
		{
			alert ("elementos con id \""+idBoton+"\" existe duplicidad en el uso de id, debería ser único");
			return false
		}
		
		that = this
		sender = $('#'+idBoton);
		
		$('#'+idBoton).click( function(){ laFuncion.call(that, sender); } );
	}
	
	//-------------------------------------------------------------------
	
	this.callFuncServer = function(funcServerName, args)
	{
		if(!this.conectado)
		{
			alert("err. cliente no conectado con el servidor ws (llamada callFuncServer, clase: "+this.constructor.name+")");
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
	
	//permite llamar a una funcion por su nombre, pasandole, ademas un array de argumentos
	this.callPorNombre = function(nombre, args)
	{  
		var laFuncion = this[nombre];
											
		if (typeof laFuncion != "function")
		{
			alert(nombre+", no parece ser una funcion válida (llamada callPorNombre, clase: "+this.constructor.name+")");
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



//testtesttesttesttesttesttesttesttesttesttest-------------------------

//herencia#################
jsKimClient.extender(miClase);

function miClase()
{
	
	
	this.funcionCliente = function(arg1, arg2)
	{
		alert("aqui estoy y tengo argumentos uno:"+arg1+" y otro:"+arg2);
	}
	//--------
	
	this.handlerBoton = function(sender)
	{
		this.callFuncServer("nuevaFuncion", ["cosa", 0.5]);
	}
	
	//----------
	
//--------
	
	this.handlerBotonAlternativa = function(sender)
	{
		this.callFuncServer("nuevaFuncion", ["cosa", 0.5]);
	}
	
	//--------
	
	this.manejaEventoDin = function(din1, din2, din3, din4)
	{
		//alert("evento entradas digitales:"+din1+din2+din3+din4);
		
		if(din1)
			this.activaCasillaDin(1);
    	else
    		this.desactivaCasillaDin(1);
    	
    	if(din2)
    		this.activaCasillaDin(2);
    	else
    		this.desactivaCasillaDin(2);
    	
    	if(din3)
    		this.activaCasillaDin(3);
    	else
    		this.desactivaCasillaDin(3);
    	
    	if(din4)
    		this.activaCasillaDin(4);
    	else
    		this.desactivaCasillaDin(4);
    	
	}
	
	//--------
	this.activaCasillaDin = function(numeroCasilla)
	{
	    $('#casiDIN'+numeroCasilla).css( "background-color", "green" );
	}
	//-------
	this.desactivaCasillaDin = function(numeroCasilla)
	{
	    $('#casiDIN'+numeroCasilla).css( "background-color", "white" );
	}
	
	
	
}







