/**
 * Página de opções: concessão de permissão por domínio (menor privilégio).
 * A permissão é solicitada individualmente, por gesto do usuário.
 */
(function () {
'use strict';

const lista   = document.getElementById('listaHosts');
const semHosts = document.getElementById('semHosts');
const mensagem = document.getElementById('mensagem');

document.getElementById('versao').textContent = chrome.runtime.getManifest().version;

function padrao(host) {
    return 'https://' + host + '/*';
}

async function carregar() {
    const cfg = await chrome.storage.local.get(['sigadocHosts', 'pjeHosts']);
    const hosts = [].concat(cfg.sigadocHosts || [], cfg.pjeHosts || [])
        .map(function (h) { return String(h).trim().toLowerCase(); })
        .filter(Boolean);
    const unicos = Array.from(new Set(hosts));

    lista.innerHTML = '';
    semHosts.hidden = unicos.length > 0;

    for (const host of unicos) {
        const concedida = await chrome.permissions.contains({ origins: [padrao(host)] });

        const li = document.createElement('li');

        const span = document.createElement('span');
        span.className = 'host';
        span.textContent = host;

        const badge = document.createElement('span');
        badge.className = 'badge ' + (concedida ? 'ok' : 'falta');
        badge.textContent = concedida ? 'autorizado' : 'sem permissão';

        li.appendChild(span);
        li.appendChild(badge);

        if (!concedida) {
            const botao = document.createElement('button');
            botao.textContent = 'Conceder';
            botao.addEventListener('click', async function () {
                try {
                    const ok = await chrome.permissions.request({ origins: [padrao(host)] });
                    mensagem.textContent = ok
                        ? 'Permissão concedida para ' + host + '.'
                        : 'Permissão recusada para ' + host + '.';
                } catch (e) {
                    mensagem.textContent = 'Falha ao solicitar permissão: ' + e.message;
                }
                carregar();
            });
            li.appendChild(botao);
        }

        lista.appendChild(li);
    }
}

document.getElementById('btnRevogar').addEventListener('click', async function () {
    const atuais = await chrome.permissions.getAll();
    const origens = (atuais.origins || []).filter(function (o) {
        return o.indexOf('localhost') < 0 && o.indexOf('127.0.0.1') < 0;
    });
    if (origens.length === 0) {
        mensagem.textContent = 'Não há permissões de domínio para revogar.';
        return;
    }
    await chrome.permissions.remove({ origins: origens });
    mensagem.textContent = 'Permissões revogadas.';
    carregar();
});

carregar();

/*
 * "Criar processo no SESJUR" só faz sentido no popup (ícone da extensão),
 * onde a aba ativa é a página do SIGADOC/PJe; aberta como página de opções,
 * a própria aba seria a ativa.
 */
chrome.tabs.getCurrent(function (abaAtual) {
    if (abaAtual) return;
    const bloco = document.getElementById('blocoCriar');
    const botao = document.getElementById('btnCriar');
    const saida = document.getElementById('mensagemCriar');
    bloco.hidden = false;

    botao.addEventListener('click', function () {
        botao.disabled = true;
        saida.textContent = 'Lendo a página…';
        chrome.runtime.sendMessage({ type: 'GJ_CRIAR_DA_ABA' }, function (r) {
            botao.disabled = false;
            if (chrome.runtime.lastError || !r || !r.success) {
                saida.textContent = 'Falha: ' + ((r && r.error && r.error.message) ||
                    (chrome.runtime.lastError && chrome.runtime.lastError.message) || 'sem resposta');
                return;
            }
            // O cadastro já foi aberto em uma nova aba; eventuais erros aparecem lá.
            window.close();
        });
    });
});
})();
