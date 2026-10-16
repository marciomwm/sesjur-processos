# Gestão Jurídica

Sistema interno do setor jurídico para cadastro e acompanhamento de Processos
Administrativos (SIGADOC) e Processos Judiciais (PJe), com Vínculo N:N entre
as duas frentes, Extração assistida do navegador do usuário e controle de
acesso por Perfil.

## Processos e vínculo

**Processo Administrativo**:
Processo originado no SIGADOC, identificado por um Protocolo único.
_Avoid_: processo SIGADOC, expediente

**Processo Judicial**:
Processo originado no PJe, identificado por um Número CNJ único.
_Avoid_: processo PJe, ação, autos

**Processo Extrajudicial**:
Procedimento ou expediente originado em instituição externa ao Poder Executivo
estadual (Ministério Público, Defensoria, Tribunal de Contas, conselhos…),
identificado pelo Protocolo da instituição de origem — único dentro da
Instituição Externa. Distinto do Processo Administrativo (interno) e do
Processo Judicial.
_Avoid_: procedimento externo, expediente do MP

**Instituição Externa**:
Instituição de onde vem o Processo Extrajudicial (ex.: MP-MT). Tabela de apoio
própria, separada da Instituição da estrutura interna.

**Órgão Externo**:
Órgão ou unidade interna de uma Instituição Externa (ex.: 1ª Promotoria de
Justiça de Cuiabá); nome único dentro da instituição.

**Vínculo**:
Relação N:N entre um Processo Administrativo e um Processo Judicial ou
Extrajudicial; removê-lo nunca exclui nenhum dos processos. O vínculo
extrajudicial fica no Histórico dos dois processos.
_Avoid_: relacionamento, associação

**Protocolo**:
Identificador de negócio do Processo Administrativo no SIGADOC (ex.:
`SEPLAG-PRO-2026/16696`).

**Número CNJ**:
Identificador de negócio do Processo Judicial, no formato padronizado do CNJ
com dígito verificador (ex.: `1008695-17.2026.8.11.0006`).
_Avoid_: número do processo

**Origem**:
Proveniência de um registro — `MANUAL` (digitado por um Usuário) ou extraído
da fonte oficial (`SIGADOC`/`PJE`). Registros extraídos carregam também a URL
de origem, a versão do extrator e a data da extração.
_Avoid_: fonte

**Procedimento**:
Classificação textual livre do tipo de trâmite de um Processo Administrativo
(ex.: "Fornecimento de Produto de Saúde", "Ressarcimento Interfederativo").

## Situação e histórico

**Situação** (Processo Administrativo):
Estado corrente do processo no SIGADOC, um entre um conjunto fechado de
códigos: Aguardando Andamento, Sobrestado, Arquivado, Em Trânsito, Outra.

**Tarefa** (Processo Administrativo):
Providência interna a executar, ligada a um Processo Administrativo (N por processo),
com Descrição, Prazo, Status e Observação. Status: Pendente, Em andamento,
Concluída ou Cancelada — as duas últimas são **finalizadas**. Qualquer Usuário
cria uma Tarefa; só Administrador, Gestor ou quem a criou a altera ou exclui.
_Avoid_: ação (nome antigo; o código, as rotas e o banco ainda usam `acao`), atividade (reservado à Atividade do processo)

**Solicitação** (Processo Administrativo):
Pedido enviado a outra unidade (uma Unidade Administrativa, o **destinatário**) por um
**expediente** do SIGADOC, ligado a um Processo Administrativo (um processo
tem N solicitações), com Data, Assunto, Prazo opcional e Status manual:
Aguardando, Atendido ou Encerrado. **Vencida** é a que segue Aguardando depois
do Prazo. Ao contrário da Tarefa, a Solicitação não afeta o Prazo vigente nem a
Atividade do processo. Mesma regra de alteração da Tarefa: qualquer Usuário
cria; só Administrador, Gestor ou quem a criou altera ou exclui.
_Avoid_: ofício, pedido, requisição

