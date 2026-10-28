# Migração em paralelo, com corte único após a paridade

> O nome do arquivo guarda o título original ("corte por marco"). A decisão foi
> revista em 2026-10-27: em vez de dois marcos de corte em produção, há **um só
> corte**, depois da paridade.

O sistema atual está em produção, inclusive com a API de Integração v1 na VPN
da SES-MT, e é usado todos os dias. Trocar a base técnica no lugar não teria
rota de reversão. Decidimos construir o Laravel num **repositório novo**
(`C:\SESJUR\sesjur`) ao lado do legado: a pasta `sesjur-processos` permanece
onde está, **congelada** como referência de comportamento (só correção de
defeito grave).

- Durante o desenvolvimento o Laravel aponta para uma **cópia** do banco,
  restaurada de backup. Os dois sistemas nunca escrevem no mesmo banco.
- Na Fase 1 o esquema entra **como está** (migração-base gerada de
  `database.sql`). A remodelagem do Procedimento (ADR 0010) acontece na Fase 3,
  junto com a portagem dos Processos: portar e depois remodelar duplicaria o
  trabalho.
- **Nada do Laravel vai para produção antes do corte.** Cada fase é validada em
  **homologação** (ambiente com cópia recente do banco). Os antigos "marcos"
  viram **pontos de verificação** em homologação.
- O **corte é único** e só ocorre quando tudo o que a equipe usa hoje estiver
  portado e verificado (paridade). Passos: ensaio(s) de migração com backup
  real → congelar as entradas no legado numa janela combinada → backup →
  migrações → conferência (contagens, chaves, UNIQUE) → troca de rota,
  incluindo a API v1 no mesmo endereço → legado em **somente consulta** por um
  período de segurança → desligamento.
- Do legado se preservam para sempre: o código (com uma tag final), o último
  backup do banco, os ADRs e a `Spec/`.
- Depois do corte, as funcionalidades **novas** (Agenda, Férias e Licenças,
  Planos…) entram em produção uma a uma, na ordem definida pela Subprocuradoria.
- O novo repositório passa a ser a fonte da verdade da especificação: recebe
  uma cópia de `Spec/`, do `CONTEXT.md` e dos ADRs, e reinicializa o `.specify`.

Rejeitamos: substituir no lugar (risco alto, sem reversão); compartilhar o
banco de produção entre os dois sistemas (migrações só aditivas e login
duplicado); e **cortes parciais por marco**, porque os módulos se referenciam
por chave estrangeira e a equipe teria de usar os dois sistemas ao mesmo tempo,
com um único banco.

**Consequência**: a equipe só passa a usar o Laravel no corte, e funcionalidade
nova só chega à produção depois dele. Se algo novo for urgente, escolhe-se
explicitamente entre antecipá-lo (e adiar o corte) ou aguardar. Depois do corte,
a reversão é restaurar o backup do legado; o que tiver sido criado no Laravel
nesse intervalo precisa ser exportado — o plano de corte define como.
