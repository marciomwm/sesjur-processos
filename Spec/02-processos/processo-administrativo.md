# Processo Administrativo

Processo originado no **SIGADOC**, identificado por **Protocolo** único (ex.: `SEPLAG-PRO-2026/16696`). É a entidade central do sistema: a ela se ligam Vínculos, Providências, Prioridades, Produtos de Saúde, Designações, Eventos e (conforme o Procedimento) módulos específicos.

## Atributos

| Atributo | Regra |
|---|---|
| Protocolo | Obrigatório, formato validado (`ProtocoloValidator`), **UNIQUE** |
| Data de abertura | Data; base do Prazo padrão |
| Objeto | Texto do objeto do processo |
| Procedimento | FK para o catálogo (ver abaixo); obrigatório em novos cadastros enquanto houver item ativo; não pode ser removido de quem já tem |
| Classificação anterior | Texto livre legado, preservado e exibido como histórico; nunca editado |
| Situação | Código fechado: `AGUARDANDO_ANDAMENTO`, `SOBRESTADO`, `ARQUIVADO`, `EM_TRANSITO`, `OUTRA` + descrição original |
| Responsável | Dado importado do SIGADOC (nome, matrícula, lotação) — ≠ Designação |
| Prazo | Data + hora opcional; padrão = abertura + 30 dias (sem abertura, hoje + 30) |
| Prazo vigente / Atividade | Calculados (ver [prazo-e-atividade.md](../03-providencias/prazo-e-atividade.md)) |
| Origem e proveniência | `MANUAL`/`SIGADOC`, URL de origem, versão do extrator, data da extração |
| Observações internas | Texto livre |

## Procedimento (catálogo)

- **RN-PA-001** Itens do catálogo são mantidos **só por Administradores** (Configurações › Procedimentos).
- **RN-PA-002** Usuário escolhe apenas itens **ativos**; não cria procedimento no formulário.
- **RN-PA-003** Nome único sem distinção de caixa, acentos e espaços repetidos; inativado, nunca excluído.
- **RN-PA-004** Quem já possui um item inativo continua válido e o vê na edição sem ser substituído.
- **RN-PA-005** Conciliação do legado: `database/conciliar-procedimentos.php` lista a Classificação anterior e, com `--aplicar`, vincula **apenas correspondência exata** (ignorando caixa, acentos, espaços repetidos).
- **RN-PA-006** `procedimentos.modulo` (chave estável) ativa painel específico — ver [pagamento-indenizatorio.md](../04-modulos/pagamento-indenizatorio.md) e [demanda-saude.md](../04-modulos/demanda-saude.md).

## Funcionalidades

### Listagem
- Busca por protocolo, objeto, procedimento, responsável.
- Filtros: atividade (ativo/inativo), prazo (vencidos / próximos 7 dias), situação, procedimento.
- Ordenação pelo **prazo vigente**, com allowlist de colunas; paginação.

### Ficha
Guias: **Informações · Providências · Judiciais · Extrajudiciais · Produtos de Saúde · Prioridades · Movimentações · Histórico** (mais painéis de módulo quando o Procedimento os tem — ex.: Dados do Pagamento).

- **Barra "Registrar"** (rodapé no celular): abre modais de Nova Tarefa, Nova Solicitação e Nova Anotação com os mesmos campos das páginas de cadastro; processo, autor, data/hora e tipo não são digitados.
- **Chips de Prioridade** no cabeçalho.
- **Gerar expediente** abre o Gerador já com o processo ([../06-expedientes/gerador-de-expedientes.md](../06-expedientes/gerador-de-expedientes.md)).
- **Termo de registro** do processo (`termo-registro-processo-administrativo.php`).
- **Movimentações** = Eventos importados do SIGADOC (somente leitura).
- **Histórico** = trilha de Auditoria (providências, prioridades, produtos, vínculos extrajudiciais) — não confundir com Movimentações.

### Cadastro/edição
- Informar Protocolo e acionar **Completar cadastro pelo SIGADOC** (extração assistida) ou preencher manualmente.
- O formulário mostra, em destaque (verde), os campos vindos da fonte externa.
- Se o Protocolo já existe, mostra o link da ficha em vez de importar.
- Widget de **Vínculos** com Judiciais e Extrajudiciais (autocomplete).
- Prazo acompanha a data de abertura enquanto ainda for o padrão.

### Exclusão
- Confirmação com aviso: remove o processo e seus vínculos, **não** remove os processos vinculados.
- Exclusão física com o registro completo na Auditoria.

## Eventos (espelho do SIGADOC)

- **RN-PA-010** A lista de Eventos é **substituída por inteiro** a cada Extração confirmada.
- **RN-PA-011** Cada Evento: data, tipo (`JUNTADA`, `ANOTACAO`, `TRANSFERENCIA`, `RECEBIMENTO`, `SOBRESTAR`, `DESSOBRESTAR`, `APENSACAO`, `DESENTRANHAMENTO`, `CRIACAO`…), lotação, responsável, atendente, documento, descrição.
- **RN-PA-012** `historicoCompleto=false` sinaliza "histórico parcial" (quando a visão completa "Ver Mais" falhou).

## Critérios de aceitação

- **CA-PA-01** Cadastrar com Protocolo repetido é recusado com mensagem clara (UNIQUE + pré-checagem).
- **CA-PA-02** Prazo em branco resulta em abertura + 30 dias.
- **CA-PA-03** Procedimento é exigido no novo cadastro enquanto existir item ativo.
- **CA-PA-04** Excluir o processo não exclui Judiciais/Extrajudiciais vinculados.
- **CA-PA-05** Após Extração confirmada, Eventos antigos desaparecem e os novos refletem o SIGADOC.
- **CA-PA-06** Alterar o processo ou suas Tarefas recalcula Prazo vigente e Atividade na mesma transação.

## Situação

✅ Implementado. Documentos relacionados, classificação documental e card "Documento Interno Produzido" do SIGADOC seguem em `_futuros` no extrator.
