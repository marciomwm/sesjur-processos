# Perfis e permissões

## Perfis

| Perfil | Descrição |
|---|---|
| **Administrador** | Acesso total, inclusive usuários, perfis, senhas e configurações |
| **Gestor** | Processos e Designação de processos aos usuários |
| **Usuário** | Acompanha processos; vê as Designações, mas não as altera |

Em qualquer perfil, o usuário edita o **próprio cadastro**, exceto Perfil e status (somente Administrador).

## Matriz de permissões

| Capacidade | Administrador | Gestor | Usuário |
|---|:-:|:-:|:-:|
| Cadastrar/editar processos (Adm., Jud., Extra) | ✅ | ✅ | ✅ |
| Criar/remover **Designação** | ✅ | ✅ | ❌ |
| Criar Tarefa / Solicitação / Anotação | ✅ | ✅ | ✅ |
| Alterar/excluir Tarefa, Solicitação, Anotação | ✅ | ✅ | só as próprias |
| Lançar bloqueios e guias de depósito | ✅ | ✅ | ✅ |
| Alterar/excluir lançamentos e guias | ✅ | ✅ | só os próprios |
| Ver/registrar **Intimações Pessoais** | ✅ | ✅ | ❌ |
| Criar/alterar/excluir Modelo de Expediente **Compartilhado** | ✅ | ✅ | ❌ (pode duplicar) |
| Criar Modelo **Individual** | ✅ | ✅ | ✅ |
| Cadastrar Recurso | ✅ | ✅ | ✅ |
| Alterar/excluir Recurso | ✅ | ✅ | só os próprios |
| Manter Pastas e Áreas (Recursos) | ✅ | ✅ | ❌ |
| Configurações do Sistema (Usuários, Extensão, Procedimentos, Prioridades) | ✅ | ❌ | ❌ |
| Agentes Públicos, Cargos, Instituições, Unidades | ✅ | conforme tela\* | ❌ |
| Definir/alterar Perfil, status e senha de outro usuário | ✅ | ❌ | ❌ |
| Pesquisa geral — incluir Usuários nos resultados | ✅ | ❌ | ❌ |
| Paciente / Catálogo de Produtos (planejado) | ver [demanda-saude.md](../04-modulos/demanda-saude.md) | | |

\* Confirmar na implementação das telas de tabela de apoio e estrutura antes de tratar como regra.

## Regras gerais

- **RN-PERM-001** A autorização é **imposta no serviço**, não apenas escondida na tela (ex.: regra de "quem criou" nas Tarefas é verificada no `AcaoService`).
- **RN-PERM-002** Nenhum registro de autoria é excluído fisicamente: Usuários são inativados; Agentes, Unidades, Prioridades e Instituições nunca são excluídos.
- **RN-PERM-003** Senha definida por Administrador é **provisória** e exige troca no próximo login.
- **RN-PERM-004** Credenciais ficam em registro próprio, separado do cadastro, para que o hash nunca apareça em consultas de listagem.
- **RN-PERM-005** Acesso negado gera `AcessoNegadoException` e resposta adequada (sem vazar a existência do registro restrito).

## Critérios de aceitação

- **CA-PERM-01** Um Usuário não vê o menu nem as rotas de Intimações Pessoais; acessar a URL diretamente é negado.
- **CA-PERM-02** Um Usuário que não criou uma Tarefa não consegue alterá-la nem por requisição direta.
- **CA-PERM-03** Somente Administrador acessa Configurações do Sistema.
- **CA-PERM-04** Alterar o próprio cadastro não permite elevar o próprio Perfil.
