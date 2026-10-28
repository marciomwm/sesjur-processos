# Dimensões como módulos independentes (monólito modular)

Os administradores querem ajustar cada Dimensão — Principal, Administrativa,
Jurídica e Configurações Gerais — de forma independente, quando for necessário,
inclusive **criando novas Dimensões, Módulos e Páginas**, sem tocar nas demais.
Decidimos organizar o Laravel como um **monólito modular**:

- Na raiz do repositório `sesjur`, **uma pasta por Dimensão**: `principal/`,
  `administrativa/`, `juridica/` e `configuracoes-gerais/` (sem acento no
  sistema de arquivos; o nome de exibição fica no manifesto). Cada pasta tem o
  seu código (namespace `Sesjur\<Dimensao>`), rotas, views, assets, migrações e
  testes.
- Cada pasta tem um **manifesto** `dimensao.php`: identidade, ordem, estado
  (`planejada` ou `ativa`), Módulos › Páginas › Subpáginas, Funcionalidades e
  permissões (com o padrão por Perfil). O Núcleo **descobre as pastas que têm
  manifesto** e compõe o registro de funcionalidades (ADR 0008). **Criar uma
  Dimensão é criar uma pasta com manifesto**; criar um Módulo ou uma Página é
  editar o manifesto e os arquivos da própria Dimensão.
- **Dependência em um só sentido: Dimensão → Núcleo.** Uma Dimensão não importa
  classes de outra. Elas se comunicam por **ID** no banco e por **contratos**
  definidos no Núcleo e registrados por cada Dimensão — por exemplo, provedor de
  widgets para a Home, provedor de eventos para a Agenda, provedor de
  indicadores e escopos da API v1.
- O **Núcleo** (`app/` e `bootstrap/` do Laravel) guarda o que é de todos:
  identidade (Usuário, Agente Público, Perfil, Vinculação à SGS), Instituição e
  Unidade, Auditoria, base das tabelas de apoio, registro de funcionalidades,
  layout e contratos. Entidade necessária a mais de uma Dimensão sobe para o
  Núcleo.
- A **Principal é a Home**: `/` e o destino do login. Mostra os widgets que as
  outras Dimensões contribuem e não conhece nenhuma delas por nome.
- Um provider do Núcleo mapeia, para cada pasta descoberta, o autoload PSR-4, as
  rotas, as views, as migrações e as entradas do Vite. **Escrevemos esse
  carregador** em vez de adotar um pacote de módulos de terceiros: a hierarquia
  de seis níveis, o manifesto e a regra de dependência são nossos (ADR 0006).
- O legado `sesjur-processos` fica fora do repositório novo, ao lado dele.

Rejeitamos a estrutura única por tipo de arquivo (`controllers/`, `models/`…),
que mistura as Dimensões; um repositório por Dimensão, que fragmentaria banco,
autenticação e layout; e o pacote de terceiros.

**Consequência**: há um custo inicial (o carregador e o gerador de esqueleto
de Dimensão, Módulo e Página) e uma disciplina a manter, **verificada por um
teste de arquitetura** que falha se uma Dimensão importar outra. As migrações
das Dimensões convivem num só banco, com a ordem definida pelo Núcleo. Uma
Dimensão `planejada` não é carregada em produção.
