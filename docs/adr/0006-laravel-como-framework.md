# Laravel como framework, mantendo o CSS próprio e as dependências locais

> **Status:** em vigor. A rejeição do Tailwind abaixo foi revista pelo ADR 0012
> (Tailwind compilado localmente, sem CDN).

O SESJUR nasceu em PHP sem framework (páginas em `public/*.php`, Services,
Repositories e Validators próprios). A reestruturação — quatro Dimensões,
módulos entregues aos poucos, permissões mais finas — pede convenções,
roteamento, autorização e testes que hoje mantemos à mão. Decidimos adotar o
**Laravel**, sobre o mesmo ambiente de produção (PHP 8.3.33, MySQL 8.4.3):

- Controller fino → Service/Action → Eloquent. Form Requests no lugar dos
  Validators. **Sem Repository** sobre Eloquent (duplicaria a camada).
- Blade + Alpine.js empacotado localmente com Vite, e o **CSS próprio** portado.
- **Auditoria própria** (append-only, na mesma transação), compatível com a
  tabela `auditoria`, em vez de pacote de terceiros.
- **API de Integração v1** com middleware de token próprio (hash, escopo, IP,
  validade), sem Sanctum: o contrato dos clientes em produção não muda.
- Tabelas e colunas **mantêm os nomes atuais** (`$table` no Eloquent); mudanças
  de modelo vão por migrações *expand/contract*, cada uma com sua especificação.

**Reafirma o ADR 0004.** "Sem dependência externa em tempo de execução" passa a
significar: `vendor/` e assets compilados ficam no servidor, sem CDN, sem fonte
nem script externos. Composer e npm são ferramentas de build, não de execução.

Rejeitamos Tailwind/Bootstrap (reabririam o ADR 0004 sem ganho), Livewire como
padrão (avaliar só para listagens complexas), pacotes de auditoria e de
permissões (formato próprio quebraria o Histórico; o grão de que precisamos é
pequeno) e Sanctum.

**Consequência**: passamos a ter uma etapa de build e uma dependência de
versão do framework a acompanhar; a suíte de 93 testes é portada junto com
cada módulo, não de uma vez.
