# Hosts aceitos e ponte com a extensão

> Substitui o ADR 0002 **no ponto da lista de hosts**. O mecanismo
> (`postMessage` + content script + service worker) continua valendo.

O ADR 0002 restringiu a ponte a `localhost` e `127.0.0.1`. O Laravel/Laragon
serve em `sesjur.test` e a produção ficará em
`https://sesjur.saude.mt.gov.br/`, e a extensão precisa funcionar nos três
ambientes. Decidimos:

- O content script da ponte é declarado para: `http://localhost/*`,
  `http://127.0.0.1/*`, `sesjur.test` (HTTP e HTTPS) e
  `https://sesjur.saude.mt.gov.br/*`. **Qualquer outro host é recusado.**
- Os domínios do SIGADOC e do PJe continuam **permissões opcionais, concedidas
  uma a uma** pelo usuário. Nenhuma permissão global de navegação.
- Permanecem as garantias da ponte: `event.source === window`,
  `event.origin === location.origin` e tipo de mensagem de lista fechada; o
  service worker só atende `sender.id === chrome.runtime.id`.
- A aplicação funciona nos três hosts **sem alterar código** entre ambientes
  (só `.env`).
- A extensão é de **instalação manual e individual** ("carregar sem
  compactação"). Como o ID muda por máquina, continuamos sem
  `externally_connectable`. A aplicação lê a **versão da extensão** pela ponte
  (`GJ_PING`) e avisa quando ela está ausente ou abaixo de uma **versão mínima
  configurável**.

**Consequência**: toda máquina precisa receber a nova versão da extensão antes
de usar o ambiente de produção, e acrescentar ou trocar um host da aplicação
exige publicar uma nova versão da extensão. O procedimento de instalação e
atualização fica documentado em `Spec/10-operacao`.
