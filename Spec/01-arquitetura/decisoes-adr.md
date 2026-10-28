# Decisões arquiteturais (ADRs)

Os registros completos estão em `docs/adr/`. Este índice resume o que cada um decidiu e o que muda para quem desenvolve.

| ADR | Decisão | Consequência principal |
|---|---|---|
| [0001](../../docs/adr/0001-extracao-no-navegador-nunca-no-servidor.md) | Extração roda no navegador do usuário, nunca no servidor | Sem SSRF; sem manipular sessão de terceiros no backend |
| [0002](../../docs/adr/0002-ponte-postmessage-restrita-a-localhost.md) | Ponte página↔extensão por `postMessage` restrito a localhost | Aplicação precisa ser servida em localhost/127.0.0.1; mudar de host exige revisar permissão estática |
| [0003](../../docs/adr/0003-extracao-nunca-fabrica-valor-ausente.md) | Campo ausente = `null` + `warning`, nunca valor inventado | Campo em branco para revisão manual é preferível a dado errado |
| [0004](../../docs/adr/0004-css-proprio-sem-framework.md) | CSS próprio, sem framework/CDN | Zero dependência externa; custo de manter o CSS |
| [0005](../../docs/adr/0005-modulo-por-procedimento.md) | Módulo por Procedimento via chave estável (`procedimentos.modulo`) | Painéis específicos não quebram com renomeação; dados do módulo em tabela dedicada |

### Reestruturação (2026-10-27)

| ADR | Decisão | Consequência principal |
|---|---|---|
| [0006](../../docs/adr/0006-laravel-como-framework.md) | Laravel como framework; CSS próprio e dependências locais mantidos (reafirma o 0004) | Etapa de build local; Controller → Service → Eloquent, sem Repository; auditoria e token da API próprios |
| [0007](../../docs/adr/0007-migracao-em-paralelo-e-corte-por-marco.md) | Repositório novo (`C:\SESJUR\sesjur`), legado congelado, **corte único após a paridade**, homologação contínua | Nada do Laravel em produção antes do corte; remodelagem do Procedimento na Fase 3 |
| [0008](../../docs/adr/0008-navegacao-em-seis-niveis-e-registro-de-funcionalidades.md) | Dimensão › Módulo › Página › Subpágina › Guia › Seção; Funcionalidade é ação; registro de funcionalidades | "Módulo" vira nível de navegação; o antigo módulo do Procedimento passa a *Painel do Tipo* |
| [0009](../../docs/adr/0009-hosts-aceitos-e-ponte-com-a-extensao.md) | Ponte aceita 4 hosts; extensão instalada à mão com aviso de versão (**substitui o 0002 na lista de hosts**) | Cada máquina atualiza a extensão para usar a produção |
| [0010](../../docs/adr/0010-procedimento-como-entidade.md) | Procedimento como entidade (1 Processo : N), Tipo, estados, Prazo próprio (**revisa o 0005**) | Providências, Designação e Prazo migram para o Procedimento; Prazo vigente e Atividade deixam de existir |
| [0011](../../docs/adr/0011-dimensoes-como-modulos-independentes.md) | Dimensões como módulos independentes: pasta por Dimensão na raiz, manifesto, Núcleo, Principal = Home | Dependência só Dimensão → Núcleo (verificada por teste); criar Dimensão/Módulo/Página toca só a pasta da Dimensão |

## Alternativas rejeitadas (resumo)

- **Área de transferência** como canal com a extensão: depende de gesto, sem estrutura, sem garantia de origem.
- **`externally_connectable`**: exigiria fixar o ID da extensão no PHP (muda por máquina em instalação sem compactação).
- **Servidor HTTP dentro da extensão**: inviável em MV3 e amplia a superfície de ataque.
- **Construtor genérico de campos configuráveis** para módulos de Procedimento: caro, valida mal, dificulta relatórios.
- **Content scripts por sistema** (`sigadoc.js`/`pje.js`): domínios não são conhecidos de antemão; adotado coletor único injetado sob demanda, com separação por sistema nos **extratores**.

## Quando criar um novo ADR

Registrar quando a decisão (1) é difícil de reverter, (2) seria surpreendente sem contexto e (3) resultou de uma escolha real entre alternativas.
