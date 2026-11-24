# F2 Bakup

Backup e restauração do WordPress em um único ZIP. Desenvolvido pela **F2Digital**.

**Versão atual: 1.3.0** · PHP 8.1+ · WordPress 6.4+ · GPL-2.0-or-later

## Instalação

Baixe o asset **f2-backup-local.zip** na [última Release](https://github.com/fabiosalum/f2backup/releases/latest). O ZIP atualizado também está em [dist/f2-backup-local.zip](dist/f2-backup-local.zip).

No WordPress, acesse **Plugins → Adicionar plugin → Enviar plugin**, envie o ZIP sem descompactar e ative. Para atualizar uma instalação existente, escolha **Substituir o atual pelo enviado**.

Abra **Ferramentas → F2 Bakup**.

## Recursos

- Painel responsivo com histórico de pontos de retorno.
- Backup dos arquivos na raiz do WordPress, mídia, temas, plugins, configuração e tabelas do prefixo da instalação.
- Manutenção durante a cópia, comparação das exportações do banco e verificação SHA-256 dos arquivos.
- Download e envio do pacote pelo painel; envio dividido em partes de 1 MiB.
- Restauração no ambiente original ou migração para outro WordPress, domínio e hospedagem.
- Migração de URLs e caminhos no banco, incluindo dados serializados e JSON.
- Adaptação das tabelas e permissões de usuários ao prefixo do destino.
- Recuperação do `wp-config.php` original no mesmo ambiente; conexão do destino preservada na migração.
- Remoção de arquivos, diretórios e tabelas criados depois do ponto salvo.
- Executor independente do WordPress e reversão dos arquivos antes da troca final das tabelas.
- Atualização manual por Releases deste repositório, com validação do ZIP e do SHA-256.
- Compatibilidade com porta e socket efetivos do PHP/WordPress, inclusive ambientes LocalWP.

## Atualizar o plugin

Clique em **Atualizar plugin**. O botão consulta a última Release estável de `fabiosalum/f2backup` e pede confirmação antes de instalar uma versão superior. O repositório fica fixado no código, sem campo de edição ou opção no painel. Não há instalação automática nos sites.

## Criar um ponto de retorno

Encerre requisições em andamento e pause integrações/cron externos que escrevam diretamente no banco ou nos arquivos. Confirme a pausa no painel e clique em **Criar ponto de retorno**. Mantenha a página da operação aberta, guarde o link privado e baixe o ZIP ao concluir.

O site ficará em manutenção durante a operação. Os dados devem permanecer sem gravações; as verificações não oferecem um snapshot transacional global de processos externos. Não use esse fluxo com o site continuando a receber pedidos.

## Restaurar

Envie o ZIP ou use **Restaurar** no histórico. Confira o ponto e digite **RESTAURAR**. Dados posteriores à cópia serão removidos.

Para importar em outra hospedagem, instale um WordPress novo e o F2 Bakup **1.3.0+**, mantenha **Importar em um novo WordPress** marcado e envie o backup anterior. Confira origem e destino, digite **RESTAURAR** e acompanhe a operação. Entre com o usuário e a senha do backup ao concluir.

A migração preserva o `wp-config.php`, o banco de conexão e o prefixo do destino. Importa as tabelas e os arquivos da origem, incluindo sua versão do núcleo WordPress; não mantém necessariamente a versão recém-instalada. Os GUIDs e dados binários não são substituídos. Configurações do servidor, drop-ins de banco/cache e o F2 Bakup atual do destino são preservados. As regras de links permanentes são regeneradas no primeiro carregamento.

Backups antigos continuam aceitos. Antes da versão 1.3.0 não havia metadado da pasta de origem: informe o caminho antigo no campo opcional se houver caminhos absolutos a adaptar. Serialização customizada que precise de substituições interrompe a migração antes da troca final, em vez de arriscar corromper dados.

É necessário um WordPress funcional para iniciar. Dados e configurações do servidor fora da instalação não são incluídos. Confira compatibilidade de PHP/MySQL e plugins de cache/segurança na hospedagem de destino.

Teste primeiro em uma cópia isolada. Guarde outra cópia fora do servidor: ZIPs contêm dados pessoais e credenciais, sem criptografia. Espaço insuficiente ou permissões podem também impedir a reversão. Multisite, views, triggers e links simbólicos não são suportados.

Leia o [manual completo](f2-backup-local/LEIA-ME.md), especialmente os requisitos e o tratamento de interrupções.

## Publicar uma nova versão

1. Altere o código em `f2-backup-local/`.
2. Aumente a versão no cabeçalho de `f2-backup-local.php` e em `F2SB_Engine::VERSION` no `engine.php`.
3. Atualize as notas em `RELEASE_NOTES.md`.
4. Gere o ZIP com `python3 scripts/build-release.py`.
5. Publique uma Release estável com tag `vVERSAO` e anexe `dist/f2-backup-local.zip`.

O nome do plugin é **F2 Bakup**. A pasta e o nome do asset continuam **f2-backup-local**, preservando a identificação das instalações existentes.

## Licença

[GPL-2.0-or-later](LICENSE). Autor: Fábio Melo Salum.
