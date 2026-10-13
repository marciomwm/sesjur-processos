# Ponte aplicação↔extensão via postMessage restrito a localhost

Precisávamos de um canal programático entre a página PHP e a extensão MV3.
Descartamos a área de transferência (depende de gesto do usuário, não carrega
estrutura, não garante origem) e `externally_connectable` (exigiria fixar o ID
da extensão no código PHP; em instalação "sem compactação" o ID muda por
máquina). Decidimos usar `window.postMessage` entre a página e um content
script injetado **apenas** em `http://localhost/*` e `http://127.0.0.1/*`, que
repassa ao service worker por `chrome.runtime.sendMessage` — aceitando apenas
mensagens com `event.source === window`, `event.origin === location.origin` e
um tipo de uma lista fechada.

**Consequência**: a extensão só funciona com a aplicação servida em
localhost/127.0.0.1; portar a aplicação para outro host exige revisar essa
permissão estática antes de mais nada.
