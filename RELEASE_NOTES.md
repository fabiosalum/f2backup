## F2 Bakup 1.2.1

- Nome público alterado para F2 Bakup, preservando a identificação interna e os backups existentes.
- Corrigida a porta MySQL fixa: a conexão independente usa host, porta, socket, usuário, senha e banco efetivos do WordPress, incluindo os padrões do LocalWP.
- A conexão é testada antes de criar a operação e sua identidade é comparada com o servidor usado pelo WordPress.
- Tentativas antigas que falharam na pré-conexão deixam de bloquear um novo backup.
- Mantidos o painel responsivo, a restauração ampliada e a atualização manual por Releases.

### Instalação

Envie **f2-backup-local.zip** pelo instalador de plugins do WordPress. Se necessário, escolha **Substituir o atual pelo enviado**.

Em **Atualizações do plugin**, configure `fabiosalum/f2backup`.

### Antes de usar

Encerre gravações externas e requisições em andamento. O WordPress ficará em manutenção durante a cópia. Faça o primeiro teste de restauração em uma cópia isolada e guarde um backup fora do servidor.
