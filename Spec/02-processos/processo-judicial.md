# Processo Judicial

Processo originado no **PJe** (1º Grau TJMT validado), identificado por **Número CNJ** único (ex.: `1008695-17.2026.8.11.0006`).

## Atributos

| Grupo | Campos |
|---|---|
| Identificação | Número CNJ (formato **e dígito verificador** — `CnjValidator`), tribunal, órgão julgador, cargo judicial, competência, jurisdição |
| Classificação | Classe judicial + código CNJ; Assunto principal e secundários (tabela própria, normalizados) |
| Datas e valores | Data de autuação, última distribuição, valor da causa (normalizado) |
| Sinalizadores **tri-estado** | Segredo de Justiça, Justiça Gratuita, Tutela ou Liminar, Prioridade (`NULL` = não informado pela fonte) |
| Partes | Parte Processual + Polo (Ativo, Passivo, Terceiro, Outro), com documento e representação |
| Cronologia | Movimentações (andamentos e documentos juntados) |
| Documentos | Documento Judicial: número próprio, texto integral quando obtido, indicador **Decisória** |
| Situação | Situação do processo |
| Proveniência | `origem`, `url_origem`, `data_extracao`, `extrator_versao` |
| Observações internas | Texto livre |

> **Removidos em 28/09/2026 (extrator 1.4.0):** Vara, Comarca e Designação. No TJMT a jurisdição faz as vezes de comarca e o órgão julgador já traz a vara. O Processo Judicial **não tem Designação**.

## Regras

- **RN-PJ-001** Número CNJ é UNIQUE e validado por dígito verificador, aceitando entrada com ou sem máscara.
- **RN-PJ-002** Sinalizadores são **tri-estado**: nunca afirmar "não" quando a fonte não informou.
- **RN-PJ-003** O **polo vem do container** (`#poloAtivo`/`#poloPassivo`), não de heurística.
- **RN-PJ-004** Assuntos normalizados em tabela própria: um Principal e demais Secundários.
- **RN-PJ-005** **Movimentações** são substituídas por inteiro a cada Extração confirmada.
- **RN-PJ-006** **Documentos Judiciais** são atualizados por **upsert** (nunca substituídos em bloco); **Decisória** = sentença, decisão, acórdão, despacho ou embargos.
- **RN-PJ-007** A cronologia do PJe é paginada: quando só a primeira página foi lida, `historicoCompleto=false` e a aplicação **sinaliza histórico parcial**.
- **RN-PJ-008** Processo com Lançamentos de bloqueio ou Guias de Depósito **não pode ser excluído** (registros financeiros nunca somem em cascata); idem com Intimações Pessoais (mesmo excluídas logicamente).
- **RN-PJ-009** Falha ao gravar partes/assuntos não deixa processo parcialmente gravado (transação).

## Funcionalidades

- **Listagem** com visões **Cards** (padrão) e **Tabela** (escolha na sessão), filtros (tribunal, situação, datas, classe, assunto, partes), ordenação e paginação.
- **Ficha** com dados cadastrais, partes por polo, assuntos, Movimentações, Documentos, Vínculos, Bloqueios/Depósitos e Intimações (quando o Perfil permite).
- **Documento Judicial**: página própria (`documento-judicial.php`).
- **Cadastro**: informar Número CNJ **e** URL do processo → **Completar cadastro pelo PJe**. A URL é validada (esquema, HTTPS, allowlist) **antes** de qualquer navegação.
- **Identificador Divergente**: se o CNJ lido ≠ informado, nada é preenchido.
- **Exclusão** com confirmação; remove partes, assuntos e vínculos em cascata, **não** os Administrativos.

## Critérios de aceitação

- **CA-PJ-01** CNJ com dígito verificador inválido é recusado.
- **CA-PJ-02** Duplicidade de CNJ é recusada.
- **CA-PJ-03** Sinalizador não informado permanece "não informado", não "não".
- **CA-PJ-04** Cronologia parcial é rotulada como parcial.
- **CA-PJ-05** Tentar excluir processo com bloqueios/guias/intimações é bloqueado com explicação.
- **CA-PJ-06** Extração com CNJ divergente interrompe e preserva o que o usuário digitou.

## Situação

✅ Implementado. Mapeamento de DOM do PJe em [docs/pje-selectors.md](../../docs/pje-selectors.md). Evolução prevista: advogados/procuradores, audiências, perícias, cumprimento e obrigações.
