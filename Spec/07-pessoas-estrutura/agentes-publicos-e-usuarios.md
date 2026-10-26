# Agentes Públicos e Usuários

## Agente Público

Pessoa com vínculo funcional ou profissional com a Administração (efetivo, comissionado, estagiário, terceirizado, cedido…), **lotada numa Unidade Administrativa**; o órgão/entidade é a Instituição da unidade (não é gravado à parte). É a **base** dos Usuários — mas nem todo agente tem acesso ao sistema. Evitar "servidor" (é só um tipo de vínculo) e "pessoa".

- **RN-AGE-001** **Nunca é excluído**: o desligamento é a situação **Desligado**, que também **inativa a conta** de Usuário.
- **RN-AGE-002** Dados pessoais e funcionais; tipo de vínculo (na migração só foi deduzido do cargo — estagiário/residente; demais ficaram "Outro" e devem ser revisados).
- **RN-AGE-003** Páginas: `agentes-publicos.php`, `agente-publico.php`, `novo-/editar-agente-publico.php`. Serviço: `AgentePublicoService`. Fica em página própria, fora de Configurações do Sistema.

## Usuário

Credencial de acesso de um Agente Público: **login (e-mail de acesso)**, **Perfil** e **status da conta**.

- **RN-USU-001** Um agente tem **no máximo um** Usuário; todo Usuário pertence a **um** agente.
- **RN-USU-002** A conta é criada **a partir da ficha do agente** (`novo-usuario.php?agente_id=…`), que **exige matrícula e cargo** e recusa agente Desligado ou que já tem conta.
- **RN-USU-003** **Nunca é excluído fisicamente**; desligamento = inativação (preserva autoria e histórico).
- **RN-USU-004** **Unicidade garantida pelo banco** (e-mail e matrícula); pré-checagem apenas melhora a mensagem; erro 1062 de corrida é traduzido na mesma mensagem.
- **RN-USU-005** Dados pessoais/funcionais exibidos no Usuário são os do agente (**cópia sincronizada**): alterar um atualiza o outro, com auditoria dos dois lados. Remover essas colunas de `usuarios` é passo futuro.
- **RN-USU-006** Cadastro direto pelo serviço (scripts, testes) liga a conta ao agente sem conta de mesma matrícula ou cria o agente.
- **RN-USU-007** Data de nascimento opcional; obrigatória com `'usuarios' => ['data_nascimento_obrigatoria' => true]` em `config.php`.
- **RN-USU-008** Auditoria ativa: `USUARIO_CRIADO/EDITADO/ATIVADO/INATIVADO`, gravada na mesma transação; na edição registra apenas os campos alterados.

## Autenticação

- Login em `login.php`, saída em `logout.php`; sessão e autor via `usuario_logado_id()` (`app/Core/Auth.php`, `AuthService`).
- **Credencial**: senha em registro próprio (`CredencialRepository`), separado do cadastro; política em `SenhaValidator`.
- Senha definida por Administrador é **provisória** e força troca no próximo login (`alterar-senha.php`, `definir-senha-usuario.php`).
- Planejamento original de RBAC: `database/planejamento_autenticacao_rbac.sql` (**não executar** — referência histórica).

## Cargo

Tabela de apoio com a função exercida. Referenciada por **FK, nunca texto livre**. Itens obsoletos inativados, nunca excluídos, e continuam válidos para quem já os possui. Telas: *Sistema › Cargos* (`tabela-apoio.php?tipo=cargos`).

## Critérios de aceitação

- **CA-AGE-01** Desligar o agente inativa o acesso do seu Usuário.
- **CA-USU-01** Não é possível criar conta para agente que já tem conta ou está Desligado.
- **CA-USU-02** E-mail e matrícula duplicados são recusados com mensagem amigável.
- **CA-USU-03** Senha provisória força troca no próximo login.
- **CA-USU-04** Editar apenas um campo grava na Auditoria só esse campo (antes/depois).

## Situação

✅ Implementado (`tests/php/usuarios.php`, `agentes.php`, `acesso.php`; migrações `2026-09-25_usuarios`, `2026-09-25_perfis_designacoes`, `2026-09-30_agentes_…`).
