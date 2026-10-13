/**
 * Normalizadores: convertem o texto bruto lido do DOM no contrato interno
 * da aplicação. Espelham as regras de app/Validators/Normalizador.php.
 *
 * Regra invariável: entrada irreconhecível resulta em `null`, nunca em um
 * valor inventado.
 */
(function (raiz) {
'use strict';

/** Colapsa espaços; devolve null quando vazio. */
function texto(valor) {
    if (valor == null) return null;
    const s = String(valor).replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
    return s === '' ? null : s;
}

/** Converte data para ISO (AAAA-MM-DD). Aceita dd/mm/aaaa e aaaa-mm-dd. */
function dataIso(valor) {
    const s = texto(valor);
    if (!s) return null;

    let m = s.match(/(\d{4})-(\d{2})-(\d{2})/);
    if (m && dataValida(+m[3], +m[2], +m[1])) return m[1] + '-' + m[2] + '-' + m[3];

    m = s.match(/(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})/);
    if (m) {
        const d = m[1].padStart(2, '0'), mes = m[2].padStart(2, '0'), a = m[3];
        if (dataValida(+d, +mes, +a)) return a + '-' + mes + '-' + d;
    }
    return null;
}

/**
 * Como dataIso, aceitando também o ano com dois dígitos (dd/mm/aa), formato
 * usado pelo SIGA-DOC na tabela de movimentações. Ano curto → 20aa.
 * Separado de dataIso para não relaxar a leitura dos demais campos.
 */
function dataIsoAnoCurto(valor) {
    const completa = dataIso(valor);
    if (completa) return completa;
    const s = texto(valor);
    if (!s) return null;
    const m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2})$/);
    if (!m) return null;
    const d = m[1].padStart(2, '0'), mes = m[2].padStart(2, '0'), a = '20' + m[3];
    return dataValida(+d, +mes, +a) ? a + '-' + mes + '-' + d : null;
}

/** Meses abreviados como o PJe os escreve ("14 dez 2023"). */
const MESES_ABREVIADOS = {
    jan: '01', fev: '02', mar: '03', abr: '04', mai: '05', jun: '06',
    jul: '07', ago: '08', set: '09', out: '10', nov: '11', dez: '12'
};

/**
 * Como dataIso, aceitando também o mês por extenso abreviado — formato usado
 * pelo PJe tanto na <dl> de detalhes ("14 dez 2023") quanto nos separadores de
 * data da cronologia ("18 set 2026").
 */
function dataIsoExtensa(valor) {
    const completa = dataIso(valor);
    if (completa) return completa;

    const s = texto(valor);
    if (!s) return null;

    const m = s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
               .match(/\b(\d{1,2})\s+([a-z]{3,})\.?\s+(\d{4})\b/);
    if (!m) return null;

    const mes = MESES_ABREVIADOS[m[2].slice(0, 3)];
    if (!mes) return null;

    const d = m[1].padStart(2, '0');
    return dataValida(+d, +mes, +m[3]) ? m[3] + '-' + mes + '-' + d : null;
}

