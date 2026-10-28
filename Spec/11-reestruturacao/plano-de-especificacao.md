# Plano de especificação — Reestruturação do SESJUR (Laravel + quatro dimensões)

| | |
|---|---|
| **Situação** | Rascunho — decisões do §3 e respostas da 2ª, 3ª, 4ª e 5ª rodadas (§3, "Decisões complementares") registradas em 2026-10-27; pendências no §9 |
| **Data** | 2026-10-27 |
| **Escopo** | Plano para *especificar* a reestruturação: o que decidir, o que escrever, em que ordem. Não é o plano técnico de implementação (`/speckit-plan`) de cada funcionalidade. |
| **Base** | `CONTEXT.md`, `Spec/` (pastas 00–10), `docs/adr/0001–0005`, `database/database.sql` (44 tabelas) |

---

## 1. Objetivo

Reestruturar o SESJUR — ferramenta digital de gestão de informações e conhecimento da equipe da **Subprocuradoria-Geral da Saúde (SGS)** — em duas frentes:

1. **Fundação:** migrar a base técnica do PHP sem framework para o **Laravel**.
2. **Estrutura funcional:** reorganizar o sistema em **quatro dimensões** — Principal, Administrativa, Jurídica e Configurações Gerais — implementadas **gradativamente** (módulos, páginas, seções, ferramentas e funcionalidades), conforme a necessidade dos administradores e gestores da Subprocuradoria.

Nenhuma dimensão é isolada: elas compartilham dados, usuários e navegação; **apenas algumas funcionalidades são restritas a determinados perfis**.

> **Nota sobre a contagem:** o enunciado diz "três dimensões", mas lista quatro. Este plano trata **quatro**: Principal, Administrativa, Jurídica e Configurações Gerais.

---

## 2. Ponto de partida

O SESJUR **já existe e está em uso**, inclusive com a API de Integração v1 em produção na VPN da SES-MT. Portanto o trabalho é **reestruturação**, não projeto novo — mas, após as decisões do §3, com **uma mudança de modelo relevante**: o Procedimento deixa de ser um item de catálogo e passa a ser uma entidade própria.

| Aspecto | Hoje |
|---|---|
| Stack | PHP ≥ 8.1 sem framework (páginas em `public/*.php`, `api/*.php`), Services/Repositories/Validators próprios, PDO, MariaDB/MySQL, Laragon |
| Dados | 44 tabelas, migrações SQL manuais com backup prévio |
| Interface | PHP no servidor + JS puro + CSS próprio (ADR 0004) |
| Acesso | Perfis Administrador / Gestor / Usuário; credenciais em tabela própria |
| Integrações | Extensão Chrome MV3 (SIGADOC e PJe), API de Integração v1 somente leitura |
| Qualidade | 93 testes PHP + 271 asserções de extração |
| Especificação | `Spec/` com 10 pastas temáticas; constituição do Spec Kit **ainda em branco** |

Hoje o Procedimento é um **tipo** escolhido num catálogo e gravado no Processo Administrativo (um por processo); a Designação, as Tarefas, Solicitações, Anotações, Prioridades e Produtos de Saúde pendem do Processo Administrativo.

---

## 3. Decisões registradas

Respostas ao §3 da versão anterior deste plano. As colunas à direita dizem o que cada decisão obriga a especificar.

| # | Tema | Decisão | Implicações para a especificação |
|---|---|---|---|
| 1 | "Principal" duplicada | Conforme proposto. | Uma única **Página do Usuário** na dimensão Principal; a Jurídica pode ter atalho. |
| 2 | Os 8 "Processos" | **São Procedimentos.** Cada Tipo de Procedimento tem **painel (dashboard) próprio**, com seções e campos específicos. Todo Processo Administrativo (SIGADOC) registrado origina **ao menos um** Procedimento; a relação é **1 Processo : N Procedimentos**. Procedimento tem **ID próprio** e **não existe sem Processo Administrativo**. Os Procedimentos são **seguidos e executados por Usuários designados** (um ou mais). *(A ideia inicial de que os Processos "pertencem às Unidades em que se encontram" foi substituída na 2ª rodada: ver "Decisões complementares".)* | Ver §3.1 (modelo). Reescrever o termo *Procedimento* no `CONTEXT.md`; **revisar o ADR 0005** (hoje só alguns tipos têm Módulo; passam a ter todos); nova spec do **Processo Administrativo** (registro + primeiro Procedimento); nova spec de **Designação** (migra para o Procedimento). |
| 3 | Classificação dos Processos | Por **origem**: **Administrativo** (interno, Protocolo SIGADOC), **Judicial** (externo, Número CNJ), **Extrajudicial** (externo, sem padrão de protocolo). | Módulo de navegação **Protocolos** com três páginas. O identificador do Extrajudicial segue **livre** (sem validador de formato; hoje é único por Instituição Externa). Acrescentar *interno/externo* ao glossário. |
| 4 | Agentes Públicos | **Agente Público** = todo agente do **Poder Executivo Estadual**. **Agente Público SGS** = o **vinculado** à SGS, ainda que **lotado** em outra Unidade da SES-MT ou da PGE-MT. **Vinculação ≠ Lotação.** | Ver §3.2. O escopo de Instituições e Unidades passa a ser o **Poder Executivo Estadual**, não só SES-MT e PGE-MT. Nova spec de **Vinculação à SGS**. |
| 5 | Hierarquia de navegação | **Dimensão › Módulo › Página › Subpágina › Guia › Seção**, mais **Funcionalidade**. Exemplo: *Jurídica › Procedimento › Comunicação Interinstitucional › Instituição Interessada*. | **Substitui** minha proposta de reservar "Módulo" ao ADR 0005 — o exemplo é coerente com ele (o Módulo *Procedimento* contém uma Página por Tipo). O registro de funcionalidades passa a ter 6 níveis (§5.2). **Funcionalidade** = ação que pode ser acrescentada em qualquer ponto da aplicação (página ou outro nível); não é nível de menu (4ª rodada, P). |
| 6 | ADR 0004 × Laravel | Conforme proposto. | Vendor e assets locais, sem CDN; CSS próprio mantido. ADR 0006. |
| 7 | Host e extensão | A extensão **deve funcionar** em `localhost`/`127.0.0.1`, em `sesjur.test` (Laragon) e em **`https://sesjur.saude.mt.gov.br/`** (produção). | Revisa o **ADR 0002** (hoje só localhost). Ver §3.3. |
| 8 | API v1 congelada | Conforme proposto. | Contrato `v1` é requisito de compatibilidade. |
| 9 | `entrega-api/` | Conforme proposto. | Fica fora do repositório e do pacote de migração. |
| 10 | Dados sensíveis | São **internos e restritos** aos usuários do SESJUR que são **agentes públicos vinculados à SGS**. | O acesso depende de **Vinculação à SGS = Sim**, além do Perfil (§5.4). A seção "Proteção de dados" das specs fica enxuta (§7). Esses dados **não** vão para a API v1 nem para exportações. |
| 11 | Constituição | Conforme proposto. | Preencher com `principios.md` (I–X). |

### Decisões complementares (2ª rodada)

