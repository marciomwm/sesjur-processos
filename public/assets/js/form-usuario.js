/**
 * Validação do formulário de usuário no navegador.
 * Serve apenas à experiência de uso: as mesmas regras são aplicadas
 * obrigatoriamente no servidor (UsuarioValidator / UsuarioService).
 */
(function () {
    'use strict';

    const form = document.getElementById('formUsuario');
    if (!form) return;

    // Descrição do perfil acompanha a opção escolhida.
    const perfil = document.getElementById('perfil');
    const ajudaPerfil = perfil && perfil.dataset.descricoes
        ? perfil.closest('.campo').querySelector('.campo__ajuda') : null;
    if (ajudaPerfil) {
        const descricoes = JSON.parse(perfil.dataset.descricoes);
        perfil.addEventListener('change', function () {
            ajudaPerfil.textContent = descricoes[perfil.value] || '';
        });
    }

    // Criação a partir do agente: sugere o e-mail institucional como login
    // (sem apagar o que o administrador já digitou) e esconde o resumo de
    // outro agente.
    const agente = form.querySelector('[data-agente-usuario]');
    const emailLogin = form.elements.email;
    if (agente && emailLogin) {
        let sugerido = emailLogin.value;
        agente.addEventListener('change', function () {
            const opcao = agente.options[agente.selectedIndex];
            const email = (opcao && opcao.dataset.email) || '';
            if (emailLogin.value === '' || emailLogin.value === sugerido) {
                emailLogin.value = email;
                sugerido = email;
            }
            const resumo = form.querySelector('[data-resumo-agente]');
            if (resumo) resumo.hidden = resumo.dataset.resumoAgente !== agente.value;
        });
    }

    const hoje = new Date();
    const hojeIso = [
        hoje.getFullYear(),
        String(hoje.getMonth() + 1).padStart(2, '0'),
        String(hoje.getDate()).padStart(2, '0')
    ].join('-');

    const regras = {
        nome: function (v) {
            if (!v) return 'Informe o nome completo.';
            if (v.length < 3) return 'O nome deve ter pelo menos 3 caracteres.';
            if (v.length > 150) return 'O nome deve ter no máximo 150 caracteres.';
            if (!/\p{L}/u.test(v)) return 'O nome deve conter letras.';
            return '';
        },
        email: function (v) {
            if (!v) return 'Informe o e-mail institucional.';
            if (v.length > 254 || !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) {
                return 'Informe um e-mail válido, por exemplo usuario@ses.mt.gov.br.';
            }
            return '';
        },
        matricula: function (v) {
            if (!v) return 'Informe a matrícula.';
            if (v.length > 30) return 'A matrícula deve ter no máximo 30 caracteres.';
            if (!/^[A-Z0-9](?:[A-Z0-9./-]*[A-Z0-9])?$/.test(v)) {
                return 'A matrícula aceita apenas letras, números e os separadores ". / -".';
            }
            return '';
        },
        data_nascimento: function (v, campo) {
            // Com type="date", texto incompleto chega como "" mas badInput = true.
            if (campo.validity && campo.validity.badInput) return 'Data de nascimento inválida.';
            if (!v) return campo.required ? 'Informe a data de nascimento.' : '';
            if (v > hojeIso) return 'A data de nascimento não pode ser futura.';
            if (v < '1900-01-01') return 'Data de nascimento inválida.';
            return '';
        },
        cargo_id: function (v) { return v ? '' : 'Selecione o cargo.'; },
        lotacao_id: function (v) { return v ? '' : 'Selecione a lotação.'; },
        ativo: function (v) { return v === '0' || v === '1' ? '' : 'Selecione o status do usuário.'; }
    };

    /** Mesma normalização do servidor, aplicada ao sair do campo. */
    const normalizar = {
        nome: function (v) { return v.replace(/\s+/g, ' ').trim(); },
        email: function (v) { return v.replace(/\s+/g, '').toLowerCase(); },
        matricula: function (v) { return v.trim().toUpperCase(); }
    };

    function mostrarErro(campo, mensagem) {
        const alvo = document.getElementById('erro-' + campo.name);
        campo.classList.toggle('is-invalido', mensagem !== '');
        campo.setAttribute('aria-invalid', mensagem !== '' ? 'true' : 'false');
        if (alvo) {
            alvo.textContent = mensagem;
            alvo.hidden = mensagem === '';
            // A dica do campo fica oculta enquanto houver erro.
            const ajuda = alvo.nextElementSibling;
            if (ajuda && ajuda.classList.contains('campo__ajuda')) ajuda.hidden = mensagem !== '';
        }
    }

    function validarCampo(campo) {
        const regra = regras[campo.name];
        if (!regra) return true;
        if (normalizar[campo.name]) campo.value = normalizar[campo.name](campo.value);
        const mensagem = regra(campo.value, campo);
        mostrarErro(campo, mensagem);
        return mensagem === '';
    }

    Object.keys(regras).forEach(function (nome) {
        const campo = form.elements[nome];
        if (!campo) return;
        campo.addEventListener('blur', function () { validarCampo(campo); });
        campo.addEventListener('change', function () { validarCampo(campo); });
        // Enquanto digita, apenas remove o erro já corrigido.
        campo.addEventListener('input', function () {
            if (campo.classList.contains('is-invalido') && regras[nome](campo.value, campo) === '') {
                mostrarErro(campo, '');
            }
        });
    });

    form.addEventListener('submit', function (ev) {
        let primeiroInvalido = null;
        Object.keys(regras).forEach(function (nome) {
            const campo = form.elements[nome];
            if (campo && !validarCampo(campo) && !primeiroInvalido) primeiroInvalido = campo;
        });

        if (primeiroInvalido) {
            ev.preventDefault();
            primeiroInvalido.focus();
            return;
        }

        // Evita envio duplicado por duplo clique.
        const botao = form.querySelector('button[type="submit"]');
        if (botao) {
            botao.disabled = true;
            botao.dataset.rotulo = botao.textContent;
            botao.textContent = 'Salvando…';
        }
    });

    // Ao voltar pelo histórico (bfcache), reabilita o botão.
    window.addEventListener('pageshow', function () {
        const botao = form.querySelector('button[type="submit"]');
        if (botao && botao.dataset.rotulo) {
            botao.disabled = false;
            botao.textContent = botao.dataset.rotulo;
        }
    });
})();
