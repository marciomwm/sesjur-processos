# Extração roda no navegador do usuário, nunca no servidor

O PHP precisa de dados do SIGADOC/PJe, mas nunca deve reproduzir a sessão do
usuário nem fazer requisições para esses domínios. Decidimos que toda Extração
executa como JavaScript na aba já autenticada do próprio usuário (extensão
Chrome); a URL informada no formulário é validada e repassada à extensão, mas
**jamais** vira uma requisição do servidor. Isso elimina por completo a
superfície de SSRF e evita qualquer manipulação de cookies/sessão de terceiros
no backend.