| # | Pergunta | Decisão | Implicações |
|---|---|---|---|
| A | Processos já cadastrados sem Tipo (só a classificação em texto livre) | Cada um recebe **um Procedimento do Tipo *Outros***. | Marcar como "migrado"; preservar a *classificação anterior* como histórico. Cumpre a regra de que todo Processo tem ao menos um Procedimento. |
| B | Designação: de Agente ou de Usuário? Quantos? | **De Usuário do Sistema**; **um ou mais** por Procedimento. | Sem papéis (responsável/auxiliar) por ora. Migração: as Designações atuais (Processo ↔ Usuário) passam ao Procedimento gerado. |
| C | Unidade do Processo | **Não há campo de Unidade.** Todo Processo Administrativo registrado no SESJUR com **algum Procedimento não finalizado** é de responsabilidade da SGS. | A "Unidade do Processo" sai do modelo. Surge um **estado calculado** (*sob responsabilidade da SGS*), que depende dos estados do Procedimento (definidos na 3ª rodada, K); *Finalizado* e *Encerrado* encerram a responsabilidade (4ª rodada, M). |
| D | Vinculação à SGS | **Sim/Não**, por ora, sem período nem histórico. | Atributo simples do Agente Público. Se o histórico vier a ser necessário, é uma evolução própria. |
| E | Usuário sem Vinculação à SGS | **Pode existir**: pode-se conceder acesso a Agentes Públicos sem vínculo com a SGS. | O Perfil sozinho não basta: dados sensíveis exigem também Vinculação = Sim (§5.4). Alcance definido na 3ª rodada (G): acessa e **pode ser designado** a Procedimento. |
| F | Distribuição da extensão | **Instalação manual e individual.** | Sem loja nem política central: cada máquina precisa ser atualizada à mão. Exige procedimento documentado e **aviso na aplicação quando a extensão estiver desatualizada** (§3.3, RF-FUND-017). |

### Decisões complementares (3ª rodada)

| # | Pergunta | Decisão | Implicações |
|---|---|---|---|
| G | Usuário sem Vinculação à SGS: o que acessa? Pode ser designado? | **Acessa** e **pode ser designado** a um Procedimento. | Mantida a regra do item 10: dados sensíveis só com Vinculação = Sim. Interpretação adotada: "acessa" = tudo que o Perfil permite, **exceto** dados sensíveis. |
| H | Onde fica o projeto Laravel? | **Novo repositório dentro da raiz** (`C:\SESJUR\<nome>`), ao lado de `sesjur-processos`. | O legado não é tocado. Nome `sesjur` (`C:\SESJUR\sesjur`); `Spec/` copiada para ele e `.specify` reinicializado (4ª rodada, R). |
| I | Versão do PHP em produção | **A mesma desta máquina**: PHP **8.3.33**, MySQL **8.4.3**, Composer e Node 22 do Laragon. | Atende a qualquer Laravel atual (exige PHP ≥ 8.2/8.3). Extensões necessárias presentes (`pdo_mysql`, `mbstring`, `openssl`, `intl`, `fileinfo`, `curl`, `sodium`…); `zip` ausente — o Composer usará `unzip`/`7z` ou `git`. |
| J | Âncora de Tarefas, Solicitações, Anotações, Prioridades e Prazo/Atividade | **No Procedimento.** | Ver §3.1. O Expediente, não consultado, segue no Processo. |
| K | Estados do Procedimento | **Inativo, Ativo, Finalizado e Encerrado** (tabela no §3.1). | **Substitui a Atividade** (ativo/inativo do Processo). A **Solicitação passa a influenciar o estado** — hoje ela não afeta a Atividade. |
| L | Quem designa Usuários e quem registra a Vinculação | **Administradores e Gestores**, nos dois casos. | O Gestor passa a poder editar o atributo *Vinculação à SGS* do Agente. |

### Decisões complementares (4ª rodada)

| # | Pergunta | Decisão | Implicações |
|---|---|---|---|
| M | Finalizado × Encerrado | **Finalizado** = objeto tratado/atendido; **Encerrado** = encerrado sem atendimento (arquivamento, perda de objeto). Ambos **terminais** e ambos **encerram a responsabilidade da SGS**. | Processo sob responsabilidade da SGS = tem Procedimento **Inativo ou Ativo**. |
| N | Reabertura | **Sim**, só por ação explícita **Reabrir**, de Administrador ou Gestor, com Auditoria. | Terminal não aceita Tarefa nem Solicitação novas; ao reabrir, o estado volta a ser calculado (Inativo/Ativo). |
| O | Prazo | O **Prazo é do Procedimento**. Padrão: **data de autuação (abertura) do Processo + 30 dias**; o usuário pode **substituí-lo manualmente**. Tarefas e Solicitações **não alteram** o Prazo do Procedimento, mas, se tiverem prazo **posterior** a ele, são **sinalizadas**. | O **Prazo vigente deixa de existir** (era o menor entre o do Processo e o das Tarefas). A listagem, o filtro "vencidos / 7 dias" e a ordenação passam a usar o Prazo do Procedimento. A sinalização é **calculada**, não gravada (Princípio VIII). |
| P | Funcionalidade | **Ação** acrescentada em qualquer ponto da aplicação — nível de Página ou outro. **Protocolos mantém o nome.** | O registro de funcionalidades (RF-FUND-006) cobre também as ações. |
| Q | Troca de Tipo | **Permitida**, por **Administrador ou Gestor**. | Inverte a proposta anterior (RN-PROC-003). Exige tratar os dados do painel do Tipo anterior sem perdê-los (§9, item 1). |
| R | Repositório | **Sim**: nome **`sesjur`**; `Spec/` copiada para ele; `.specify` reinicializado lá; legado congelado. | O novo repositório passa a ser a fonte da verdade da especificação (§6). |

### Decisões complementares (5ª rodada)

| # | Tema | Decisão | Implicações |
|---|---|---|---|
| S | Estratégia de corte | **Opção A: corte único após a paridade.** | Nada do Laravel vai para produção antes do corte; os antigos "marcos" viram pontos de verificação em **homologação**. Funcionalidade nova só chega à produção depois do corte. Corrige a falha do ADR 0007 (dois sistemas escrevendo no mesmo banco entre os marcos). Ver §8. |
| T | Estrutura do repositório | **Uma pasta por Dimensão na raiz** do repositório novo: `principal/`, `administrativa/`, `juridica/`, `configuracoes-gerais/`. A pasta **`sesjur-processos` permanece como legado**, intocada, ao lado. | Interpretei "raiz do repositório" como a raiz de `C:\SESJUR\sesjur` (decisões H e R). Ver §5.3.1 e §9, item 7. |
| U | Home | A dimensão **Principal é a Home** da aplicação. | `/` e o destino do login. Substitui a ideia de "hub mínimo" por uma Home composta por widgets das outras Dimensões. |
| V | Independência | Ajustes **em cada Dimensão de forma independente**, inclusive **criar novas Dimensões, Módulos e Páginas**. | Monólito modular com manifesto por Dimensão (ADR 0011). Criar uma Dimensão é criar uma pasta com manifesto. |
| W | Núcleo e contratos | *Proposta minha, derivada de V:* as Dimensões dependem só de um **Núcleo** compartilhado e se comunicam por ID e por contratos; nenhuma importa outra. | Define o que sobe para o Núcleo (identidade, Instituição, Unidade, Auditoria, registro, layout). **A confirmar.** |

