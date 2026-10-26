# Arquitetura

## Visão em camadas

| Camada | Tecnologia | Local |
|---|---|---|
| Interface web | PHP renderizado no servidor + JS puro + CSS próprio | `public/`, `app/Views/`, `public/assets/` |
| API JSON | PHP (endpoints finos) | `api/` |
| Regras de negócio | Services + Validators | `app/Services/`, `app/Validators/` |
| Acesso a dados | Repositories (PDO) | `app/Repositories/` |
| Núcleo | Database, Csrf, Auth, Perfil, Prazo, Paginacao, helpers | `app/Core/` |
| Banco | MariaDB/MySQL (InnoDB, utf8mb4) | `database/` |
| Extensão do navegador | Chrome Manifest V3 | `chrome-extension/` |
| Testes | Runner PHP próprio + runner HTML dos extratores | `tests/` |

Ambiente de referência: Windows + **Laragon** (Apache, PHP ≥ 8.1 com `pdo_mysql` e `mbstring`, MySQL ≥ 8.0.16 ou MariaDB ≥ 10.2). Aplicação servida em subdiretório (`/gestao-juridica/public`), sem VirtualHost.

## Padrão por funcionalidade

```
public/<pagina>.php  →  Service (regra + transação + auditoria)  →  Repository (SQL)  →  Banco
public/salvar-*.php / alterar-status-*.php / excluir-*.php   (somente POST + CSRF)
api/*.php                                                       (JSON: autocomplete, extração, vínculos)
```

- **Repository** só fala SQL; **Service** valida, autoriza, abre transação e audita; **Validator** não acessa banco.
- Formulários têm *partials* reutilizáveis em `app/Views/partials/` (ex.: `campos-*.php`, `form-*.php`) usados tanto na página de cadastro quanto nos modais.
- Componente de modal reutilizável: `public/assets/js/modal.js` + `<dialog class="modal">`.
- Sem JavaScript, os botões de modal degradam para as páginas de cadastro comuns.

## Fluxo de integração (extração)

```
Aba SIGADOC/PJe (autenticada)
   └─ collector.js (injetado sob demanda) → extractors/ → normalizers/ → contrato JSON
        └─ service-worker.js ⇄ app-bridge.js (só localhost) ⇄ extension-bridge.js (página SESJUR)
              └─ api/extracao-preparar.php  (autoriza: formato + allowlist)
              └─ api/extracao-confirmar.php (revalida; confere identificador)
                    └─ formulário → revisão humana → salvar → MariaDB
```

Detalhes em [../05-extracao/](../05-extracao/).

## Requisitos não funcionais

- **RNF-001 Segurança:** ver [seguranca.md](seguranca.md).
- **RNF-002 Operação offline/rede interna:** nenhuma chamada a serviço externo em tempo de execução.
- **RNF-003 Transacionalidade:** operações compostas (processo + partes + assuntos + vínculos; ação + recálculo de prazo/atividade; lançamento + auditoria) em **uma** transação.
- **RNF-004 Integridade:** FKs, UNIQUE e CHECK no banco; a checagem prévia no serviço melhora a mensagem, e o erro 1062 de corrida é traduzido na mesma mensagem amigável.
- **RNF-005 Cache de assets:** `asset()` acrescenta `?v=<mtime>` a CSS/JS.
- **RNF-006 Erros:** telas mostram mensagem genérica com código; detalhe no log do PHP.
- **RNF-007 Usabilidade:** listagens com paginação, filtros e ordenação (allowlist de colunas); processo judicial com visões Cards/Tabela (escolha guardada na sessão); pesquisa geral com atalho `/`.
- **RNF-008 Acessibilidade básica:** Prioridade sempre exibida com número e rótulo, não só por cor; modais com `<dialog>`.

## Pontos de atenção

- A extensão só conversa com a aplicação em `localhost`/`127.0.0.1` (ADR 0002): acessar por IP ou nome da máquina exige revisar a permissão estática.
- `AllowOverride None` no Apache ignora os `.htaccess` e deixa `app/`, `database/` e `tests/` acessíveis por HTTP — deve ser `All`.
- Dívida técnica registrada: `usuarios` ainda guarda cópia sincronizada de dados do Agente Público; remover as colunas é passo futuro, após trocar as consultas que as leem.
