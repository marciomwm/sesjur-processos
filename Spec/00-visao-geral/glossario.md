# Glossário do domínio

Resumo navegável do `CONTEXT.md` (a fonte completa e canônica). Termos entre _Avoid_ não devem aparecer em telas, código novo ou documentos.

## Processos e vínculo

| Termo | Definição | Evitar |
|---|---|---|
| Processo Administrativo | Processo do SIGADOC, identificado por **Protocolo** único | processo SIGADOC, expediente |
| Processo Judicial | Processo do PJe, identificado por **Número CNJ** único | processo PJe, ação, autos |
| Processo Extrajudicial | Procedimento de instituição externa (MP, Defensoria, TCE, conselhos); Protocolo único **dentro** da Instituição Externa | procedimento externo |
| Instituição Externa / Órgão Externo | Origem do extrajudicial e sua unidade interna (nome único dentro da instituição) | — |
| Vínculo | Relação N:N Administrativo ↔ Judicial/Extrajudicial; removê-lo nunca exclui processos | relacionamento, associação |
| Protocolo | Ex.: `SEPLAG-PRO-2026/16696` | — |
| Número CNJ | Formato CNJ com dígito verificador, ex.: `1008695-17.2026.8.11.0006` | número do processo |
| Origem | `MANUAL`, `SIGADOC` ou `PJE`; extraídos guardam URL, versão do extrator e data | fonte |
| Procedimento | Tipo de trâmite do Processo Administrativo, de catálogo mantido por Administradores | objeto, produto |
| Classificação anterior | Texto livre pré-catálogo, preservado como histórico | — |

## Situação, histórico e providências

| Termo | Definição | Evitar |
|---|---|---|
| Situação | Estado no SIGADOC: Aguardando Andamento, Sobrestado, Arquivado, Em Trânsito, Outra | — |
| Tarefa | Providência interna do processo (Pendente, Em andamento, Concluída, Cancelada) | ação\*, atividade |
| Solicitação | Pedido a outra Unidade Administrativa por expediente do SIGADOC (Aguardando, Atendido, Encerrado) | ofício, pedido |
| Anotação | Nota interna da equipe sobre o processo | observação, comentário |
| Providências | Guia da ficha: Tarefas + Solicitações + Anotações | — |
| Providência rápida | Registro pela barra "Registrar", em modal, sem sair da ficha | — |
| Expediente | Documento redigido no Gerador; não é gravado | usar para o Processo Administrativo |
| Propriedade / Modelo | Variável `{{TOKEN}}` do texto / ponto de partida do Expediente | template |
| Prioridade | Critério de atenção preferencial, nível 1–5 | urgência |
| Produto de Saúde | Objeto material da demanda (medicamento, exame, órtese…) | item, insumo |
| Prazo / Prazo vigente | Data (hora opcional) / o mais próximo entre o do processo e o das Tarefas não finalizadas | — |
| Atividade | Calculada: ativo se houver Tarefa não finalizada | status, situação |
| Evento | Entrada do histórico do Administrativo, espelhando o SIGADOC | movimentação |
| Movimentação | Entrada da cronologia do Judicial, espelhando o PJe | evento, andamento |
| Documento Judicial | Peça juntada ao Judicial, com número próprio; atualizada por upsert | — |
| Histórico Completo | Indicador de que a cronologia cobre toda a timeline da fonte | — |
| Parte Processual / Polo | Pessoa ligada ao Judicial / posição: Ativo, Passivo, Terceiro, Outro | — |
| Sinalizador tri-estado | Booleano com 3º valor "não informado pela fonte" | — |

\* "ação" é o nome antigo da Tarefa; código, rotas e banco ainda usam `acao`.

## Bloqueios, depósitos e intimações

| Termo | Definição |
|---|---|
| Lançamento de bloqueio | Bloqueio ou Desbloqueio num Processo Judicial |
| Saldo retido | Σ bloqueios − Σ desbloqueios, calculado, nunca negativo em ordem cronológica |
| Guia de Depósito Judicial | Guia para depósito em juízo (Emitida, Paga, Cancelada; "Vencida" é derivada) |
| Intimação Pessoal | Intimação a um Agente Público em um processo; restrita a Administrador e Gestor |
| Situação do prazo | Calculada: Dentro do prazo, Próxima do vencimento (≤ 3 dias), Vencida, Cumprida no prazo, Cumprida fora do prazo |

## Extração

| Termo | Definição |
|---|---|
| Extração | Leitura do DOM da página de origem, pela extensão, na aba autenticada do usuário |
| Confirmação humana | Todo dado extraído é revisado e salvo deliberadamente pelo usuário |
| Domínio Autorizado | Host do SIGADOC/PJe concedido explicitamente à extensão |
| Criação pela extensão | Atalho que abre o cadastro já com o identificador e dispara a Extração |
| Identificador Divergente | Falha: identificador lido ≠ informado; nada é preenchido |

## Pessoas e acesso

| Termo | Definição |
|---|---|
| Agente Público | Pessoa com vínculo com a Administração, lotada numa Unidade Administrativa; nunca excluída (situação Desligado) |
| Usuário | Credencial de um Agente Público (login, Perfil, status); nunca excluído fisicamente |
| Cargo | Tabela de apoio da função exercida |
| Perfil | Administrador, Gestor ou Usuário |
| Designação | Atribuição de um Processo Administrativo a um Usuário (só Admin/Gestor criam ou removem) |
| Responsável | Agente indicado pelo SIGADOC como detentor, importado da fonte (≠ Designação) |
| Credencial | Senha em registro separado; senha definida por Administrador é provisória |
| Auditoria | Registro append-only de quem fez o quê, quando, em qual entidade |

## Estrutura organizacional e recursos

| Termo | Definição |
|---|---|
| Unidade Administrativa | Unidade da estrutura de uma Instituição; também lotação do Usuário e destinatário da Solicitação; nunca excluída |
| Instituição, Nível, Unidade Superior | Órgão a que pertence a unidade; posição na estrutura; unidade imediatamente acima (sem ciclos) |
| Gestor da Unidade, Lotacionograma, Competências | Responsável (nome/matrícula), relação de servidores lotados, atribuições regimentais |
| Visualizações da estrutura | Lista (árvore), Hierarquia (organograma), Nível (faixas) |
| Recurso, Pasta, Área | Link de uso da equipe; seu tipo; sua categoria (N por recurso) |
| Configurações do Sistema | Área de Administradores: Usuários, Extensão, Procedimentos, Prioridades |
