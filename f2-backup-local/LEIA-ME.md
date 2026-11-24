# F2 Bakup — 1.3.0

Um ponto de retorno para seu WordPress, com painel próprio, arquivo ZIP, restauração completa e atualizações manuais pelo botão.

## Instalar ou atualizar

Em **Plugins → Adicionar plugin → Enviar plugin**, envie `f2-backup-local.zip`, sem descompactar. Se o plugin já estiver instalado, escolha **Substituir o atual pelo enviado**. Ative e abra **Ferramentas → F2 Bakup**.

Este ZIP instala o plugin. O ZIP do site é outro arquivo, gerado pelo botão **Criar ponto de retorno**.

## O que significa voltar ao ponto salvo

No mesmo ambiente, a restauração recupera:

- Arquivos do núcleo, temas, plugins e respectivas versões.
- Mídia, uploads e outros arquivos regulares dentro da raiz do WordPress.
- O `wp-config.php` original, `.htaccess` e arquivos ocultos.
- Diretórios vazios e permissões de arquivos/pastas.
- Estrutura e conteúdo das tabelas do prefixo deste WordPress, inclusive opções, usuários, posts, pedidos e dados dos plugins.
- Valores serializados, binários, acentos, NULL e emojis, sem substituir URLs ou reserializar o conteúdo.

Arquivos, diretórios e tabelas desse prefixo criados depois da cópia são removidos da instalação restaurada. Pedidos, formulários, usuários e edições posteriores deixam de existir. A restauração exige uma confirmação digitando **RESTAURAR**.

O conteúdo do WordPress retorna à cópia. Propriedade/UID/GID dos arquivos, horários originais de modificação, integrações externas, Redis como armazenamento de negócio, e-mail, DNS, SSL, configuração do Nginx/PHP e dados fora da raiz não fazem parte desse ponto. O plugin não é um snapshot físico da VPS.

### Consistência durante a criação

Para que banco e arquivos correspondam ao mesmo estado, o novo backup:

1. Abre uma página independente de operação e disponibiliza um link privado de recuperação.
2. Coloca o WordPress em manutenção, bloqueando novas requisições normais, inclusive cron HTTP.
3. Aguarda 15 segundos para dar tempo às requisições anteriores de terminar.
4. Copia o banco e os arquivos em etapas.
5. Exporta o banco novamente e compara o SHA-256 das exportações.
6. Confere os hashes dos arquivos dentro do ZIP e os arquivos de origem.
7. Só marca a cópia como concluída se as verificações passarem; então libera o site.

**Antes de clicar:** finalize as requisições em andamento e pause jobs, cron CLI, integrações e qualquer acesso externo que escreva diretamente no banco ou nos arquivos. Uma rotina externa não obedece à manutenção do WordPress. As verificações detectam alterações, mas não substituem a pausa desses escritores nem oferecem isolamento transacional global entre requisições. Não mantenha o site recebendo pedidos durante a operação.

Caso o banco ou um arquivo mude durante as verificações, o backup é rejeitado e o site é liberado. Se a conexão ou processo morrer, reabra o link privado e use **Cancelar backup e liberar site** ou consulte o estado antes de continuar. Não use cópias incompletas.

## Criar, baixar e restaurar

1. Confirme a pausa das gravações externas e clique em **Criar ponto de retorno**.
2. Mantenha a página de operação aberta. Salve o link privado mostrado; não compartilhe.
3. Ao concluir, baixe o ZIP e guarde uma cópia fora do servidor. A cópia contém dados pessoais e credenciais e não é criptografada.
4. Use **Finalizar e voltar ao painel**. O ZIP continuará disponível no histórico até você excluí-lo.
5. Para recuperar, clique em **Restaurar** no histórico ou envie o ZIP do computador na área de recuperação.
6. Confira a data e o endereço. A validação ocorre antes de mudar o site.
7. Digite **RESTAURAR** e confirme. O site ficará em manutenção.
8. O banco é importado em tabelas temporárias; os arquivos anteriores são preservados para reversão; a troca das tabelas só ocorre após preparar arquivos e remover os extras.
9. Ao concluir, finalize para remover os temporários de restauração e o executor. Entre novamente com os usuários/senhas da data da cópia.
10. Limpe o cache de CDN e da hospedagem e confira páginas, formulários, login e mídia antes de reabrir gravações. O plugin invalida OPcache dos arquivos substituídos e, havendo `object-cache.php`, agenda uma limpeza do cache de objetos no primeiro bootstrap do WordPress.

