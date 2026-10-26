# Princípios inegociáveis

Base para a constituição do projeto (`/speckit-constitution`). Cada princípio decorre de uma decisão já tomada ou de regra transversal do sistema.

## I. A extração acontece no navegador do usuário, nunca no servidor
O PHP jamais faz requisições ao SIGADOC/PJe nem reproduz cookies ou sessão. A URL informada é validada e repassada à extensão. _(ADR 0001)_

## II. Confirmação humana antes de gravar
`EXTRAIR → VALIDAR → PREENCHER → USUÁRIO REVISA → CONFIRMA → SALVA`. A extração preenche formulário; nunca grava sozinha.

## III. Nunca fabricar valor ausente
Escalar não encontrado = `null` + `warning`; coleção ausente = `[]`. Um dado errado num cadastro jurídico é pior que um campo em branco. _(ADR 0003)_

## IV. Relacionamento por ID, identificadores de negócio com UNIQUE
Protocolo e Número CNJ são únicos, mas os vínculos usam chaves estrangeiras numéricas. Remover vínculo nunca exclui processo.

## V. Auditável por padrão
Toda alteração relevante grava na Auditoria (append-only), na mesma transação, com autor, data e campos alterados. Registros de autoria nunca somem: tabelas de apoio são inativadas, não excluídas.

## VI. O servidor é a autoridade de validação e autorização
Validação no cliente é conveniência. Permissão é checada no serviço. PDO com prepared statements em 100% das consultas; ordenação por allowlist de colunas; escape de saída com `e()`; CSRF em todo POST.

## VII. Sem dependências externas em tempo de execução
Rede interna, possivelmente sem internet: CSS próprio, sem CDN, sem fontes externas. _(ADR 0004)_

## VIII. Estado calculado nunca é gravado à mão
Prazo vigente, Atividade, Saldo retido, Situação do prazo, "Vencida" e Competência são derivados; recalculados na mesma transação da mudança que os afeta.

## IX. Módulos por chave estável, não por nome
Painéis específicos de Procedimento são selecionados por `procedimentos.modulo`, não pelo nome do item. _(ADR 0005)_

## X. Nunca perder dado do usuário numa falha
Em qualquer falha de extração, os dados já digitados são preservados. Migrações são idempotentes, precedidas de backup, com rota de reversão documentada.

## Restrições adicionais

- Permissão estática da extensão limitada a `http://localhost/*` e `http://127.0.0.1/*`; domínios institucionais são permissões opcionais concedidas uma a uma. _(ADR 0002)_
- Nenhuma credencial, cookie ou token é lido, armazenado ou transmitido pela extensão.
- Mensagens de erro ao usuário são genéricas com código; o detalhe vai para o log do PHP.

## Fluxo de trabalho

- Mudanças de esquema: backup → migração idempotente em `database/` → atualização de `database/database.sql` → testes.
- Mudança em termos do domínio: atualizar primeiro `CONTEXT.md`.
- Decisão difícil de reverter ou surpreendente: registrar ADR em `docs/adr/`.
- Mudança de seletor de DOM: apenas em `chrome-extension/config/selectors.js`, com incremento de `VERSAO_EXTRATOR` e caso em `tests/extractors/casos.js`.