### 3.1 Modelo Processo × Procedimento

```
Processo Administrativo (SIGADOC) 1 ──< Procedimento >──< Usuários designados (1..N)
   │                                       │ Tipo de Procedimento (catálogo)
   │                                       └─ dados do painel do Tipo (tabela dedicada)
   └──N:N── Processo Judicial / Processo Extrajudicial

Sob responsabilidade da SGS = tem ao menos um Procedimento Inativo ou Ativo   (calculado)
```

| Termo | Definição proposta |
|---|---|
| **Tipo de Procedimento** | O que hoje se chama *Procedimento*: item do catálogo (Demanda Jurídica de Produto de Saúde, Comunicação Interinstitucional, Análise de Legalidade das Aquisições, Consultoria Jurídica, Pagamento Indenizatório, Ressarcimento Judicial, Gestão Administrativa, Outros). Mantido por Administradores; inativado, nunca excluído. Cada Tipo tem **Painel** próprio (seções e campos específicos, chave estável — ADRs 0005 e 0010); "Módulo" ficou como nível de navegação (ADR 0008). |
| **Procedimento** | Trâmite concreto, com ID próprio, de um Tipo sobre um Processo Administrativo. Obrigatoriamente ligado a **um** Processo Administrativo; um Processo tem **um ou mais**. Tem **Usuários designados** (um ou mais) que o seguem e executam. |
| **Processo sob responsabilidade da SGS** | Estado **calculado**: o Processo Administrativo que tem ao menos um Procedimento **em aberto** (Inativo ou Ativo — nem Finalizado nem Encerrado). Não é gravado à mão (Princípio VIII) e não há campo de Unidade. |

Regras que a especificação deve fixar:

- **RN-PROC-001** Não se registra Processo Administrativo sem ao menos um Procedimento: criação do processo e do primeiro Procedimento numa **única transação** (RNF-003).
- **RN-PROC-002** Não existe Procedimento sem Processo Administrativo; excluir/arquivar o processo trata os Procedimentos (decidir: bloquear ou arquivar em cascata — nunca perder histórico).
- **RN-PROC-003** O Tipo pode ser trocado por **Administrador ou Gestor**, com Auditoria (quem, quando, Tipo anterior e novo). Os dados do painel do Tipo anterior **não podem ser perdidos** (Princípio X) — tratamento em §9, item 1.
- **RN-PROC-004** Dados do painel ficam em tabela dedicada por Tipo; **sem construtor genérico de campos** (já rejeitado no ADR 0005).
- **RN-PROC-005** A Designação é de **Usuário** do Sistema, **com ou sem Vinculação à SGS**; um Procedimento tem um ou mais designados. Designam **Administrador e Gestor** (decidido).
- **RN-PROC-006** O Procedimento tem exatamente um dos quatro estados da tabela abaixo; *Inativo* e *Ativo* são **calculados** (Princípio VIII); *Finalizado* e *Encerrado* são **escolhas do usuário**, só permitidas **sem pendências**.
- **RN-PROC-007** *Pendência* = Tarefa não finalizada (Pendente ou Em andamento) **ou** Solicitação em Aguardando. Anotações e Prioridades não são pendências.
- **RN-PROC-008** *Reabrir* leva um Procedimento Finalizado ou Encerrado de volta ao cálculo (Inativo/Ativo). É ação explícita de Administrador ou Gestor, auditada. Enquanto terminal, o Procedimento não aceita Tarefa nem Solicitação.
- **RN-PROC-009** O **Prazo do Procedimento** nasce, por padrão, na **data de autuação (abertura) do Processo + 30 dias** e pode ser substituído manualmente. Sem data de autuação, vale a regra atual (hoje + 30). Tarefas e Solicitações **não o alteram**.
- **RN-PROC-010** Tarefa ou Solicitação **pendente** com prazo **posterior** ao Prazo do Procedimento é **sinalizada** (calculado; muda se o Prazo for alterado). A sinalização não bloqueia o registro.

**Estados do Procedimento** (3ª rodada, K)

| Estado | Condição | Natureza |
|---|---|---|
| **Inativo** | Sem pendências **e** não Finalizado nem Encerrado. É o estado inicial. | Calculado |
| **Ativo** | Com ao menos uma pendência. | Calculado |
| **Finalizado** | Sem pendências **e** o usuário seleciona a opção. Objeto **tratado/atendido**. | Escolha do usuário |
| **Encerrado** | Sem pendências **e** o usuário seleciona a opção. Encerrado **sem atendimento** (arquivamento, perda de objeto). | Escolha do usuário |

> *Finalizado* e *Encerrado* são **terminais** e encerram a responsabilidade da SGS (4ª rodada, M). Só se sai deles por **Reabrir** (RN-PROC-008).

**Âncora das entidades atuais** (Tarefa, Solicitação, Anotação, Prioridade, Prazo e Atividade: **decididos**; as demais, proposta — cada spec confirma):

| Entidade | Hoje | Proposta |
|---|---|---|
| Designação | Processo Administrativo ↔ Usuário | **Procedimento** ↔ Usuário(s) — decidido (2ª rodada, B) |
| Tarefa, Solicitação, Anotação | Processo Administrativo | **Procedimento**, com visão consolidada na ficha do Processo |
| Prazo vigente e Atividade | calculados no Processo | **Prazo vigente deixa de existir**: o **Prazo do Procedimento** é um campo (padrão autuação + 30 dias, editável) e Tarefas/Solicitações apenas são **sinalizadas** se o excederem. A **Atividade é substituída pelo Estado do Procedimento**. O Processo agrega (4ª rodada, O) |
| Prioridade | Processo Administrativo | **Procedimento** (a prioridade decorre da demanda) |
| Produto de Saúde | Processo Administrativo | Módulo do Tipo *Demanda Jurídica de Produto de Saúde* |
| Pagamento Indenizatório | Processo Administrativo | Módulo do Tipo *Pagamento Indenizatório* |
| Eventos do SIGADOC | Processo Administrativo | Processo Administrativo (inalterado) |
| Vínculo N:N Judicial/Extrajudicial | Processo Administrativo | Processo Administrativo (inalterado) |
| Bloqueios, Guias de Depósito, Intimações | Processo Judicial / processo indicado | inalterado |
| Expediente | Processo Administrativo | Processo Administrativo, com Procedimento opcional como contexto |

### 3.2 Vinculação × Lotação

| Conceito | Significado | Observação |
|---|---|---|
| **Lotação** | Unidade Administrativa em que o agente exerce suas funções (SES-MT, PGE-MT ou outra do Executivo). | Já existe em `agentes_publicos`. |
| **Vinculação à SGS** | O agente integra a equipe da SGS, **independentemente** da lotação. Atributo **Sim/Não** do Agente Público (2ª rodada, D). | Novo. Define o **Agente Público SGS**, a visão *SGS › Agentes Públicos* e o **acesso a dados sensíveis**. |

Evitar *vínculo* para esse conceito: já significa a relação N:N entre processos e o vínculo funcional (efetivo, comissionado…). Por ora **não há período nem histórico**; a mudança de Sim para Não é registrada na Auditoria (quem, quando), o que preserva o rastro sem modelar vigência.

