// Pide confirmación en los botones con data-confirm="¿Seguro?"
document.addEventListener('click',function(e){
  var b=e.target.closest('[data-confirm]');
  if(b&&!confirm(b.dataset.confirm))e.preventDefault();
});

// Muestra las fechas de apertura/cierre solo cuando el modo es "según fechas".
document.addEventListener('change',function(e){
  if(e.target.name!=='modo_control')return;
  var box=document.getElementById('fechas');
  if(box)box.hidden=e.target.value!=='automatico';
});

// Copiar al portapapeles: <button data-copy="#campo">
document.addEventListener('click',function(e){
  var b=e.target.closest('[data-copy]');
  if(!b)return;
  var campo=document.querySelector(b.dataset.copy);
  if(!campo)return;
  campo.select();
  var listo=function(){var t=b.textContent;b.textContent='¡Copiado!';setTimeout(function(){b.textContent=t;},1500);};
  if(navigator.clipboard&&navigator.clipboard.writeText)navigator.clipboard.writeText(campo.value).then(listo,function(){document.execCommand('copy');listo();});
  else{document.execCommand('copy');listo();}
});

// Sugerencias rápidas: <div class="chips" data-chips="Seguridad,Caja" data-target="#nombres"> agrega una línea al cuadro de texto.
document.querySelectorAll('[data-chips]').forEach(function(box){
  var ta=document.querySelector(box.dataset.target);
  if(!ta)return;
  box.dataset.chips.split(',').forEach(function(txt){
    var b=document.createElement('button');b.type='button';b.textContent='+ '+txt;
    b.addEventListener('click',function(){
      var lineas=ta.value.split(/\r?\n/).map(function(s){return s.trim();}).filter(Boolean);
      if(lineas.some(function(s){return s.toLowerCase()===txt.toLowerCase();}))return;
      lineas.push(txt);ta.value=lineas.join('\n');ta.focus();b.classList.add('usado');
    });
    box.appendChild(b);
  });
});

// Vista previa de "turnos seguidos": muestra los horarios que se van a crear.
document.querySelectorAll('[data-turnos-preview]').forEach(function(form){
  var salida=form.querySelector('[data-preview]');
  function pintar(){
    var ini=form.elements.hora_inicio.value,dur=parseInt(form.elements.duracion.value,10),cant=parseInt(form.elements.cantidad.value,10);
    if(!/^\d\d:\d\d$/.test(ini)||!dur||!cant){salida.textContent='';return;}
    var m=parseInt(ini.slice(0,2),10)*60+parseInt(ini.slice(3),10),out=[];
    function f(x){return String(Math.floor(x/60)).padStart(2,'0')+':'+String(x%60).padStart(2,'0');}
    for(var i=0;i<Math.min(cant,12);i++){if(m+dur>=1440)break;out.push(f(m)+'–'+f(m+dur));m+=dur;}
    salida.textContent=out.length?'Se crearán: '+out.join(', '):'Con esos datos los turnos pasan de medianoche.';
  }
  form.addEventListener('input',pintar);pintar();
});

// Formularios con cambios sin guardar (tabla de cupos): resalta lo modificado y avisa antes de salir.
document.querySelectorAll('form[data-dirty]').forEach(function(form){
  var aviso=form.querySelector('[data-dirty-aviso]'),barra=form.querySelector('.barra-guardar'),sucio=false;
  form.addEventListener('input',function(e){
    if(e.target.tagName!=='INPUT')return;
    e.target.classList.toggle('cambio',e.target.value!==e.target.defaultValue);
    e.target.classList.toggle('cero',e.target.value==='0');
    sucio=!!form.querySelector('input.cambio');
    if(aviso)aviso.hidden=!sucio;
    if(barra)barra.classList.toggle('sucio',sucio);
  });
  form.addEventListener('submit',function(){sucio=false;});
  window.addEventListener('beforeunload',function(e){if(sucio){e.preventDefault();e.returnValue='';}});
});