/** "15:14" ou "15:14:16" → "HH:MM". Fora do formato → null. */
function horaHm(valor) {
    const s = texto(valor);
    if (!s) return null;
    const m = s.match(/\b([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?\b/);
    return m ? m[1].padStart(2, '0') + ':' + m[2] : null;
}

/**
 * Separa a descrição do código entre parênteses, como o PJe apresenta classes
 * e assuntos: "CUMPRIMENTO DE SENTENÇA (156)" → { descricao, codigo }.
 * Sem parênteses, devolve a descrição inteira e código null.
 */
function descricaoComCodigo(valor) {
    const s = texto(valor);
    if (!s) return { descricao: null, codigo: null };

    const m = s.match(/^(.*?)\s*\((\d{1,10})\)\s*$/);
    return m
        ? { descricao: texto(m[1]), codigo: m[2] }
        : { descricao: s, codigo: null };
}

/**
 * Interpreta a identificação de uma parte como o PJe a exibe:
 *   "FULANO DE TAL - CPF: 000.000.000-00 (REQUERENTE)"
 *   "MUNICÍPIO DE X - CNPJ: 00.000.000/0001-00 (REQUERIDO)"
 *   "FULANO DE TAL (REQUERENTE)"      — sem documento
 * Devolve sempre as três chaves; o que não estiver presente vem null.
 */
function identificacaoParte(valor) {
    const s = texto(valor);
    if (!s) return { nome: null, documento: null, tipo: null };

    let restante = s;
    let tipo = null;

    const papel = restante.match(/\(([^()]+)\)\s*$/);
    if (papel) {
        tipo = texto(papel[1]);
        restante = restante.slice(0, papel.index);
    }

    const doc = documento(restante);

    // O nome é o que antecede o rótulo do documento; sem documento, o que
    // sobra depois de retirar o papel.
    const corte = restante.match(/\s*-\s*(CPF|CNPJ|CPF\/CNPJ)\s*:/i);
    const nome = texto(corte ? restante.slice(0, corte.index) : restante);

    return { nome: nome, documento: doc, tipo: tipo };
}

/**
 * Classifica o texto da situação (marcador) do SIGA-DOC.
 * Códigos: AGUARDANDO_ANDAMENTO · SOBRESTADO · ARQUIVADO · EM_TRANSITO · OUTRA.
 * Espelha App\Validators\Normalizador::situacaoAdministrativa().
 */
function situacaoAdministrativa(valor) {
    const s = texto(valor);
    if (!s) return null;
    const k = s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    if (k.indexOf('sobrestad') >= 0) return 'SOBRESTADO';
    if (/arquiv/.test(k) && k.indexOf('desarquiv') < 0) return 'ARQUIVADO';
    if (k.indexOf('aguardando andamento') >= 0 || k.indexOf('em andamento') >= 0) return 'AGUARDANDO_ANDAMENTO';
    if (/caixa de entrada|a receber|em transito|transferido/.test(k)) return 'EM_TRANSITO';
    return 'OUTRA';
}

function dataValida(dia, mes, ano) {
    if (mes < 1 || mes > 12 || dia < 1 || ano < 1000) return false;
    const dias = new Date(ano, mes, 0).getDate();
    return dia <= dias;
}

/** "R$ 1.234,56" → 1234.56 · "1234.56" → 1234.56 · lixo → null */
function valorMonetario(valor) {
    if (valor == null || valor === '') return null;
    if (typeof valor === 'number') return isFinite(valor) ? valor : null;

    const limpo = String(valor).replace(/[^\d,.\-]/g, '');
    if (limpo === '' || limpo === '-') return null;

    let normalizado = limpo;
    const temVirgula = limpo.indexOf(',') >= 0;
    const temPonto = limpo.indexOf('.') >= 0;

    if (temVirgula && temPonto) {
        normalizado = limpo.replace(/\./g, '').replace(',', '.');
    } else if (temVirgula) {
        normalizado = limpo.replace(',', '.');
    }

    const n = Number(normalizado);
    return isFinite(n) ? n : null;
}

/** Booleano tri-estado: null quando a informação não está disponível. */
function booleanoOuNulo(valor) {
    if (valor == null || valor === '') return null;
    if (typeof valor === 'boolean') return valor;
    const s = String(valor).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    if (['1', 'true', 'sim', 's', 'yes', 'segredo de justica', 'sigiloso'].indexOf(s) >= 0) return true;
    if (['0', 'false', 'nao', 'n', 'no', 'publico', 'sem sigilo', 'nivel 0'].indexOf(s) >= 0) return false;
    return null;
}

/**
 * Sinalizador que pode trazer o motivo no lugar de SIM — "Prioridade?" do PJe
 * exibe "Idoso", "Doença grave"… Texto que não é SIM/NÃO vale como SIM, e o
 * próprio texto vira o motivo. Vazio → { valor: null, motivo: null }.
 */
function booleanoComMotivo(valor) {
    const s = texto(valor);
    if (!s) return { valor: null, motivo: null };
    const b = booleanoOuNulo(s);
    if (b !== null) return { valor: b, motivo: null };
    return { valor: true, motivo: s };
}

/** Texto sem acentos e em minúsculas, para comparação por palavra-chave. */
function chaveBusca(valor) {
    return String(valor || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
}

/**
 * Primeiro argumento textual de `copyToClipboard(event, '...')`, como o PJe
 * escreve no onclick dos ícones "copiar número". Qualquer outro onclick → null.
 */
function argumentoCopiar(onclick) {
    const s = String(onclick || '');
    const m = s.match(/copyToClipboard\s*\(\s*[^,()]*,\s*['"]([^'"]{1,60})['"]/);
    return m ? texto(m[1]) : null;
}

/**
 * "Juntado por FULANO DE TAL em 18/09/2026 15:00:16"
 *   → { por: 'FULANO DE TAL', dataHora: '2026-09-18 15:00:16' }
 * Partes ausentes vêm null.
 */
function juntadaDocumento(valor) {
    const s = texto(valor);
    if (!s) return { por: null, dataHora: null };
    const por = s.match(/Juntado\s+por\s+(.+?)\s+em\s+\d/i);
    const quando = s.match(/(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2})(?::(\d{2}))?/);
    let dataHora = null;
    if (quando && dataValida(+quando[1], +quando[2], +quando[3])) {
        dataHora = quando[3] + '-' + quando[2] + '-' + quando[1] + ' ' +
                   quando[4] + ':' + quando[5] + ':' + (quando[6] || '00');
    }
    return { por: por ? texto(por[1]) : null, dataHora: dataHora };
}

/**
 * Classifica o rótulo de um documento ("249562011 - Sentença") conforme as
 * regras de config/selectors.js (PJE_SELECTORS.integra.classificacao).
 * Sem correspondência → null (o documento não é peça decisória).
 */
function classificarDocumento(rotulo, regras) {
    const k = chaveBusca(rotulo);
    if (!k) return null;
    for (const regra of (regras || [])) {
        if (regra.chaves.some(function (c) { return k.indexOf(c) >= 0; })) return regra.codigo;
    }
    return null;
}

/** Normaliza o número CNJ para NNNNNNN-DD.AAAA.J.TR.OOOO. */
function numeroCnj(valor) {
    const s = texto(valor);
    if (!s) return null;
    const d = s.replace(/\D/g, '');
    if (d.length !== 20) return null;
    return d.slice(0, 7) + '-' + d.slice(7, 9) + '.' + d.slice(9, 13) + '.' +
           d.slice(13, 14) + '.' + d.slice(14, 16) + '.' + d.slice(16);
}

/** Confere o dígito verificador do CNJ (módulo 97, base 10). */
function cnjDigitoValido(valor) {
    const n = numeroCnj(valor);
    if (!n) return false;
    const d = n.replace(/\D/g, '');
    const base = d.slice(0, 7) + d.slice(9) + '00';
    let resto = 0;
    for (const c of base) resto = (resto * 10 + Number(c)) % 97;
    return Number(d.slice(7, 9)) === 98 - resto;
}

/** Normaliza o protocolo administrativo (SIGLA-ESPÉCIE-ANO/NÚMERO). */
function protocolo(valor) {
    let s = texto(valor);
    if (!s) return null;
    s = s.toUpperCase()
         .replace(/\s*([\-\/])\s*/g, '$1')
         .replace(/(\d{4})[\s.](\d{1,10})$/, '$1/$2')
         .replace(/[–—_]/g, '-')
         .replace(/-{2,}/g, '-');
    return s;
}

/** Compara identificadores após a normalização. */
function protocolosEquivalentes(a, b) {
    const na = protocolo(a), nb = protocolo(b);
    return !!na && !!nb && na === nb;
}

function cnjEquivalentes(a, b) {
    const na = numeroCnj(a), nb = numeroCnj(b);
    return !!na && !!nb && na === nb;
}

/** Normaliza o polo de uma parte processual. */
function polo(valor) {
    const s = String(valor || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase();
    if (s.indexOf('ATIVO') >= 0) return 'ATIVO';
    if (s.indexOf('PASSIVO') >= 0) return 'PASSIVO';
    if (s.indexOf('TERCEIRO') >= 0) return 'TERCEIRO';
    return 'OUTRO';
}

/** Extrai CPF/CNPJ de um texto, quando legitimamente exibido. */
function documento(valor) {
    const s = texto(valor);
    if (!s) return null;
    const m = s.match(/\d{3}\.\d{3}\.\d{3}-\d{2}|\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\d{3}\.?\*+\.?\*+-?\d{2}/);
    return m ? m[0] : null;
}

/** Data/hora ISO com fuso local, usada em `extractedAt`. */
function agoraIso() {
    const d = new Date();
    const pad = function (n) { return String(Math.abs(Math.trunc(n))).padStart(2, '0'); };
    const deslocamento = -d.getTimezoneOffset();
    const sinal = deslocamento >= 0 ? '+' : '-';
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
           'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) +
           sinal + pad(deslocamento / 60) + ':' + pad(deslocamento % 60);
}

const API = {
    texto, dataIso, dataIsoAnoCurto, dataIsoExtensa, horaHm,
    descricaoComCodigo, identificacaoParte,
    situacaoAdministrativa, valorMonetario, booleanoOuNulo, booleanoComMotivo,
    chaveBusca, argumentoCopiar, juntadaDocumento, classificarDocumento,
    numeroCnj, cnjDigitoValido, protocolo,
    protocolosEquivalentes, cnjEquivalentes,
    polo, documento, agoraIso
};

raiz.GJ = raiz.GJ || {};
raiz.GJ.normalizers = API;
if (typeof module !== 'undefined' && module.exports) { module.exports = API; }

})(typeof globalThis !== 'undefined' ? globalThis : self);
