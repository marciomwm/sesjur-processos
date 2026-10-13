# Fixtures de DOM

## Aviso importante

Os arquivos presentes nesta pasta são **fixtures sintéticas**. Elas exercitam o
*motor* de extração (`extractors/dom-utils.js`) e o contrato de dados — não
representam, e não devem ser confundidas com, a marcação real do SIGADOC ou do
PJe, que ainda **não foi analisada**.

Cada fixture sintética traz o atributo `data-fixture="sintetica"` no `<html>`.

## Como criar uma fixture real (passo obrigatório para concluir os extratores)

1. Autentique-se normalmente no sistema e abra a página do processo.
2. Salve o HTML:
   - `Ctrl+S` → "Página da Web, apenas HTML", **ou**
   - DevTools → `Elements` → botão direito em `<html>` → *Copy* → *Copy outerHTML*.
3. **Anonimize** antes de versionar:
   - substitua nomes de pessoas por `FULANO DE TAL`, `BELTRANO DE TAL`;
   - substitua CPF/CNPJ por valores fictícios (`000.000.000-00`);
   - remova qualquer token, cookie, `sessionId`, matrícula ou e-mail;
   - mantenha a **estrutura** intacta (classes, ids, `data-*`, hierarquia).
4. Salve como:
   - `tests/fixtures/sigadoc/real-<descricao>.html`
   - `tests/fixtures/pje/real-<descricao>.html`
5. Registre o arquivo em `tests/extractors/casos.js`.
6. Mapeie os seletores em `chrome-extension/config/selectors.js` e rode
   `tests/extractors/runner.html`.

## Casos que devem existir para cada sistema

| Caso | Arquivo sugerido | Resultado esperado |
|---|---|---|
| Página válida | `*-valido.html` | `success: true`, campos preenchidos |
| Campo ausente | `*-campo-ausente.html` | `success: true`, campo `null` + warning |
| DOM parcialmente modificado | `*-dom-alterado.html` | `success: true` com warnings, ou `SELECTORS_MISSING` |
| Processo inexistente | `*-inexistente.html` | `PROCESS_NOT_FOUND` / `SELECTORS_MISSING` |
| Sessão expirada | `*-sessao-expirada.html` | `SESSION_REQUIRED` |
| Identificador divergente | `*-divergente.html` | `IDENTIFIER_MISMATCH` |