Um Agente Público **sem** Vinculação pode ter acesso ao Sistema (2ª rodada, E): tem Usuário e Perfil como qualquer outro e **pode ser designado a Procedimentos** (3ª rodada, G), mas não acessa dados sensíveis. O atributo é registrado por **Administrador e Gestor** (L).

### 3.3 Hosts e extensão do Chrome

| Ambiente | Endereço | Observação |
|---|---|---|
| Desenvolvimento | `http://localhost`, `http://127.0.0.1` | Já coberto hoje. |
| Laragon | `sesjur.test` | Laragon pode emitir HTTPS para `.test`; validar com e sem. |
| Produção | `https://sesjur.saude.mt.gov.br/` | Acesso pela VPN, como a API v1 hoje. |

- A extensão passa a declarar **esses hosts** como permissão estática do content script da ponte; os domínios do SIGADOC e do PJe continuam **opcionais, um a um** (ADR 0002, restrição adicional).
- Mantém-se a regra da ponte: só aceita `event.source === window` **e** `event.origin === location.origin`.
- **Distribuição: instalação manual e individual** (2ª rodada, F), por "Carregar sem compactação". O ID da extensão muda por máquina — o motivo de a ponte usar `postMessage` e não `externally_connectable` (ADR 0002) — e continua valendo.
- Cada máquina precisa ser **atualizada à mão** para aceitar o host de produção. Para tornar isso gerenciável: (1) procedimento de instalação/atualização em `Spec/10-operacao`; (2) a aplicação lê a **versão da extensão** pela ponte (`GJ_PING`) e **avisa quando está abaixo da versão mínima**; (3) a versão mínima fica em configuração, não no código.
- **ADR 0009** substitui o 0002 nesse ponto e delimita exatamente quais hosts a extensão aceita.

---

## 4. Mapa: nova estrutura × sistema atual

Legenda — ✅ existe · ⚙️ parcial/a remodelar · 🗓️ especificado, não implementado · 🆕 novo (sem especificação).

Mapeamento dos agrupamentos do enunciado para a hierarquia do §3, item 5 (a confirmar): o primeiro nível sob cada Dimensão é **Módulo**; o seguinte, **Página**; os demais, **Subpágina**, **Guia** e **Seção**.

### 4.1 Dimensão Principal

| Módulo › Página | Situação | Ação |
|---|---|---|
| Hub (atalhos entre dimensões, alertas, pesquisa geral) | ⚙️ (dashboard + pesquisa) | Spec nova: **Hub** |
| Usuário › Página do Usuário (Meu Perfil, Meus Procedimentos/Processos, Minhas Ações) | ⚙️ | Spec: **Página do Usuário**. Com o §3, "Meus Processos" passa a ser, na prática, **Meus Procedimentos** (os que o agente foi designado a seguir), com os Processos correspondentes. |

### 4.2 Dimensão Administrativa

| Módulo › Página › Subpágina | Situação | Ação |
|---|---|---|
| **Agenda** | 🆕 | Spec nova: eventos, prazos, audiências, férias, aniversários; fontes agregadas; permissões |
| **Institucional › Agentes Públicos** | ⚙️ | **Todos** os agentes do Poder Executivo Estadual (§3, item 4) |
| **Institucional › Unidades Administrativas › Instituições** | ⚙️ | Escopo amplia para o Poder Executivo |
| **Institucional › Unidades Administrativas › Unidades** | ⚙️ (`lotacoes`, hierarquia, 3 visualizações) | Idem |
| **SGS › Agentes Públicos SGS › Registros de Agentes** | ⚙️ | Visão dos agentes com **Vinculação à SGS**, qualquer que seja a lotação |
| **SGS › … › Banco de Candidatos** | 🆕 | Spec nova: candidatos, etapas, conversão em Agente |
| **SGS › … › Férias e Licenças** | 🆕 | Spec nova: períodos, tipos, saldo, substituições; alimenta a Agenda |
| **SGS › … › Aniversários** | 🆕 (derivado de `data_nascimento`) | Spec curta; alimenta Agenda e Hub |
| **SGS › Planos › Capacitação / PTA / Aquisição** | 🆕 | Uma spec por plano |
| **SGS › Bens e Serviços › Materiais de Consumo / Bens Patrimoniais** | 🆕 | Uma spec por página |
| **Indicadores** | 🆕 | Spec nova (depende de Planos, Agentes, Bens) |

### 4.3 Dimensão Jurídica

| Módulo › Página | Situação | Ação |
|---|---|---|
| **Procedimentos** › uma Página por Tipo (8) — cada uma com painel próprio, Seções e campos específicos | ⚙️ (`procedimentos` é catálogo; Pagamento Indenizatório ✅; Demanda de Saúde 🗓️) | Spec **estrutural** (entidade Procedimento, Tipo, painel, designação, §3.1) + **uma spec por Tipo**. Comunicação Interinstitucional, Análise de Legalidade, Consultoria, Ressarcimento Judicial, Gestão Administrativa e Outros ainda não têm campos definidos. |
| **Protocolos** (nome a confirmar) › Administrativos · Judiciais · Extrajudiciais | ✅ | Migrar (`02-processos`). Registro do Administrativo inclui o primeiro Procedimento (mesma transação); sem campo de Unidade. |
| **Providências** › Tarefas · Solicitações · Anotações | ✅ | Migrar; **reancorar no Procedimento** (§3.1) |
| **Recursos e Artefatos** › Links Úteis · Gerador de Expedientes · Intimações Pessoais · Bloqueios e Depósito | ✅ | Migrar |
| **Recursos e Artefatos** › Pareceres e Manifestações | 🆕 | Spec nova: acervo/produção, vínculo ao Procedimento/Processo, modelos, busca |
| **Interessados** › Instituições (Órgãos Institucionais) | ✅ | Migrar; distinguir das Instituições internas da Administrativa |
| **Interessados** › Usuários SUS | 🗓️ (Paciente) | Promover Paciente a cadastro próprio; dado sensível (§3, item 10) |
| **Interessados** › Entregadores | ✅ | Migrar |
| **Indicadores** › Processos por tipo / por origem; Demandas de Produtos de Saúde por tipo e por Instituição | 🆕 | Definir cada medida; "Processos por tipo" passa a ser **Procedimentos por Tipo**. Depende do Catálogo de Produtos. |

### 4.4 Dimensão Configurações Gerais

| Página | Situação | Ação |
|---|---|---|
| Usuários | ✅ | Migrar |
| Extensão do Chrome | ✅ | Migrar; passa a refletir os hosts do §3.3 |
| **Tipos de Procedimento** (hoje "Procedimentos") | ⚙️ | Migrar com o novo nome; define também a chave de Módulo |
| Prioridades | ✅ | Migrar |
| Limitações de acesso | ⚙️ | Spec nova (§5.4), incluindo a Vinculação à SGS |
| Tabelas de apoio (Cargos, Níveis, Pastas, Áreas…) | ✅ | Migrar |
| Clientes da API de Integração | ✅ | Migrar sem mudar o contrato |
| Auditoria (consulta) | ⚙️ | Spec: consulta e filtros |

---

## 5. Especificação da Fundação Laravel (Fase 1)

