(() => {
  'use strict';

  const body=document.body,page=body.dataset.page||'',basePath=body.dataset.basePath||'',locale=document.documentElement.lang==='en'?'en':'pl';
  const appUrl=path=>`${basePath}${path}`;
  const supported=new Set(['users','proxmox','networks','templates','storages','plans']);

  function addDetailsButtons(){
    if(!supported.has(page))return;
    document.querySelectorAll('[data-admin-action][data-id]').forEach(action=>{
      const row=action.closest('tr');if(!row||row.querySelector('[data-open-admin-resource]'))return;
      const actions=action.closest('.actions')||action.parentElement;if(!actions)return;
      const button=document.createElement('button');button.type='button';button.className='btn btn-sm btn-outline-secondary';button.dataset.openAdminResource=page;button.dataset.id=action.dataset.id;button.textContent=locale==='pl'?'Szczegóły':'Details';actions.prepend(button);
    });
  }
  const observer=new MutationObserver(()=>queueMicrotask(addDetailsButtons));observer.observe(document.body,{childList:true,subtree:true});addDetailsButtons();

  document.addEventListener('click',async event=>{
    const details=event.target.closest('[data-open-admin-resource][data-id]');
    if(details){event.preventDefault();event.stopImmediatePropagation();location.assign(appUrl(`/admin/${details.dataset.openAdminResource}/${Number(details.dataset.id)}`));return;}

    const resetPassword=event.target.closest('[data-admin-action="reset-password"][data-id]');
    if(resetPassword){event.preventDefault();event.stopImmediatePropagation();location.assign(appUrl(`/admin/users/${Number(resetPassword.dataset.id)}#password`));return;}

    const rotateSecret=event.target.closest('[data-admin-action="rotate-secret"][data-id]');
    if(rotateSecret){event.preventDefault();event.stopImmediatePropagation();location.assign(appUrl(`/admin/proxmox/${Number(rotateSecret.dataset.id)}#secret`));return;}

    const member=event.target.closest('[data-admin-action="member"][data-id]');
    if(member){event.preventDefault();event.stopImmediatePropagation();location.assign(appUrl(`/projects/${Number(member.dataset.id)}#members`));return;}

    const access=event.target.closest('[data-admin-action="project-access"][data-id]');
    if(access){event.preventDefault();event.stopImmediatePropagation();location.assign(appUrl(`/projects/${Number(access.dataset.id)}#access`));return;}

    const assign=event.target.closest('[data-action="assign"][data-id]');
    if(assign){event.preventDefault();event.stopImmediatePropagation();location.assign(appUrl(`/vms/${Number(assign.dataset.id)}#assignment`));return;}

    const liveAssign=event.target.closest('[data-live-action="assign"][data-live-vm]');
    if(liveAssign){
      event.preventDefault();event.stopImmediatePropagation();liveAssign.disabled=true;
      try{
        const response=await fetch(appUrl('/api/v1/admin/vms/discovery'),{headers:{Accept:'application/json'}}),payload=await response.json();
        if(!response.ok)throw new Error(payload.error?.message||`HTTP ${response.status}`);
        const vm=payload.data?.vms?.[Number(liveAssign.dataset.liveVm)];
        if(!vm?.portal_managed||!vm.portal_id)throw new Error(locale==='pl'?'Ta VM nie jest zarządzana przez portal.':'This VM is not managed by the portal.');
        location.assign(appUrl(`/vms/${Number(vm.portal_id)}#assignment`));
      }catch(error){liveAssign.disabled=false;const container=document.getElementById('toastContainer');if(container&&window.bootstrap?.Toast){const toast=document.createElement('div');toast.className='toast align-items-center text-bg-danger border-0';toast.innerHTML=`<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;toast.querySelector('.toast-body').textContent=error.message||String(error);container.append(toast);const instance=new bootstrap.Toast(toast,{delay:6000});toast.addEventListener('hidden.bs.toast',()=>toast.remove());instance.show();}}
    }
  },true);
})();
