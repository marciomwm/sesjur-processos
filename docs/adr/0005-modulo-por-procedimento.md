# Módulo por Procedimento, selecionado por chave estável

Cada Procedimento do catálogo pode ter um painel e campos próprios (Pagamento
Indenizatório, Demanda de Saúde, Aquisição Pública…). Hoje o Pagamento
Indenizatório se ativa comparando o **nome** do procedimento com uma constante
no código, o que deixa o painel inoperante se um administrador renomear o item.
Decidimos acrescentar a `procedimentos` uma coluna `modulo` com uma chave
estável (ex.: `DEMANDA_SAUDE`); o código mapeia a chave para a tabela, o
formulário e o painel do módulo, e os dados específicos ficam em tabela
dedicada por módulo. Procedimentos sem módulo guardam só os dados comuns.

Rejeitamos o construtor genérico de campos configuráveis no banco: custa caro,
valida mal e dificulta relatórios. Migrar o Pagamento Indenizatório para a
chave é um passo separado, com backup e conciliação.