Primeira entrega. Nenhuma funcionalidade nova de negócio: o objetivo é ter o **casco** — autenticação, autorização, navegação, auditoria, padrões de código e testes — sobre o qual os módulos são portados e criados. **A remodelagem do Procedimento ocorre na Fase 3**, junto com a portagem dos Processos (portar e depois remodelar duplicaria o trabalho); na Fase 1 o esquema entra como está.

### 5.1 Decisões técnicas propostas

| Tema | Proposta | Alternativa |
|---|---|---|
| Versão do Laravel/PHP | Versão estável corrente do Laravel, sobre **PHP 8.3.33 e MySQL 8.4.3** (iguais em produção — 3ª rodada, I). Confirmar na instalação que a versão escolhida do Laravel aceita o PHP 8.3 | — |
| Estratégia | Novo projeto Laravel **em paralelo**, em **repositório novo** `C:\SESJUR\sesjur` (3ª e 4ª rodadas, H e R); o legado `sesjur-processos` segue em produção, congelado, até o **corte único** após a paridade (5ª rodada, S); módulos portados em ondas (§8) | Substituir no lugar (descartada: risco alto sem rollback) |
| Banco | Durante o desenvolvimento, Laravel aponta para **cópia** do banco (restaurada de backup). No corte: backup → migrações → validação (Princípio X) | Compartilhar o banco de produção entre os dois sistemas (descartada) |
| Esquema | **Migração-base** gerada de `database.sql`; nomes de tabelas e colunas **mantidos** (Eloquent com `$table`). Mudanças de modelo (Procedimento, Vinculação) via migrações *expand/contract* com spec própria | Renomear tudo para a convenção Laravel |
| Camadas | Controller fino → Service/Action → Eloquent. Form Requests no lugar dos Validators. **Sem Repository** sobre Eloquent | Manter Repositories |
| Organização do código | **Monólito modular**: pasta por Dimensão na raiz, manifesto `dimensao.php`, Núcleo compartilhado, dependência só Dimensão → Núcleo, carregador próprio (ADR 0011) | Pacote de módulos de terceiros; estrutura por tipo de arquivo; um repositório por Dimensão |
| Front-end | Blade + Alpine.js (empacotado localmente com Vite) + **CSS próprio portado** (ADR 0004). Modal `<dialog>` preservado | Livewire (avaliar só para listagens complexas) |
| Autorização | Policies/Gates sobre os 3 perfis atuais + **Vinculação à SGS** como segundo critério + tabela de permissões por funcionalidade (§5.4) | Pacote de permissões de terceiros |
| Auditoria | Mantida **própria** (append-only, mesma transação), via trait/observer, formato compatível com `auditoria` | Pacote de auditoria |
| API v1 | Rotas `/api/v1` com middleware de token **próprio**; contrato idêntico | Sanctum (rompe clientes) |
| Testes | Pest ou PHPUnit com banco isolado; **portar a suíte de 93 testes** junto com cada módulo; runner JS dos extratores inalterado | — |
| Configuração | `.env`; nenhuma credencial versionada | — |

### 5.2 Requisitos da fundação

| ID | Requisito |
|---|---|
| RF-FUND-001 | Projeto Laravel executando no ambiente de referência (Laragon), com instruções de instalação em `Spec/10-operacao`. |
| RF-FUND-002 | Login por e-mail; senha provisória definida por Administrador exige troca no próximo login (RN-PERM-003); credenciais em tabela separada (RN-PERM-004); limitação de tentativas. |
| RF-FUND-003 | **Usuários atuais entram sem redefinir senha** — verificar o algoritmo de hash de `usuario_credenciais` e garantir compatibilidade (re-hash transparente no primeiro login, se necessário). |
| RF-FUND-004 | Autorização imposta em Policy/serviço, não só na tela (RN-PERM-001); acesso negado não revela a existência do registro (RN-PERM-005). |
| RF-FUND-005 | Layout com **seletor de Dimensão** e menu lateral hierárquico (**Módulo › Página › Subpágina**; Guias e Seções renderizadas na própria página), gerado a partir de um **registro de funcionalidades**, filtrado pela permissão do usuário. |
| RF-FUND-006 | O registro guarda, por item: Dimensão, Módulo, Página, Subpágina, rota, permissão exigida e **estado** (`planejada` / `ativa`). Itens `planejada` ficam ocultos para Usuário e Gestor e aparecem como "em breve" para Administrador — é o mecanismo da implementação gradativa. **Funcionalidades** (ações) também são registradas, com o ponto da aplicação em que aparecem, permissão e estado, e seguem a mesma regra de exibição. |
| RF-FUND-007 | Auditoria append-only na mesma transação da alteração (Princípio V), compatível com o Histórico atual. |
| RF-FUND-008 | Tabelas de apoio por base comum: **inativar, nunca excluir** (RN-PERM-002). |
| RF-FUND-009 | Erros ao usuário: mensagem genérica com código; detalhe no log (RNF-006). |
| RF-FUND-010 | CSRF, escape de saída e cabeçalhos de segurança ativos por padrão; nenhum recurso externo no carregamento da página (RNF-002). |
| RF-FUND-011 | A dimensão **Principal** é a **Home** da aplicação (`/` e destino pós-login): atalhos para as Dimensões liberadas ao perfil e widgets contribuídos pelas demais Dimensões (RF-FUND-020). |
| RF-FUND-012 | Pesquisa geral (atalho `/`) disponível no cabeçalho, ainda que só com as entidades já portadas. |
| RF-FUND-013 | Rotas da API de Integração v1 reservadas e cobertas por teste de contrato (portadas na onda de Protocolos). |
| RF-FUND-014 | Cache de assets por versão (equivalente ao `asset()` com `?v=`), via Vite. |
| RF-FUND-015 | A aplicação e a ponte com a extensão operam nos **três hosts** do §3.3, sem alteração de código entre ambientes (apenas `.env`). |
| RF-FUND-016 | O Usuário é ligado a um Agente Público; a **Vinculação à SGS** (Sim/Não) do agente é consultável pelas Policies (mesmo que o cadastro completo chegue na Fase 2). Usuário sem Vinculação é permitido. |
| RF-FUND-017 | A aplicação detecta a versão da extensão pela ponte e **avisa** o usuário quando ela estiver ausente ou abaixo da versão mínima configurada. |
| RF-FUND-018 | Cada Dimensão vive numa **pasta própria na raiz do repositório** e declara um **manifesto** `dimensao.php` (identidade, ordem, estado, Módulos › Páginas › Subpáginas, Funcionalidades, permissões com padrão por Perfil). O Núcleo **descobre** as pastas com manifesto e compõe o registro (RF-FUND-006). |
| RF-FUND-019 | **Criar uma Dimensão, um Módulo ou uma Página** exige alterar **apenas** a pasta da Dimensão envolvida (e, para uma Dimensão nova, criar a pasta). Um gerador de esqueleto (comando Artisan) cria a estrutura padrão. |
| RF-FUND-020 | As Dimensões se comunicam só por **ID** e por **contratos do Núcleo** (widgets da Home, eventos da Agenda, indicadores, escopos da API v1). Um **teste de arquitetura** falha se uma Dimensão importar classes de outra. |
| RF-FUND-021 | Cada Dimensão tem **rotas, views, assets, migrações e testes próprios**, carregados pelo Núcleo; as migrações respeitam a ordem Núcleo → Dimensões. |
| RF-FUND-022 | Uma Dimensão com estado `planejada` **não é carregada** (rotas, menu, widgets) fora do ambiente de desenvolvimento e homologação; desativar uma Dimensão não quebra as demais. |

