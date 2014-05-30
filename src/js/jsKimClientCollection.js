
var jsKimClientCollection = {
		
		"addClient": function (cliente){
			this.clients.push(cliente);
		},
		
		//lo retira de la coleccion pero no elimina rl objeto
		"removeClient": function (cliente){
			
			this.clients = $.grep(this.clients, function(elem) 
												{
											        return elem !== cliente;
											    });
			
		},
		
		
		"removeClientByServerIp": function (ip){
			
			this.clients = $.grep(this.clients, function(elem) 
												{
											        return elem.serverUri !== ip;
											    });
			
		},
		
		
		"findClientByServerIp": function (ip){
			
			var arrayCoincidentes = $.grep(this.clients, function(elem) 
									{
								        return elem.serverUri == ip;
								    });
			
			//piensa que se puede dar el caso de dos clientes conectados al mismo server, es un caso quizas absurdo,
			//pero posible, en ese caso hemos decidido devolver solo el primero de ellos
			if(arrayCoincidentes.length > 0)
				return arrayCoincidentes[0];
			else
				return false;
			
		},
		
		"findClientByIndex": function (index){
			
			//si accedemos e un elemento que produciria overflow del array, devolvemos false
			if(index >= this.clients.length)
				return false
			else
				return this.clients[index];
			
		},
		
		"getClientCount": function(){
			
			return this.clients.length;
			
		},
		
		"clients" : []

};