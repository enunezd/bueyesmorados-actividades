document.addEventListener('click',e=>{
  const b=e.target.closest('[data-confirm]');
  if(b&&!confirm(b.dataset.confirm))e.preventDefault();
});
// Muestra las fechas de apertura/cierre solo cuando el modo es "según fechas".
document.addEventListener('change',e=>{
  if(e.target.name!=='modo_control')return;
  const box=document.getElementById('fechas');
  if(box)box.hidden=e.target.value!=='automatico';
});
