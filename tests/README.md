# Testes de integração

Em um MySQL/MariaDB descartável em 127.0.0.1:13306, com usuário root e senha vazia, execute `php tests/migration.php`. Requer extensões mysqli e ZipArchive. O teste cria e recria somente os bancos de fixtures `f2_test`, `f2_origin` e `f2_destination`; use exclusivamente uma instância isolada de testes.

Cobre backup/restauração no mesmo ambiente, integridade, binários, conflitos de caminhos, reversão, interrupção na troca de tabelas e migração entre banco/usuário/senha/domínio/prefixo diferentes. Também cobre permissões do administrador, PHP serializado, referências, JSON, preservação de configuração e compatibilidade de backups antigos.
