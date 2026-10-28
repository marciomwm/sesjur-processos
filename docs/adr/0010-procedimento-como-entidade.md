# Procedimento como entidade: um Processo, vários Procedimentos

> Revisa o ADR 0005 (Módulo por Procedimento).

Hoje o "Procedimento" é um item de catálogo gravado no Processo
Administrativo (um por processo), e Designação, Tarefas, Solicitações,
Anotações, Prioridades e Prazo pendem do Processo. A Subprocuradoria precisa
tratar o mesmo Processo por mais de um trâmite, cada um com seu painel, seus
responsáveis e seu prazo. Decidimos:

**Modelo**
- **Tipo de Procedimento** é o catálogo (o que hoje se chama "Procedimento"):
  mantido por Administradores, inativado e nunca excluído.
- **Procedimento** é o trâmite concreto: tem **ID próprio**, pertence a **um**
  Processo Administrativo (FK obrigatória, nunca órfão) e um Processo tem **um
  ou mais**. Registrar um Processo cria o primeiro Procedimento **na mesma
  transação**.
- **Todo Tipo tem Painel próprio** (seções e campos específicos), selecionado por
  chave estável, com dados em **tabela dedicada por Tipo**. Mantém-se a rejeição
  do construtor genérico de campos (ADR 0005). A **troca de Tipo** é permitida a
  Administrador ou Gestor, com Auditoria; os dados do painel anterior nunca são
  apagados.
- **Designação** é de **Usuário** do Sistema, com ou sem Vinculação à SGS; um ou
  mais por Procedimento; designam Administrador e Gestor.
- Tarefas, Solicitações, Anotações, Prioridades e Prazo passam ao Procedimento.

**Estados** (RN-PROC-006 a 010)
- **Inativo** e **Ativo** são calculados: Ativo = há *pendência* (Tarefa
  Pendente/Em andamento ou Solicitação Aguardando); Inativo = não há.
- **Finalizado** (objeto tratado/atendido) e **Encerrado** (sem atendimento) são
  escolhas do usuário, só permitidas **sem pendências**; são terminais e não
  aceitam Tarefa nem Solicitação. Sai-se deles por **Reabrir**, ação de
  Administrador ou Gestor, auditada.
- O Processo está **sob responsabilidade da SGS** (estado calculado, sem campo de
  Unidade) enquanto tiver Procedimento Inativo ou Ativo.

**Prazo**
- O **Prazo do Procedimento** nasce na data de autuação do Processo + 30 dias e
  pode ser substituído à mão. Tarefas e Solicitações **não o alteram**; as que
  tiverem prazo posterior a ele são **sinalizadas** (cálculo, não gravação).
- **Prazo vigente** e **Atividade** do Processo deixam de existir.

**Migração (expand/contract)**: cada Processo existente gera um Procedimento do
Tipo que já tinha; sem Tipo, do Tipo *Outros*, marcado como "migrado", com a
classificação anterior preservada. Designações, Tarefas, Solicitações,
Anotações e Prioridades passam ao Procedimento gerado.

**Consequência**: reescrevem-se as Providências, a Designação, o cálculo de
prazo, a Página do Usuário ("Meus Procedimentos") e os Indicadores ("Processos
por tipo" vira Procedimentos por Tipo). A Solicitação, que hoje não afeta a
Atividade, passa a influenciar o estado.
