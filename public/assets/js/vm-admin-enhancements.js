(() => {
  'use strict';
  const body=document.body;
  if(body.dataset.page!=='vm-details'||body.dataset.admin!=='1')return;
  const basePath=body.dataset.basePath||'',csrf=body.dataset.csrf||'',locale=document.documentElement.lang==='en'?'en':'pl';
  const appUrl=path=>`${basePath}${path}`;
  const h=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const relative=location.pathname.startsWith(basePath)?location.pathname.slice(basePath.length):location.pathname;
  const match=relative.match(/^\/vms\/(\d+)$/);if(!match)return;const vmId=Number(match[1]);

  async function api(path,options={}){const init={...options,headers:{Accept:'application/json',...(['GET','HEAD'].includes(options.method||'GET')?{}:{'X-CSRF-Token':csrf}),...(options.body?{'Content-Type':'application/json'}:{})}};if(options.body&&typeof options.body!=='string')init.body=JSON.stringify(options.body);const response=await fetch(appUrl(path),init);const payload=await response.json();if(!response.ok)throw new Error(payload.error?.message||`HTTP ${response.status}`);return payload.data;}
  async function inject(){
    const content=document.getElementById('appContent');if(!content||document.getElementById('vmAssignmentPanel'))return false;
    const firstPanel=content.querySelector('.panel');if(!firstPanel)return false;
    const data=await api(`/api/v1/admin/vms/${vmId}/assignment-options`),targets=data.targets||[],current=data.current||{};
    const options=targets.map(target=>{const selected=Number(target.project_id)===Number(current.project_id)&&Number(target.user_id)===Number(current.owner_user_id);return `<option value="${Number(target.project_id)}:${Number(target.user_id)}" ${selected?'selected':''}>${h(target.project_name)} · ${h(target.username)} (${h(target.membership_role)})</option>`;}).join('');
    const panel=document.createElement('section');panel.id='vmAssignmentPanel';panel.className='panel mt-3';panel.innerHTML=`<div class="panel-header"><div><h2 class="h5 mb-0">${locale==='pl'?'Przypisanie VM':'VM assignment'}</h2><p class="resource-meta mb-0">${locale==='pl'?'Lista zawiera tylko aktywne projekty i użytkowników mających dostęp do sieci oraz storage tej VM.':'Only active project/user pairs with access to this VM network and storage are listed.'}</p></div></div><div class="panel-body"><form id="vmAssignmentForm" class="row g-2 align-items-end"><div class="col-md-9"><label class="form-label">${locale==='pl'?'Projekt i właściciel':'Project and owner'}</label><select class="form-select" name="target" ${options?'':'disabled'}>${options||`<option>${locale==='pl'?'Brak dozwolonych celów przypisania':'No valid assignment targets'}</option>`}</select></div><div class="col-md-3"><button class="btn btn-primary w-100" type="submit" ${options?'':'disabled'}>${locale==='pl'?'Zapisz przypisanie':'Save assignment'}</button></div></form><div class="alert alert-danger mt-3 mb-0 d-none" id="vmAssignmentError"></div></div>`;
    firstPanel.insertAdjacentElement('afterend',panel);
    panel.querySelector('#vmAssignmentForm')?.addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget,button=form.querySelector('button[type="submit"]'),error=panel.querySelector('#vmAssignmentError');button.disabled=true;error.classList.add('d-none');try{const [projectId,userId]=String(new FormData(form).get('target')||'').split(':').map(Number);if(!projectId||!userId)return;await api(`/api/v1/vms/${vmId}/assignment`,{method:'PATCH',body:{project_id:projectId,owner_user_id:userId}});location.reload();}catch(exception){error.textContent=exception.message||String(exception);error.classList.remove('d-none');button.disabled=false;}});
    if(location.hash==='#assignment')panel.scrollIntoView({block:'start'});return true;
  }
  let attempts=0;const timer=window.setInterval(async()=>{attempts++;try{if(await inject()||attempts>40)window.clearInterval(timer);}catch(error){window.clearInterval(timer);const content=document.getElementById('appContent');if(content){const warning=document.createElement('div');warning.className='alert alert-warning mt-3';warning.textContent=(locale==='pl'?'Nie udało się pobrać opcji przypisania VM: ':'Unable to load VM assignment options: ')+(error.message||String(error));content.append(warning);}}},100);
})();
