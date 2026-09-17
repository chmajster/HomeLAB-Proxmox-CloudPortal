'use strict';
(() => {
  const boot = JSON.parse(document.getElementById('portal-data').textContent);
  const permissions = new Set(boot.me.permissions);
  const can = p => permissions.has(p);
  const content = document.getElementById('content');
  const editor = document.getElementById('editor');
  const details = document.getElementById('details');
  const form = document.getElementById('editor-form');
  const fields = document.getElementById('fields');
  let offset = 0, detailTimer = null, submitAction = null, formKey = null;
  const uuid = () => {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes=crypto.getRandomValues(new Uint8Array(16));bytes[6]=(bytes[6]&15)|64;bytes[8]=(bytes[8]&63)|128;
    const hex=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
    return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
  };
  const titles = {dashboard:'Dashboard infrastruktury', users:'Użytkownicy', roles:'Role', permissions:'Uprawnienia', tokens:'Tokeny API', audit:'Audit', providers:'Połączenia', credentials:'Credentiale', templates:'Szablony Terraform', deployments:'Deploymenty', jobs:'Zadania', logs:'Logi zadań', ansible:'Playbooki Ansible', account:'Moje konto'};
  document.getElementById('page-title').textContent = titles[boot.page] || boot.page;
  function el(tag, text, className) { const n=document.createElement(tag); if(text!==undefined)n.textContent=String(text); if(className)n.className=className; return n; }
  function message(text, error=false) { const box=document.getElementById('message'); box.hidden=false; box.textContent=text; box.className=error?'error':''; }
  async function api(path, method='GET', data=null, key=null) {
    const headers={'Accept':'application/json','X-CSRF-Token':boot.csrf};
    if(data!==null)headers['Content-Type']='application/json';
    if(key)headers['Idempotency-Key']=key;
    const response=await fetch(boot.base+'/backend-api'+path,{method,headers,body:data===null?undefined:JSON.stringify(data),credentials:'same-origin',redirect:'error'});
    const body=await response.json();
    if(!response.ok) {
      if(response.status===401)location.assign(boot.base+'/login');
      throw new Error(body.error?.message || body.detail || 'Nie udało się wykonać operacji.');
    }
    return body;
  }
  function button(label, fn, kind='secondary') { const b=el('button',label,kind);b.type='button';b.addEventListener('click',()=>Promise.resolve(fn()).catch(e=>message(e.message,true)));return b; }
  function showDetails(title, nodes) { document.getElementById('details-title').textContent=title; const body=document.getElementById('details-content');body.replaceChildren(...nodes);details.showModal(); }
  document.getElementById('close-editor').onclick=()=>editor.close();
  document.getElementById('close-details').onclick=()=>details.close();
  details.addEventListener('close',()=>{if(detailTimer)clearTimeout(detailTimer);detailTimer=null;document.getElementById('details-content').replaceChildren();});
  editor.addEventListener('close',()=>{fields.replaceChildren();submitAction=null;});
  function openForm(title, action) { document.getElementById('save').disabled=false;fields.replaceChildren();document.getElementById('form-error').textContent='';document.getElementById('editor-title').textContent=title;submitAction=action;formKey=uuid();editor.showModal(); }
  function input(name,label,value='',type='text',required=true) {
    const wrapper=el('label',label);const node=type==='textarea'?el('textarea'):el('input');
    node.name=name;if(type!=='textarea')node.type=type;node.required=required;
    if(type==='checkbox') { node.checked=!!value;wrapper.className='check';wrapper.prepend(node); }
    else {node.value=value??'';wrapper.append(node);}
    if(type==='password')node.autocomplete='new-password';
    fields.append(wrapper);return node;
  }
  function select(name,label,options,value='',multiple=false,required=true) {
    const wrapper=el('label',label),node=el('select');node.name=name;node.multiple=multiple;node.required=required;
    if(!multiple) {const empty=el('option','Wybierz');empty.value='';node.append(empty);}
    for(const [id,text] of options){const o=el('option',text);o.value=String(id);o.selected=multiple?(value||[]).map(String).includes(String(id)):String(id)===String(value);node.append(o);}
    if(multiple)node.size=Math.min(8,Math.max(3,options.length));wrapper.append(node);fields.append(wrapper);return node;
  }
  const value = name => form.elements.namedItem(name)?.value || '';
  const checked = name => !!form.elements.namedItem(name)?.checked;
  const selected = name => Array.from(form.elements.namedItem(name)?.selectedOptions||[]).map(o=>o.value);
  const number = name => Number(value(name));
  form.addEventListener('submit',async e=>{
    e.preventDefault();const save=document.getElementById('save');save.disabled=true;
    try{await submitAction(formKey);editor.close();await load();}
    catch(err){document.getElementById('form-error').textContent=err.message;}
    finally{save.disabled=false;}
  });
  async function list(path){return (await api(path)).items||[];}
  async function all(path){let rows=[],part=[];do{part=await list(path+'?offset='+rows.length+'&limit=200');rows.push(...part);}while(part.length===200);return rows;}
  function recordDetails(row) {const dl=el('dl');for(const [key,v] of Object.entries(row)){dl.append(el('dt',key),el('dd',typeof v==='object'?JSON.stringify(v):v??'—'));}showDetails('Szczegóły',[dl]);}
  async function change(action,id,path,method='POST') {
    if(!confirm(action+'?'))return;
    await api(path,method,method==='DELETE'?null:{},uuid());message(action+': wykonano.');await load();
  }
  async function userForm(row=null){
    openForm(row?'Edytuj użytkownika':'Dodaj użytkownika',async key=>{
      const data={email:value('email'),first_name:value('first_name'),last_name:value('last_name')};
      if(!row)Object.assign(data,{username:value('username'),password:value('password'),is_service_account:checked('is_service_account')});
      await api('/users'+(row?'/'+row.id:''),row?'PUT':'POST',data,key);message('Użytkownik zapisany.');
    });
    if(!row){input('username','Nazwa użytkownika');const p=input('password','Hasło początkowe','','password');p.minLength=12;p.maxLength=256;input('is_service_account','Konto serwisowe (bez logowania hasłem)',false,'checkbox',false);}
    input('email','E-mail',row?.email||'','email');input('first_name','Imię',row?.first_name||'','text',false);input('last_name','Nazwisko',row?.last_name||'','text',false);
  }
  async function roleForm(row=null){
    const choices=await list('/permissions');
    openForm(row?'Edytuj rolę':'Dodaj rolę',async key=>{const selected=Array.from(fields.querySelectorAll('input[data-permission]:checked')).map(n=>n.value);await api('/roles'+(row?'/'+row.id:''),row?'PUT':'POST',{name:value('name'),permissions:selected},key);message('Rola zapisana.');});
    input('name','Nazwa',row?.name||'');const grid=el('div',undefined,'permission-list');
    for(const p of choices){const label=el('label',p,'check'),c=el('input');c.type='checkbox';c.dataset.permission='1';c.value=p;c.checked=row?.permissions.includes(p)||false;c.disabled=!can(p);label.prepend(c);grid.append(label);}fields.append(grid);
  }
  async function assignRoles(row){
    const [roles,current]=await Promise.all([all('/roles'),list('/users/'+row.id+'/roles')]);
    openForm('Role: '+row.username,async()=>{await api('/users/'+row.id+'/roles','PUT',{role_ids:selected('roles').map(Number)});message('Role przypisane.');});
    select('roles','Role',roles.map(r=>[r.id,r.name]),current.map(r=>r.id),true,false);
  }
  function secretResult(result,label='Token — wyświetlany tylko raz'){
    const secret=result.token||result.reset_token;
    if(!secret){message('Operacja była już wykonana. Zapisanej wartości tokena nie można odczytać ponownie.');return;}
    showDetails(label,[el('p','Zapisz wartość teraz. Po zamknięciu okna nie będzie dostępna.'),el('div',secret,'secret')]);
  }
  async function tokenForm(){
    const [users,perms]=await Promise.all([can('users.read')?all('/users'):Promise.resolve([boot.me.user]),can('roles.read')?list('/permissions'):Promise.resolve([...permissions])]);
    openForm('Utwórz API Token',async key=>{const data={name:value('name'),user_id:number('user_id'),scopes:selected('scopes')};if(value('expires_at'))data.expires_at=new Date(value('expires_at')).toISOString();secretResult(await api('/tokens','POST',data,key));});
    input('name','Nazwa tokena');select('user_id','Konto',users.filter(u=>u.is_active).map(u=>[u.id,u.username+(u.is_service_account?' — serwisowe':'')]),boot.me.user.id);select('scopes','Uprawnienia tokena',perms.filter(can).map(p=>[p,p]),[],true);input('expires_at','Wygasa (opcjonalnie)','','datetime-local',false);
  }
  async function serviceAccount(){
    openForm('Konto i token serwisowy portalu',async key=>{
      const roles=await all('/roles');let role=roles.find(r=>r.name==='Portal Service'&&r.permissions.length===1&&r.permissions[0]==='portal.connect');
      if(!role)role=await api('/roles','POST',{name:'Portal Service '+value('username'),permissions:['portal.connect']},key);
      const user=await api('/users','POST',{username:value('username'),email:value('email'),password:'Service-'+key,is_service_account:true},key);
      await api('/users/'+user.id+'/roles','PUT',{role_ids:[role.id]});
      secretResult(await api('/tokens','POST',{name:'Cloud Portal Service Account',user_id:user.id,scopes:['portal.connect']},key),'Token połączenia — wklej w Ustawienia → Infrastruktura → Backend');
    });input('username','Nazwa konta','cloud-portal');input('email','E-mail techniczny','','email');
  }
  function credentialForm(row=null){
    openForm(row?'Edytuj credential':'Dodaj credential',async key=>{
      const data={name:value('name'),type:value('type'),endpoint:value('endpoint'),username:value('username'),verify_ssl:checked('verify_ssl')};
      const secrets={};for(const k of ['password','token_id','token_secret','private_key','known_hosts','access_key_id','secret_access_key','session_token','tenant_id','client_id','client_secret','subscription_id','project_name','domain_name','secret'])if(value(k))secrets[k]=value(k);
      if(Object.keys(secrets).length)data.secrets=secrets;
      await api('/credentials'+(row?'/'+row.id:''),row?'PUT':'POST',data,key);message('Credential zapisany. Sekrety są dostępne wyłącznie w backendzie.');
    });
    input('name','Nazwa',row?.name||'');const type=select('type','Typ',['proxmox','ssh','winrm','vmware','aws','azure','openstack','other'].map(t=>[t,t]),row?.type||'proxmox');
    input('endpoint','Endpoint HTTPS (dla API)',row?.endpoint||'','url',false);input('username','Użytkownik',row?.username||'','text',false);input('verify_ssl','Weryfikuj certyfikat',row?.verify_ssl??true,'checkbox',false);
    fields.append(el('p',row?'Pozostaw wszystkie pola sekretów puste, aby zachować zapisany komplet. Podanie choć jednego sekretu zastępuje cały komplet. Zmiany credentiala wymagają zakończenia aktywnych zadań.':'Wprowadź credentiale wymagane przez wybrany typ.'));
    const allowed={proxmox:['password','token_id','token_secret'],ssh:['password','private_key','known_hosts'],winrm:['password'],vmware:['password'],aws:['access_key_id','secret_access_key','session_token'],azure:['tenant_id','client_id','client_secret','subscription_id'],openstack:['password','project_name','domain_name'],other:['secret']};
    const nodes={};for(const key of [...new Set(Object.values(allowed).flat())])nodes[key]=input(key,key,'', ['private_key','known_hosts'].includes(key)?'textarea':'password',false);
    const update=()=>{for(const [key,node]of Object.entries(nodes)){node.parentElement.hidden=!allowed[type.value]?.includes(key);if(node.parentElement.hidden)node.value='';}};type.onchange=update;update();
  }
  async function providerForm(row=null){
    const creds=await all('/credentials');openForm(row?'Edytuj połączenie':'Dodaj połączenie Proxmox',async key=>{await api('/providers'+(row?'/'+row.id:''),row?'PUT':'POST',{name:value('name'),type:'proxmox',credentials_id:number('credentials_id')},key);message('Połączenie zapisane.');});input('name','Nazwa',row?.name||'');select('credentials_id','Credential Proxmox',creds.filter(c=>c.type==='proxmox').map(c=>[c.id,c.name]),row?.credentials_id||'');
  }
  async function discoverProvider(row){
    const selector=el('select');for(const resource of ['nodes','storages','networks','templates','vms','pools']){const o=el('option',resource);o.value=resource;selector.append(o);}
    const body=el('pre','Pobieranie…');showDetails(row.name,[selector,body]);
    const update=async()=>{try{body.textContent=JSON.stringify((await api('/providers/'+row.id+'/'+selector.value)).items,null,2);}catch(e){body.textContent=e.message;}};selector.onchange=update;await update();
  }
  async function deploymentForm(){
    const providers=await all('/providers');const credentials=can('credentials.read')?await all('/credentials'):[];
    openForm('Utwórz VM',async key=>{
      const provider=providers.find(p=>p.id===number('provider_id'));
      const variables={name:value('vm_name'),node:value('node'),template_id:number('template_id'),template_node:value('template_node')||value('node'),cpu:number('cpu'),memory:number('memory'),disk:number('disk'),network:value('network'),storage:value('storage'),ssh_username:value('ssh_username')};
      if(value('ssh_public_key'))variables.ssh_public_key=value('ssh_public_key');if(value('vlan_id'))variables.vlan_id=number('vlan_id');
      const data={name:value('name'),provider_id:provider.id,credentials_id:provider.credentials_id,template:'proxmox-vm',variables,executor:value('executor')};
      if(value('playbook'))data.ansible=ansibleValues('ansible_credential');
      const result=await api('/deployments','POST',data,key);message('Deployment utworzony. Zadanie: '+result.job.id);
    });
    input('name','Nazwa deploymentu');const provider=select('provider_id','Połączenie Proxmox',providers.map(p=>[p.id,p.name]));
    input('vm_name','Nazwa VM');select('node','Węzeł',[]);select('template_id','Szablon VM',[]);input('template_node','Węzeł szablonu','','text',false);select('storage','Storage',[]);select('network','Sieć',[]);
    for(const [key,label,defaultValue,min,max]of [['cpu','vCPU',2,1,128],['memory','RAM (MiB)',4096,512,1048576],['disk','Dysk (GiB)',40,1,65536]]){const n=input(key,label,defaultValue,'number');n.min=min;n.max=max;}
    const vlan=input('vlan_id','VLAN (opcjonalnie)','','number',false);vlan.min=1;vlan.max=4094;input('ssh_username','Użytkownik cloud-init','clouduser');input('ssh_public_key','Publiczny klucz SSH','','textarea',false);select('executor','Executor',[['terraform','Terraform'],['opentofu','OpenTofu (wymaga instalacji na backendzie)']],'terraform');
    if(can('ansible.execute')&&can('ansible.read')&&can('credentials.read')) ansibleFields(credentials,await list('/ansible/playbooks'),'ansible_credential',true);
    function replace(name,options){const node=form.elements.namedItem(name);node.replaceChildren();for(const [id,label]of options){const o=el('option',label);o.value=id;node.append(o);}}
    let discoveryVersion=0;
    const nodeInput=form.elements.namedItem('node');
    const save=document.getElementById('save');save.disabled=true;
    async function nodeResources(version,id) {
      save.disabled=true;replace('storage',[]);replace('network',[]);
      const node=nodeInput.value;if(!node)return;
      const [storages,networks]=await Promise.all(['storages','networks'].map(r=>list('/providers/'+id+'/'+r+'?node='+encodeURIComponent(node))));
      if(version!==discoveryVersion||id!==provider.value||node!==nodeInput.value)return;
      replace('storage',storages.filter(s=>!s.disable&&s.enabled!==0&&s.active!==0&&String(s.content||'').split(',').includes('images')).map(s=>[s.storage,s.storage]));
      replace('network',networks.filter(n=>n.type==='bridge'||n.type==='OVSBridge').map(n=>[n.iface,n.iface]));
      save.disabled=false;
    }
    provider.onchange=async()=>{
      const version=++discoveryVersion,id=provider.value;save.disabled=true;
      for(const name of ['node','template_id','storage','network'])replace(name,[]);
      if(!id)return;
      try{
        const [nodes,templates]=await Promise.all(['nodes','templates'].map(r=>list('/providers/'+id+'/'+r)));
        if(version!==discoveryVersion)return;
        replace('node',nodes.filter(n=>!n.status||n.status==='online').map(n=>[n.node,n.node]));
        replace('template_id',templates.map(t=>[t.vmid,`${t.name||t.vmid} — ${t.node}`]));
        const syncTemplate=()=>{form.elements.namedItem('template_node').value=templates.find(t=>String(t.vmid)===value('template_id'))?.node||'';};
        form.elements.namedItem('template_id').onchange=syncTemplate;syncTemplate();
        await nodeResources(version,id);
      }catch(e){document.getElementById('form-error').textContent=e.message;}
    };
    nodeInput.onchange=()=>nodeResources(++discoveryVersion,provider.value).catch(e=>{document.getElementById('form-error').textContent=e.message;});
  }
  function ansibleValues(credentialField){
    const variables={};
    if(value('playbook')==='bootstrap-linux')for(const key of ['hostname','timezone'])if(value('ansible_'+key))variables[key]=value('ansible_'+key);
    return {playbook:value('playbook'),credentials_id:number(credentialField),variables};
  }
  function ansibleFields(credentials,books,credentialField,optional=false){
    const playbook=select('playbook',optional?'Ansible po utworzeniu VM (opcjonalnie)':'Zatwierdzony playbook',books.map(p=>[p.id,p.name]),'',false,!optional);
    const credential=select(credentialField,'Credential SSH / WinRM',[],'',false,!optional);
    const hostname=input('ansible_hostname','Nazwa hosta (opcjonalnie)','','text',false);
    const timezone=input('ansible_timezone','Strefa czasowa (opcjonalnie, np. Europe/Warsaw)','','text',false);
    const update=()=>{
      const book=books.find(b=>b.id===playbook.value),previous=credential.value;
      credential.replaceChildren();const empty=el('option','Wybierz');empty.value='';credential.append(empty);
      for(const c of credentials.filter(c=>c.type===book?.transport)){const option=el('option',c.name);option.value=c.id;option.selected=String(c.id)===previous;credential.append(option);}
      credential.required=!!book;credential.parentElement.hidden=!book;
      hostname.parentElement.hidden=!book?.variables.includes('hostname');timezone.parentElement.hidden=!book?.variables.includes('timezone');
    };
    playbook.onchange=update;update();
  }
  async function ansibleForm(){
    const creds=await all('/credentials'),books=await list('/ansible/playbooks');openForm('Uruchom Ansible',async key=>{
      const ansible=ansibleValues('credentials_id');ansible.inventory={hosts:value('hosts').split(/[\s,]+/).filter(Boolean)};
      await api('/jobs','POST',{operation:'ansible.execute',ansible},key);message('Zadanie Ansible dodane do kolejki.');
    });ansibleFields(creds,books,'credentials_id');input('hosts','Adresy IP (po jednym w wierszu)','','textarea');
  }
  async function showLogs(row){
    const pre=el('pre',''),status=el('p','Request ID: '+row.request_id);showDetails('Logi: '+row.id,[status,pre]);let after=0;
    const poll=async()=>{try{const data=await api('/jobs/'+row.id+'/logs?after='+after+'&limit=200');for(const line of data.items)pre.textContent+=line.timestamp+' '+line.message+'\n';after=data.next_after;status.textContent='Status: '+data.status+' | Request ID: '+data.request_id;
      if(details.open&&(data.items.length===200||['running','queued'].includes(data.status)))detailTimer=setTimeout(poll,data.items.length===200?100:2000);
    }catch(e){status.textContent=e.message;}};await poll();
  }
  function rowActions(row,resource){
    const actions=el('div',undefined,'actions');actions.append(button('Szczegóły',()=>recordDetails(row)));
    const edit={users:userForm,roles:roleForm,credentials:credentialForm,providers:providerForm};
    if(edit[resource]&&can(resource+'.update'))actions.append(button('Edytuj',()=>edit[resource](row)));
    if(['users','roles','credentials','providers'].includes(resource)&&can(resource+'.delete'))actions.append(button('Usuń',()=>change('Usunąć rekord '+row.id,row.id,'/'+resource+'/'+row.id,'DELETE'),'danger'));
    if(resource==='users'){
      if(can('roles.assign')&&can('roles.read'))actions.append(button('Role',()=>assignRoles(row)));
      if(can('users.update')){
        actions.append(button(row.is_active?'Wyłącz':'Włącz',()=>change(row.is_active?'Wyłączyć konto':'Włączyć konto',row.id,'/users/'+row.id+(row.is_active?'/disable':'/enable'))));
        actions.append(button('Odblokuj',()=>change('Odblokować konto',row.id,'/users/'+row.id+'/unlock')));
        actions.append(button('Reset hasła',async()=>{if(confirm('Unieważnić sesje i wygenerować token resetu?'))secretResult(await api('/users/'+row.id+'/reset-password','POST',{},uuid()),'Token resetu — ważny 15 minut');}));
      }
    }
    if(resource==='tokens'&&can('tokens.revoke')&&!row.revoked_at)actions.append(button('Unieważnij',()=>change('Unieważnić token',row.id,'/tokens/'+row.id+'/revoke'),'danger'));
    if(resource==='credentials'&&can('credentials.test'))actions.append(button('Testuj',async()=>recordDetails(await api('/credentials/'+row.id+'/test','POST',{}))));
    if(resource==='providers')actions.append(button('Zasoby',()=>discoverProvider(row)));
    if(resource==='deployments'&&!row.active_job_id&&row.status!=='destroyed'){
      if(can('jobs.execute')&&can('terraform.execute')){actions.append(button('Plan',async()=>{await api('/jobs','POST',{operation:'terraform.plan',deployment_id:row.id},uuid());message('Plan dodany do kolejki.');await load();}));}
      if(can('jobs.execute')&&can('terraform.execute')&&can('deployments.create'))actions.append(button('Apply / ponów',async()=>{if(!confirm('Uruchomić apply dla istniejącego deploymentu?'))return;await api('/jobs','POST',{operation:'terraform.apply',deployment_id:row.id},uuid());await load();}));
      if(can('deployments.destroy')&&can('jobs.execute')&&can('terraform.execute'))actions.append(button('Zniszcz',()=>change('Zniszczyć VM '+row.name,row.id,'/deployments/'+row.id+'/destroy'),'danger'));
    }
    if(resource==='jobs'){
      actions.append(button('Logi',()=>showLogs(row)));
      if(can('jobs.cancel')&&['queued','running'].includes(row.status))actions.append(button('Anuluj',()=>change('Anulować zadanie',row.id,'/jobs/'+row.id+'/cancel'),'danger'));
    }
    return actions;
  }
  const columns={users:[['id','ID'],['username','Użytkownik'],['email','E-mail'],['is_active','Aktywny'],['is_service_account','Serwisowy']],roles:[['id','ID'],['name','Nazwa'],['permissions','Uprawnienia']],tokens:[['name','Nazwa'],['token_prefix','Prefix'],['user_id','Konto'],['scopes','Zakres'],['expires_at','Wygasa'],['revoked_at','Unieważniony']],credentials:[['name','Nazwa'],['type','Typ'],['endpoint','Endpoint'],['username','Użytkownik'],['configured','Skonfigurowany']],providers:[['id','ID'],['name','Nazwa'],['type','Typ'],['credentials_id','Credential']],deployments:[['name','Nazwa'],['status','Status'],['provider','Provider'],['template','Szablon'],['created_at','Utworzono']],jobs:[['id','ID'],['operation','Operacja'],['status','Status'],['request_id','Request ID'],['created_at','Utworzono']],audit:[['timestamp','Czas'],['user_id','Użytkownik'],['action','Operacja'],['resource','Zasób'],['result','Wynik'],['request_id','Request ID']],templates:[['id','ID'],['name','Nazwa'],['provider','Provider']],ansible:[['id','ID'],['name','Nazwa'],['transport','Transport'],['variables','Parametry']]};
  function renderTable(rows,resource){
    if(!rows.length){content.append(el('section','Brak rekordów.','card'));return;}
    const wrap=el('div',undefined,'table-wrap'),table=el('table'),head=el('thead'),tr=el('tr');
    for(const[,label]of columns[resource])tr.append(el('th',label));tr.append(el('th','Akcje'));head.append(tr);table.append(head);const body=el('tbody');
    for(const row of rows){const tr=el('tr');for(const[key]of columns[resource]){const v=row[key],td=el('td');const text=typeof v==='boolean'?(v?'Tak':'Nie'):Array.isArray(v)?v.join(', '):v??'—';if(key==='status')td.append(el('span',text,'status '+text));else td.textContent=text;td.title=String(text);tr.append(td);}const td=el('td');td.append(rowActions(row,resource));tr.append(td);body.append(tr);}table.append(body);wrap.append(table);content.append(wrap);
  }
  async function dashboard(){const data=await api('/health'),cards=el('div',undefined,'cards');for(const[key,v]of Object.entries(data.checks)){const card=el('section',undefined,'card');card.append(el('h2',key),el('div',typeof v==='object'?v.online+'/'+v.expected:v?'OK':'Niedostępne','metric'));cards.append(card);}content.append(cards);}
  async function load(){
    content.replaceChildren();const create=document.getElementById('create');create.hidden=true;
    document.getElementById('previous').hidden=true;document.getElementById('next').hidden=true;document.getElementById('page-number').textContent='';
    if(boot.page==='dashboard'){await dashboard();return;}
    if(boot.page==='account'){
      content.append(el('section',boot.me.user.username+' — '+boot.me.user.email,'card'));
      content.append(button('Zmień hasło',()=>{openForm('Zmień hasło',async()=>{await api('/auth/change-password','POST',{current_password:value('current_password'),password:value('password')});location.assign(boot.base+'/login');});input('current_password','Obecne hasło','','password');const p=input('password','Nowe hasło','','password');p.minLength=12;}));return;
    }
    if(boot.page==='permissions'){const grid=el('section',undefined,'card permission-list');for(const p of await list('/permissions'))grid.append(el('p',p));content.append(grid);return;}
    let resource=boot.page==='logs'?'jobs':boot.page;
    const path=resource==='ansible'?'/ansible/playbooks':'/'+resource;
    const rows=await list(path+'?offset='+offset+'&limit=100');renderTable(rows,resource);
    const creators={users:userForm,roles:roleForm,tokens:tokenForm,credentials:credentialForm,providers:providerForm,deployments:deploymentForm,ansible:ansibleForm};
    const needed=resource==='ansible'?['ansible.execute','jobs.execute','credentials.read']:resource==='deployments'?['deployments.create','terraform.execute','jobs.execute','providers.read']:resource==='providers'?['providers.create','credentials.read']:resource==='roles'?['roles.create','roles.read']:[resource+'.create'];
    if(creators[resource]&&needed.every(can)){create.hidden=false;create.textContent=resource==='ansible'?'Uruchom':'Dodaj';create.onclick=()=>Promise.resolve(creators[resource]()).catch(e=>message(e.message,true));}
    if(resource==='tokens'&&['users.create','users.update','roles.create','roles.read','roles.assign','tokens.create','portal.connect'].every(can))content.prepend(button('Utwórz konto i token serwisowy portalu',serviceAccount));
    if(!['ansible','templates'].includes(resource)){
      document.getElementById('previous').hidden=offset===0;document.getElementById('next').hidden=rows.length<100;document.getElementById('page-number').textContent='Strona '+(offset/100+1);
    }
  }
  document.getElementById('previous').onclick=()=>{offset=Math.max(0,offset-100);load().catch(e=>message(e.message,true));};
  document.getElementById('next').onclick=()=>{offset+=100;load().catch(e=>message(e.message,true));};
  load().catch(e=>message(e.message,true));
})();
