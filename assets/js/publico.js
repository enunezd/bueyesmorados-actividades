// Pide confirmación en los botones con data-confirm="¿Seguro?"
document.addEventListener('click',function(ev){
  var b=ev.target.closest('[data-confirm]');
  if(b&&!confirm(b.dataset.confirm))ev.preventDefault();
});

// Selector de familia con búsqueda: <div class="picker" data-picker data-url=".." data-actividad="..">
// Escribe 2 o más letras, elige de la lista y se guarda el id de la familia (campo oculto familia_id).
(function(){
  document.querySelectorAll('[data-picker]').forEach(function(box){
    var input=box.querySelector('input[type=text]');
    var oculto=box.querySelector('input[name=familia_id]');
    var lista=box.querySelector('ul');
    var estado=box.querySelector('.sel');
    var url=box.dataset.url, actividad=box.dataset.actividad||'';
    var enviar=box.hasAttribute('data-autosubmit');
    var opciones=[], activo=-1, espera=null, pedido=0;

    function cerrar(){lista.hidden=true;input.setAttribute('aria-expanded','false');activo=-1;}
    function marcar(i){
      var items=lista.querySelectorAll('li[role=option]');
      items.forEach(function(li,n){li.setAttribute('aria-selected',n===i?'true':'false');});
      activo=i;
      if(items[i])items[i].scrollIntoView({block:'nearest'});
    }
    function elegir(f){
      input.value=f.nombre;oculto.value=f.id;
      box.classList.add('elegida');
      if(estado)estado.textContent='✔ Familia '+f.nombre+' seleccionada';
      cerrar();
      if(enviar)box.closest('form').submit();
    }
    function pintar(){
      lista.innerHTML='';
      if(!opciones.length){
        var v=document.createElement('li');v.className='vacio';v.textContent='No encontramos esa familia. Prueba con otro apellido.';v.setAttribute('role','presentation');
        lista.appendChild(v);
      }
      opciones.forEach(function(f,n){
        var li=document.createElement('li');li.setAttribute('role','option');li.setAttribute('aria-selected','false');
        var t=document.createElement('span');t.textContent=f.nombre;li.appendChild(t);
        if(f.ya){li.setAttribute('aria-disabled','true');var y=document.createElement('span');y.className='ya';y.textContent='Ya inscrita';li.appendChild(y);}
        else if(f.secciones){var s=document.createElement('small');s.textContent='Sección '+f.secciones.replace(/,/g,', ');li.appendChild(s);}
        li.addEventListener('mousedown',function(ev){ev.preventDefault();if(!f.ya)elegir(f);});
        lista.appendChild(li);
      });
      lista.hidden=false;input.setAttribute('aria-expanded','true');activo=-1;
    }
    function buscar(){
      var q=input.value.trim();
      if(q.length<2){cerrar();return;}
      var mio=++pedido;
      fetch(url+'?q='+encodeURIComponent(q)+(actividad?'&actividad='+encodeURIComponent(actividad):''),{credentials:'same-origin'})
        .then(function(r){return r.ok?r.json():[];})
        .then(function(d){if(mio===pedido){opciones=d;pintar();}})
        .catch(function(){});
    }
    input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-expanded','false');
    input.addEventListener('input',function(){
      // si se vuelve a escribir, la selección anterior ya no vale
      oculto.value='';box.classList.remove('elegida');
      clearTimeout(espera);espera=setTimeout(buscar,160);
    });
    input.addEventListener('keydown',function(ev){
      var items=lista.querySelectorAll('li[role=option]');
      if(ev.key==='ArrowDown'&&!lista.hidden){ev.preventDefault();marcar(Math.min(activo+1,items.length-1));}
      else if(ev.key==='ArrowUp'&&!lista.hidden){ev.preventDefault();marcar(Math.max(activo-1,0));}
      else if(ev.key==='Enter'&&!lista.hidden&&activo>=0){ev.preventDefault();if(!opciones[activo].ya)elegir(opciones[activo]);}
      else if(ev.key==='Escape'){cerrar();}
    });
    input.addEventListener('blur',function(){setTimeout(cerrar,120);});
    input.addEventListener('focus',function(){if(input.value.trim().length>=2&&!oculto.value)buscar();});
    if(oculto.value){box.classList.add('elegida');if(estado)estado.textContent='✔ Familia '+input.value+' seleccionada';}
  });
})();