### 5.3 Navegação por dimensão

```
[ Principal ] [ Administrativa ] [ Jurídica ] [ Configurações Gerais ]   ← Dimensão
   └─ menu lateral: Módulo › Página › Subpágina                           ← registro de funcionalidades
        └─ na página: Guias (abas) › Seções                               ← da própria tela
```

- A dimensão ativa é deduzida da rota; o usuário só vê dimensões em que tem ao menos uma página permitida.
- Dados compartilhados (Agente Público, Instituição, Processo, Procedimento) são **referenciados por ID**, nunca duplicados entre dimensões (Princípio IV).

### 5.3.1 Estrutura do repositório e independência das Dimensões

Decisões T, U, V e W; detalhes no ADR 0011. Os nomes de pasta não levam acento; o nome de exibição está no manifesto.

```
C:\SESJUR\
├─ sesjur-processos\          LEGADO — congelado, intocado, com o seu próprio Git
└─ sesjur\                    NOVO repositório (Laravel)
   ├─ app\  bootstrap\  config\  database\  public\  resources\  routes\  tests\   ← Laravel + NÚCLEO
   ├─ principal\              HOME (/): hub, widgets, Página do Usuário
   ├─ administrativa\         Agenda · Institucional · SGS · Indicadores
   ├─ juridica\               Procedimentos · Protocolos · Providências · Recursos e Artefatos · Interessados · Indicadores
   ├─ configuracoes-gerais\   Usuários · Extensão · Tipos de Procedimento · Prioridades · Limitações de acesso · API · Auditoria
   ├─ chrome-extension\       extensão MV3 (cópia do legado, evolui aqui)
   ├─ docs\adr\  Spec\  CONTEXT.md  .specify\
   └─ (nova Dimensão = nova pasta com manifesto)

<dimensão>\
├─ dimensao.php               manifesto
├─ src\                       namespace Sesjur\<Dimensao>: Controllers, Services, Models, Policies, Requests
├─ routes\                    web.php (e api.php, quando houver)
├─ resources\                 views, css, js (entrada própria no Vite)
├─ database\migrations\
├─ tests\
└─ README.md
```

| Regra | Efeito |
|---|---|
| Dependência só **Dimensão → Núcleo** | Cada Dimensão pode ser alterada sem tocar nas outras. |
| Comunicação por **ID** e **contratos** | A Home mostra widgets de outras Dimensões sem conhecê-las por nome; a Agenda recebe eventos de quem os fornecer. |
| O que é de todos sobe para o **Núcleo** | Identidade (Usuário, Agente Público, Perfil, Vinculação), Instituição, Unidade, Auditoria, tabelas de apoio (base), registro, layout, contratos. As **telas** continuam na Dimensão dona (ex.: *Institucional › Agentes* em `administrativa/`, *Usuários* em `configuracoes-gerais/`). |
| Contribuições por **contrato** | `juridica/` fornece os escopos da API v1 e os widgets "Meus Procedimentos"; `administrativa/` fornece Aniversários e Férias para a Home e a Agenda. |
| Especificação por Dimensão | `Spec/` segue organizada por Dimensão (§6); cada pasta de Dimensão aponta para a sua. |

### 5.4 Perfis e permissões — proposta (a confirmar)

Dois critérios independentes: **Perfil** (Administrador / Gestor / Usuário) e **Vinculação à SGS**. A permissão por funcionalidade atende "limitações de acessos" sem multiplicar perfis.

| Dimensão / módulo | Administrador | Gestor | Usuário |
|---|:-:|:-:|:-:|
| Principal (hub, página do usuário) | ✅ | ✅ | ✅ |
| Administrativa › Agenda | ✅ | ✅ | leitura |
| Administrativa › Institucional | ✅ | conforme tela | leitura |
| Administrativa › SGS › Registros, Aniversários | ✅ | ✅ | leitura |
| Administrativa › SGS › Planos, Bens e Serviços, Indicadores | ✅ | ✅ | leitura de Indicadores |
| **Dados sensíveis\*** — Banco de Candidatos, Férias e Licenças, Usuários SUS (Paciente) | ✅ | ✅ | Férias/Licenças só as próprias |
| Jurídica | conforme `perfis-e-permissoes.md` (inalterado) | | |
| Jurídica › Pareceres e Manifestações (novo) | ✅ | ✅ | cria; altera só os próprios |
| Configurações Gerais | ✅ | ❌ | ❌ |

\* **Em todos os casos, só com Vinculação à SGS = Sim** (§3, item 10). Esses dados não são expostos pela API v1 nem em exportações.

**Usuário sem Vinculação à SGS** (2ª rodada, E): recebe as permissões do seu Perfil em tudo que **não** é dado sensível. Pode ser designado a Procedimento (3ª rodada, G). **Gestor** passa a poder editar o atributo *Vinculação à SGS* do Agente (L), mesmo onde a tela de Agentes é restrita ao Administrador.

### 5.5 Migração de dados e corte

1. Congelar o esquema do legado (última migração: `2026-10-26_api_integracao`).
2. Gerar a migração-base; restaurar um backup recente em ambiente de teste.
3. Script de conferência: contagem por tabela e checagem de chaves/UNIQUE antes e depois.
4. **Remodelagem do Procedimento (Fase 3)** — migração com regras explícitas:
   - cada Processo Administrativo com Tipo (`procedimento_id`) gera **um Procedimento** desse Tipo;
   - Processos sem Tipo (apenas a "classificação anterior" em texto livre) → recebem **um Procedimento do Tipo *Outros***, marcado como "migrado" (decidido, 2ª rodada, A); a classificação anterior é preservada como histórico;
   - Designações, Tarefas, Solicitações, Anotações e Prioridades passam ao Procedimento gerado;
   - conferência: nenhum registro filho sem pai; contagens antes/depois.
5. **Homologação contínua:** cada fase é validada num ambiente com cópia recente do banco; antes do corte, faz-se ao menos um **ensaio completo** de migração com backup real.
6. **Corte único** após a paridade (§8, ADR 0007): congelar as entradas no legado → backup → migrações Laravel → conferência → troca de rota (a API v1 no mesmo endereço) → legado em somente consulta → desligamento; **rota de reversão documentada** (Princípio X).
7. A extensão do Chrome muda apenas os hosts aceitos pela ponte (§3.3).

### 5.6 Critérios de aceitação da fundação