**Anotação** (Processo Administrativo):
Nota livre e interna da equipe sobre um Processo Administrativo (um processo
tem N anotações), com autor e data. Mesma regra de alteração da Tarefa.
_Avoid_: observação (campo da Tarefa), comentário; não confundir com o Evento
do tipo "Anotação" importado do SIGADOC, que é histórico da fonte e não é
editado no sistema

**Expediente**:
Documento (ofício, comunicação interna, despacho…) redigido no Gerador de
Expedientes a partir de um modelo e vinculado a um Processo Administrativo,
cujos atributos — e os dos processos vinculados a ele — preenchem as
Propriedades do texto. Não é gravado no sistema: é exportado ou copiado para
o SIGADOC.
_Avoid_: usar "expediente" para o Processo Administrativo

**Propriedade** (Expediente):
Variável do texto de um Expediente, escrita como `{{TOKEN}}`. As do
**catálogo** são atributos do Processo Administrativo e dos Processos
Judiciais e Extrajudiciais vinculados; as demais são criadas pelo Usuário.

**Modelo** (Expediente):
Ponto de partida de um Expediente — texto, formatação e Propriedades —,
gravado no sistema. Qualquer Usuário cria os seus. **Individual** (padrão):
só quem o criou vê e usa. **Compartilhado**: todos os Usuários veem e usam;
só Administrador ou Gestor o cria, altera, exclui ou muda a visibilidade — os
demais podem duplicá-lo (a cópia é Individual). Favorito e contagem de uso
são de cada Usuário.
_Avoid_: template, modelo público

**Providências**:
Guia da ficha do Processo Administrativo que reúne Tarefas, Solicitações e
Anotações — tudo o que a equipe registra e acompanha no sistema, em oposição
ao que é espelhado da fonte (Eventos, na guia Movimentações).

**Providência rápida**:
Tarefa, Solicitação ou Anotação registrada pela barra "Registrar" da ficha, num
modal, sem sair do processo. É a mesma entidade da página de cadastro; o
processo, o autor, a data/hora e o tipo nunca são digitados.

**Prioridade**:
Critério que pede atenção preferencial ao Processo Administrativo (ex.: Idoso,
Determinação Judicial), com Nível de 1 (baixa) a 5 (máxima). Um processo tem
nenhuma, uma ou várias, sem repetir. Tabela de apoio: inativada, nunca excluída.
_Avoid_: urgência, prioridade como sinalizador tri-estado (esse é o do Processo
Judicial, lido do PJe)

**Produto de Saúde**:
Objeto material da demanda do Processo Administrativo — medicamento,
procedimento, exame, órtese, transporte sanitário… —, com Categoria, Descrição
e Classificação SIGTAP opcional. Um processo tem N produtos, sem repetir o item.
_Avoid_: item, insumo (Insumo é uma das categorias)

**Classificação SIGTAP**:
Procedimento da Tabela SIGTAP do SUS (código de 10 dígitos, com grupo,
subgrupo e forma de organização). Enquanto a tabela oficial não é importada,
o código é cadastrado manualmente na primeira vez e reaproveitado depois.

**Histórico** (Processo Administrativo):
Guia com a trilha de Auditoria do processo — providências, prioridades,
produtos e vínculos extrajudiciais. Não confundir com Movimentações (Eventos
do SIGADOC).

**Prazo** (Processo Administrativo):
Data com hora opcional (sem hora, vale até o fim do dia). Por padrão, a data
de abertura + 30 dias.

**Prazo vigente**:
O prazo que vale para o Processo Administrativo: o mais próximo do vencimento
entre o Prazo do próprio processo e os prazos das Tarefas ainda não
finalizadas. Calculado pelo sistema a cada alteração do processo ou das tarefas.

