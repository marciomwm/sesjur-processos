# Segurança

## Aplicação

| Controle | Regra |
|---|---|
| SQL | PDO com prepared statements em 100% das consultas; nada concatenado; ordenação por allowlist de colunas |
| Saída | `e()` (`htmlspecialchars`) em todas as views |
| CSRF | Token de sessão em todo POST (formulários e endpoints JSON) |
| Validação | No servidor é a autoridade; no cliente, conveniência |
| Exclusão | Confirmação explícita com aviso do que será e do que **não** será removido |
| Integridade | FKs, UNIQUE e CHECK no banco, além das regras de serviço |
| Redirecionamento | `remover-vinculo.php` valida `retorno` por expressão regular (sem *open redirect*) |
| Autenticação | Login por e-mail de acesso; senha em registro separado (hash); troca obrigatória de senha provisória; política em `SenhaValidator` |
| Autorização | Por Perfil e por autoria, imposta no serviço (ver [perfis-e-permissoes.md](../00-visao-geral/perfis-e-permissoes.md)) |
| Arquivos sensíveis | `.htaccess` nega HTTP a `app/`, `database/`, `tests/`; `config.php` não é versionado |
| Erros | Mensagem genérica + código ao usuário; detalhe no log |
| API de Integração | Somente GET; token por cliente só no cabeçalho (nunca na URL), guardado como hash SHA-256; escopos; IPs e validade opcionais; limite por minuto e bloqueio de IP após tokens inválidos; toda chamada em `api_requisicoes`; `ca=` do PJe removido das URLs exportadas (ver [api-integracao.md](../08-transversais/api-integracao.md)) |

## Integração

- **RN-SEG-001** O PHP nunca acessa SIGADOC/PJe → elimina a superfície de SSRF (ADR 0001).
- **RN-SEG-002** Allowlist de domínios aplicada em **três pontos**: PHP (ao autorizar), service worker (antes de navegar) e Chrome (permissão de host concedida pelo usuário).
- **RN-SEG-003** Esquemas `javascript:`, `data:`, `file:`, `blob:` e afins são bloqueados; HTTPS exigido por configuração.
- **RN-SEG-004** A extensão declara só `http://localhost/*` e `http://127.0.0.1/*` como permissão estática; domínios institucionais são permissões **opcionais**, uma a uma.
- **RN-SEG-005** Nenhuma credencial, cookie ou token é lido, armazenado ou transmitido; sem tentativa de contornar CAPTCHA ou controle de acesso.
- **RN-SEG-006** Nenhum conteúdo de página sai do navegador; sem IA nem serviço externo.
- **RN-SEG-007** Ponte `postMessage`: aceita só `event.source === window` **e** `event.origin === location.origin`, para lista fechada de tipos (`GJ_PING`, `GJ_SYNC_CONFIG`, `GJ_EXTRACT_SIGADOC`, `GJ_EXTRACT_PJE`, `GJ_OBTER_PENDENTE`). O service worker só atende `sender.id === chrome.runtime.id`.
- **RN-SEG-008** Dados entre página de origem e cadastro (Criação pela extensão) ficam em `chrome.storage.session`, vinculados à aba, entregues uma única vez, validade de 5 minutos.
- **RN-SEG-009** Tokens de sessão em `href`/`onclick` do PJe (`ca=`) nunca são lidos; documentos são identificados pelo texto visível.

## Dados pessoais

- O pacote de transferência entre computadores contém dados pessoais e de processos: transportar por meio seguro e apagar depois de instalar.
- Fixtures de teste são **anonimizadas** (nomes, documentos, matrículas, e-mails e tokens removidos; estrutura preservada).
- Backups em `database/backup_*.sql` contêm dados reais e **não devem ser versionados/publicados**. (Ver pendências em [../10-operacao/instalacao-e-transferencia.md](../10-operacao/instalacao-e-transferencia.md).)
