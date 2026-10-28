# Princípios inegociáveis

Base da constituição do projeto, que agora está preenchida em `.specify/memory/constitution.md` (v1.1.0, 2026-10-27) — **em caso de divergência prevalece a constituição**. Cada princípio decorre de uma decisão já tomada ou de regra transversal do sistema. Os princípios XI a XIII e os ajustes em VII, VIII e IX vêm da reestruturação (ADRs 0006 a 0010).

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
Rede interna, possivelmente sem internet: CSS próprio, `vendor/` e assets compilados locais, sem CDN, sem fontes externas. Composer e npm são ferramentas de build, não de execução. _(ADRs 0004 e 0006)_

## VIII. Estado calculado nunca é gravado à mão
Estado do Procedimento (Inativo e Ativo), Processo sob responsabilidade da SGS, sinalização de prazo excedido, Saldo retido, Situação do prazo, "Vencida" e Competência são derivados; recalculados na mesma transação da mudança que os afeta. _(Legado: Prazo vigente e Atividade, que o ADR 0010 extingue.)_ Finalizado e Encerrado são escolhas do usuário, só sem Pendências.

## IX. Painel por Tipo, selecionado por chave estável
Cada Tipo de Procedimento tem Painel próprio, selecionado por chave estável (`procedimentos.modulo`), não pelo nome do item; dados em tabela dedicada, sem construtor genérico de campos. Trocar o Tipo nunca apaga os dados do painel anterior. _(ADRs 0005 e 0010)_

## X. Nunca perder dado do usuário numa falha
Em qualquer falha de extração, os dados já digitados são preservados. Há um único corte em produção, após a paridade (ADR 0007). Migrações são idempotentes, precedidas de backup, com rota de reversão documentada.

## XI. Entrega gradativa pelo registro de funcionalidades
O sistema cresce por Dimensão › Módulo › Página › Subpágina › Guia › Seção e por Funcionalidades (ações), todas registradas com permissão e estado (`planejada` ou `ativa`). Nada é exposto antes de estar `ativa`. _(ADR 0008)_

## XII. Dado sensível restrito por Vinculação à SGS
Banco de Candidatos, Férias e Licenças e Usuários SUS só são acessíveis a Usuários cujo Agente tem Vinculação à SGS = Sim, qualquer que seja o Perfil, e nunca saem pela API nem por exportações.

## XIII. Dimensões independentes
Cada Dimensão vive em pasta própria na raiz do repositório, com manifesto `dimensao.php`, rotas, views, assets, migrações e testes. Dependência só **Dimensão → Núcleo**; as Dimensões se comunicam por ID e por contratos do Núcleo. Criar ou ajustar uma Dimensão, Módulo ou Página toca só a pasta da Dimensão. A Principal é a Home. _(ADR 0011)_

## Restrições adicionais

- Permissão estática da extensão limitada aos hosts da aplicação (`localhost`, `127.0.0.1`, `sesjur.test` e `sesjur.saude.mt.gov.br`); domínios institucionais são permissões opcionais concedidas uma a uma. _(ADRs 0002 e 0009)_
- Nenhuma credencial, cookie ou token é lido, armazenado ou transmitido pela extensão.
- Mensagens de erro ao usuário são genéricas com código; o detalhe vai para o log do PHP.

## Fluxo de trabalho

- Mudanças de esquema: backup → migração idempotente em `database/` → atualização de `database/database.sql` → testes.
- Mudança em termos do domínio: atualizar primeiro `CONTEXT.md`.
- Decisão difícil de reverter ou surpreendente: registrar ADR em `docs/adr/`.
- Mudança de seletor de DOM: apenas em `chrome-extension/config/selectors.js`, com incremento de `VERSAO_EXTRATOR` e caso em `tests/extractors/casos.js`.