**Atividade**:
Indicador calculado do Processo Administrativo: **ativo** enquanto houver
Tarefa não finalizada; **inativo** sem tarefas ou com todas finalizadas. Nunca é
editado à mão.
_Avoid_: status, situação (Situação é o estado no SIGADOC)

**Evento** (Processo Administrativo):
Entrada do histórico de movimentação do Processo Administrativo (juntada,
anotação, transferência, recebimento, sobrestamento...), espelhando o
SIGADOC; a lista inteira é substituída a cada Extração confirmada.
_Avoid_: movimentação (reservado ao Processo Judicial)

**Movimentação** (Processo Judicial):
Entrada da cronologia do Processo Judicial — um andamento processual ou um
documento juntado — espelhando a timeline do PJe; substituída por inteiro a
cada Extração confirmada.
_Avoid_: evento (reservado ao Processo Administrativo), andamento

**Documento Judicial**:
Peça juntada a um Processo Judicial, identificada por número próprio, com o
texto integral quando obtido. Ao contrário da Movimentação, é atualizada por
upsert (nunca substituída em bloco) e pode ser Decisória — sentença, decisão,
acórdão, despacho ou embargos — ou não.

**Histórico Completo**:
Indicador de que a cronologia importada de um Processo Judicial cobre a
timeline inteira da fonte, e não apenas a primeira página exibida (o PJe pagina
a cronologia).

**Parte Processual**:
Pessoa ou organização ligada a um Processo Judicial, associada a um Polo.

**Polo**:
Posição de uma Parte Processual no Processo Judicial — Ativo, Passivo,
Terceiro ou Outro.

**Assunto Processual**:
Matéria tratada por um Processo Judicial, normalizada em registro próprio: um
Principal e os demais Secundários.

**Sinalizador tri-estado**:
Campo booleano de um Processo Judicial (Segredo de Justiça, Justiça Gratuita,
Tutela ou Liminar, Prioridade) que admite um terceiro valor — "não informado
pela fonte" — para nunca afirmar "não" quando o dado simplesmente não estava
na página de origem.

## Bloqueios e depósitos judiciais

**Lançamento de bloqueio**:
Registro, num Processo Judicial, de um **Bloqueio** (valor constrito nas
contas do Estado por ordem judicial) ou de um **Desbloqueio** (valor liberado),
com data, valor, identificador opcional (protocolo SISBAJUD, ordem, conta) e
observação. Qualquer Usuário lança; só Administrador, Gestor ou quem lançou
altera ou exclui.
_Avoid_: movimentação (reservado à cronologia do PJe), constrição, penhora

**Saldo retido**:
Soma dos bloqueios menos a dos desbloqueios de um Processo Judicial, numa
data. Nunca fica negativo em ordem cronológica — não se desbloqueia o que
ainda não foi bloqueado (no mesmo dia, o bloqueio conta antes). É calculado,
nunca gravado.

**Guia de Depósito Judicial**:
Guia emitida para depósito de valores em juízo num Processo Judicial, com
número, emissão, vencimento opcional, valor, finalidade e Status manual:
Emitida, Paga (com a data do pagamento) ou Cancelada. **Vencida** é a que
segue Emitida depois do vencimento. Mesma regra de alteração do Lançamento.
_Avoid_: boleto, DJE

Um Processo Judicial com lançamentos ou guias não pode ser excluído: os
registros financeiros nunca somem em cascata.

## Intimações pessoais

