# Projeto_Hi-Teach_Underdog
Projeto integrador da PUCPR envolvendo Desenvolvimento Web, Banco de Dados e Engenharia de Requisitos.

## Fórum e anexos

- Execute `Underdog Project/Interface_Principal/config/forum.sql` em instalações novas e existentes (o script cria tabelas e aplica ajustes de compatibilidade sem apagar dados).
- Garanta permissão de escrita para a pasta `Underdog Project/Interface_Principal/uploads/forum` para o usuário do servidor web.
- Requisitos PHP para anexos:
  - extensão `fileinfo` habilitada (validação de MIME real);
  - `upload_max_filesize` **>= 10M**;
  - `post_max_size` maior que o total do envio (ex.: **>= 50M** para até 5 arquivos de 10MB + campos do formulário).
- Limites do fórum: máximo de 5 anexos por envio e 10MB por arquivo.
- A entrega de anexos segue a visibilidade atual do fórum (`pages/forum.php`): não exige login, mas valida id, MIME real e nome seguro do arquivo.
