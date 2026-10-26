# API de Integração (v1) ✅

API **somente leitura** para que sistemas externos importem os dados do SESJUR. Cada sistema consumidor é um **Cliente da API**, com Token próprio, Escopos e um Usuário responsável (ver `CONTEXT.md`, seção "API de Integração").

A API é servida pelo **SESJUR em funcionamento na VPN da SES-MT** (`https://sesjur.saude.mt.gov.br/sesjur-processos/`), que recebe o código deste repositório por `git pull`. Os dados exportados são os do banco desse servidor.

Primeiro cliente previsto: **Sistema de Dieggo Ronney de Oliveira** (todos os escopos, validade de um ano). O cliente e o token devem ser criados **no servidor da VPN** (ver [Implantação](#implantação-no-servidor-da-vpn)): um token criado em outra instalação, como o ambiente local de desenvolvimento, não vale lá.

## Regras

- **RN-API-001** Só `GET`. Nenhum endpoint da v1 grava, altera ou exclui dados.
- **RN-API-002** Autenticação só por Token no cabeçalho `Authorization: Bearer <token>` (ou `X-API-Key: <token>`). Token na URL é recusado (`400 TOKEN_IN_URL`) e deve ser considerado exposto.
- **RN-API-003** O Token é exibido uma única vez (criação/renovação). O banco guarda só o SHA-256; renovar invalida o anterior na hora.
- **RN-API-004** O Cliente independe do login web: o responsável pode estar inativo no SESJUR. Revogar o Cliente não afeta o Usuário, e vice-versa.
- **RN-API-005** Escopos possíveis: `processos_administrativos`, `processos_judiciais`, `processos_extrajudiciais`, `vinculos`. **Não são exportados:** Providências (Tarefas, Solicitações, Anotações), Usuários e credenciais, Auditoria, Agentes Públicos, Intimações, Bloqueios/Guias, Pagamentos e Modelos de Expediente.
- **RN-API-006** O parâmetro `ca=` do PJe (chave de acesso de quem fez a extração) é removido de `url_origem` antes de sair.
- **RN-API-007** Limites: `api.limite_por_minuto` chamadas por cliente (padrão 120 → `429 RATE_LIMIT`); `api.max_falhas_por_ip` tokens inválidos por IP em 10 min (padrão 20 → `429 TOO_MANY_FAILURES`).
- **RN-API-008** Toda chamada (inclusive as recusadas) fica em `api_requisicoes`; criação, renovação e revogação de clientes ficam na Auditoria.
- **RN-API-009** Chamadas sem HTTPS são recusadas (`403 HTTPS_REQUIRED`). É o padrão: vale mesmo sem a seção `api` no `config.php`. Só o ambiente local de desenvolvimento usa `api.exigir_https = false`.
- **RN-API-010** Atrás de proxy reverso, `X-Forwarded-Proto` e `X-Forwarded-For` só são aceitos de IPs listados em `api.proxies_confiaveis`; de qualquer outra origem são ignorados (poderiam ser forjados para burlar o HTTPS, os IPs permitidos ou os limites).

## Para o sistema consumidor

### Endereço

```
https://sesjur.saude.mt.gov.br/sesjur-processos/api/v1/
```

O servidor do sistema consumidor precisa estar **conectado à VPN da SES-MT** e resolver o nome `sesjur.saude.mt.gov.br`. Se o certificado do SESJUR for emitido por autoridade certificadora interna, instale a cadeia dessa autoridade no servidor consumidor; **não desative a verificação do certificado**.

### Endpoints

| Recurso | Endpoint | Consulta individual | Conteúdo aninhado |
|---|---|---|---|
| Identificação | `GET /` | — | cliente, escopos liberados, limites |
| Processos Administrativos | `GET /processos-administrativos.php` | `?id=` ou `?protocolo=` | procedimento, responsável, eventos (SIGADOC), prioridades, produtos de saúde, judiciais e extrajudiciais vinculados |
| Processos Judiciais | `GET /processos-judiciais.php` | `?id=` ou `?numero_cnj=` | partes, assuntos, movimentações (PJe), documentos, administrativos vinculados. `&incluir=integra` traz o texto integral dos documentos |
| Processos Extrajudiciais | `GET /processos-extrajudiciais.php` | `?id=` | instituição e órgão externos, administrativos vinculados |
| Vínculos | `GET /vinculos.php?tipo=judicial\|extrajudicial` | `?id=` | protocolo e identificador dos dois lados |

### Parâmetros das listagens

| Parâmetro | Descrição |
|---|---|
| `limite` | 1 a 500 (padrão 100) |
| `apos_id` | cursor: devolve registros com `id` maior que este (padrão 0) |
| `atualizado_desde` | ISO 8601 (`2026-10-01` ou `2026-10-01T08:00:00-04:00`): só o que mudou desde então — o próprio processo ou algum filho (eventos, partes, movimentações, documentos, vínculos…). Em vínculos: criados desde |
| `somente_ids=1` | todos os ids atuais do recurso, sem paginação — para detectar exclusões |

### Formato

```json
{
  "success": true,
  "gerado_em": "2026-10-26T11:24:30-04:00",
  "dados": [ { "id": 34, "protocolo": "SES-PRO-2026/71558", "...": "...", "eventos": [ ] } ],
  "paginacao": { "limite": 100, "apos_id": 0, "quantidade": 35, "tem_mais": false, "proximo_apos_id": null }
}
```

- Datas: `AAAA-MM-DD`; data/hora: ISO 8601 com fuso (`America/Cuiaba`); hora isolada: `HH:MM:SS`.
- Booleanos tri-estado (`segredo_justica`, `justica_gratuita`, `tutela_liminar`, `prioridade`): `null` = não informado pela fonte, nunca "não".
- Valores monetários (`valor_causa`) vêm como texto decimal (`"367492.00"`) para não perder precisão.
- Erros: `{"success": false, "error": {"code": "...", "message": "..."}}` com o status HTTP correspondente (401, 403, 404, 405, 422, 429, 500).

### Importação recomendada

1. **Carga inicial:** para cada recurso, chame com `apos_id=0` e repita com `apos_id = paginacao.proximo_apos_id` enquanto `tem_mais` for `true`. Guarde o `gerado_em` da **primeira** página.
2. **Atualização incremental:** repita o passo 1 com `atualizado_desde = <gerado_em guardado>` e grave por *upsert* pelo `id`. Os filhos vêm completos: substitua a lista de eventos/movimentações/partes do processo pela recebida.
3. **Exclusões:** periodicamente, compare seus ids com `?somente_ids=1` e remova os que sumiram. Uma carga completa semanal também cobre filhos removidos (ex.: prioridade retirada), que o filtro incremental não detecta.
4. Respeite `429` aguardando o cabeçalho `Retry-After`.

```bash
curl -H "Authorization: Bearer $SESJUR_TOKEN" \
  "https://sesjur.saude.mt.gov.br/sesjur-processos/api/v1/processos-judiciais.php?limite=100&apos_id=0"
```

### Dados pessoais e sigilo

A exportação inclui dados pessoais (nomes e CPF/CNPJ de partes, responsáveis, objetos com nome de pacientes) e processos marcados com **segredo de justiça**. O sistema consumidor assume o mesmo dever de sigilo do SESJUR (LGPD): guardar o Token fora do código-fonte, restringir o acesso aos dados importados e não repassá-los.

## Implantação no servidor da VPN

### Topologia (informada pela TI da SES-MT em 26/10/2026)

```
Sistema consumidor (na VPN)
   │  HTTPS
   ▼
nginx — 10.15.1.11 — sesjur.saude.mt.gov.br   (encerra o TLS, proxy reverso)
   │  HTTP
   ▼
VM 10.15.0.115 — Apache + PHP 8.3 + MySQL      (código por git pull)
```

Como o TLS termina no nginx, o Apache recebe HTTP e o PHP não vê o HTTPS: a API depende de `api.proxies_confiaveis` e de o nginx repassar os cabeçalhos abaixo. Pedido à TI (bloco `location` do SESJUR no nginx):

```nginx
proxy_set_header Host              $host;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
# O cabecalho Authorization ja e repassado por padrao; nao remova.

# A pasta .git do projeto (historico com backups do banco) nunca pode ser servida.
location ~ /\.git { deny all; return 404; }
```

> ⚠️ **Constatado em 26/10/2026:** `https://sesjur.saude.mt.gov.br/sesjur-processos/.git/` estava acessível, o que permite reconstruir o repositório — inclusive os `database/backup_*.sql` versionados, com dados reais. O `.htaccess` da raiz passou a bloquear `.git` (vale após o `git pull`); o bloqueio no nginx acima é a proteção principal e deve ser aplicado de imediato.

### Passos

Na pasta do projeto na VM, depois do `git pull`:

1. **Backup** do banco: `mysqldump -u <usuário> gestao_juridica > database/backup_antes_api_integracao_AAAAMMDD.sql`.
2. **Migração** (idempotente, só cria `api_clientes` e `api_requisicoes`): `mysql -u <usuário> gestao_juridica < database/migracao_2026-10-26_api_integracao.sql`.
3. **Configuração** em `app/config/config.php` (não versionado), seção `api`:
   ```php
   'api' => [
       'url_publica'        => 'https://sesjur.saude.mt.gov.br/sesjur-processos/api/v1/',
       'exigir_https'       => true,
       'proxies_confiaveis' => ['10.15.1.11'],   // nginx que encerra o HTTPS
   ],
   ```
   Se a primeira chamada responder `403 HTTPS_REQUIRED`, veja o IP gravado: `SELECT ip, status_http FROM api_requisicoes ORDER BY id DESC LIMIT 5;`. Esse é o endereço com que o nginx chega ao Apache; se não for `10.15.1.11`, coloque-o em `proxies_confiaveis`. Se já for, falta o nginx enviar `X-Forwarded-Proto`.
4. **Cliente**: `php database/api-clientes.php criar --nome="Sistema de Dieggo Ronney de Oliveira" --responsavel=dieggooliveira@pge.mt.gov.br --expira=AAAA-MM-DD [--ips=<IP do servidor consumidor>]`. Entregue o token exibido por meio seguro.
5. **Teste**: `curl -H "Authorization: Bearer <token>" https://sesjur.saude.mt.gov.br/sesjur-processos/api/v1/` deve responder `"success": true` com o `endereco` e os recursos liberados.

## Administração (linha de comando)

```bash
php database/api-clientes.php listar
php database/api-clientes.php criar --nome="Sistema X" --responsavel=email@dominio [--escopos=a,b] [--ips=10.0.0.5,10.0.1.0/24] [--expira=AAAA-MM-DD]
php database/api-clientes.php renovar --id=N
php database/api-clientes.php revogar --id=N
```

Estrutura: `database/migracao_2026-10-26_api_integracao.sql` (`api_clientes`, `api_requisicoes`). Código: `api/v1/`, `app/Services/ApiClienteService.php`, `app/Repositories/ApiClienteRepository.php`, `app/Repositories/ExportacaoRepository.php`. Testes: `tests/php/api-integracao.php`.

## Pendências

- 🗓️ Tela de administração dos Clientes da API em Configurações (hoje só por linha de comando).
- 🗓️ TI da SES-MT: aplicar no nginx os cabeçalhos `X-Forwarded-*` e o bloqueio de `/.git`; confirmar o IP com que o nginx chega à VM; liberar na VPN o acesso do servidor consumidor e informar o IP dele (para `--ips`).
- 🗓️ Avaliar retirar `database/backup_*.sql` do repositório e do histórico (contêm dados reais).
- 🗓️ O cookie de sessão do sistema web não recebe `Secure` atrás do nginx (o PHP não vê o HTTPS); usar a mesma regra de proxy confiável no `bootstrap.php`.
