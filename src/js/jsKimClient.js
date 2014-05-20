
// define la clase jsKimClient
function jsKimClient() 
{
	//that=this;
	
	this.conectado = false;
	
	//-------------------------------------------------------------------
	
	this.connectServerWs = function(dir, puerto)
	{   
		
		this.wsUri = "ws://"+dir+":"+puerto
		this.websocket = new WebSocket(this.wsUri);
		
		//se captura la referencia del objeto que llamo a esta funcion de forma dinamica para usarlo luego
		//dentro del ambito websocket.onMessage "this" es el propio objetowebsocket, 
		//that = this NO SE PUEDE hacer arriba en el constructor por que capturaria this de la clase padre
		//desvirtuando el efecto de herencia
		that=this
		
		
		//manejadores de eventos del ws que acabamos de crear
		this.websocket.onopen = function(ev) 
		{ 
			$('#message_box').append("<div class=\"system_msg\">Conectado</div>"); //notify user
			that.conectado = true;
		}
		
		
		this.websocket.onerror	= function(ev){$('#message_box').append("<div class=\"system_error\">Error Conexi√≥n ws - "+ev.data+"</div>");}; 
		this.websocket.onclose 	= function(ev){$('#message_box').append("<div class=\"system_msg\">Conexion cerrada</div>");}; 
		this.websocket.onmessage = function(ev){ that.onServerMessage.call(that, ev); };
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
			
			alert("recibido del servidor que llama a la funcion llamada "+funcName+" y con "+args.length+" argumentos");
			
			this.callPorNombre(funcName, args);
		}	
		else if(tipo == 'debugMsg')//TODO un interesantisimo recurso para el debug proveninete del serividor en un div...
		{
			//TODO DEPENDIENTE DE HTML INSERTAR MEJOR NUEVO DIV DE DEBUG AL PRINCIPIO SI NO EXISTE!!!
			$('#message_debug').append("<div class=\"system_msg\">"+umsg+"</div>");
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
	this.registerButtonClickHandler = function(idBoton, funcion)
	{
		if (typeof funcion != "function")
		{
			alert(" El handler de click no parece ser una funcion v·lida (llamada registerButtonClickHandler, clase: "+this.constructor.name+")");
			return false;
		}
		
		//a partir de aqui.. con jquery ya harias el onclick...
	}
	
	//-------------------------------------------------------------------
	
	this.callFuncServer = function(funcServerName, args)
	{console.log(this);
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
			alert(nombre+", no parece ser una funcion v·lida (llamada callPorNombre, clase: "+this.constructor.name+")");
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

jsKimClient.extender(miClase);

function miClase()
{
	
	//that = this;
	//codigo aqui es como si fuera en el constructor
	//this.connectServerWs("echo.websocket.org", "80")//nada
	this.funcionCliente = function()
	{
		alert("aqui estoy y tengo argumentos");
	}
	
}

cliente = new miClase();



cliente.connectServerWs("127.0.0.1", "12000");

//tiempo para que se conecte...
setTimeout(function(){cliente.callFuncServer("nuevaFuncion", ["algo", "otra cosa"]); }, 3000);



