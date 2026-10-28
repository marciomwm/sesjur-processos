# Navegação em seis níveis e registro de funcionalidades

> **Atualização (ADR 0011):** o registro de funcionalidades não é um arquivo
> único; é **composto pelos manifestos** `dimensao.php` de cada Dimensão.

O SESJUR passa a ter quatro **Dimensões** — Principal, Administrativa, Jurídica
e Configurações Gerais — e será entregue aos poucos, conforme a necessidade dos
administradores e gestores. Precisávamos de um modelo de navegação que
comportasse isso sem refazer o menu a cada entrega. Decidimos:

- Hierarquia fixa: **Dimensão › Módulo › Página › Subpágina › Guia › Seção**.
  Exemplo: *Jurídica › Procedimentos › Comunicação Interinstitucional ›
  Instituição Interessada*. O menu lateral mostra até a Subpágina; Guias e
  Seções pertencem à própria tela.
- **Funcionalidade** não é um nível: é uma **ação** que pode ser acrescentada
  em qualquer ponto (Página ou outro nível), como gerar expediente ou reabrir.
- Um **registro de funcionalidades** é a fonte do menu. Cada item guarda
  Dimensão, Módulo, Página, Subpágina, rota, permissão exigida e **estado**
  (`planejada` ou `ativa`); as ações também são registradas, com o ponto em que
  aparecem. Item `planejada` fica oculto para Usuário e Gestor e aparece como
  "em breve" para o Administrador: é o mecanismo da implementação gradativa.
- O menu é conveniência. **A autorização é imposta no servidor** (Policy ou
  serviço), nunca só pela ausência do link.
- Dados compartilhados (Agente Público, Instituição, Processo, Procedimento)
  são referenciados por ID, nunca duplicados entre Dimensões.

**Consequência**: "Módulo" passa a ser um nível de navegação. O que o ADR 0005
chamava de *Módulo do Procedimento* (painel e campos próprios) passa a se
chamar **Painel do Tipo** (ADR 0010); a coluna `procedimentos.modulo` pode
manter o nome por compatibilidade.
