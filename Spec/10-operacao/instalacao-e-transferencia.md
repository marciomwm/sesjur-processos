# Instalação e transferência entre computadores

## Requisitos

- Windows com **Laragon** (referência atual) — alternativa: XAMPP.
- Apache com `mod_rewrite` e `mod_headers` (opcionais) e **`AllowOverride All`** (para os `.htaccess`).
- PHP ≥ 8.1 com `pdo_mysql` e `mbstring`.
- MySQL ≥ 8.0.16 ou MariaDB ≥ 10.2 (CHECK constraints).
- Chrome (extensão MV3).

## Instalação nova

1. Copiar o projeto para `C:\laragon\www\gestao-juridica` (XAMPP: `htdocs/gestao-juridica`).
2. Iniciar Apache e MySQL.
3. Importar `database/database.sql` (**contém `DROP TABLE IF EXISTS`: reimportar apaga os dados** — fazer backup antes).
4. `cp app/config/config.example.php app/config/config.php` e ajustar `db` e `app` (`base_url=/gestao-juridica/public`, `api_url=/gestao-juridica/api`, `debug=false` em produção).
5. Criar o primeiro administrador: `database/definir-administrador.php`.
6. Acessar `http://localhost/gestao-juridica/public/`; o cartão **Banco de dados** do Dashboard deve mostrar `Conectado` + versão.
7. Instalar a extensão e autorizar domínios ([../05-extracao/extensao-chrome.md](../05-extracao/extensao-chrome.md)).

## Banco existente (atualização)

Para cada migração pendente, em ordem de data: **backup** → aplicar → verificar (ver cabeçalho de cada arquivo em `database/`). Todas são idempotentes. Rota de reversão está documentada no cabeçalho de cada migração.

## Transferência para outro computador

Pasta `transferencia/`:

| Arquivo | Função |
|---|---|
| `exportar.bat` | No computador de **origem** (Laragon rodando): gera na Área de Trabalho `SESJUR-transferencia-AAAAMMDD_HHMMSS` (pasta e `.zip`) com arquivos, banco com dados e instalador |
| `instalar.bat` | No computador **novo**, de dentro da pasta extraída |
| `LEIA-ME.txt` | Passo a passo completo |

Comportamento seguro do instalador:

- Banco `gestao_juridica` já existente: **backup** e **confirmação** antes de substituir.
- `C:\laragon\www\gestao-juridica` já existente: **renomeada** para `gestao-juridica_anterior_AAAAMMDD_HHMMSS` (nada é apagado).
- Laragon em outra pasta: `set LARAGON=D:\laragon`. Root com senha: `set MYSQL_PWD=…` e ajustar `config.php`.
- Mesmos usuários e senhas do computador de origem.
- Extensão precisa ser carregada de novo e os domínios reautorizados; **só funciona em `localhost`/`127.0.0.1`**.

## Segurança operacional

- O pacote de transferência contém **dados pessoais e de processos** e o `config.php`: transportar por meio seguro e **apagar o .zip e a pasta** depois de instalar.
- `database/backup_*.sql` contêm dados reais: manter fora de repositório público e de qualquer compartilhamento.
- `debug=false` em produção; erros detalhados vão ao log do PHP (ex.: `C:\laragon\tmp\php_errors.log`).

## Pendências

- Atualizar o `README.md` da raiz (ainda cita XAMPP e a pasta `gestao-juridica/`).
- Avaliar excluir `database/backup_*.sql`, `chrome-extension.zip` e `teste.txt` do controle de versão (via `.gitignore`) — **decisão da equipe**, não aplicada.

## Critérios de aceitação

- **CA-OPE-01** Instalação nova chega ao Dashboard com banco `Conectado`.
- **CA-OPE-02** Reexecutar uma migração não falha nem duplica estruturas.
- **CA-OPE-03** `instalar.bat` nunca apaga banco ou pasta existentes sem backup/renomeação.
