<?php
/* Motor privado. Não possui rotas públicas quando instalado como plugin. */
if (!defined('F2SB_ENGINE')) { http_response_code(403); exit; }

final class F2SB_Engine {
    const VERSION = '1.2.1';
    const FORMAT = 'f2sb-1';
    const CHUNK = 1048576;
    public static function fail($message) { throw new RuntimeException($message); }
    public static function json($file) {
        $value = json_decode((string) file_get_contents($file), true);
        if (!is_array($value)) self::fail('Arquivo de controle inválido.');
        return $value;
    }
    public static function save($file, $data) {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = $file . '.new';
        if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) self::fail('Não foi possível gravar o controle. Verifique espaço e permissões.');
        chmod($tmp, 0600);
        if (!rename($tmp, $file)) self::fail('Não foi possível salvar o controle.');
    }
    public static function mkdir($dir) {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) self::fail('Não foi possível criar uma pasta de trabalho.');
    }
    public static function inside($path, $root) {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
    public static function relative($path) {
        if (!is_string($path) || $path === '' || strlen($path) > 2048 || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, ':') || str_starts_with($path, '/')) self::fail('Caminho inválido no pacote.');
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') self::fail('Caminho inseguro no pacote.');
        }
        return $path;
    }
    public static function target($root, $relative) {
        self::relative($relative);
        $at = rtrim($root, '/');
        foreach (explode('/', $relative) as $part) {
            $at .= '/' . $part;
            if (is_link($at)) self::fail('Link simbólico encontrado; operação interrompida: ' . $relative);
        }
        return $at;
    }
    public static function writeAll($handle, $data) {
        $len = strlen($data); $pos = 0;
        while ($pos < $len) {
            $n = fwrite($handle, substr($data, $pos));
            if ($n === false || $n === 0) self::fail('Falha de escrita. Verifique o espaço em disco.');
            $pos += $n;
        }
    }
    public static function line($handle, $record) {
        self::writeAll($handle, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    public static function ident($name) { return '`' . str_replace('`', '``', $name) . '`'; }
    public static function fingerprint($cfg) {
        return hash('sha256',json_encode([$cfg['host'],$cfg['user'],$cfg['password'],$cfg['name']],JSON_THROW_ON_ERROR));
    }
    public static function endpoint($config) {
        // Compatível com DB_HOST e com os padrões do PHP usados pelo LocalWP.
        $host=(string)$config['host']; $port=null; $socket=null;
        if(str_starts_with($host,'p:')) $host=substr($host,2);
        $pos=strpos($host,':/');
        if($pos!==false) { $socket=substr($host,$pos+1); $host=substr($host,0,$pos); }
        if(preg_match('/^\[([0-9a-fA-F:.]+)\](?::([0-9]+))?$/D',$host,$m)) {
            $host=$m[1]; if(isset($m[2])) $port=(int)$m[2];
        } elseif(substr_count($host,':')<=1 && preg_match('/^([^:]*):([0-9]+)$/D',$host,$m)) {
            $host=$m[1]; $port=(int)$m[2];
        }
        if(array_key_exists('connect_host',$config)) $host=$config['connect_host'];
        if(array_key_exists('port',$config)) $port=$config['port'];
        elseif($port===null && (int)ini_get('mysqli.default_port')>0) $port=(int)ini_get('mysqli.default_port');
        if(array_key_exists('socket',$config)) $socket=$config['socket'];
        elseif($socket===null && ini_get('mysqli.default_socket')!=='') $socket=(string)ini_get('mysqli.default_socket');
        if($port!==null && ($port<1 || $port>65535)) self::fail('Porta do banco inválida.');
        if(substr_count($host,':')>1 && !str_starts_with($host,'[') && extension_loaded('mysqlnd')) $host='['.$host.']';
        return [$host,$port,$socket];
    }
    public static function serverSignature($db) {
        $r=$db->query('SELECT DATABASE(), @@hostname, @@port, @@server_id');
        if(!$r) self::fail('Não foi possível identificar o banco usado pelo WordPress.');
        $identity=array_map(static fn($v)=>$v===null?null:(string)$v,$r->fetch_row());
        return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
    }
    public static function db($config) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        [$host,$port,$socket]=self::endpoint($config);
        $db=mysqli_init(); $db->options(MYSQLI_OPT_CONNECT_TIMEOUT,15);
        try {
            // Assim como wpdb: autentica primeiro e seleciona o banco depois.
            $db->real_connect($host,$config['user'],$config['password'],null,$port,$socket,(int)($config['flags']??0));
            $db->select_db($config['name']);
            if(!empty($config['server_signature']) && !hash_equals($config['server_signature'],self::serverSignature($db))) {
                $db->close(); self::fail('A conexão separada alcançou outro servidor MySQL. Confira a porta/socket da aba Database do LocalWP. Nenhum backup foi iniciado.');
            }
            $db->set_charset($config['charset']?:'utf8mb4');
            $db->query("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
        } catch(mysqli_sql_exception $e) {
            // Não expõe senha ou texto da exceção; o código permite diagnosticar o endpoint.
            $endpoint=$host.($port!==null?':'.$port:'').($socket?' (socket configurado)':'');
            self::fail('Não foi possível repetir a conexão do WordPress em '.$endpoint.' (MySQL '.$e->getCode().'). Confira a porta/socket do ambiente; no LocalWP, consulte a aba Database. Não altere a senha do site para corrigir este aviso.');
        }
        return $db;
    }
    public static function tables($db, $prefix) {
        $list = [];
        $result = $db->query('SHOW FULL TABLES');
        while ($row = $result->fetch_row()) {
            if (!str_starts_with($row[0], $prefix)) continue;
            if ($row[1] !== 'BASE TABLE') self::fail('Views não são suportadas nesta versão: ' . $row[0]);
            $list[] = $row[0];
        }
        sort($list, SORT_STRING);
        return $list;
    }
    public static function zip($file, $flags = 0) {
        $zip = new ZipArchive();
        if ($zip->open($file, $flags) !== true) self::fail('Não foi possível abrir o ZIP. Confira a extensão ZipArchive e o espaço em disco.');
        return $zip;
    }
    public static function scanStart($job, $file = 'queue.jsonl') {
        file_put_contents($job . '/' . $file, "\"\"\n");
    }
    /* Fila em disco evita manter todos os caminhos do site em memória. */
    public static function scan($job, &$s, $callback, $seconds = 3.0) {
        $queue = fopen($job . '/queue.jsonl', 'c+b');
        fseek($queue, $s['queue_offset']);
        $end = microtime(true) + $seconds;
        try {
            while (microtime(true) < $end) {
                if ($s['scan_dir'] === null) {
                    $line = fgets($queue);
                    if ($line === false) return true;
                    $s['queue_offset'] = ftell($queue);
                    $s['scan_dir'] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $s['scan_cursor'] = 0;
                }
                $rel = $s['scan_dir'];
                $dir = $rel === '' ? $s['root'] : self::target($s['root'], $rel);
                if (!is_readable($dir)) self::fail('Pasta sem permissão de leitura: ' . $rel);
                $dh = opendir($dir);
                if (!$dh) self::fail('Não foi possível ler a pasta: ' . $rel);
                $n = 0; $complete = true;
                try {
                    while (($name = readdir($dh)) !== false) {
                        if ($name === '.' || $name === '..') continue;
                        $n++;
                        if ($n <= $s['scan_cursor']) continue;
                        $r = ltrim($rel . '/' . $name, '/');
                        if (self::excluded($r, $s)) { $s['scan_cursor'] = $n; continue; }
                        $full = self::target($s['root'], $r);
                        if (is_dir($full)) {
                            $callback($r, $full, true);
                            $pos = ftell($queue); fseek($queue, 0, SEEK_END);
                            self::line($queue, $r); fflush($queue); fseek($queue, $pos);
                        } elseif (is_file($full)) {
                            $callback($r, $full);
                        } else self::fail('Arquivo especial não suportado: ' . $r);
                        $s['scan_cursor'] = $n;
                        if (microtime(true) >= $end) { $complete = false; break; }
                    }
                } finally { closedir($dh); }
                if ($complete) { $s['scan_dir'] = null; $s['scan_cursor'] = 0; }
            }
            return false;
        } finally { fclose($queue); }
    }
    public static function excluded($rel, $s) {
        // Somente arquivos operacionais deste plugin; nenhum cache/upload é omitido.
        return $rel === '.maintenance' || preg_match('/^f2sb-restore-[a-f0-9]{32}\.php$/D', $rel) || preg_match('~^wp-content/mu-plugins/f2sb-cache-flush-[a-f0-9]+\.php$~D',$rel) || in_array($rel, $s['exclude'] ?? [], true);
    }
    public static function backup($job, &$s, $cfg) {
        if ($s['phase'] === 'pause') {
            if(file_exists($s['root'].'/.maintenance')) self::fail('O site já está em manutenção.');
            $s['site_paused']=true; $s['pause_until']=time()+15; self::maintenance($s);
            $s['phase']='settle'; $s['message']='Site em manutenção. Aguardando finalizar as requisições anteriores.';
        } elseif ($s['phase']==='settle') {
            if(time() >= $s['pause_until']) {
                $s['created']=gmdate('c'); $s['phase']='db';
                $s['message']='Copiando o banco com novas requisições do WordPress bloqueadas.';
            }
        } elseif ($s['phase'] === 'db') {
            $db = self::db($cfg); $out = fopen($job . '/database.jsonl', 'ab');
            $end = microtime(true) + 3;
            try {
                while ($s['table_i'] < count($s['tables']) && microtime(true) < $end) {
                    $table = $s['tables'][$s['table_i']];
                    if (!$s['schema_written']) {
                        $row = $db->query('SHOW CREATE TABLE ' . self::ident($table))->fetch_row();
                        $columns = [];
                        $res = $db->query('SHOW FULL COLUMNS FROM ' . self::ident($table));
                        while ($c = $res->fetch_assoc()) {
                            if (!preg_match('/(?:VIRTUAL|STORED) GENERATED/i', $c['Extra'])) $columns[] = $c['Field'];
                        }
                        if (!$columns) self::fail('Tabela sem colunas exportáveis: ' . $table);
                        self::line($out, ['kind'=>'schema', 'table'=>$table, 'sql'=>$row[1], 'columns'=>$columns]);
                        $pk=[]; $keys=$db->query('SHOW INDEX FROM '.self::ident($table)." WHERE Key_name='PRIMARY'");
                        while($k=$keys->fetch_assoc()) $pk[(int)$k['Seq_in_index']]=$k['Column_name'];
                        ksort($pk); $s['pk']=array_values($pk); $s['pk_last']=null;
                        $s['columns'] = $columns; $s['schema_written'] = true;
                    }
                    $sql = 'SELECT ' . implode(',', array_map([self::class,'ident'], $s['columns'])) . ' FROM ' . self::ident($table);
                    if($s['pk']) {
                        $pkNames=implode(',',array_map([self::class,'ident'],$s['pk']));
                        if($s['pk_last']!==null) {
                            $values=[];
                            foreach($s['pk_last'] as $v) $values[]="'".$db->real_escape_string(base64_decode($v,true))."'";
                            if(count($s['pk'])===1) $sql.=' WHERE '.$pkNames.' > '.$values[0];
                            else $sql.=' WHERE ('.$pkNames.') > ('.implode(',',$values).')';
                        }
                        $sql.=' ORDER BY '.$pkNames.' LIMIT 100';
                    } else $sql.=' LIMIT 100 OFFSET '.(int)$s['row_i'];
                    $result=$db->query($sql,MYSQLI_USE_RESULT); $found=false;
                    try {
                        while(microtime(true)<$end && ($row=$result->fetch_row())!==null) {
                            $found=true;
                            self::line($out, ['kind'=>'row','table'=>$table, 'values'=>array_map(static fn($v)=>$v === null ? null : base64_encode($v), $row)]);
                            if($s['pk']) {
                                $s['pk_last']=[];
                                foreach($s['pk'] as $key) $s['pk_last'][]=base64_encode($row[array_search($key,$s['columns'],true)]);
                            }
                            $s['row_i']++; $s['rows']++;
                        }
                    } finally { $result->free(); }
                    if (!$found && microtime(true)<$end) {
                        self::line($out, ['kind'=>'end','table'=>$table]);
                        $s['table_i']++; $s['row_i'] = 0; $s['schema_written'] = false;
                    }
                }
                fflush($out);
                if ($s['table_i'] === count($s['tables'])) {
                    $s['phase'] = 'files'; self::scanStart($job);
                    $s['queue_offset']=0; $s['scan_dir']=null; $s['scan_cursor']=0;
                }
            } finally { fclose($out); $db->close(); }
        } elseif ($s['phase'] === 'files') {
            $zip = self::zip($job . '/backup.zip', ZipArchive::CREATE);
            $index = fopen($job . '/files.jsonl', 'ab');
            try {
                $done = self::scan($job, $s, function($rel, $full, $directory=false) use ($zip, $index, &$s,$job) {
                    if($directory) {
                        $h=fopen($job.'/directories.jsonl','ab');
                        try { self::line($h,['path'=>$rel,'mode'=>fileperms($full)&0777]); } finally { fclose($h); }
                        $s['directories']=($s['directories']??0)+1; return;
                    }
                    if (!is_readable($full)) self::fail('Arquivo sem leitura: ' . $rel);
                    $before = stat($full);
                    if (!$zip->addFile($full, 'site/' . $rel)) self::fail('Não foi possível adicionar: ' . $rel);
                    // STORE evita compressão demorada de vídeos/PDFs durante close().
                    $zip->setCompressionName('site/' . $rel, ZipArchive::CM_STORE);
                    $hash = hash_file('sha256', $full);
                    if ($hash === false) self::fail('Falha ao verificar: ' . $rel);
                    clearstatcache(true, $full); $after = stat($full);
                    if ($before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']) self::fail('Arquivo mudou durante a cópia. Pause gravações e refaça o backup: ' . $rel);
                    self::line($index, ['path'=>$rel, 'size'=>$after['size'], 'sha256'=>$hash, 'mode'=>$after['mode'] & 0777]);
                    $s['files']++; $s['bytes'] += $after['size'];
                });
                if (!$zip->close()) self::fail('Falha ao fechar o ZIP. Confira o espaço em disco.');
                if ($done) {
                    $s['phase']='check_db'; self::mkdir($job.'/check');
                    $s['check_state']=['phase'=>'db','table_i'=>0,'row_i'=>0,'schema_written'=>false,'tables'=>$s['tables'],'rows'=>0];
                    $s['message']='Conferindo se o banco permaneceu estável durante a cópia.';
                }
            } finally { fclose($index); }
        } elseif($s['phase']==='check_db') {
            self::backup($job.'/check',$s['check_state'],$cfg);
            if($s['check_state']['phase']==='files') {
                if(!hash_equals(hash_file('sha256',$job.'/database.jsonl'),hash_file('sha256',$job.'/check/database.jsonl'))) self::fail('O banco mudou durante a cópia. Pause também cron/integrações externas e refaça o backup.');
                unset($s['check_state']); $s['phase']='check_files'; $s['check_offset']=0;
                $s['message']='Conferindo o ZIP e os arquivos de origem antes de liberar o site.';
            }
        } elseif($s['phase']==='check_files') {
            $h=fopen($job.'/files.jsonl','rb'); fseek($h,$s['check_offset']);
            $zip=self::zip($job.'/backup.zip'); $end=microtime(true)+3;
            try {
                while(microtime(true)<$end && ($line=fgets($h))!==false) {
                    $r=json_decode($line,true,512,JSON_THROW_ON_ERROR);
                    $in=$zip->getStream('site/'.$r['path']);
                    if(!$in) self::fail('Arquivo ausente no ZIP: '.$r['path']);
                    $ctx=hash_init('sha256'); hash_update_stream($ctx,$in); fclose($in);
                    if(!hash_equals($r['sha256'],hash_final($ctx)) || !hash_equals($r['sha256'],hash_file('sha256',self::target($s['root'],$r['path'])))) self::fail('Um arquivo mudou durante a cópia: '.$r['path']);
                    $s['check_offset']=ftell($h);
                }
                if(feof($h)) $s['phase']='seal';
            } finally { fclose($h); $zip->close(); }
        } elseif ($s['phase'] === 'seal') {
            $manifest = ['format'=>self::FORMAT, 'version'=>self::VERSION, 'created'=>$s['created'], 'home'=>$s['home'], 'siteurl'=>$s['siteurl'], 'prefix'=>$s['prefix'], 'wp_version'=>$s['wp_version'], 'php'=>PHP_VERSION, 'files'=>$s['files'], 'bytes'=>$s['bytes'], 'rows'=>$s['rows'], 'tables'=>$s['tables'], 'database_sha256'=>hash_file('sha256',$job.'/database.jsonl'), 'index_sha256'=>hash_file('sha256',$job.'/files.jsonl'), 'exclude'=>$s['exclude']];
            $manifest['full_config']=true; $manifest['connection_fingerprint']=self::fingerprint($cfg);
            $manifest['consistency']=!empty($s['site_paused'])?'maintenance-verified':'verified';
            $manifest['directories']=[];
            if(is_file($job.'/directories.jsonl')) {
                $h=fopen($job.'/directories.jsonl','rb');
                while(($line=fgets($h))!==false) $manifest['directories'][]=json_decode($line,true,512,JSON_THROW_ON_ERROR);
                fclose($h);
            }
            if(strlen(json_encode($manifest,JSON_THROW_ON_ERROR))>8388608) self::fail('A árvore de diretórios excede o limite desta versão.');
            self::save($job . '/manifest.json', $manifest);
            $zip = self::zip($job . '/backup.zip');
            foreach (['database.jsonl','files.jsonl','manifest.json'] as $name) {
                if (!$zip->addFile($job . '/' . $name, $name)) self::fail('Falha ao finalizar o pacote.');
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
            if (!$zip->close()) self::fail('Não foi possível finalizar o ZIP.');
            $s['phase']='done'; $s['archive_bytes']=filesize($job.'/backup.zip');
            $s['message']='Backup concluído. Baixe e guarde o ZIP em local seguro.';
            self::releaseMaintenance($s); $s['site_paused']=false;
        }
    }
    public static function inspect($job, &$s) {
        $zip = self::zip($job.'/backup.zip');
        try {
            $stat = $zip->statName('manifest.json');
            if (!$stat || $stat['size'] > 8388608) self::fail('Manifesto ausente ou inválido. Use um ZIP criado por este plugin.');
            $m = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($m['format'] ?? '') !== self::FORMAT) self::fail('Formato de backup não suportado.');
            foreach (['home','siteurl','prefix','files','bytes','tables','database_sha256','index_sha256'] as $key) if (!isset($m[$key])) self::fail('Manifesto incompleto.');
            if (!is_array($m['tables']) || count($m['tables']) > 10000 || !preg_match('/^[a-zA-Z0-9_]+$/D', $m['prefix'])) self::fail('Lista de tabelas inválida.');
            foreach ($m['tables'] as $t) if (!is_string($t) || !str_starts_with($t,$m['prefix']) || strlen($t)>64) self::fail('Nome de tabela inválido.');
            if (count(array_unique($m['tables'])) !== count($m['tables'])) self::fail('Tabelas duplicadas no manifesto.');
            if (rtrim($m['home'],'/') !== rtrim($s['home'],'/') || rtrim($m['siteurl'],'/') !== rtrim($s['siteurl'],'/') || $m['prefix'] !== $s['prefix']) self::fail('Este pacote pertence a outro domínio, caminho ou prefixo. A versão 1 restaura somente no mesmo endereço e prefixo.');
            if(!empty($m['full_config'])) {
                if(!isset($m['connection_fingerprint']) || !hash_equals($m['connection_fingerprint'],self::fingerprint(self::json($job.'/config.json')))) self::fail('A conexão com o banco mudou desde o backup. A restauração do wp-config.php original exige as mesmas credenciais. Ajuste o ambiente antes de continuar.');
                if($zip->locateName('site/wp-config.php')===false) self::fail('wp-config.php ausente no backup completo.');
            }
            foreach($m['directories']??[] as $dir) self::relative($dir['path']);
            if ($m['files'] < 1 || $m['bytes'] < 0 || $zip->numFiles !== (int)$m['files'] + 3) self::fail('Contagem de arquivos incompatível.');
            $free = disk_free_space($job);
            if ($free !== false && $free < ((int)$m['bytes'] * 2 + 134217728)) self::fail('Espaço insuficiente para extração e cópia de segurança dos arquivos atuais. Libere pelo menos duas vezes o tamanho descompactado.');
            $s['manifest']=$m; $s['zip_i']=0; $s['phase']='validate'; $s['message']='Validando e extraindo o pacote em área privada. O site ainda não será alterado.';
            self::mkdir($job.'/stage');
        } finally { $zip->close(); }
    }
    public static function validate($job, &$s) {
        $zip = self::zip($job.'/backup.zip'); $end=microtime(true)+3;
        try {
            while ($s['zip_i'] < $zip->numFiles && microtime(true)<$end) {
                $i=$s['zip_i']; $stat=$zip->statIndex($i); $name=$stat['name'];
                self::relative($name);
                if ($zip->locateName($name) !== $i) self::fail('Entradas duplicadas no ZIP.');
                $opsys=0; $attrs=0; $zip->getExternalAttributesIndex($i,$opsys,$attrs);
                if (($opsys === ZipArchive::OPSYS_UNIX) && (($attrs >> 16) & 0170000) === 0120000) self::fail('Links simbólicos não são permitidos no ZIP.');
                if (!in_array($name,['database.jsonl','manifest.json','files.jsonl'],true) && !str_starts_with($name,'site/')) self::fail('Entrada desconhecida no pacote.');
                if (str_starts_with($name,'site/')) self::relative(substr($name,5));
                if (($stat['encryption_method'] ?? 0) !== 0) self::fail('ZIP criptografado não suportado.');
                if ($stat['size'] > (int)$s['manifest']['bytes'] + 134217728 && str_starts_with($name,'site/')) self::fail('Tamanho inválido no pacote.');
                $dest=self::target($job.'/stage',$name); self::mkdir(dirname($dest));
                $in=$zip->getStream($name); $out=fopen($dest,'wb');
                if (!$in || !$out) self::fail('Não foi possível extrair: '.$name);
                $crc=hash_init('crc32b'); $size=0;
                try {
                    while (!feof($in)) {
                        $buf=fread($in,self::CHUNK);
                        if ($buf === false) self::fail('Erro ao ler o ZIP.');
                        $size+=strlen($buf);
                        if ($size>$stat['size']) self::fail('Entrada excedeu o tamanho informado.');
                        hash_update($crc,$buf); self::writeAll($out,$buf);
                    }
                } finally { fclose($in); fclose($out); }
                $expected=sprintf('%08x', $stat['crc'] & 0xffffffff);
                if ($size!==$stat['size'] || hash_final($crc)!==$expected) self::fail('ZIP corrompido: '.$name);
                chmod($dest,0600); $s['zip_i']++;
            }
            if ($s['zip_i']===$zip->numFiles) {
                foreach (['database'=>'database.jsonl','index'=>'files.jsonl'] as $key=>$file) {
                    if (!hash_equals($s['manifest'][$key.'_sha256'],hash_file('sha256',$job.'/stage/'.$file))) self::fail('Falha na integridade de '.$file);
                }
                $s['phase']='verify'; $s['index_offset']=0; $s['verified']=0; $s['verified_bytes']=0;
            }
        } finally { $zip->close(); }
    }
    public static function verify($job,&$s) {
        $h=fopen($job.'/stage/files.jsonl','rb'); fseek($h,$s['index_offset']); $end=microtime(true)+3;
        try {
            while (microtime(true)<$end && ($line=fgets($h))!==false) {
                $r=json_decode($line,true,512,JSON_THROW_ON_ERROR); self::relative($r['path']);
                $file=self::target($job.'/stage/site',$r['path']);
                if (!is_file($file) || filesize($file)!==$r['size'] || !hash_equals($r['sha256'],hash_file('sha256',$file))) self::fail('Integridade inválida: '.$r['path']);
                $s['verified']++; $s['verified_bytes']+=$r['size']; $s['index_offset']=ftell($h);
            }
            if (feof($h)) {
                if ($s['verified']!==$s['manifest']['files'] || $s['verified_bytes']!==$s['manifest']['bytes']) self::fail('Índice de arquivos incompleto.');
                foreach($s['manifest']['directories']??[] as $dir) self::mkdir(self::target($job.'/stage/site',$dir['path']));
                $s['phase']='ready'; $s['message']='Pacote verificado. Confira a data e confirme a restauração.';
            }
        } finally { fclose($h); }
    }
    public static function beginRestore($job,&$s,$cfg) {
        if ($s['phase']!=='ready') self::fail('Valide o pacote antes de restaurar.');
        if (file_exists($s['root'].'/.maintenance')) self::fail('O site já está em manutenção. Finalize a outra operação primeiro.');
        if (!is_writable($s['root'])) self::fail('A raiz do site precisa permitir escrita.');
        $db=self::db($cfg); $existing=self::tables($db,$s['prefix']); $map=[]; $old=[];
        $tag=substr(bin2hex(random_bytes(8)),0,12);
        foreach ($s['manifest']['tables'] as $i=>$t) $map[$t]='f2sb_n_'.$tag.'_'.$i;
        foreach ($existing as $i=>$t) $old[$t]='f2sb_o_'.$tag.'_'.$i;
        $s['map']=$map; $s['old']=$old; $s['db_offset']=0; $s['db_rows']=0; $s['db_table']=null; $s['db_seen']=[];
        $s['phase']='import'; $s['destructive']=true; $s['applied']=0; $s['index_offset']=0;
        $s['message']='Restaurando. Mantenha esta aba aberta. O site ficará em manutenção.';
        self::mkdir($job.'/undo');
        file_put_contents($job.'/journal.jsonl','');
        $db->close(); self::maintenance($s);
    }
    public static function maintenance($s) {
        // time() evita que a proteção expire após 10 minutos se a aba for fechada.
        $marker='F2SB:'.($s['lock_id']??'legacy');
        if (file_put_contents($s['root'].'/.maintenance', '<?php $upgrading = time(); /* '.$marker.' */')===false) self::fail('Não foi possível ativar a manutenção.');
    }
    public static function releaseMaintenance($s) {
        $file=$s['root'].'/.maintenance';
        if(is_file($file) && str_contains((string)file_get_contents($file),'F2SB:'.($s['lock_id']??'legacy'))) {
            if(!unlink($file)) self::fail('Não foi possível liberar o modo de manutenção.');
        }
    }
    public static function import($job,&$s,$cfg) {
        $db=self::db($cfg); $db->query('SET FOREIGN_KEY_CHECKS=0');
        $h=fopen($job.'/stage/database.jsonl','rb'); fseek($h,$s['db_offset']); $end=microtime(true)+3;
        try {
            while (microtime(true)<$end && ($line=fgets($h))!==false) {
                $r=json_decode($line,true,512,JSON_THROW_ON_ERROR); $table=$r['table'] ?? '';
                if (!isset($s['map'][$table])) self::fail('Tabela não autorizada no pacote.');
                $temp=$s['map'][$table];
                if ($r['kind']==='schema') {
                    if ($s['db_table']!==null || in_array($table,$s['db_seen'],true)) self::fail('Sequência de tabelas inválida.');
                    $head='CREATE TABLE '.self::ident($table);
                    if (!str_starts_with($r['sql'],$head.' ' ) && !str_starts_with($r['sql'],$head."\n")) self::fail('Estrutura de tabela inválida.');
                    // Prefixa também referências de chaves estrangeiras e nomes de constraints.
                    $replace=[];
                    foreach ($s['map'] as $a=>$b) $replace[self::ident($a)]=self::ident($b);
                    $sql=strtr($r['sql'],$replace);
                    $sql=preg_replace_callback('/CONSTRAINT\s+`([^`]+)`/i',static fn($m)=>'CONSTRAINT '.self::ident('f2_'.substr(hash('sha256',$temp.$m[1]),0,32)),$sql);
                    $db->query($sql); $s['db_columns']=$r['columns']; $s['db_table']=$table;
                } elseif ($r['kind']==='row') {
                    if ($s['db_table']!==$table || count($r['values'])!==count($s['db_columns'])) self::fail('Linha do banco inválida.');
                    $vals=[];
                    foreach ($r['values'] as $v) {
                        if ($v === null) $vals[]='NULL';
                        else {
                            $decoded=base64_decode($v,true);
                            if ($decoded===false) self::fail('Dados binários inválidos.');
                            $vals[]="X'".bin2hex($decoded)."'";
                        }
                    }
                    $db->query('INSERT INTO '.self::ident($temp).' ('.implode(',',array_map([self::class,'ident'],$s['db_columns'])).') VALUES ('.implode(',',$vals).')');
                    $s['db_rows']++;
                } elseif ($r['kind']==='end') {
                    if ($s['db_table']!==$table) self::fail('Final de tabela inválido.');
                    $s['db_seen'][]=$table; $s['db_table']=null;
                } else self::fail('Registro do banco desconhecido.');
                $s['db_offset']=ftell($h);
            }
            if (feof($h)) {
                if ($s['db_table']!==null || count($s['db_seen'])!==count($s['map'])) self::fail('Banco incompleto no pacote.');
                // Impede pacote com URLs adulteradas ou incompatíveis.
                $options=$s['map'][$s['prefix'].'options'] ?? null;
                if (!$options) self::fail('Tabela de opções ausente.');
                $r=$db->query('SELECT option_name, option_value FROM '.self::ident($options)." WHERE option_name IN ('home','siteurl')");
                $urls=[]; while($row=$r->fetch_assoc()) $urls[$row['option_name']]=rtrim($row['option_value'],'/');
                if (($urls['home']??'')!==rtrim($s['home'],'/') || ($urls['siteurl']??'')!==rtrim($s['siteurl'],'/')) self::fail('URLs do banco não correspondem ao site.');
                $s['phase']='directories'; $s['dir_i']=0;
            }
        } finally { fclose($h); $db->close(); }
    }
    public static function journal($job,$rel,$existed,$mode=0644) {
        $h=fopen($job.'/journal.jsonl','ab');
        try { self::line($h,['path'=>$rel,'existed'=>$existed,'mode'=>$mode]); fflush($h); }
        finally { fclose($h); }
    }
    public static function journalDir($job,$rel,$existed,$mode) {
        $h=fopen($job.'/journal.jsonl','ab');
        try { self::line($h,['path'=>$rel,'existed'=>$existed,'mode'=>$mode,'kind'=>'directory']); fflush($h); }
        finally { fclose($h); }
    }
    public static function removeConflict($job,$root,$rel) {
        $path=self::target($root,$rel);
        foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $entry) {
            $child=$rel.'/'.$entry->getFilename(); $full=self::target($root,$child);
            if(is_dir($full)) self::removeConflict($job,$root,$child);
            elseif(is_file($full)) { self::preserve($job,$root,$child); if(!unlink($full)) self::fail('Falha ao resolver conflito de arquivos.'); }
            else self::fail('Arquivo especial em diretório conflitante.');
        }
        self::journalDir($job,$rel,true,fileperms($path)&0777);
        if(!rmdir($path)) self::fail('Falha ao substituir diretório conflitante: '.$rel);
    }
    public static function applyDirectories($job,&$s) {
        $list=$s['manifest']['directories']??[]; $end=microtime(true)+3;
        while($s['dir_i']<count($list) && microtime(true)<$end) {
            $r=$list[$s['dir_i']]; $to=self::target($s['root'],$r['path']);
            if(is_file($to)) { self::preserve($job,$s['root'],$r['path']); if(!unlink($to)) self::fail('Falha ao substituir arquivo por pasta.'); }
            $existed=is_dir($to); self::journalDir($job,$r['path'],$existed,$existed?(fileperms($to)&0777):0755);
            if(!$existed && !mkdir($to,0755,true)) self::fail('Falha ao criar diretório do backup.');
            if(!chmod($to,0755)) self::fail('Falha ao preparar permissões de diretório.');
            $s['dir_i']++;
        }
        if($s['dir_i']===count($list)) $s['phase']='apply';
    }
    public static function finishDirectories($job,&$s) {
        $list=$s['manifest']['directories']??[]; $end=microtime(true)+3;
        if(!isset($s['dir_finish_i'])) $s['dir_finish_i']=count($list)-1;
        while($s['dir_finish_i']>=0 && microtime(true)<$end) {
            $r=$list[$s['dir_finish_i']];
            if(!chmod(self::target($s['root'],$r['path']),$r['mode']&0777)) self::fail('Falha ao recuperar permissões da pasta.');
            $s['dir_finish_i']--;
        }
        if($s['dir_finish_i']<0) $s['phase']='switch';
    }
    public static function preserve($job,$root,$rel) {
        $to=self::target($root,$rel); $existed=is_file($to); $mode=$existed ? (fileperms($to)&0777) : 0644;
        if (is_dir($to)) self::removeConflict($job,$root,$rel);
        elseif (file_exists($to) && !$existed) self::fail('Arquivo especial no destino: '.$rel);
        if ($existed) {
            $undo=self::target($job.'/undo',$rel); self::mkdir(dirname($undo));
            if (!copy($to,$undo)) self::fail('Falha ao preservar o arquivo atual: '.$rel);
            chmod($undo,0600);
        }
        self::journal($job,$rel,$existed,$mode);
        return $to;
    }
    public static function atomicCopy($from,$to,$mode=0644) {
        if(!is_dir(dirname($to)) && !mkdir(dirname($to),0755,true)) self::fail('Falha ao criar a pasta de destino.');
        $tmp=dirname($to).'/.f2sb-write-'.bin2hex(random_bytes(8));
        if (!copy($from,$tmp)) { @unlink($tmp); self::fail('Falha ao copiar arquivo para o site.'); }
        // Nunca importa setuid/setgid ou permissões de escrita para todos.
        chmod($tmp, $mode & 0777);
        if (!rename($tmp,$to)) { @unlink($tmp); self::fail('Falha ao substituir arquivo no site.'); }
        if(function_exists('opcache_invalidate')) @opcache_invalidate($to,true);
    }
    public static function protectedPath($rel,$s) {
        return ($rel==='wp-config.php' && empty($s['manifest']['full_config'])) || self::excluded($rel,$s);
    }
    public static function apply($job,&$s) {
        $h=fopen($job.'/stage/files.jsonl','rb'); fseek($h,$s['index_offset']); $end=microtime(true)+3;
        try {
            while (microtime(true)<$end && ($line=fgets($h))!==false) {
                $r=json_decode($line,true,512,JSON_THROW_ON_ERROR); $rel=$r['path'];
                if (!self::protectedPath($rel,$s)) {
                    $to=self::preserve($job,$s['root'],$rel);
                    self::atomicCopy(self::target($job.'/stage/site',$rel),$to,(int)$r['mode']);
                }
                $s['applied']++; $s['index_offset']=ftell($h);
            }
            if (feof($h)) {
                if(!empty($s['prune'])) {
                    self::scanStart($job); $s['queue_offset']=0; $s['scan_dir']=null; $s['scan_cursor']=0; $s['pruned']=0;
                    $s['phase']='prune';
                } else $s['phase']='dir_finish';
            }
        } finally { fclose($h); }
    }
    public static function prune($job,&$s) {
        // A lista é preparada antes das exclusões para não mudar os índices de readdir().
        $h=fopen($job.'/prune.jsonl','ab'); $dirs=fopen($job.'/prune-dirs.jsonl','ab');
        try {
            $done=self::scan($job,$s,function($rel,$full,$directory=false) use ($job,$h,$dirs,&$s) {
                if(self::protectedPath($rel,$s)) return;
                if($directory) { if(!is_dir(self::target($job.'/stage/site',$rel))) self::line($dirs,$rel); }
                elseif(!is_file(self::target($job.'/stage/site',$rel))) self::line($h,$rel);
            });
        } finally { fclose($h); fclose($dirs); }
        if($done) { $s['phase']='prune_apply'; $s['prune_offset']=0; }
    }
    public static function pruneApply($job,&$s) {
        $h=fopen($job.'/prune.jsonl','rb'); fseek($h,$s['prune_offset']); $end=microtime(true)+3;
        try {
            while(microtime(true)<$end && ($line=fgets($h))!==false) {
                $rel=json_decode($line,true,512,JSON_THROW_ON_ERROR);
                $to=self::preserve($job,$s['root'],$rel);
                if(!unlink($to)) self::fail('Falha ao remover arquivo extra: '.$rel);
                $s['pruned']++; $s['prune_offset']=ftell($h);
            }
            if(feof($h)) { $s['phase']='prune_dirs'; $s['prune_dir_i']=count(file($job.'/prune-dirs.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[])-1; }
        } finally { fclose($h); }
    }
    public static function pruneDirectories($job,&$s) {
        $list=file($job.'/prune-dirs.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]; $end=microtime(true)+3;
        while($s['prune_dir_i']>=0 && microtime(true)<$end) {
            $rel=json_decode($list[$s['prune_dir_i']],true,512,JSON_THROW_ON_ERROR); $to=self::target($s['root'],$rel);
            if(is_dir($to)) {
                if($rel==='wp-content/mu-plugins' && glob($to.'/f2sb-cache-flush-*.php')) { $s['prune_dir_i']--; continue; }
                self::journalDir($job,$rel,true,fileperms($to)&0777);
                if(!rmdir($to)) self::fail('Uma pasta extra recebeu novos arquivos durante a restauração. Pause integrações externas: '.$rel);
            }
            $s['prune_dir_i']--;
        }
        if($s['prune_dir_i']<0) $s['phase']='dir_finish';
    }
    public static function switchDB($job,&$s,$cfg) {
        $db=self::db($cfg); $db->query('SET FOREIGN_KEY_CHECKS=0'); $pairs=[];
        try {
            foreach ($s['old'] as $a=>$b) $pairs[]=self::ident($a).' TO '.self::ident($b);
            foreach ($s['map'] as $a=>$b) $pairs[]=self::ident($b).' TO '.self::ident($a);
            // Checkpoint anterior ao RENAME permite reconhecer uma troca já concluída.
            $s['phase']='switch_pending'; self::save($job.'/state.json',$s);
            $db->query('RENAME TABLE '.implode(', ',$pairs));
            $s['phase']='cleanup'; self::save($job.'/state.json',$s);
        } finally { $db->close(); }
    }
    public static function switchRecovery($job,&$s,$cfg) {
        $db=self::db($cfg);
        try {
            $present=[]; $res=$db->query('SHOW TABLES'); while($r=$res->fetch_row()) $present[$r[0]]=true;
            $staged=0; foreach($s['map'] as $temp) if(isset($present[$temp])) $staged++;
            if ($staged===0) {
                foreach($s['map'] as $original=>$temp) if(!isset($present[$original])) self::fail('Tabela restaurada ausente. Consulte a hospedagem.');
                foreach($s['old'] as $old) if(!isset($present[$old])) self::fail('Não foi possível confirmar a troca completa do banco. Consulte a hospedagem.');
                $s['phase']='cleanup'; return;
            }
            if ($staged===count($s['map'])) {
                foreach($s['old'] as $original=>$old) {
                    if(!isset($present[$original]) || isset($present[$old])) self::fail('Estado ambíguo na troca do banco. Consulte a hospedagem.');
                }
                // Com todas as tabelas de origem intactas, a reversão de arquivos é segura.
                $s['phase']='switch';
                self::fail('A troca do banco não foi concluída. Use Reverter para recuperar os arquivos anteriores.');
            }
            self::fail('Troca do banco incompleta. Não prossiga; consulte o suporte da hospedagem.');
        } finally { $db->close(); }
    }
    public static function cleanup($job,&$s,$cfg) {
        $db=self::db($cfg); $db->query('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($s['old'] as $old) $db->query('DROP TABLE IF EXISTS '.self::ident($old));
        } finally { $db->close(); }
        // Executa uma vez no primeiro bootstrap do WordPress, antes dos plugins normais.
        if(!empty($s['manifest']['full_config']) && is_file($s['root'].'/wp-content/object-cache.php')) {
            $dir=$s['root'].'/wp-content/mu-plugins';
            if(!is_dir($dir) && !mkdir($dir,0755,true)) self::fail('Falha ao preparar limpeza do cache de objetos.');
            $file=$dir.'/f2sb-cache-flush-'.substr(hash('sha256',$job),0,16).'.php';
            $code="<?php\nif (!defined('ABSPATH')) exit;\nwp_cache_flush();\n@unlink(__FILE__);\n";
            if(file_put_contents($file,$code)===false) self::fail('Falha ao agendar a limpeza do cache de objetos.');
        }
        self::releaseMaintenance($s);
        $s['phase']='done'; $s['message']='Restauração concluída. Entre novamente e limpe o cache da hospedagem/CDN e de objetos.';
        $s['finished']=time();
    }
    public static function rollback($job,&$s,$cfg) {
        if (in_array($s['phase'],['cleanup','done','switch_pending'],true) || in_array($s['failed_phase']??'',['cleanup','switch_pending'],true)) self::fail('O banco pode já ter sido trocado. Não é seguro reverter os arquivos isoladamente.');
        if (!isset($s['rollback_list'])) {
            $lines=file($job.'/journal.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
            $s['rollback_list']=array_reverse($lines); $s['rollback_i']=0;
        }
        $end=microtime(true)+3;
        while ($s['rollback_i']<count($s['rollback_list']) && microtime(true)<$end) {
            $r=json_decode($s['rollback_list'][$s['rollback_i']],true,512,JSON_THROW_ON_ERROR); $to=self::target($s['root'],$r['path']);
            if(($r['kind']??'')==='directory') {
                if($r['existed']) {
                    if(!is_dir($to) && !mkdir($to,0755,true)) self::fail('Falha ao recuperar pasta anterior.');
                    chmod($to,$r['mode']&0777);
                } elseif(is_dir($to) && !rmdir($to)) self::fail('Falha ao remover pasta criada na restauração.');
            } elseif($r['existed']) self::atomicCopy(self::target($job.'/undo',$r['path']),$to,$r['mode']);
            elseif(is_file($to) && !unlink($to)) self::fail('Falha ao remover arquivo da restauração.');
            $s['rollback_i']++;
        }
        if ($s['rollback_i']===count($s['rollback_list'])) {
            $db=self::db($cfg); $db->query('SET FOREIGN_KEY_CHECKS=0');
            try { foreach($s['map'] as $t) $db->query('DROP TABLE IF EXISTS '.self::ident($t)); }
            finally { $db->close(); }
            self::releaseMaintenance($s); $s['phase']='rolled_back'; $s['message']='Arquivos anteriores recuperados; o banco original foi mantido.';
        }
    }
    public static function step($job,&$s,$cfg) {
        if (($s['destructive']??false) && !in_array($s['phase'],['done','rolled_back'],true)) self::maintenance($s);
        if ($s['type']==='backup') self::backup($job,$s,$cfg);
        else {
            switch ($s['phase']) {
                case 'inspect': self::inspect($job,$s); break;
                case 'validate': self::validate($job,$s); break;
                case 'verify': self::verify($job,$s); break;
                case 'import': self::import($job,$s,$cfg); break;
                case 'directories': self::applyDirectories($job,$s); break;
                case 'apply': self::apply($job,$s); break;
                case 'prune': self::prune($job,$s); break;
                case 'prune_apply': self::pruneApply($job,$s); break;
                case 'prune_dirs': self::pruneDirectories($job,$s); break;
                case 'dir_finish': self::finishDirectories($job,$s); break;
                case 'switch': self::switchDB($job,$s,$cfg); break;
                case 'switch_pending': self::switchRecovery($job,$s,$cfg); break;
                case 'cleanup': self::cleanup($job,$s,$cfg); break;
                case 'rollback': self::rollback($job,$s,$cfg); break;
                default: self::fail('Operação não está pronta para continuar.');
            }
        }
    }
    public static function execute($job,$op='step') {
        $lock=fopen($job.'/lock','c');
        if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) self::fail('Há uma requisição em andamento. Aguarde alguns segundos.');
        try {
            $s=self::json($job.'/state.json'); $cfg=self::json($job.'/config.json');
            if($op==='status') return $s;
            if($op==='cancel_backup') {
                if($s['type']!=='backup') self::fail('Esta ação cancela somente backups.');
                if($s['phase']==='done') self::fail('O backup já terminou.');
                self::releaseMaintenance($s); $s['site_paused']=false; $s['phase']='cancelled'; $s['running']=false;
                $s['message']='Backup cancelado. O site foi liberado.'; self::save($job.'/state.json',$s); return $s;
            }
            if(!empty($s['running']) && !in_array($s['phase'],['switch_pending','cleanup'],true)) {
                $s['failed_phase']=$s['phase']; $s['phase']='failed';
                $s['error']='A requisição anterior foi interrompida. Não será repetida automaticamente. Reverta a restauração ou exclua e refaça o backup.';
            }
            if($op==='rollback') {
                if(empty($s['destructive'])) self::fail('Nenhuma restauração foi iniciada.');
                if(in_array($s['phase'],['done','cleanup','switch_pending','rolled_back'],true) || in_array($s['failed_phase']??'',['switch_pending','cleanup'],true)) self::fail('A troca do banco pode já ter ocorrido; não é seguro reverter apenas os arquivos.');
                $s['phase']='rollback'; unset($s['error']);
            } elseif($s['phase']==='failed') {
                if(in_array($s['failed_phase']??'',['switch_pending','cleanup'],true)) { $s['phase']=$s['failed_phase']; unset($s['error']); }
                else { if($s['type']==='backup') {self::releaseMaintenance($s);$s['site_paused']=false;} self::save($job.'/state.json',$s); self::fail($s['error']); }
            }
            $s['running']=true; $s['updated']=time(); self::save($job.'/state.json',$s);
            @set_time_limit(60);
            try {
                if($op==='begin' || ($s['type']==='restore' && $s['phase']==='ready' && isset($s['token_hash']))) self::beginRestore($job,$s,$cfg);
                else self::step($job,$s,$cfg);
                $s['running']=false; $s['updated']=time(); self::save($job.'/state.json',$s);
            } catch(Throwable $e) {
                $s['running']=false; $s['failed_phase']=$s['phase']; $s['phase']='failed'; $s['error']=$e->getMessage();
                if($s['type']==='backup') {self::releaseMaintenance($s);$s['site_paused']=false;}
                $s['updated']=time(); self::save($job.'/state.json',$s); throw $e;
            }
            return $s;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public static function publicState($s) {
        $keys=['type','phase','files','bytes','rows','table_i','tables','created','message','error','archive_bytes','zip_i','verified','applied','db_rows','rollback_i','destructive','failed_phase','directories','pause_until','site_paused','return_url','download_url'];
        $out=array_intersect_key($s,array_flip($keys));
        if(isset($s['manifest'])) $out['manifest']=array_intersect_key($s['manifest'],array_flip(['created','home','wp_version','php','files','bytes','rows','full_config','consistency']));
        return $out;
    }
    public static function removeTree($dir) {
        if (is_link($dir)) { unlink($dir); return; }
        if (!is_dir($dir)) { if(is_file($dir)) unlink($dir); return; }
        foreach(new FilesystemIterator($dir,FilesystemIterator::SKIP_DOTS) as $item) self::removeTree($item->getPathname());
        if(!rmdir($dir)) self::fail('Não foi possível excluir os arquivos temporários.');
    }
}
