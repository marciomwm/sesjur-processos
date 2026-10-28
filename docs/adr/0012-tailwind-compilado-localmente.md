# Tailwind CSS compilado localmente, sem CDN

> **Status:** em vigor. Substitui a rejeição do Tailwind no ADR 0006 e a
> decisão de CSS sem framework do ADR 0004, nos pontos abaixo. Continuam valendo
> a regra de execução sem dependência externa e a ausência de CDN.

Até aqui o CSS é escrito à mão (ADR 0004, `public/assets/css/app.css`). Com o
Laravel e o Vite entrando (ADR 0006), a manutenção de um CSS próprio cresce a
cada Dimensão, e a equipe quer usar o **Tailwind CSS** para compor a interface.

Decidimos adotar o Tailwind **compilado localmente**, dentro do build do Vite:

- O Tailwind entra como dependência de **build** (npm), com o plugin oficial
  para Vite. A versão fica fixada no `package.json` e é atualizada de forma
  deliberada, como as demais dependências de build.
- O CSS é gerado em `public/build/` durante o build e servido como arquivo
  estático, pelo mesmo caminho dos demais assets. **Nenhum CSS, fonte ou script
  do Tailwind é carregado de CDN** (sem `cdn.tailwindcss.com`, sem Google Fonts
  para este fim). O sistema continua funcionando em rede interna sem internet.
- O Tailwind precisa **ler os templates de todas as Dimensões** (ADR 0011): a
  varredura de classes cobre `principal/`, `administrativa/`, `juridica/`,
  `configuracoes-gerais/` e o Núcleo. Uma Dimensão nova entra na varredura pelo
  mesmo carregador que descobre as demais, sem editar o CSS dela à parte.
- Não há reescrita de telas que já funcionam. O `app.css` existente é portado
  aos poucos, junto com cada Módulo, na migração em paralelo (ADR 0007). Enquanto
  isso, Tailwind e o CSS próprio convivem, e o CSS próprio é removido quando o
  Módulo que o usa for portado.
- O reset base do Tailwind (*preflight*) pode alterar estilos herdados. Ele é
  verificado em homologação, tela a tela, antes de ser aceito.

Rejeitamos: **Tailwind via CDN** (viola a regra de execução sem dependência
externa e quebra a operação em rede interna); **manter só o CSS próprio**, que
faz a equipe manter componentes utilitários à mão sem ganho de consistência; e
**Bootstrap**, que não resolve a questão de forma diferente e continua exigindo
um CSS de sobrescrita.

**Consequência**: passamos a depender de uma ferramenta de build para o CSS e a
acompanhar a versão do Tailwind, como já acontece com o Laravel e o Vite. O
risco de regressão visual fica concentrado no reset base, e por isso a validação
é feita em homologação antes do corte.
