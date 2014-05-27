

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
	
	this.handlerBotonAlternativa = function(sender)
	{
		this.callFuncServer("nuevaFuncion", ["cosa", 0.5]);
	}
	
	//--------
	
	this.manejaEventoDin = function(din1, din2, din3, din4)
	{
		
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