| ID | Critério |
|---|---|
| CA-FUND-01 | Um usuário existente entra com a senha atual; um provisório é forçado a trocá-la. |
| CA-FUND-02 | Usuário comum não vê nem acessa por URL as páginas de Configurações Gerais. |
| CA-FUND-03 | O menu muda conforme a dimensão e o perfil; item `planejada` não aparece para Usuário. |
| CA-FUND-04 | Toda gravação de exemplo (ex.: tabela de apoio) gera linha em Auditoria na mesma transação; falha desfaz as duas. |
| CA-FUND-05 | Nenhuma requisição a domínio externo ao carregar qualquer página. |
| CA-FUND-06 | Suíte de testes da fundação passa em banco isolado; a do legado continua passando no legado. |
| CA-FUND-07 | O backup restaurado abre no Laravel sem perda de registros (contagens idênticas). |
| CA-FUND-08 | A ponte com a extensão responde em `localhost`, `127.0.0.1`, `sesjur.test` e `sesjur.saude.mt.gov.br`, e **recusa** qualquer outro host. |
| CA-FUND-09 | Um Usuário cujo Agente tem Vinculação à SGS = Não não acessa nenhuma página de dado sensível, nem por URL direta, qualquer que seja o Perfil. |
| CA-FUND-10 | Com a extensão ausente ou abaixo da versão mínima, a aplicação exibe o aviso; com a versão correta, não. |
| CA-FUND-11 | `/` abre a **Home** (Principal) e é o destino do login; sem permissão para uma Dimensão, o atalho dela não aparece. |
| CA-FUND-12 | Criar uma Dimensão de teste (pasta + manifesto) faz aparecer no seletor, com rota e menu, **sem alterar nenhum arquivo fora da pasta**; apagar a pasta a remove sem afetar as demais. |
| CA-FUND-13 | O teste de arquitetura falha se `juridica/` importar uma classe de `administrativa/` (e vice-versa), e passa quando só usam o Núcleo ou contratos. |
| CA-FUND-14 | Com a Dimensão Jurídica `planejada`, a Home, a Administrativa e as Configurações Gerais continuam funcionando e a Home não exibe seus widgets. |

---

## 6. Estrutura-alvo da documentação (`Spec/`)

Mantém as pastas 00–10 como **fonte do comportamento atual** e acrescenta a organização por dimensão. Cada funcionalidade nova nasce em `specs/<###-nome>/spec.md` (Spec Kit) e, ao estabilizar, é consolidada na pasta da dimensão.

```
Spec/
├─ 00-visao-geral/          visão, glossário, perfis (atualizar: hierarquia, Procedimento, Vinculação)
├─ 01-arquitetura/          arquitetura (reescrever p/ Laravel), segurança, ADRs 0001–0010
├─ 02-principal/            hub, página do usuário
├─ 03-administrativa/       agenda · institucional · sgs-agentes · planos · bens-e-servicos · indicadores
├─ 04-juridica/             procedimentos (estrutura + um arquivo por Tipo) · protocolos · providencias · recursos-e-artefatos · interessados · indicadores
├─ 05-configuracoes/        usuários · extensão · tipos de procedimento · prioridades · limitações de acesso · API · auditoria
├─ 06-transversais/         pesquisa geral, auditoria, extração/extensão, expedientes
├─ 07-dados/                modelo de dados (migração-base + dicionário)
├─ 08-operacao/             instalação Laravel, migração/corte, testes
└─ 11-reestruturacao/       este plano + mapeamento antigo→novo (provisório)
```

A renumeração só deve ocorrer **depois** da aprovação deste plano e em um único commit, com tabela de equivalência (antiga → nova) em `11-reestruturacao/`, para não quebrar links nos ADRs e no `CONTEXT.md`.

O novo repositório **`sesjur`** recebe uma cópia de `Spec/` e deste plano e passa a ser a **fonte da verdade** da especificação; o `.specify` é reinicializado nele (o atual, na raiz `C:\SESJUR`, não está num repositório Git); o legado `sesjur-processos` fica **congelado** como referência de comportamento (4ª rodada, R).

**ADRs novos a registrar**

| ADR | Tema |
|---|---|
| 0006 | Adoção do Laravel; reafirma e delimita o ADR 0004 (CSS próprio; vendor/assets locais) |
| 0007 | Migração em paralelo e **corte único após a paridade** (revisado na 5ª rodada) |
| 0008 | Hierarquia de navegação (Dimensão › Módulo › Página › Subpágina › Guia › Seção) e registro de funcionalidades |
| 0009 | Hosts aceitos e ponte com a extensão (**substitui o ADR 0002** nesse ponto) |
| 0010 | Procedimento como entidade (1 Processo : N Procedimentos); Tipo de Procedimento; Painel em todos os Tipos (**revisa o ADR 0005**) |
| 0011 | Dimensões como módulos independentes (monólito modular): pasta por Dimensão, manifesto, Núcleo, Home = Principal |

**Termos a registrar no `CONTEXT.md` antes de qualquer spec**

Tipo de Procedimento · Procedimento (novo sentido) · Processo sob responsabilidade da SGS · Processo Interno / Externo · Agente Público (escopo: Poder Executivo Estadual) · Agente Público SGS · Vinculação à SGS (≠ Lotação) · Dimensão · Módulo (nível de navegação) · Página · Subpágina · Guia · Seção · Funcionalidade · Home · Núcleo · Manifesto da Dimensão. Para cada um, a lista *Avoid* (ex.: *Procedimento* não designa mais o tipo; *vínculo* não designa a Vinculação à SGS).

---

## 7. Modelo de especificação por funcionalidade

Cada spec nova (e cada spec portada) segue o `.specify/templates/spec-template.md` e deve conter:

1. **Propósito e usuários** — quem usa e para quê; perfil de acesso e se exige Vinculação à SGS.
2. **Termos do domínio** — adicionados primeiro ao `CONTEXT.md`, com *Avoid*.
3. **Posição na navegação** — Dimensão › Módulo › Página › Subpágina › Guia › Seção.
4. **Regras de negócio (`RN-`)** e **requisitos (`RF-`/`RNF-`)**.
5. **Dados** — entidades, relações, identificadores de negócio e UNIQUE, o que é calculado (nunca gravado à mão — Princípio VIII); **a que Processo/Procedimento se ancora**.
6. **Permissões** — linha na matriz de §5.4.
7. **Auditoria** — quais eventos e quais campos.
8. **Proteção de dados** — só quando há dado sensível; enxuta: finalidade, quem acessa (regra de Vinculação à SGS), fora da API e das exportações.
9. **Integrações** — Agenda, Hub, Indicadores, Pesquisa geral, API v1, extensão.
10. **Critérios de aceitação (`CA-`)** testáveis e **o que está fora do escopo**.
11. **Paridade** (só para módulos portados): comportamento atual a preservar e testes legados a portar.
12. Para cada **Tipo de Procedimento**: o painel (Seções e campos), o que o painel mostra do Processo e das Providências, e quais indicadores alimenta.

---

## 8. Roteiro proposto

As ondas abaixo são **indicativas**: a ordem real é definida pelos administradores e gestores da Subprocuradoria.

**Corte único (5ª rodada, S).** Nada do Laravel vai para produção antes dele. Cada fase é validada em **homologação** (ambiente com cópia recente do banco), e os antigos "marcos" viram **pontos de verificação** dessa homologação. O corte só ocorre quando tudo o que a equipe usa hoje no legado estiver portado e verificado (paridade). Depois dele, as funcionalidades **novas** entram em produção uma a uma. Se algo novo for urgente antes do corte, a Subprocuradoria escolhe entre antecipá-lo (adiando o corte) e aguardar (§9, item 9).

