/* F2 Bakup. Interface independente, sem bibliotecas remotas. */
(() => {
  'use strict';
  const $=id=>document.getElementById('f2sb-'+id);
  const root=document.querySelector('.f2sb');
  const issue=root.dataset.issue==='1';
  let active='',busy=false,jobs=[],release=null,repository='';
  const phases={pause:'Preparando manutenção',settle:'Aguardando requisições',db:'Copiando banco de dados',files:'Copiando arquivos',check_db:'Verificando banco',check_files:'Verificando arquivos',seal:'Finalizando ZIP',inspect:'Lendo pacote',validate:'Validando ZIP',verify:'Conferindo integridade',ready:'Pronto para restaurar',done:'Concluído',failed:'Interrompido',cancelled:'Cancelado',rolled_back:'Revertido',upload:'Enviando arquivo'};
  const size=n=>{const u=['B','KB','MB','GB','TB'];let i=0;n=Number(n)||0;while(n>=1024&&i<4){n/=1024;i++;}return n.toFixed(i?1:0)+' '+u[i];};
  const date=v=>new Date(v).toLocaleString('pt-BR',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
  const sleep=()=>new Promise(r=>setTimeout(r,180));
  function el(tag,text,className){const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(className)e.className=className;return e;}
  async function api(op,data={}){
    const body=new FormData();body.set('action','f2sb');body.set('nonce',F2SB.nonce);body.set('op',op);Object.entries(data).forEach(([k,v])=>body.set(k,v));
    const response=await fetch(F2SB.ajax,{method:'POST',body,credentials:'same-origin'});const text=await response.text();let json;
    try{json=JSON.parse(text);}catch{throw new Error('Resposta inesperada do servidor. Confira os logs PHP e o limite de memória. '+text.slice(0,400));}
    if(!json.success)throw new Error(json.data?.message||'A operação falhou.');return json.data;
  }
  function setBusy(value){busy=value;['backup','upload','restore','update-check','update-install','update-save'].forEach(id=>$(id).disabled=value||issue);}
  function reveal(){ $('progress').hidden=false; $('progress').scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'nearest'}); }
  function show(s){
    $('progress').hidden=false;$('title').textContent=phases[s.phase]||s.phase;$('status').textContent=s.error||s.message||'Mantenha esta aba aberta.';
    $('detail').textContent=s.type==='backup'?`${s.files||0} arquivos · ${size(s.bytes)} · ${s.rows||0} linhas do banco`:`${s.verified||0} arquivos conferidos`;
    $('bar').removeAttribute('value');$('confirm').hidden=s.phase!=='ready';
    if(s.phase==='ready'){
      const m=s.manifest;$('detail').textContent=`Ponto de retorno: ${date(m.created)}\nOrigem: ${m.home}\nWordPress ${m.wp_version} · ${m.files} arquivos · ${size(m.bytes)}\n${m.rows} linhas do banco de dados`;
      $('config-note').textContent=m.full_config?'O wp-config.php original também será recuperado. A conexão com o banco foi conferida.':'Backup legado: o wp-config.php atual será preservado. Gere um novo backup nesta versão para incluir a restauração da configuração original.';
    }
    if(['ready','done'].includes(s.phase)){$('bar').max=1;$('bar').value=1;}
  }
  function error(e){$('progress').hidden=false;$('title').textContent='Não foi possível concluir';$('status').textContent=e.message;$('bar').removeAttribute('value');setBusy(false);}
  async function run(id){active=id;setBusy(true);try{while(true){const {state:s}=await api('step',{id});show(s);if(['done','ready','failed'].includes(s.phase))break;await sleep();}await list();}catch(e){error(e);}finally{setBusy(false);}}
  function render(){
    const backups=jobs.filter(j=>j.type==='backup'&&j.phase==='done');$('count').textContent=String(backups.length);$('latest').textContent=backups[0]?date(backups[0].created):'Nenhum por enquanto';$('storage').textContent=size(backups.reduce((a,j)=>a+(j.archive_bytes||0),0));
    const filter=$('filter').value;const selected=jobs.filter(j=>filter==='all'||j.type===filter);const box=$('jobs');box.replaceChildren();
    if(!selected.length){const empty=el('div',undefined,'f2sb-empty');empty.append(el('span','◷'),el('strong','Seu primeiro ponto de retorno começa aqui.'),el('span','Crie um backup completo ou envie uma cópia existente.'));box.append(empty);return;}
    const wrap=el('div',undefined,'f2sb-table-wrap'),table=el('table',undefined,'f2sb-table'),head=el('thead'),tr=el('tr');
    ['Ponto de retorno','Conteúdo','Tamanho','Status','Ações'].forEach(t=>tr.append(el('th',t)));head.append(tr);table.append(head);const body=el('tbody');
    selected.forEach(job=>{
      const row=el('tr'),name=el('td');name.append(el('strong',date(job.created),'f2sb-point-title'),el('span',job.type==='backup'?'Backup do site':'Arquivo de restauração','f2sb-point-sub'));row.append(name);
      const scope=el('td');scope.append(el('span',job.type==='backup'?'Arquivos + banco':'Pacote ZIP'),el('small',job.files?`${job.files.toLocaleString('pt-BR')} arquivos`:'Verificação em etapas','f2sb-point-sub'));row.append(scope,el('td',job.archive_bytes?size(job.archive_bytes):'—'));
      const status=el('td');status.append(el('span',phases[job.phase]||job.phase,'f2sb-status '+(job.phase==='done'?'done':job.phase==='failed'?'failed':'')));row.append(status);
      const actions=el('td'),buttons=el('div',undefined,'f2sb-actions');
      if(job.type==='backup'&&job.phase==='done'){
        const url=new URL(F2SB.download);url.searchParams.set('action','f2sb_download');url.searchParams.set('id',job.id);url.searchParams.set('_wpnonce',F2SB.nonce);
        const a=el('a','↓ Baixar','f2sb-btn f2sb-btn-white');a.href=url.href;buttons.append(a);
        const restore=el('button','↶ Restaurar','f2sb-btn f2sb-btn-outline');restore.onclick=async()=>{if(busy)return;setBusy(true);try{const d=await api('prepare_local',{id:job.id});reveal();await run(d.id);}catch(e){error(e);}};buttons.append(restore);
      }
      if(!job.destructive&&!job.site_paused&&!['upload','done','failed','rolled_back','cancelled'].includes(job.phase)){
        const b=el('button',job.phase==='ready'?'Conferir':'Continuar','f2sb-btn f2sb-btn-white');b.onclick=()=>{if(busy)return;active=job.id;reveal();if(job.phase==='ready')show(job);else run(job.id);};buttons.append(b);
      }
      if(!job.site_paused&&(!job.destructive||['done','rolled_back'].includes(job.phase))){const b=el('button','Excluir','f2sb-text-action');b.onclick=async()=>{if(busy||!confirm('Excluir a cópia e os temporários deste ponto do servidor? O ZIP baixado no seu computador não será alterado.'))return;try{await api('delete',{id:job.id});await list();}catch(e){error(e);}};buttons.append(b);}
      else buttons.append(el('small','Use o link privado da operação.','f2sb-point-sub'));
      actions.append(buttons);row.append(actions);body.append(row);
    });table.append(body);wrap.append(table);box.append(wrap);
  }
  async function list(){const d=await api('list');jobs=d.jobs;render();}
  function executor(d){window.location.assign(d.runner+'#'+d.token);}
  $('backup').onclick=async()=>{if(busy)return;if(!$('quiet').checked){$('quiet').focus();alert('Confirme que as gravações externas e requisições em andamento foram encerradas. O site ficará em manutenção durante o backup.');return;}setBusy(true);try{executor(await api('start_backup',{quiet:'1'}));}catch(e){error(e);}};
  function filename(){const file=$('file').files[0];$('filename').textContent=file?`${file.name} · ${size(file.size)}`:'Arraste seu backup para cá';}
  $('file').onchange=filename;
  ['dragenter','dragover'].forEach(name=>$('drop').addEventListener(name,e=>{e.preventDefault();$('drop').classList.add('dragging');}));
  ['dragleave','drop'].forEach(name=>$('drop').addEventListener(name,e=>{e.preventDefault();$('drop').classList.remove('dragging');}));
  $('drop').addEventListener('drop',e=>{if(e.dataTransfer.files.length){$('file').files=e.dataTransfer.files;filename();}});
  $('upload').onclick=async()=>{if(busy)return;const file=$('file').files[0];if(!file){$('file').click();return;}setBusy(true);$('confirm').hidden=true;reveal();$('title').textContent='Enviando seu ponto de retorno';try{const {id}=await api('start_upload',{size:file.size});active=id;for(let offset=0;offset<file.size;offset+=1048576){await api('upload_chunk',{id,offset,chunk:file.slice(offset,offset+1048576)});$('bar').max=file.size;$('bar').value=Math.min(offset+1048576,file.size);$('status').textContent=`${size(Math.min(offset+1048576,file.size))} de ${size(file.size)}`;}await run(id);}catch(e){error(e);}finally{setBusy(false);}};
  $('restore').onclick=async()=>{if(busy)return;if($('word').value!=='RESTAURAR'){$('word').focus();return;}setBusy(true);try{executor(await api('start_restore',{id:active,confirm:'RESTAURAR'}));}catch(e){error(e);}};
  $('refresh').onclick=()=>{if(!busy)list().catch(error);};$('filter').onchange=render;
  document.querySelectorAll('.f2sb-nav>a').forEach(a=>a.onclick=()=>{document.querySelectorAll('.f2sb-nav>a').forEach(b=>b.classList.remove('active'));a.classList.add('active');if(a.hash==='#f2sb-updates')$('updates').open=true;});
  function updateMessage(text){$('updates').open=true;$('update-result').hidden=false;$('update-result').textContent=text;}
  $('update-save').onclick=async()=>{if(busy)return;setBusy(true);try{const d=await api('update_save',{repository:$('repository').value});repository=d.repository;$('repository').value=repository;$('update-install').hidden=true;updateMessage(d.message);}catch(e){updateMessage(e.message);}finally{setBusy(false);}};
  $('update-check').onclick=async()=>{if(busy)return;$('updates').open=true;$('updates').scrollIntoView({block:'nearest',behavior:'smooth'});if(!repository){$('repository').focus();updateMessage('Informe e salve o repositório público para habilitar as atualizações manuais.');return;}setBusy(true);updateMessage('Consultando a última versão publicada…');try{release=await api('update_check');$('update-install').hidden=!release.available;updateMessage(release.available?`Nova versão disponível: ${release.version}\nVersão instalada: ${release.current}\nO pacote será conferido por SHA-256 antes da instalação.`:`Você já está na versão mais recente: ${release.current}.`);}catch(e){updateMessage(e.message);$('update-install').hidden=true;}finally{setBusy(false);}};
  $('update-install').onclick=async()=>{if(busy||!release?.available||!confirm(`Instalar a versão ${release.version} do F2 Bakup? O código do plugin será substituído. Mantenha uma cópia recente do site.`))return;setBusy(true);updateMessage('Conferindo e instalando o pacote…');try{const d=await api('update_install');updateMessage(d.message);$('update-install').hidden=true;setTimeout(()=>location.reload(),1800);}catch(e){updateMessage(e.message);setBusy(false);}};
  list().catch(error);api('update_settings').then(d=>{repository=d.repository;$('repository').value=repository;}).catch(()=>{});
})();
