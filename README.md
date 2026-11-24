# F2 Bakup

Backup e restauração do WordPress em um único ZIP. Desenvolvido pela **F2Digital**.

**Versão atual: 1.2.1** · PHP 8.1+ · WordPress 6.4+ · GPL-2.0-or-later

## Instalação

Baixe o asset **f2-backup-local.zip** na [última Release](https://github.com/fabiosalum/f2backup/releases/latest). A cópia da versão inicial também está em [dist/f2-backup-local.zip](dist/f2-backup-local.zip).

No WordPress, acesse **Plugins → Adicionar plugin → Enviar plugin**, envie o ZIP sem descompactar e ative. Para atualizar uma instalação existente, escolha **Substituir o atual pelo enviado**.

Abra **Ferramentas → F2 Bakup**.

## Recursos

- Painel responsivo com histórico de pontos de retorno.
- Backup dos arquivos na raiz do WordPress, mídia, temas, plugins, configuração e tabelas do prefixo da instalação.
- Manutenção durante a cópia, comparação das exportações do banco e verificação SHA-256 dos arquivos.
- Download e envio do pacote pelo painel; envio dividido em partes de 1 MiB.
- Recuperação do `wp-config.php` original, diretórios vazios e permissões.
- Remoção de arquivos, diretórios e tabelas criados depois do ponto salvo.
- Executor independente do WordPress e reversão dos arquivos antes da troca final das tabelas.
- Atualização manual por Releases deste repositório, com validação do ZIP e do SHA-256.
- Compatibilidade com porta e socket efetivos do PHP/WordPress, inclusive ambientes LocalWP.

## Configurar as atualizações

Na seção **Atualizações do plugin**, salve:

```text
fabiosalum/f2backup
```

Use **Verificar atualizações** e, quando houver uma versão superior, **Instalar nova versão**. Não há instalação automática nos sites.

## Criar um ponto de retorno

Encerre requisições em andamento e pause integrações/cron externos que escrevam diretamente no banco ou nos arquivos. Confirme a pausa no painel e clique em **Criar ponto de retorno**. Mantenha a página da operação aberta, guarde o link privado e baixe o ZIP ao concluir.

O site ficará em manutenção durante a operação. Os dados devem permanecer sem gravações; as verificações não oferecem um snapshot transacional global de processos externos. Não use esse fluxo com o site continuando a receber pedidos.

## Restaurar

Envie o ZIP ou use **Restaurar** no histórico. Confira o ponto e digite **RESTAURAR**. Dados posteriores à cópia serão removidos.

A restauração exige o mesmo endereço, prefixo e credenciais de banco, e um WordPress funcional com este plugin para iniciar. Não é um instalador para hospedagem vazia nem uma migração entre domínios. Dados e configurações do servidor fora da instalação não são incluídos.

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

[GPL-2.0-or-later](LICENSE). Autor: F2Digital.
