<?php
if(!defined('ABSPATH')) exit;

final class F2SB_Updater {
    const PLUGIN='f2-backup-local/f2-backup-local.php';
    public static function repository($value) {
        $value=trim($value);
        if(str_starts_with($value,'https://github.com/')) $value=substr($value,19);
        $value=preg_replace('/\.git$/','',rtrim($value,'/'));
        if(!preg_match('~^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$~D',$value)) F2SB_Engine::fail('Informe https://github.com/usuario/repositorio ou usuario/repositorio.');
        return $value;
    }
    public static function check($repo) {
        $response=wp_safe_remote_get('https://api.github.com/repos/'.$repo.'/releases/latest',[
            'timeout'=>25,'redirection'=>0,'limit_response_size'=>1048576,
            'headers'=>['Accept'=>'application/vnd.github+json','X-GitHub-Api-Version'=>'2022-11-28','User-Agent'=>'F2-Backup-Local/'.F2SB_Engine::VERSION]
        ]);
        if(is_wp_error($response)) F2SB_Engine::fail('Não foi possível consultar a versão: '.$response->get_error_message());
        $status=wp_remote_retrieve_response_code($response);
        if($status===404) F2SB_Engine::fail('Repositório público ou Release não encontrado. Publique uma Release estável com o arquivo f2-backup-local.zip.');
        if($status===403 || $status===429) F2SB_Engine::fail('Limite temporário de consultas atingido. Tente mais tarde.');
        if($status!==200) F2SB_Engine::fail('A consulta retornou HTTP '.$status.'.');
        $release=json_decode(wp_remote_retrieve_body($response),true);
        $version=ltrim($release['tag_name']??'','vV');
        if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D',$version) || !empty($release['draft']) || !empty($release['prerelease'])) F2SB_Engine::fail('Use uma Release estável com tag como v1.3.0.');
        $asset=null;
        foreach($release['assets']??[] as $a) if(($a['name']??'')==='f2-backup-local.zip' && ($a['state']??'')==='uploaded') {$asset=$a;break;}
        if(!$asset) F2SB_Engine::fail('Anexe f2-backup-local.zip à Release. O arquivo automático Source code não é usado.');
        $url=$asset['browser_download_url']??'';
        // Host e caminho são fixos; o download nativo também valida os redirecionamentos.
        if(!is_string($url) || !str_starts_with($url,'https://github.com/'.$repo.'/releases/download/')) F2SB_Engine::fail('Endereço do pacote inválido.');
        $digest=$asset['digest']??'';
        if(!preg_match('/^sha256:[a-f0-9]{64}$/D',$digest)) F2SB_Engine::fail('O pacote não possui digest SHA-256 válido. Envie novamente o ZIP como um asset novo da Release.');
        $data=['repository'=>$repo,'version'=>$version,'current'=>F2SB_Engine::VERSION,'available'=>version_compare($version,F2SB_Engine::VERSION,'>'),'url'=>$url,'sha256'=>substr($digest,7),'published'=>$release['published_at']??'','checked'=>time()];
        set_transient('f2sb_manual_release',$data,15*MINUTE_IN_SECONDS);
        return array_diff_key($data,array_flip(['url','sha256']));
    }
    public static function validatePackage($file,$version) {
        $zip=F2SB_Engine::zip($file);
        try {
            $sum=0;
            if($zip->numFiles>10000) F2SB_Engine::fail('Pacote com arquivos demais.');
            for($i=0;$i<$zip->numFiles;$i++) {
                $r=$zip->statIndex($i); $name=rtrim($r['name'],'/'); F2SB_Engine::relative($name);
                if($name!=='f2-backup-local' && !str_starts_with($name,'f2-backup-local/')) F2SB_Engine::fail('O ZIP precisa conter somente a pasta f2-backup-local.');
                $os=0;$attrs=0;$zip->getExternalAttributesIndex($i,$os,$attrs);
                if($os===ZipArchive::OPSYS_UNIX && (($attrs>>16)&0170000)===0120000) F2SB_Engine::fail('Links simbólicos não são aceitos na atualização.');
                $sum+=$r['size']; if($sum>33554432) F2SB_Engine::fail('Pacote descompactado excede 32 MiB.');
            }
            $head=$zip->getFromName(self::PLUGIN,8192);
            if(!$head || !preg_match('/^[ \t*]*Version:\s*([^\r\n]+)/mi',$head,$m) || trim($m[1])!==$version) F2SB_Engine::fail('A versão do cabeçalho do plugin não corresponde à tag da Release.');
            $engine=$zip->getFromName('f2-backup-local/engine.php',8192);
            if(!$engine || !preg_match("/const VERSION\\s*=\\s*'([^']+)'/",$engine,$m) || $m[1]!==$version) F2SB_Engine::fail('Atualize também F2SB_Engine::VERSION no pacote antes de publicar a Release.');
            foreach(['engine.php','updater.php','views/dashboard.php','assets/admin.js','assets/admin.css','assets/recovery.html'] as $name) if($zip->locateName('f2-backup-local/'.$name)===false) F2SB_Engine::fail('Pacote incompleto: '.$name);
        } finally {$zip->close();}
    }
    public static function install($repo) {
        $release=get_transient('f2sb_manual_release');
        if(!$release || $release['repository']!==$repo || !$release['available']) F2SB_Engine::fail('Verifique as atualizações novamente antes de instalar.');
        if(defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) F2SB_Engine::fail('A hospedagem bloqueou alterações de arquivos.');
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        $file=download_url($release['url'],120);
        if(is_wp_error($file)) F2SB_Engine::fail('Falha no download: '.$file->get_error_message());
        $filter=null;
        try {
            if(!hash_equals($release['sha256'],hash_file('sha256',$file))) F2SB_Engine::fail('O SHA-256 do download não corresponde ao pacote publicado. Atualização cancelada.');
            self::validatePackage($file,$release['version']);
            $current=get_site_transient('update_plugins'); if(!is_object($current)) $current=new stdClass();
            if(!isset($current->response) || !is_array($current->response)) $current->response=[];
            $current->response[self::PLUGIN]=(object)['slug'=>'f2-backup-local','plugin'=>self::PLUGIN,'new_version'=>$release['version'],'package'=>$release['url'],'url'=>''];
            set_site_transient('update_plugins',$current);
            $filter=static function($reply,$package) use($release,$file){return $package===$release['url']?$file:$reply;};
            add_filter('upgrader_pre_download',$filter,10,2);
            $skin=new WP_Ajax_Upgrader_Skin(); $upgrader=new Plugin_Upgrader($skin);
            $active=is_plugin_active(self::PLUGIN); $result=$upgrader->upgrade(self::PLUGIN,['clear_update_cache'=>true]);
            if(is_wp_error($result)) F2SB_Engine::fail($result->get_error_message());
            if($result!==true) {
                $errors=$skin->get_errors();
                F2SB_Engine::fail(is_wp_error($errors)&&$errors->has_errors()?$errors->get_error_message():'Não foi possível instalar. Verifique as permissões do sistema de arquivos.');
            }
            if($active && !is_plugin_active(self::PLUGIN)) {
                $activation=activate_plugin(self::PLUGIN,'',false,true);
                if(is_wp_error($activation)) F2SB_Engine::fail('Atualizado, mas a reativação falhou: '.$activation->get_error_message());
            }
            delete_transient('f2sb_manual_release');
            return ['installed'=>true,'version'=>$release['version'],'message'=>'Atualização instalada. Recarregue o painel.'];
        } finally {
            if($filter) remove_filter('upgrader_pre_download',$filter,10);
            if(is_file($file)) unlink($file);
        }
    }
    public static function handle($op) {
        $repo=get_option('f2sb_github_repository','');
        if($op==='update_settings') return ['repository'=>$repo,'version'=>F2SB_Engine::VERSION];
        if($op==='update_save') {
            $repo=self::repository(wp_unslash($_POST['repository']??''));
            update_option('f2sb_github_repository',$repo,false); delete_transient('f2sb_manual_release');
            return ['repository'=>$repo,'message'=>'Repositório salvo.'];
        }
        if(!$repo) F2SB_Engine::fail('Configure o repositório na seção Atualizações.');
        if($op==='update_check') return self::check($repo);
        if($op==='update_install') return self::install($repo);
        F2SB_Engine::fail('Ação de atualização inválida.');
    }
}
