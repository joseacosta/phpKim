alert("carga de script");

function unaClase(param) 
{
	
	var that = this;
	
	//acesible desde fuera (desde dentro usando "that")
    this.member = param;
    
    //no accesible desde fuera, (desde dentro como cualquier otra variable)
    var secret = 3;
   

    function dec() 
    {
    	
        if (secret > 0) 
        {
            secret -= 1;
            return true;
        } 
        else 
        {
            return false;
        }
        
    }

    this.funcMiembro =  function (factor, cadena) 
				        {
    	                	var multiplicacion = secret*factor
    	                	return "multiplicacion: "+multiplicacion+", argCadena: "+cadena;
				        };
				   
    this.funcMiembro2 =  function () 
				    {
				        that.member;
				    };
				    
	this.callPorNombre = function(nombre, args)
	                     {
		                        //las funciones miembro de la clase si que son accesibles mediante 
		                        //this o that, en sete caso tien sentido ceñirnos a that
								var laFuncion = that[nombre];
								
		
								if (typeof laFuncion != "function")
								{
									alert(nombre+", no parece ser una funcion válida");
									return false;
								}
								
								
								alert(this.funcMiembro(100, "haaaal"));
								
								//a ver como hacemos para pasar los argumentos, usa el metodo "apply() del objeto function"
								return laFuncion(args[0], args[1]);
								
								/*
								//alternativa mas sucia usando eval()
								var llamadaNombreFunc = "that"+"."+nombre;
								//notese como aquí hacemos que todos los argumentos se pasen entre comillas, como no hay tipado,
								//los numeros entre comillas siguen funcionando como numeros en el contexto adecuado
								var llamadaArgumentos = '("'+args.join('","')+'")';
								return eval( llamadaNombreFunc+llamadaArgumentos );
								*/
	                     };
	                                         
				    
				    
}




var unaInstancia = new unaClase('abc');



//como comprobar si una funcion/campo existe??
var laFuncion = unaInstancia["funcMiembro"];

if (typeof laFuncion != "function")
{
	alert("funcMiembro"+", no parece ser una funcion válida");
}




var devuelto = unaInstancia.callPorNombre("funcMiembro", [5.5, "unacadena"]);

alert (devuelto);

//accesible desde fuera
alert (unaInstancia.member);

//no accesible desde fuera, mostrara undefined
alert (unaInstancia.secret);

/*
 * 
 * 
 * 
 * 
 * 
 */



var Person = Base.extend({
	
	  constructor: function(name) 
	  	        {
				       this.name = name;
		        }
	  ,
	  
	  propiedadPerson: "valor de la propiedad person"
		  
	  ,
	  
	  walk: function(arg, arg2) 
	        {
	       		alert("ando como una persona, argumento: "+arg+", argumento2: "+arg2);
	        }
	  ,
	  
	  sayHello: function() 
	  	     {
		  	      alert("hola, como persona");
	  	     }
	  
	  ,
	  
	  callPorNombre : function(nombre, args)
		           {  
					var laFuncion = this[nombre];
								
				
					if (typeof laFuncion != "function")
					{
						alert(nombre+", no parece ser una funcion válida");
						return false;
					}
								
								//a ver como hacemos para pasar los argumentos, usa el metodo "apply() del objeto function"
								return laFuncion.apply(this, args);
								
		           }
	  
	});

	
//Herencia

var Student = Person.extend({
		
	  propiedadStudent : "valor student"
		  
	  ,
	  
      //override
	  sayHello: function() 
	  			{
		    		   alert("hola, como estudiante");
	  			}
	  
	});
	  
var estu = new Student();

estu.walk = function(arg1, arg2){
	
	alert("ando como un estudiante, argumento: "+arg1+", argumento2: "+arg2);
}


estu.sayHello();

//recuerda que estas dos son del padre
estu.walk("cosita");
alert (estu.propiedadPerson)


//la madre del corderoooooooooo
estu.callPorNombre("sayHello", []);