**Intimação Pessoal**:
Intimação dirigida a um Agente Público (a **pessoa intimada**) num processo já
cadastrado — judicial, extrajudicial ou administrativo, sempre exatamente um,
por chave estrangeira. Registra quem a **recebeu** (outro Agente Público, que
pode ser a própria pessoa intimada), a data do recebimento, a **data do
prazo** (data-limite informada; o sistema não calcula prazos processuais) e,
quando cumprida, a **data do cumprimento** (a da providência, não a do
registro). Quem registrou no sistema fica à parte, na autoria. Um processo e
um agente podem ter várias intimações; a mesma combinação de processo,
intimado, recebimento e prazo só é gravada com confirmação (possível
duplicidade). Retificar o prazo exige justificativa. Restritas aos Perfis
Administrador e Gestor: só eles veem o painel e as intimações (inclusive nas
fichas dos processos), registram, alteram, registram o cumprimento ou
excluem — exclusão lógica, com motivo, preservando o histórico.
Um processo com intimações (mesmo excluídas) não pode ser excluído.
_Avoid_: notificação, citação, comunicação

**Status** (Intimação Pessoal):
Estado operacional informado pelo Usuário: Pendente, Em andamento, Cumprida
(exige a data do cumprimento) ou Cancelada.

**Situação do prazo** (Intimação Pessoal):
Calculada, nunca gravada nem confundida com o Status: **Dentro do prazo**,
**Próxima do vencimento** (prazo em até 3 dias, inclusive hoje), **Vencida**
(prazo passado, ainda Pendente ou Em andamento), **Cumprida no prazo** ou
**Cumprida fora do prazo** (cumprimento depois do prazo). A Cancelada não tem
situação.

## Extração assistida

**Extração**:
Leitura automática dos campos de um processo a partir do DOM da página do
sistema de origem (SIGADOC ou PJe), executada pela extensão do navegador na
aba já autenticada do próprio Usuário.
_Avoid_: importação, scraping, raspagem, sincronização

**Confirmação humana**:
Exigência de que todo dado extraído seja revisado e salvo deliberadamente pelo
Usuário antes de ser gravado. A Extração preenche o formulário; nunca grava
sozinha.

**Domínio Autorizado**:
Host do SIGADOC ou do PJe que o Usuário concedeu explicitamente à extensão do
navegador (allowlist); sem essa concessão, nenhuma Extração é tentada naquele
host.
_Avoid_: allowlist (nome técnico do mecanismo; Domínio Autorizado é o conceito)

**Criação pela extensão**:
Atalho da extensão (menu do botão direito ou popup) que, na página de um
processo do SIGADOC ou do PJe — ou com um número selecionado nela —, abre o
cadastro no sistema já com o identificador e dispara a Extração normal. Segue
a Confirmação humana: nada é gravado sem o Usuário salvar.

**Identificador Divergente**:
Falha de Extração em que o identificador (Protocolo ou Número CNJ) lido na
página não corresponde ao informado pelo Usuário; interrompe a operação sem
preencher nenhum campo.

## Pessoas e acesso

**Agente Público**:
Pessoa com vínculo funcional ou profissional com a Administração (efetivo,
comissionado, estagiário, terceirizado, cedido…), lotada numa Unidade
Administrativa — o órgão/entidade é a Instituição da unidade. É a base dos
Usuários, mas nem todo agente tem acesso ao sistema. Nunca é excluído: o
desligamento é a situação Desligado, que também inativa a conta.
_Avoid_: servidor (é só um dos tipos de vínculo), pessoa

**Usuário**:
Credencial de acesso de um Agente Público: login (e-mail de acesso), Perfil e
status da conta. Um agente tem no máximo um usuário, e todo usuário pertence a
um agente; a conta é criada a partir da ficha do agente. Os dados pessoais e
funcionais exibidos no usuário são os do agente (cópia sincronizada). Nunca é
excluído fisicamente — o desligamento é uma inativação, preservando a autoria
de registros e o histórico.
_Avoid_: funcionário, servidor, conta, colaborador

**Cargo**:
Tabela de apoio com a função exercida pelo Usuário. Referenciada por chave
estrangeira — nunca texto livre — para evitar grafias divergentes; itens
obsoletos são inativados, nunca excluídos, e continuam válidos para quem já os
possui (regra comum a todas as tabelas de apoio).

## Estrutura organizacional

