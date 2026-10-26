# Decisões arquiteturais (ADRs)

Os registros completos estão em `docs/adr/`. Este índice resume o que cada um decidiu e o que muda para quem desenvolve.

| ADR | Decisão | Consequência principal |
|---|---|---|
| [0001](../../docs/adr/0001-extracao-no-navegador-nunca-no-servidor.md) | Extração roda no navegador do usuário, nunca no servidor | Sem SSRF; sem manipular sessão de terceiros no backend |
| [0002](../../docs/adr/0002-ponte-postmessage-restrita-a-localhost.md) | Ponte página↔extensão por `postMessage` restrito a localhost | Aplicação precisa ser servida em localhost/127.0.0.1; mudar de host exige revisar permissão estática |
| [0003](../../docs/adr/0003-extracao-nunca-fabrica-valor-ausente.md) | Campo ausente = `null` + `warning`, nunca valor inventado | Campo em branco para revisão manual é preferível a dado errado |
| [0004](../../docs/adr/0004-css-proprio-sem-framework.md) | CSS próprio, sem framework/CDN | Zero dependência externa; custo de manter o CSS |
| [0005](../../docs/adr/0005-modulo-por-procedimento.md) | Módulo por Procedimento via chave estável (`procedimentos.modulo`) | Painéis específicos não quebram com renomeação; dados do módulo em tabela dedicada |

## Alternativas rejeitadas (resumo)

- **Área de transferência** como canal com a extensão: depende de gesto, sem estrutura, sem garantia de origem.
- **`externally_connectable`**: exigiria fixar o ID da extensão no PHP (muda por máquina em instalação sem compactação).
- **Servidor HTTP dentro da extensão**: inviável em MV3 e amplia a superfície de ataque.
- **Construtor genérico de campos configuráveis** para módulos de Procedimento: caro, valida mal, dificulta relatórios.
- **Content scripts por sistema** (`sigadoc.js`/`pje.js`): domínios não são conhecidos de antemão; adotado coletor único injetado sob demanda, com separação por sistema nos **extratores**.

## Quando criar um novo ADR

Registrar quando a decisão (1) é difícil de reverter, (2) seria surpreendente sem contexto e (3) resultou de uma escolha real entre alternativas.
