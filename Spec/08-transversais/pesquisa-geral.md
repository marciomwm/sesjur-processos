# Pesquisa geral

Campo na barra superior (atalho **`/`**) que sugere resultados enquanto se digita e, com **Enter**, abre `pesquisa.php`.

## Escopo da busca

| Entidade | Campos pesquisados |
|---|---|
| Processos Administrativos | Protocolo, objeto, procedimento, responsável |
| Processos Judiciais | Número CNJ (com ou sem máscara), classe, assunto, partes do cabeçalho, órgão julgador |
| Partes | Nome/documento |
| Tarefas | Descrição |
| Usuários | **Somente para Administradores** |

## Regras

- **RN-PES-001** **Protocolo ou CNJ exato abre direto a ficha.**
- **RN-PES-002** Resultados respeitam o Perfil: Usuários só aparecem para Administrador; Intimações Pessoais não aparecem para quem não as pode ver.
- **RN-PES-003** Sugestões vêm de `api/pesquisa-geral.php` (JSON), sem recarregar a página.
- **RN-PES-004** Consultas com prepared statements; termos tratados como dado.

Código: `app/Services/PesquisaService.php`, `api/pesquisa-geral.php`, `public/assets/js/pesquisa-geral.js`, `public/pesquisa.php`.

## Critérios de aceitação

- **CA-PES-01** Digitar um CNJ sem máscara encontra o processo.
- **CA-PES-02** Enter com protocolo exato abre a ficha sem passar pela lista.
- **CA-PES-03** Usuário não-Administrador nunca recebe registros de Usuários.
- **CA-PES-04** O atalho `/` foca o campo, exceto quando o foco já está em um campo de texto.

## Situação

✅ Implementado (28/09/2026).