O link privado expira em 24 horas. O token está no fragmento do link e não é enviado como parâmetro de URL. A execução não depende da sessão antiga do WordPress.

## Importar em um novo WordPress ou outra hospedagem

1. Instale um WordPress novo no destino e ative o **F2 Bakup 1.3.0+**.
2. Deixe **Importar em um novo WordPress** marcado e selecione o ZIP do backup. Não é necessário gerar novamente uma cópia 1.2.2 já concluída.
3. Em cópias antigas, abra **Caminhos de backups antigos** e informe a pasta absoluta de origem se precisar adaptar caminhos salvos no banco. Cópias novas incluem esse metadado automaticamente.
4. Clique em **Enviar e verificar backup**, confira origem e destino e digite **RESTAURAR**. O conteúdo atual do destino será substituído.
5. Finalize e entre com o usuário e a senha que existiam no backup. Confira páginas, mídia, links permanentes, cache e segurança antes de publicar.

A migração preserva o `wp-config.php` e usa a conexão e o prefixo atuais do destino. As tabelas são importadas com esse prefixo e as chaves de permissões dos usuários são adaptadas. URLs e caminhos são substituídos nos campos de texto, recalculando comprimentos da serialização PHP sem instanciar objetos; JSON com barras escapadas também é tratado. GUIDs, chaves primárias e campos binários são preservados. Cache de opções transitórias e regras antigas são descartados; as regras são regeneradas pelo WordPress no primeiro carregamento.

`.htaccess`, `web.config`, `.user.ini`, `php.ini`, `wordfence-waf.php`, drop-ins `db.php`, `object-cache.php` e `advanced-cache.php` do destino são preservados, sem importar as versões da origem. O plugin F2 Bakup atual também é mantido para evitar uma regressão ao código antigo do backup. Configurações externas, licenças e plugins dependentes da hospedagem precisam ser conferidos no destino. A versão do núcleo e os demais plugins serão os contidos no backup; esse fluxo não atualiza o núcleo ao migrar.

Dados com serialização customizada que necessitem alterações e formatos serializados inválidos interrompem a operação antes da troca final do banco. Use a reversão de arquivos oferecida na página da operação. Multisite e mudanças para estruturas de diretórios WordPress não padrão não são suportados.

## Restaurar no ambiente original

Para recuperar o `wp-config.php` original, use o histórico ou desmarque a migração ao enviar o ZIP. Endereço, prefixo, conexão e pasta precisam corresponder à origem; diferenças são identificadas e apresentadas como migração antes da confirmação. Ao migrar, a configuração de conexão do destino sempre prevalece.

É preciso um WordPress funcional com o plugin instalado para iniciar. Não é instalador para uma hospedagem sem WordPress. Backups 1.0 preservam a configuração atual e não contêm metadados de diretórios vazios.

## Falhas e reversão

- Antes da troca final do banco, a página independente oferece **Reverter arquivos anteriores**. Recupera arquivos substituídos e removidos, conflitos entre arquivo/pasta e descarta o banco temporário.
- Depois da troca final do banco, a reversão isolada dos arquivos não é permitida. Se só a finalização falhar, consulte o estado e continue a finalização.
- Uma falha de disco ou permissão pode também impedir a reversão. Mantenha outra cópia independente da hospedagem, especialmente no primeiro teste.
- A trava `.maintenance` da operação não expira automaticamente enquanto uma restauração estiver pendente. Não a remova para publicar arquivos parcialmente restaurados.
- Se o link expirar ou a hospedagem limpar os temporários, a recuperação depende do FTP/painel e de uma cópia externa.

Teste primeiro em uma cópia isolada com o mesmo endereço configurado, por exemplo usando resolução local do domínio para essa cópia, sem alcançar a produção.

## Atualizar manualmente pelo GitHub

Não há consulta ou instalação automática em segundo plano. Não exige conta de licença nem assinatura. A conexão externa ocorre quando você usa o botão de atualização.

### Botão de atualização

