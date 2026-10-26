# Tabelas de apoio

Cadastros auxiliares referenciados por **chave estrangeira** (nunca texto livre) para evitar grafias divergentes.

## Regra comum a todas

- **RN-APO-001** Itens obsoletos são **inativados, nunca excluídos**.
- **RN-APO-002** O item inativado **continua válido** — e aparece na edição sem ser substituído — para quem já o possui; só deixa de aparecer em **novos** cadastros.
- **RN-APO-003** **Nome único** sem distinção de caixa, acentos e espaços repetidos.
- **RN-APO-004** Eventos de criação/edição/ativação/inativação vão para a Auditoria (`CARGO_*`, `LOTACAO_*`, …).
- **RN-APO-005** Camada genérica: `TabelaApoioService` + `TabelaApoioRepository` (base de `Cargo`/`Lotacao`…), telas `tabela-apoio.php?tipo=…`, `editar-tabela-apoio.php`, `alterar-status-tabela-apoio.php`.

## Inventário

| Tabela | Quem mantém | Uso |
|---|---|---|
| Cargos | Administrador | Função do Agente/Usuário |
| Instituições | Administrador | Órgão a que a Unidade Administrativa pertence |
| Níveis organizacionais | Administrador (também criáveis no cadastro da unidade) | Posição da unidade |
| Procedimentos | **Somente Administrador** (Configurações) | Trâmite do Processo Administrativo; `modulo` opcional |
| Prioridades | Administrador | Atenção preferencial (nível 1–5) |
| Instituições Externas / Órgãos Externos | Conforme tela (Sistema › Instituições Externas); órgão também criável no formulário do extrajudicial | Origem do Processo Extrajudicial (e da Demanda de Saúde, planejado) |
| Pastas e Áreas de Recursos | Administrador e Gestor | Organização da Central de Recursos |
| Classificação SIGTAP | Cadastro manual na 1ª vez; importação oficial planejada | Produtos de Saúde |
| Categorias de Produto de Saúde / Catálogo de Produtos | 🗓️ planejado | [demanda-saude.md](../04-modulos/demanda-saude.md) |

## Nota de nomenclatura

O código ainda tem **`lotacoes`** (tabela, `LotacaoRepository`) para o que o domínio chama **Unidade Administrativa**; "lotação" hoje só designa "a unidade em que o usuário está lotado".

## Critérios de aceitação

- **CA-APO-01** "Clínica Médica", "clinica  medica" e "CLÍNICA MÉDICA" são o mesmo nome e não coexistem.
- **CA-APO-02** Inativar um item não o remove de quem o possui nem o oferece em novos cadastros.
- **CA-APO-03** Não existe ação de exclusão física para tabelas de apoio na interface.