**Unidade Administrativa**:
Unidade da estrutura de uma Instituição (gabinete, superintendência,
coordenadoria...), com Nível, Unidade Superior, Nome, Sigla do SIGADOC,
Gestor, Lotacionograma e Competências. É também a **lotação** do Usuário e o
destinatário da Solicitação. O nome é único dentro da Instituição. Nunca é
excluída, só inativada.
_Avoid_: lotação como nome da entidade (termo antigo; hoje só "a unidade em
que o usuário está lotado"), setor, órgão

**Instituição**:
Órgão ou entidade a que a Unidade Administrativa pertence (ex.: SES-MT,
PGE-MT). Tabela de apoio.

**Nível** (organizacional):
Posição da Unidade Administrativa na estrutura da Instituição (ex.: "Nível I -
Decisão Colegiada"). Lista comum a todas as Instituições; um nível novo pode
ser criado direto no cadastro da unidade.

**Unidade Superior**:
Unidade Administrativa imediatamente acima na hierarquia, sempre da mesma
Instituição; a hierarquia nunca forma ciclo. Sem Unidade Superior, a unidade é
o topo da estrutura.

**Gestor da Unidade**:
Servidor responsável pela Unidade Administrativa, digitado na própria unidade
(nome e matrícula) — não precisa ser Usuário do sistema.

**Lotacionograma**:
Relação de servidores lotados na Unidade Administrativa (nome, matrícula,
cargo ou função), digitada na própria unidade e independente do cadastro de
Usuários. Não confundir com os Usuários cuja lotação é a unidade.

**Competências**:
Atribuições da Unidade Administrativa conforme o regimento interno da
Instituição, em texto livre.

**Visualizações da estrutura**:
As três formas de ver as Unidades Administrativas sobre os mesmos dados:
**Lista** (árvore recolhível), **Hierarquia** (organograma) e **Nível** (uma
faixa por Nível organizacional, agrupada pela Unidade Superior, com conectores).
**Focar** uma unidade mostra só ela e o que está abaixo.

## Central de Recursos

**Recurso**:
Link para um site, sistema, documento, planilha ou painel usado pela equipe,
com Título, URL (sempre http/https), Descrição, uma Pasta e nenhuma, uma ou
várias Áreas. Qualquer Usuário cadastra; só Administrador, Gestor ou quem o
cadastrou altera ou exclui.
_Avoid_: link, favorito, atalho

**Pasta** (Recurso):
Tipo do Recurso (Sistemas, Sites, Documentos…); cada Recurso fica em uma só.
Tabela de apoio mantida por Administradores e Gestores.

**Área** (Recurso):
Categoria do Recurso (Judicial, Consultiva…); um Recurso pode estar em várias
ou em nenhuma ("Sem área definida"). Tabela de apoio mantida por
Administradores e Gestores.

**Perfil**:
Nível de acesso do Usuário: Administrador (tudo, inclusive usuários, perfis e
configurações), Gestor (processos e Designação) ou Usuário (acompanha
processos; não designa).
_Avoid_: role, papel, permissão, cargo (Cargo é a função funcional, não o
nível de acesso)

**Designação**:
Atribuição de um Processo Administrativo a um Usuário para acompanhamento
(o Processo Judicial não tem Designação). Só um Usuário com Perfil
Administrador ou Gestor cria ou remove uma Designação.
_Avoid_: atribuição

**Responsável**:
Agente público indicado pelo próprio SIGADOC como detentor do Processo
Administrativo no momento da Extração — um dado importado da fonte, distinto
da Designação interna do sistema.

**Credencial**:
Senha de acesso de um Usuário, mantida em registro próprio separado do
cadastro, para que o hash nunca apareça em consultas de listagem. Uma senha
definida pelo Administrador é sempre provisória e exige troca no próximo
login.

**Auditoria**:
Registro append-only de quem fez o quê, quando e em qual entidade; nunca é
editado nem apagado.