O repositório `fabiosalum/f2backup` está fixado no código. O painel não permite alterar a origem e ignora opções antigas de repositório. Clique em **Atualizar plugin** para consultar a versão e confirme a instalação quando houver uma versão superior.

### Publicar uma versão

1. Edite o código e aumente a versão no cabeçalho de `f2-backup-local.php` e em `F2SB_Engine::VERSION` no `engine.php`, por exemplo `1.3.0`.
2. Empacote a pasta inteira mantendo a estrutura abaixo. O ZIP deve se chamar **f2-backup-local.zip**.
3. Crie uma Release estável com tag **v1.3.0** e anexe o ZIP como asset. O arquivo automático **Source code (zip)** do GitHub não é utilizado.
4. No WordPress, clique em **Atualizar plugin** e confirme a instalação.

```text
f2-backup-local.zip
  f2-backup-local/
    f2-backup-local.php
    engine.php
    updater.php
    views/dashboard.php
    assets/admin.css
    assets/admin.js
    assets/recovery.html
    LEIA-ME.md
    license.txt
```

O botão confere tag, versão no cabeçalho, estrutura do pacote e SHA-256 informado pela API do GitHub. A instalação usa o atualizador nativo do WordPress, mantendo o plugin ativo. A verificação expira após 15 minutos; consulte novamente se necessário. Mantenha o repositório sob seu controle: o pacote contém código PHP que será executado no site.

Esta versão suporta repositórios públicos e Releases estáveis com versões numéricas de três ou quatro partes. Não armazena token de GitHub e não oferece suporte a repositórios privados.

## Requisitos e espaço

- WordPress 6.4+, PHP 8.1+ de 64 bits, **ZipArchive** e **mysqli**.
- `wp-config.php` e `wp-content` na raiz padrão, sem links simbólicos.
- Leitura dos arquivos e escrita para substituí-los. Links simbólicos/arquivos especiais são rejeitados para não produzir uma cópia incompleta.
- MySQL/MariaDB e permissões para consultar estrutura, criar, inserir, renomear e excluir tabelas. Multisite, views e tabelas com triggers não são suportados.
- Uma pasta privada gravável fora da raiz pública: o plugin tenta o diretório pai e depois o temporário do sistema. Não salva credenciais em uploads públicos.
- Reserve de 3 a 4 vezes o tamanho do site em disco e espaço para duplicar as tabelas durante a restauração. ZIPs de outros plugins dentro da raiz também serão incluídos.
- O ZIP do backup usa conteúdo sem compressão para reduzir CPU/tempo de fechamento. O consumo em disco é próximo ao dos arquivos e banco exportado.
- Etapas com orçamento de aproximadamente 3 segundos e margem PHP de até 60 segundos quando permitida pela hospedagem. Um arquivo individual muito grande, linhas enormes do banco ou armazenamento lento ainda podem exceder limites rígidos.
- Mantenha os limites de memória necessários aos demais plugins. Este plugin não altera o PHP global.

Nenhum segredo da sua hospedagem está embutido neste plugin distribuído. Os arquivos de trabalho são privados e os downloads exigem administrador autenticado.

Licença: GPL-2.0-or-later. Autor: F2Digital.

## Correções da versão 1.2.1 — LocalWP e nome

- Nome público alterado para **F2 Bakup**. Pasta, identificador, formato de backup e nome do asset de atualização continuam `f2-backup-local`, para atualizar instalações existentes sem criar um segundo plugin.
- Removida a porta 3306 fixa. A conexão separada respeita host, porta e socket efetivos do PHP/WordPress, inclusive os padrões definidos pelo LocalWP. Esses parâmetros ficam salvos no controle privado para o executor independente.
- Usuário, senha e banco são obtidos da instância ativa do wpdb. Senhas vazias são preservadas; não existem tentativas com senhas ou usuários alternativos.
- A conexão independente é testada antes de criar a operação. Também é comparada com a identidade do servidor usado pelo WordPress, evitando copiar outro MySQL que tenha credenciais iguais.
- Tentativas antigas que pararam na pré-conexão sem iniciar uma cópia são identificadas como falhas e deixam de bloquear um novo backup.

No LocalWP, os dados de conexão aparecem na aba **Database** do site. Se ainda houver falha, informe somente o host, a porta (ou socket) e o código MySQL exibido; não envie a senha.
