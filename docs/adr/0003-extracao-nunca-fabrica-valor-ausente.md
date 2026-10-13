# Campo ausente na extração vira null + warning, nunca um valor inventado

As páginas do SIGADOC e do PJe têm estrutura variável (rótulos ausentes,
tabelas com layout horizontal ou vertical, reordenação de campos). Um extrator
"prestativo" poderia tentar inferir um valor plausível quando não encontra o
campo esperado. Decidimos o oposto: escalar não encontrado vira `null`,
coleção ausente vira `[]`, e a ausência sempre gera um `warning` explícito para
preenchimento manual — nunca um valor fabricado ou deduzido por heurística não
confirmada. Um dado errado num cadastro jurídico é pior do que um campo em
branco pedindo revisão.