| Fase | Entrega | Especificação a produzir antes |
|---|---|---|
| **0 — Alinhamento** ✅ *(2026-10-27)* | Constituição preenchida; `CONTEXT.md` com os termos do §6; ADRs 0006–0011 | Este plano aprovado |
| **1 — Fundação Laravel** | Repositório `C:\SESJUR\sesjur` com as **pastas e manifestos das quatro Dimensões**; Núcleo (login, perfis, registro de funcionalidades, auditoria, layout, **carregador de Dimensões**, teste de arquitetura, gerador de esqueleto); **Home** (Principal) mínima; hosts da extensão aceitos; Configurações Gerais › Usuários e tabelas de apoio | `fundacao-laravel`, `dimensoes-modulares`, `navegacao-hierarquica`, `limitacoes-de-acesso`, `hosts-e-extensao` |
| **2 — Principal e Agentes** | Home com widgets; Página do Usuário; Agente Público (Poder Executivo) com **Vinculação à SGS** | `principal-home`, `pagina-do-usuario`, `vinculacao-sgs` |
| **3 — Jurídica: núcleo** *(paridade + remodelagem)* | Protocolos (Adm./Jud./Extra); **entidade Procedimento** com estados e Designação de Usuários; estado calculado *sob responsabilidade da SGS*; Providências reancoradas; extensão; pesquisa; API v1; Tipos e Prioridades | `procedimento-entidade`, `processo-administrativo` (registro + 1º Procedimento), `designacao`, paridade de `02-processos`, `03-providencias`, `05-extracao`, `08-transversais` |
| **4 — Jurídica: complementos** *(paridade)* | Recursos e Artefatos (Links, Expedientes, Intimações, Bloqueios), Interessados (Instituições, Entregadores), painel de Pagamento Indenizatório | Paridade dos módulos correspondentes |
| **5 — Administrativa: institucional** *(paridade)* | Institucional (Agentes, Instituições, Unidades) e SGS › Registros de Agentes | Paridade; reenquadramento |
| **Homologação final e CORTE ÚNICO** | Checklist de paridade por módulo do legado; ensaio(s) completo(s) com backup real; congelar entradas; backup; migrar; conferir; trocar a rota (inclusive a API v1); legado em somente consulta e depois desligado | `plano-de-corte` (passos, janela, reversão, responsáveis) |
| **6 — Jurídica: painéis por Tipo** | Usuários SUS (Paciente), Catálogo de Produtos e painel de Demanda de Saúde; painéis dos demais Tipos (Comunicação Interinstitucional, Análise de Legalidade, Consultoria, Ressarcimento Judicial, Gestão Administrativa, Outros) | Uma spec por Tipo (§7, item 12) |
| **7 — Administrativa: novas funcionalidades** | Aniversários, Férias e Licenças, Agenda, Banco de Candidatos | Specs novas |
| **8 — Administrativa: planos e bens** | Planos de Capacitação/PTA/Aquisição, Materiais de Consumo, Bens Patrimoniais | Specs novas |
| **9 — Indicadores** | Administrativos e Jurídicos | Definição de cada medida |

Como cada Dimensão é independente (ADR 0011), as Fases 6 a 9 podem ser reordenadas ou paralelizadas sem afetar as demais, e novas Dimensões, Módulos e Páginas podem ser acrescentados a qualquer momento, antes ou depois do corte.

---
## 9. Perguntas em aberto

Resolvidas até aqui: §3 (decisões 1–11), 2ª rodada (A–F), 3ª (G–L), 4ª (M–R) e 5ª (S–W). **As Fases 1 e 3 estão desbloqueadas.** Restam as dúvidas abaixo; o item 7 deve ser confirmado antes de criar o repositório e nenhuma outra bloqueia a Fase 1:

**Decorrentes das decisões**

| # | Pergunta | Recomendação |
|---|---|---|
| 1 | **Troca de Tipo**: o que acontece com os dados do painel do Tipo anterior (cada Tipo tem tabela dedicada)? | Preservados e consultáveis, em somente leitura, como "painel anterior"; o painel do novo Tipo começa vazio; a troca é auditada. Nunca apagar (Princípio X). |
| 2 | **Prazo dos Procedimentos criados depois do primeiro**: vale o mesmo padrão (autuação do Processo + 30 dias)? Pode nascer já vencido. | Sim, o mesmo padrão, editável; o aviso de vencido já cobre o caso. |
**Pendentes da versão anterior**

| # | Pergunta | Recomendação |
|---|---|---|
| 3 | Permissão **por ação (Funcionalidade)** agora, ou só por Perfil + Vinculação nesta etapa? | Estrutura pronta na Fase 1; grão fino só quando surgir o primeiro caso real. |
| 4 | **Agenda**: da **equipe** (agrega prazos, intimações, férias, aniversários) ou de compromissos pessoais? | Equipe. |
| 5 | Quais funcionalidades têm **prioridade imediata** após a Fase 1? | Definir com a Subprocuradoria; reordena §8. |
| 6 | O Gerador de Expedientes continua com modelos em `localStorage` ou passa ao banco (já há `expediente_modelos`)? | Confirmar o estado atual antes de portar. |
| 7 | **Raiz do repositório** (decisão T): as pastas das Dimensões ficam na raiz de **`C:\SESJUR\sesjur`** (o `sesjur-processos` fica ao lado, com o seu Git) ou a raiz é `C:\SESJUR` inteiro, com o legado como pasta dentro do mesmo repositório? | `C:\SESJUR\sesjur`: o legado já é um repositório próprio; a raiz `C:\SESJUR` também guarda `entrega-api\` (com tokens em texto simples), `spec-kit\` e `.specify\`, que não devem entrar no Git do sistema. |
| 8 | **Critério do corte único**: é "tudo o que existe hoje no legado", ou algum módulo pode ficar de fora por falta de uso (ex.: Gerador de Expedientes, Central de Recursos)? | Tudo o que a equipe usa hoje; módulo sem uso só fica de fora com decisão explícita do administrador, registrada no plano de corte. |
| 9 | **Funcionalidade nova urgente antes do corte**: aceitar antecipá-la (adiando o corte) ou aguardar? | Decidir caso a caso; por padrão, aguardar. |

---

## 10. Próximos passos

1. Responder à §9 — nenhum item bloqueia as Fases 1 e 3; os itens 1 e 2 devem ser resolvidos antes da spec de Procedimento e os demais, antes das respectivas fases.
2. Criar o repositório `C:\SESJUR\sesjur` (Laravel sobre PHP 8.3) com as pastas e manifestos das quatro Dimensões (§5.3.1), copiar `Spec/`, `CONTEXT.md`, os ADRs e este plano, e reinicializar o `.specify` nele.
3. ~~Preencher a constituição~~ — **feito** (`C:\SESJUR\.specify\memory\constitution.md`, v1.0.0; será levada ao novo repositório).
4. ~~Atualizar o `CONTEXT.md`~~ — **feito** (termos marcados _Alvo_; também será copiado).
5. ~~Registrar os ADRs 0006–0010~~ — **feito** em `docs/adr/`.
6. Escrever as quatro specs da Fase 1 (`/speckit-specify`).
7. Gerar o plano técnico da fundação (`/speckit-plan`) e as tarefas (`/speckit-tasks`).
