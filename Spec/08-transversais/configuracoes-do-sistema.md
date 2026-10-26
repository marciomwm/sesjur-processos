# Configurações do Sistema

Área **exclusiva de Administradores** (`configuracoes.php`, `app/Views/configuracoes/`), organizada em **guias**:

| Guia | Conteúdo |
|---|---|
| **Usuários** | Contas, Perfis, status, senhas provisórias |
| **Extensão** | Parâmetros da integração com a extensão do navegador |
| **Procedimentos** | Catálogo de Procedimentos (e, futuramente, o módulo) |
| **Prioridades** | Catálogo de Prioridades |

**Agentes Públicos** continuam em página própria. O diagnóstico de conexão (host, porta, banco em uso, versão do MariaDB) é exibido no Dashboard e em Configurações.

## Parâmetros da extensão

| Parâmetro | Regra |
|---|---|
| Domínios do SIGADOC / do PJe | Só o host (sem `https://` nem caminho); um por linha ou separados por vírgula; subdomínios aceitos; **vazio por padrão** |
| Exigir HTTPS | Chave de configuração (`configuracoes`) |
| Template de navegação direta do SIGADOC | `…/doc/exibir?sigla=<PROTOCOLO>`; editável |
| Sincronizar com a extensão | Envia a allowlist via `api/config-integracao.php` |

- **RN-CFG-001** Alterações ficam na **Auditoria**.
- **RN-CFG-002** A allowlist é aplicada no PHP, no service worker e no Chrome (3 pontos).
- **RN-CFG-003** Nenhum endereço institucional é presumido pelo sistema.
- **RN-CFG-004** Armazenamento em `configuracoes` (PK `chave`); `ConfiguracaoService`/`ConfiguracaoRepository`.

## Arquivo de configuração local

`app/config/config.php` (**não versionado**; modelo em `config.example.php`):

- `db`: `host`, `port`, `database`, `username`, `password`;
- `app`: `base_url` (ex.: `/gestao-juridica/public`), `api_url`, `debug` (false em produção);
- `usuarios`: `data_nascimento_obrigatoria`.

## Critérios de aceitação

- **CA-CFG-01** Usuário não-Administrador não acessa nenhuma guia.
- **CA-CFG-02** Salvar domínios e sincronizar faz a extensão passar a aceitá-los (após conceder a permissão no Chrome).
- **CA-CFG-03** Cada alteração de parâmetro da extensão aparece na Auditoria com autor e valores.
- **CA-CFG-04** Domínio informado com `https://` ou caminho é normalizado ou recusado com mensagem clara.

## Situação

✅ Implementado (`tests/php/configuracoes.php`).
