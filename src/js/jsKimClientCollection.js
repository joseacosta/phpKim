
var jsKimClientCollection = {
		
		"addClient": function (cliente){
			
			if (cliente instanceof jsKimClient == false)
			{
				alert("Err. se intento agregar a la coleccion de clientes un objeto inadecuado (instancia de:"+cliente.constructor.name+"), se requieren instancias de la clase jsKimClient o derivadas: (Llamada addClient(), obj: jsKimClientCollection)");
				return false;
			}
			
			this.clients.push(cliente);
		},
		
		//lo retira de la coleccion pero no elimina el objeto
		"removeClient": function (cliente){
			
			this.clients = $.grep(this.clients, function(elem) 
												{
											        return elem !== cliente;
											    });
			
		},
		
		//lo retira de la coleccion pero no elimina el objeto
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
		
		"callFuncServerMasivo": function(funcServerName, args){
			
			var numCli = this.clients.length;
			
			for (var x = 0; x < numCli; x++)
			{
				this.clients[x].callFuncServer(funcServerName, args);
			}
			
			
		},
		
		"callFuncServerByIp": function(ip, funcServerName, args){
			
			var cli = this.findClientByServerIp(ip);
			
			if (!cli)
			{
				return false;
			}
			else
			{
				cli.callFuncServer(funcServerName, args);
				return true;
			}
			
			
		},
		
		"clients" : []

};