<?php
/**
 * Plugin Name: F2 Bakup
 * Description: Backup completo em ZIP e restauração local em etapas, sem serviço externo ou licença.
 * Version: 1.2.2
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: F2Digital
 * Update URI: false
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) exit;
define('F2SB_ENGINE',true);
require_once __DIR__.'/engine.php';
require_once __DIR__.'/updater.php';

final class F2SB_Plugin {
    public function __construct() {
        add_action('admin_menu',[$this,'menu']);
        add_action('admin_enqueue_scripts',[$this,'assets']);
        add_action('wp_ajax_f2sb',[$this,'ajax']);
        add_action('admin_post_f2sb_download',[$this,'download']);
    }
    public function menu() { add_management_page('F2 Bakup','F2 Bakup','manage_options','f2-backup-local',[$this,'page']); }
    public function assets($hook) {
        if($hook!=='tools_page_f2-backup-local') return;
        wp_enqueue_style('f2sb',plugins_url('assets/admin.css',__FILE__),[],F2SB_Engine::VERSION);
        wp_enqueue_script('f2sb',plugins_url('assets/admin.js',__FILE__),[],F2SB_Engine::VERSION,true);
        wp_localize_script('f2sb','F2SB',['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('f2sb'),'download'=>admin_url('admin-post.php'),'home'=>home_url('/')]);
    }
    private function guard() {
        if(!current_user_can('manage_options') || !current_user_can('update_plugins')) F2SB_Engine::fail('Acesso permitido somente a administradores.');
        if(is_multisite()) F2SB_Engine::fail('Multisite não é suportado na versão 1.');
        if(!class_exists('ZipArchive') || !extension_loaded('mysqli')) F2SB_Engine::fail('A hospedagem precisa habilitar ZipArchive e mysqli.');
        if(realpath(WP_CONTENT_DIR)!==realpath(ABSPATH.'wp-content')) F2SB_Engine::fail('Esta versão requer wp-content dentro da raiz padrão do WordPress.');
        if(is_link(ABSPATH.'wp-content') || !is_file(ABSPATH.'wp-config.php')) F2SB_Engine::fail('Esta versão requer wp-config.php e wp-content na raiz padrão, sem links simbólicos.');
    }
    private function vault() {
        $root=realpath(ABSPATH); $doc=isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $suffix='f2sb-'.substr(hash('sha256',$root.wp_salt('auth')),0,24);
        $candidates=[dirname($root).'/.'.$suffix, rtrim(sys_get_temp_dir(),'/').'/'.$suffix];
        foreach($candidates as $base) {
            if(is_link($base)) continue;
            $parent=realpath(dirname($base));
            if(!$parent || F2SB_Engine::inside($parent,$root) || ($doc && F2SB_Engine::inside($parent,$doc))) continue;
            if(!is_dir($base) && is_writable($parent)) @mkdir($base,0700);
            if(is_dir($base) && is_writable($base)) { chmod($base,0700); return $base; }
        }
        F2SB_Engine::fail('Não há pasta privada gravável fora da raiz pública. Solicite uma pasta temporária privada à hospedagem.');
    }
    private function job($id) {
        if(!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) F2SB_Engine::fail('Identificador inválido.');
        $dir=$this->vault().'/'.$id;
        if(!is_dir($dir) || is_link($dir)) F2SB_Engine::fail('Operação não encontrada.');
        return $dir;
    }
    private function config() {
        global $wpdb;
        if(!($wpdb instanceof wpdb) || !($wpdb->dbh instanceof mysqli)) F2SB_Engine::fail('Esta conexão personalizada não oferece o acesso mysqli necessário ao backup.');
        // wpdb disponibiliza estas propriedades por __get(), inclusive as protegidas.
        // A senha vazia é válida; nunca substitui por outra senha ou usuário de fallback.
        $cfg=['host'=>(string)$wpdb->dbhost,'user'=>(string)$wpdb->dbuser,'password'=>(string)$wpdb->dbpassword,'name'=>(string)$wpdb->dbname,'charset'=>$wpdb->dbh->character_set_name(),'flags'=>defined('MYSQL_CLIENT_FLAGS')?MYSQL_CLIENT_FLAGS:0];
        [$cfg['connect_host'],$cfg['port'],$cfg['socket']]=F2SB_Engine::endpoint($cfg);
        $cfg['server_signature']=F2SB_Engine::serverSignature($wpdb->dbh);
        return $cfg;
    }
    private function newJob($type) {
        global $wpdb,$wp_version;
        // Valida a conexão independente antes de criar uma operação ou pausar o site.
        $cfg=$this->config(); $probe=F2SB_Engine::db($cfg); $probe->close();
        $id=bin2hex(random_bytes(16)); $dir=$this->vault().'/'.$id; F2SB_Engine::mkdir($dir);
        $s=['type'=>$type,'phase'=>$type==='backup'?'db':'upload','root'=>realpath(ABSPATH),'created'=>gmdate('c'),'home'=>home_url(),'siteurl'=>site_url(),'prefix'=>$wpdb->prefix,'wp_version'=>$wp_version,'files'=>0,'bytes'=>0,'rows'=>0,'exclude'=>[],'user'=>get_current_user_id(),'updated'=>time(),'lock_id'=>$id,'return_url'=>admin_url('tools.php?page=f2-backup-local')];
        F2SB_Engine::save($dir.'/config.json',$cfg);
        copy(__DIR__.'/engine.php',$dir.'/engine.php'); chmod($dir.'/engine.php',0600);
        F2SB_Engine::save($dir.'/state.json',$s);
        return [$id,$dir,$s];
    }
    private function listJobs() {
        $jobs=[];
        foreach(glob($this->vault().'/*/state.json') ?: [] as $file) {
            try {
                $s=$this->readJobState($file); $row=F2SB_Engine::publicState($s); $row['id']=basename(dirname($file)); $jobs[]=$row;
            } catch(Throwable $e) { /* Não publica controles corrompidos. */ }
        }
        usort($jobs,static fn($a,$b)=>strcmp($b['created'],$a['created']));
        return $jobs;
    }
    private function readJobState($file) {
        $s=F2SB_Engine::json($file);
        // Tentativas antigas que falharam na pré-conexão não devem bloquear uma nova cópia.
        if(($s['type']??'')==='backup' && ($s['phase']??'')==='db' && empty($s['tables']) && empty($s['runner']) && empty($s['site_paused']) && empty($s['destructive']) && ($s['updated']??0)<time()-30) {
            $s['phase']='failed'; $s['error']='A tentativa anterior parou antes de iniciar a cópia. Exclua esta tentativa e crie um novo ponto de retorno.';
            F2SB_Engine::save($file,$s);
        }
        return $s;
    }
    public function ajax() {
        try {
            $this->guard(); check_ajax_referer('f2sb','nonce');
            $op=isset($_POST['op'])?sanitize_key(wp_unslash($_POST['op'])):'';
            if($op==='list') { wp_send_json_success(['jobs'=>$this->listJobs()]); }
            if(str_starts_with($op,'update_')) {
                if($op==='update_install') $this->assertIdle();
                wp_send_json_success(F2SB_Updater::handle($op));
            }
            if($op==='start_backup') {
                if(($_POST['quiet']??'')!=='1') F2SB_Engine::fail('Confirme que cron e integrações externas estão pausados antes de criar o ponto de retorno.');
                $this->assertIdle();
                [$id,$dir,$s]=$this->newJob('backup');
                $db=F2SB_Engine::db($this->config());
                try {
                    $s['tables']=F2SB_Engine::tables($db,$s['prefix']);
                    if(!$s['tables']) F2SB_Engine::fail('Nenhuma tabela do WordPress encontrada.');
                    $triggers=$db->query('SHOW TRIGGERS');
                    while($t=$triggers->fetch_assoc()) if(str_starts_with($t['Table'],$s['prefix'])) F2SB_Engine::fail('Tabelas com triggers exigem backup pelo painel da hospedagem.');
                } finally { $db->close(); }
                $s['table_i']=0; $s['row_i']=0; $s['schema_written']=false;
                $s['phase']='pause';
                $s['download_url']=add_query_arg('_wpnonce',wp_create_nonce('f2sb'),admin_url('admin-post.php?action=f2sb_download&id='.$id));
                F2SB_Engine::save($dir.'/state.json',$s);
                $executor=$this->executor($dir,$s); wp_send_json_success($executor);
            }
            if($op==='start_upload') {
                $this->assertIdle(); [$id,$dir,$s]=$this->newJob('restore');
                $size=filter_var($_POST['size']??null,FILTER_VALIDATE_INT);
                if(!$size || $size<100) F2SB_Engine::fail('Escolha um arquivo ZIP válido.');
                $free=disk_free_space($dir);
                if($free!==false && $free<$size+134217728) F2SB_Engine::fail('Espaço insuficiente para receber o ZIP.');
                $s['upload_size']=$size; $s['upload_offset']=0; file_put_contents($dir.'/backup.zip','');
                F2SB_Engine::save($dir.'/state.json',$s);
                wp_send_json_success(['id'=>$id]);
            }
            $id=sanitize_text_field(wp_unslash($_POST['id']??'')); $dir=$this->job($id);
            if($op==='prepare_local') {
                $this->assertIdle(); $source=F2SB_Engine::json($dir.'/state.json');
                if($source['type']!=='backup' || $source['phase']!=='done') F2SB_Engine::fail('Selecione um backup concluído.');
                [$newId,$newDir,$state]=$this->newJob('restore');
                if(!@link($dir.'/backup.zip',$newDir.'/backup.zip') && !copy($dir.'/backup.zip',$newDir.'/backup.zip')) F2SB_Engine::fail('Não foi possível preparar a restauração local.');
                $state['phase']='inspect'; F2SB_Engine::save($newDir.'/state.json',$state);
                wp_send_json_success(['id'=>$newId,'state'=>F2SB_Engine::publicState($state)]);
            }
            if($op==='step') {
                $s=F2SB_Engine::execute($dir,'step'); wp_send_json_success(['state'=>F2SB_Engine::publicState($s)]);
            }
            if($op==='upload_chunk') {
                $lock=fopen($dir.'/lock','c');
                if(!flock($lock,LOCK_EX|LOCK_NB)) F2SB_Engine::fail('Outra requisição está em andamento.');
                try {
                    $s=F2SB_Engine::json($dir.'/state.json');
                    if($s['phase']!=='upload') F2SB_Engine::fail('Envio já encerrado.');
                    $offset=filter_var($_POST['offset']??null,FILTER_VALIDATE_INT);
                    $f=$_FILES['chunk']??null;
                    if(!$f || $f['error']!==UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) F2SB_Engine::fail('Falha no envio da parte do arquivo.');
                    if($offset!==$s['upload_offset'] || $f['size']<1 || $f['size']>F2SB_Engine::CHUNK || $offset+$f['size']>$s['upload_size']) F2SB_Engine::fail('Parte fora de ordem ou tamanho inválido. Recomece o envio.');
                    $h=fopen($dir.'/backup.zip','c+b'); fseek($h,$offset);
                    try { F2SB_Engine::writeAll($h,file_get_contents($f['tmp_name'])); } finally { fclose($h); }
                    $s['upload_offset']+=$f['size'];
                    if($s['upload_offset']===$s['upload_size']) $s['phase']='inspect';
                    $s['updated']=time(); F2SB_Engine::save($dir.'/state.json',$s);
                } finally { flock($lock,LOCK_UN); fclose($lock); }
                wp_send_json_success(['state'=>F2SB_Engine::publicState($s)]);
            }
            if($op==='start_restore') {
                if(($_POST['confirm']??'')!=='RESTAURAR') F2SB_Engine::fail('Digite RESTAURAR para confirmar.');
                $this->assertIdle($id);
                $s=F2SB_Engine::json($dir.'/state.json');
                if($s['phase']!=='ready') F2SB_Engine::fail('Verifique o pacote primeiro.');
                $s['prune']=true;
                wp_send_json_success($this->executor($dir,$s));
            }
            if($op==='delete') {
                $s=F2SB_Engine::json($dir.'/state.json');
                if(!empty($s['site_paused']) || (($s['destructive']??false) && !in_array($s['phase'],['done','rolled_back'],true))) F2SB_Engine::fail('Finalize, cancele ou reverta a operação antes de excluir.');
                $lock=fopen($dir.'/lock','c');
                if(!flock($lock,LOCK_EX|LOCK_NB)) F2SB_Engine::fail('Operação em andamento.');
                if(!empty($s['runner'])) @unlink($s['root'].'/'.$s['runner']);
                try { F2SB_Engine::removeTree($dir); } finally { flock($lock,LOCK_UN); fclose($lock); }
                wp_send_json_success(['deleted'=>true]);
            }
            F2SB_Engine::fail('Ação desconhecida.');
        } catch(Throwable $e) {
            wp_send_json_error(['message'=>$e->getMessage()],400);
        }
    }
    private function assertIdle($except='') {
        foreach(glob($this->vault().'/*/state.json')?:[] as $file) {
            $s=$this->readJobState($file);
            if(basename(dirname($file))===$except) continue;
            if(($s['destructive']??false) && !in_array($s['phase'],['done','rolled_back'],true)) F2SB_Engine::fail('Há uma restauração pendente. Finalize ou reverta antes de iniciar outra operação.');
            if(!empty($s['site_paused'])) F2SB_Engine::fail('Há um backup com o site em manutenção. Use o link privado para concluir ou cancelar.');
            if(!in_array($s['phase'],['done','failed','rolled_back','cancelled'],true) && ($s['updated']??0)>time()-3600) F2SB_Engine::fail('Há outra operação em andamento. Conclua ou exclua pela lista antes de iniciar outra.');
        }
    }
    private function executor($dir,$s) {
        if(!empty($s['runner'])) @unlink($s['root'].'/'.$s['runner']);
        $token=bin2hex(random_bytes(32)); $runner='f2sb-restore-'.bin2hex(random_bytes(16)).'.php';
        $s['token_hash']=hash('sha256',$token); $s['token_expires']=time()+86400; $s['runner']=$runner;
        F2SB_Engine::save($dir.'/state.json',$s); $body=$this->runnerSource($dir);
        if(file_put_contents($s['root'].'/'.$runner,$body,LOCK_EX)!==strlen($body)) F2SB_Engine::fail('Não foi possível criar o executor.');
        chmod($s['root'].'/'.$runner,0644);
        return ['state'=>F2SB_Engine::publicState($s),'runner'=>site_url('/'.$runner),'token'=>$token,'id'=>basename($dir)];
    }
    private function runnerSource($job) {
        $path=var_export($job,true);
        $html=var_export(file_get_contents(__DIR__.'/assets/recovery.html'),true);
        return '<?php' . "\n" . '$job='.$path.';' . "\n" . '$recovery_html='.$html.';' . <<<'PHP'

ini_set('display_errors','0');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
if ($_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
    header('Cache-Control: no-store, private');
    echo $recovery_html; exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('Use POST.');
    define('F2SB_ENGINE',true);
    require $job.'/engine.php';
    $s=F2SB_Engine::json($job.'/state.json');
    $token=$_SERVER['HTTP_X_F2SB_TOKEN']??'';
    if (!is_string($token) || !hash_equals($s['token_hash'],hash('sha256',$token)) || time()>$s['token_expires']) {
        http_response_code(403); throw new RuntimeException('Acesso expirado ou inválido.');
    }
    $op=$_POST['op']??'step';
    if ($op==='finish') {
        if (!in_array($s['phase'],['done','rolled_back','cancelled','failed'],true) || !empty($s['site_paused'])) throw new RuntimeException('Finalize a operação antes de limpar.');
        if($s['type']==='restore' && !in_array($s['phase'],['done','rolled_back'],true)) throw new RuntimeException('Reverta ou conclua a restauração antes de limpar.');
        if($s['type']==='backup' && $s['phase']==='done') {
            unset($s['token_hash'],$s['token_expires'],$s['runner']); F2SB_Engine::save($job.'/state.json',$s);
            @unlink(__FILE__); echo json_encode(['success'=>true,'data'=>['finished'=>true]]); exit;
        }
        // O token será invalidado com a remoção do executor e do controle privado.
        F2SB_Engine::removeTree($job);
        @unlink(__FILE__);
        echo json_encode(['success'=>true,'data'=>['finished'=>true]]); exit;
    }
    if (!in_array($op,['step','status','rollback','cancel_backup'],true)) throw new RuntimeException('Ação inválida.');
    $s=F2SB_Engine::execute($job,$op);
    echo json_encode(['success'=>true,'data'=>['state'=>F2SB_Engine::publicState($s)]],JSON_INVALID_UTF8_SUBSTITUTE);
} catch(Throwable $e) {
    if(http_response_code()<400) http_response_code(400);
    echo json_encode(['success'=>false,'data'=>['message'=>$e->getMessage()]],JSON_INVALID_UTF8_SUBSTITUTE);
}
PHP;
    }
    public function download() {
        try {
            $this->guard(); check_admin_referer('f2sb');
            $id=sanitize_text_field(wp_unslash($_GET['id']??'')); $dir=$this->job($id); $s=F2SB_Engine::json($dir.'/state.json');
            if($s['type']!=='backup' || $s['phase']!=='done') F2SB_Engine::fail('Backup ainda não está concluído.');
            $file=$dir.'/backup.zip'; $size=filesize($file);
            while(ob_get_level()) ob_end_clean();
            nocache_headers(); header('Content-Type: application/zip'); header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="f2-backup-'.gmdate('Ymd-His',strtotime($s['created'])).'.zip"');
            header('Content-Length: '.$size);
            @set_time_limit(0); $h=fopen($file,'rb');
            while(!feof($h)) { echo fread($h,F2SB_Engine::CHUNK); flush(); if(connection_aborted()) break; }
            fclose($h); exit;
        } catch(Throwable $e) { wp_die(esc_html($e->getMessage())); }
    }
    public function page() {
        if(!current_user_can('manage_options')) return;
        $issue=''; try{$this->guard();$this->vault();}catch(Throwable $e){$issue=$e->getMessage();}
        include __DIR__.'/views/dashboard.php';
    }
}
new F2SB_Plugin();
