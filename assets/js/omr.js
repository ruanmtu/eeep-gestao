/* =====================================================================
   Leitura óptica da folha de respostas (roda no navegador do celular)

   Folha da escola: 4 quadrados pretos nos cantos + quadradinho de
   orientação + QR code (prova e aluno). Medidas em milímetros, iguais
   às de includes/omr.php.

   Folha de outro modelo: só os 4 quadrados dos cantos. A posição das
   bolinhas vem do "formato" que o professor ajusta uma vez por prova
   (coordenadas de 0 a 1 entre os centros dos quadrados).
   ===================================================================== */
window.OMR = (function () {
    'use strict';
    const LETRAS = ['A', 'B', 'C', 'D', 'E'];

    /* ---------- Homografia: plano da folha -> imagem (px) ---------- */
    function homografia(de, para) {
        const A = [], b = [];
        for (let i = 0; i < 4; i++) {
            const [x, y] = de[i], [u, v] = para[i];
            A.push([x, y, 1, 0, 0, 0, -x * u, -y * u]); b.push(u);
            A.push([0, 0, 0, x, y, 1, -x * v, -y * v]); b.push(v);
        }
        const n = 8;
        for (let c = 0; c < n; c++) {
            let p = c;
            for (let r = c + 1; r < n; r++) if (Math.abs(A[r][c]) > Math.abs(A[p][c])) p = r;
            if (Math.abs(A[p][c]) < 1e-12) return null;
            [A[c], A[p]] = [A[p], A[c]]; [b[c], b[p]] = [b[p], b[c]];
            for (let r = 0; r < n; r++) {
                if (r === c) continue;
                const f = A[r][c] / A[c][c];
                for (let k = c; k < n; k++) A[r][k] -= f * A[c][k];
                b[r] -= f * b[c];
            }
        }
        const h = b.map((v, i) => v / A[i][i]);
        return (x, y) => {
            const w = h[6] * x + h[7] * y + 1;
            return [(h[0] * x + h[1] * y + h[2]) / w, (h[3] * x + h[4] * y + h[5]) / w];
        };
    }

    /* ---------- Imagem (foto, vídeo ou canvas) em tons de cinza ---------- */
    function dimensoes(f) {
        return [f.videoWidth || f.naturalWidth || f.width, f.videoHeight || f.naturalHeight || f.height];
    }
    function prepararImagem(fonte, maxLado) {
        const [w0, h0] = dimensoes(fonte);
        const esc = Math.min(1, maxLado / Math.max(w0, h0));
        const W = Math.round(w0 * esc), H = Math.round(h0 * esc);
        const cv = document.createElement('canvas'); cv.width = W; cv.height = H;
        const ctx = cv.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(fonte, 0, 0, W, H);
        const d = ctx.getImageData(0, 0, W, H).data;
        const g = new Uint8ClampedArray(W * H);
        for (let i = 0, j = 0; i < g.length; i++, j += 4) g[i] = (d[j] * 299 + d[j + 1] * 587 + d[j + 2] * 114) / 1000;
        return { W, H, g, canvas: cv, esc };
    }

    /* ---------- Componentes conectados de uma máscara ---------- */
    function componentes(W, H, bin) {
        const rot = new Int32Array(W * H), pilha = new Int32Array(W * H), comps = [];
        let id = 0;
        for (let i = 0; i < W * H; i++) {
            if (!bin[i] || rot[i]) continue;
            id++; let topo = 0; pilha[topo++] = i; rot[i] = id;
            let n = 0, sx = 0, sy = 0, minX = W, maxX = 0, minY = H, maxY = 0;
            while (topo) {
                const p = pilha[--topo], px = p % W, py = (p - px) / W;
                n++; sx += px; sy += py;
                if (px < minX) minX = px; if (px > maxX) maxX = px; if (py < minY) minY = py; if (py > maxY) maxY = py;
                if (px > 0 && bin[p - 1] && !rot[p - 1]) { rot[p - 1] = id; pilha[topo++] = p - 1; }
                if (px < W - 1 && bin[p + 1] && !rot[p + 1]) { rot[p + 1] = id; pilha[topo++] = p + 1; }
                if (py > 0 && bin[p - W] && !rot[p - W]) { rot[p - W] = id; pilha[topo++] = p - W; }
                if (py < H - 1 && bin[p + W] && !rot[p + W]) { rot[p + W] = id; pilha[topo++] = p + W; }
            }
            const w = maxX - minX + 1, h = maxY - minY + 1;
            comps.push({ n, cx: sx / n, cy: sy / n, w, h, minX, minY, preench: n / (w * h) });
        }
        return comps;
    }

    /* Binarização adaptativa (imagem inteira) -> manchas escuras */
    function manchasEscuras(W, H, g) {
        const I = new Float64Array((W + 1) * (H + 1));
        for (let y = 0; y < H; y++) {
            let s = 0;
            for (let x = 0; x < W; x++) { s += g[y * W + x]; I[(y + 1) * (W + 1) + x + 1] = I[y * (W + 1) + x + 1] + s; }
        }
        const r = Math.max(8, Math.round(Math.max(W, H) / 30));
        const bin = new Uint8Array(W * H);
        for (let y = 0; y < H; y++) {
            const y0 = Math.max(0, y - r), y1 = Math.min(H, y + r + 1);
            for (let x = 0; x < W; x++) {
                const x0 = Math.max(0, x - r), x1 = Math.min(W, x + r + 1);
                const soma = I[y1 * (W + 1) + x1] - I[y0 * (W + 1) + x1] - I[y1 * (W + 1) + x0] + I[y0 * (W + 1) + x0];
                const v = g[y * W + x];
                bin[y * W + x] = v < soma / ((x1 - x0) * (y1 - y0)) * 0.72 && v < 150 ? 1 : 0;
            }
        }
        return componentes(W, H, bin);
    }

    /* ---------- Quadrado preto dentro de uma região (Otsu local) ---------- */
    function marcadorNaRegiao(img, reg) {
        const { W, H, g } = img;
        const x0 = Math.max(0, Math.floor(reg.x0)), y0 = Math.max(0, Math.floor(reg.y0));
        const x1 = Math.min(W, Math.ceil(reg.x1)), y1 = Math.min(H, Math.ceil(reg.y1));
        const w = x1 - x0, h = y1 - y0;
        if (w < 8 || h < 8) return null;
        const hist = new Uint32Array(256);
        for (let y = y0; y < y1; y++) for (let x = x0; x < x1; x++) hist[g[y * W + x]]++;
        const tot = w * h;
        let soma = 0; for (let i = 0; i < 256; i++) soma += i * hist[i];
        let sB = 0, wB = 0, melhor = 0, lim = 100;
        for (let t = 0; t < 256; t++) {
            wB += hist[t]; if (!wB) continue;
            const wF = tot - wB; if (!wF) break;
            sB += t * hist[t];
            const mB = sB / wB, mF = (soma - sB) / wF, v = wB * wF * (mB - mF) * (mB - mF);
            if (v > melhor) { melhor = v; lim = t; }
        }
        // pouco contraste: não há quadrado preto aqui
        let claro = 0, nc = 0, escuro = 0, ne = 0;
        for (let i = 0; i < 256; i++) if (i > lim) { claro += i * hist[i]; nc += hist[i]; } else { escuro += i * hist[i]; ne += hist[i]; }
        if (!nc || !ne || claro / nc - escuro / ne < 45) return null;
        const bin = new Uint8Array(w * h);
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) bin[y * w + x] = g[(y + y0) * W + x + x0] <= lim ? 1 : 0;
        const ladoMin = reg.ladoMin || 5, ladoMax = reg.ladoMax || Math.min(w, h) * 0.8;
        let achou = null;
        for (const c of componentes(w, h, bin)) {
            const asp = c.w / c.h;
            if (c.w < ladoMin || c.h < ladoMin || c.w > ladoMax || c.h > ladoMax) continue;
            if (asp < 0.55 || asp > 1.8 || c.preench < 0.62) continue;
            if (c.minX === 0 || c.minY === 0 || c.minX + c.w >= w || c.minY + c.h >= h) continue; // cortado na borda
            if (!achou || c.n > achou.n) achou = c;
        }
        if (!achou) return null;
        return { x: achou.cx + x0, y: achou.cy + y0, lado: Math.sqrt(achou.n) };
    }

    /* Procura os 4 cantos, um em cada região (sup. esq., sup. dir., inf. dir., inf. esq.) */
    function detectarCantos(img, regioes) {
        return regioes.map(r => marcadorNaRegiao(img, r));
    }

    /* Ajusta os cantos na imagem grande a partir dos pontos achados na pequena */
    function refinarCantos(img, pts, fator) {
        const raio = Math.max(img.W, img.H) * 0.06;
        return pts.map(p => {
            const x = p.x * fator, y = p.y * fator, l = (p.lado || 10) * fator;
            const r = Math.max(raio, l * 2.2);
            return marcadorNaRegiao(img, { x0: x - r, y0: y - r, x1: x + r, y1: y + r, ladoMin: l * 0.5, ladoMax: l * 1.8 }) || { x, y, lado: l };
        });
    }

    /* ---------- Amostragem ---------- */
    function amostrador(W, H, g, M) {
        const cinza = (x, y) => {
            const [u, v] = M(x, y);
            const ix = Math.round(u), iy = Math.round(v);
            if (ix < 0 || iy < 0 || ix >= W || iy >= H) return 255;
            return g[iy * W + ix];
        };
        const fracao = (x, y, raio, limite, passo) => {
            passo = passo || raio / 5;
            let n = 0, esc = 0;
            for (let dy = -raio; dy <= raio + 1e-9; dy += passo)
                for (let dx = -raio; dx <= raio + 1e-9; dx += passo) {
                    if (dx * dx + dy * dy > raio * raio) continue;
                    n++; if (cinza(x + dx, y + dy) < limite) esc++;
                }
            return n ? esc / n : 0;
        };
        const papel = (x, y, raio) => {
            const v = [];
            for (let a = 0; a < 24; a++) { const t = a / 24 * Math.PI * 2; v.push(cinza(x + Math.cos(t) * raio, y + Math.sin(t) * raio)); }
            v.sort((p, q) => p - q);
            return v[Math.floor(v.length * 0.8)];
        };
        return { cinza, fracao, papel };
    }
    function mediana(a) { const b = [...a].sort((p, q) => p - q); return b[Math.floor(b.length / 2)]; }

    /* Decide a resposta de uma questão a partir do preenchimento de cada bolinha */
    function decidir(f) {
        const ord = f.map((v, i) => [v, i]).sort((a, b) => b[0] - a[0]);
        const base = Math.min(...f);
        const [v1, i1] = ord[0], v2 = ord[1] ? ord[1][0] : 0;
        const forte = v => v >= 0.45 && v - base >= 0.22;
        if (forte(v1) && forte(v2)) return v1 - v2 >= 0.3 ? [LETRAS[i1], true] : ['M', true];
        if (forte(v1)) return [LETRAS[i1], false];
        if (v1 >= 0.28 && v1 - v2 >= 0.18) return [LETRAS[i1], true];
        return ['-', false];
    }

    /* =====================================================================
       Folha da escola
       ===================================================================== */

    /* Com 4 cantos aproximados, acha a rotação certa pelo quadradinho de orientação */
    function orientar(img, L, pts) {
        let melhor = null;
        for (let rot = 0; rot < 4; rot++) {
            const p = [0, 1, 2, 3].map(i => [pts[(i + rot) % 4].x, pts[(i + rot) % 4].y]);
            const M = homografia(L.marcadores, p);
            if (!M) continue;
            const s = amostrador(img.W, img.H, img.g, M);
            const branco = mediana(L.branco.map(([x, y]) => s.cinza(x, y)));
            const lim = branco * 0.6;
            const ro = (L.orientacao_tam || 4) * 0.4;
            const ori = s.fracao(L.orientacao[0], L.orientacao[1], ro, lim, ro / 4);
            const anti = Math.max(...L.anti.map(([x, y]) => s.fracao(x, y, ro, lim, ro / 4)));
            if (ori < 0.55 || anti > 0.3) continue;
            const nota = ori - anti;
            if (!melhor || nota > melhor.nota) melhor = { nota, M, pts: p };
        }
        return melhor;
    }

    /* Foto inteira (sem guia): procura os quadradinhos pretos, agrupa os de
       tamanho parecido e testa os 4 da ponta de cada grupo */
    function acharCantos(img, L) {
        const { W, H } = img, N = W * H;
        const comps = manchasEscuras(W, H, img.g).filter(c =>
            c.w >= 5 && c.h >= 5 && c.w / c.h > 0.55 && c.w / c.h < 1.8 && c.preench > 0.68 && c.n > N * 0.00002 && c.n < N * 0.02);
        comps.sort((a, b) => b.n - a.n);
        const vistos = new Set();
        let melhor = null;
        for (const ref of comps.slice(0, 40)) {
            const grupo = comps.filter(c => c.n > ref.n * 0.6 && c.n < ref.n * 1.6);
            const chave = grupo.map(c => c.cx | 0).join(',');
            if (grupo.length < 4 || vistos.has(chave)) continue;
            vistos.add(chave);
            const ext = [
                grupo.reduce((a, c) => c.cx + c.cy < a.cx + a.cy ? c : a),   // sup. esq.
                grupo.reduce((a, c) => c.cx - c.cy > a.cx - a.cy ? c : a),   // sup. dir.
                grupo.reduce((a, c) => c.cx + c.cy > a.cx + a.cy ? c : a),   // inf. dir.
                grupo.reduce((a, c) => c.cy - c.cx > a.cy - a.cx ? c : a),   // inf. esq.
            ];
            if (new Set(ext).size < 4) continue;
            let area = 0;
            for (let i = 0; i < 4; i++) { const p = ext[i], r = ext[(i + 1) % 4]; area += p.cx * r.cy - r.cx * p.cy; }
            area = Math.abs(area) / 2;
            if (area < N * 0.05) continue;
            const o = orientar(img, L, ext.map(p => ({ x: p.cx, y: p.cy })));
            if (!o) continue;
            const nota = o.nota * 2 + area / N;
            if (!melhor || nota > melhor.nota) melhor = Object.assign(o, { nota });
        }
        return melhor;
    }

    /* Lê o QR code (a identificação do aluno é o mais importante da folha).
       1) recorta a região do QR já sem perspectiva, em várias escalas, com e sem binarização;
       2) se não der, passa o jsQR no pedaço original da foto em volta do QR. */
    function lerQR(img, M, L) {
        if (!window.jsQR) return null;
        const [qx, qy, ql] = L.qr;
        const s = amostrador(img.W, img.H, img.g, M);
        const tentar = (d, w, h) => { const r = window.jsQR(d, w, h, { inversionAttempts: 'attemptBoth' }); return r && r.data && interpretarQR(r.data) ? r.data : null; };
        for (const pxmm of [8, 6, 11, 4.5, 14]) {
            const m = 4, lado = Math.round((ql + 2 * m) * pxmm);
            const cinzas = new Uint8ClampedArray(lado * lado);
            for (let y = 0; y < lado; y++) for (let x = 0; x < lado; x++) cinzas[y * lado + x] = s.cinza(qx - m + x / pxmm, qy - m + y / pxmm);
            const d = new Uint8ClampedArray(lado * lado * 4);
            for (let i = 0; i < cinzas.length; i++) { d[i * 4] = d[i * 4 + 1] = d[i * 4 + 2] = cinzas[i]; d[i * 4 + 3] = 255; }
            let r = tentar(d, lado, lado); if (r) return r;
            /* binarização: limiar entre o papel (claro) e os módulos (escuros) do próprio QR */
            const ord = Array.from(cinzas).sort((p, q) => p - q), lim = (ord[Math.floor(ord.length * 0.12)] + ord[Math.floor(ord.length * 0.9)]) / 2;
            for (let i = 0; i < cinzas.length; i++) { const v = cinzas[i] < lim ? 0 : 255; d[i * 4] = d[i * 4 + 1] = d[i * 4 + 2] = v; }
            r = tentar(d, lado, lado); if (r) return r;
        }
        /* recorte direto da foto (sem retificar), com folga em volta do QR */
        const cs = [[qx, qy], [qx + ql, qy], [qx + ql, qy + ql], [qx, qy + ql]].map(([x, y]) => M(x, y));
        const xs = cs.map(c => c[0]), ys = cs.map(c => c[1]), fol = (Math.max(...xs) - Math.min(...xs)) * 0.35;
        const x0 = Math.max(0, Math.floor(Math.min(...xs) - fol)), y0 = Math.max(0, Math.floor(Math.min(...ys) - fol));
        const x1 = Math.min(img.W, Math.ceil(Math.max(...xs) + fol)), y1 = Math.min(img.H, Math.ceil(Math.max(...ys) + fol));
        const w = x1 - x0, h = y1 - y0;
        if (w > 20 && h > 20) {
            const d = new Uint8ClampedArray(w * h * 4);
            for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) { const v = img.g[(y0 + y) * img.W + x0 + x], k = (y * w + x) * 4; d[k] = d[k + 1] = d[k + 2] = v; d[k + 3] = 255; }
            const r = tentar(d, w, h); if (r) return r;
        }
        return null;
    }
    function interpretarQR(txt) {
        const m = /^VVB1\|P(\d+)(?:\|A(\d+))?$/.exec(txt || '');
        return m ? { prova: +m[1], aluno: m[2] ? +m[2] : null } : null;
    }

    /* Lê as bolinhas da folha da escola com a homografia pronta */
    function lerSistema(img, L, o) {
        const M = o.M, s = amostrador(img.W, img.H, img.g, M);
        const respostas = [], preench = [], duvidas = [], alvos = [];
        L.questoes.forEach((q, qi) => {
            const rl = L.raio_leitura || 2.0, rp = L.raio_papel || 4.0;
            const f = q.alts.map(([x, y]) => s.fracao(x, y, rl, s.papel(x, y, rp) * 0.62, rl / 5));
            preench.push(f);
            const [r, duv] = decidir(f);
            if (duv) duvidas.push(qi);
            respostas.push(r);
            alvos.push(q.alts.map(([x, y]) => M(x, y)));
        });
        const px = Math.hypot(...[0, 1].map(k => M(10, 0)[k] - M(0, 0)[k])) / 10;
        const qr = interpretarQR(lerQR(img, M, L));
        const [qx, qy, ql] = L.qr, fq = 1.5;
        const qrBox = [[qx - fq, qy - fq], [qx + ql + fq, qy - fq], [qx + ql + fq, qy + ql + fq], [qx - fq, qy + ql + fq]].map(([x, y]) => M(x, y));
        const q0 = L.questoes[0], qn = L.questoes[L.questoes.length - 1];
        const ys = L.questoes.map(q => q.y), xs = L.questoes.flatMap(q => q.alts.map(a => a[0])), rr = (L.raio || 2) + 2.2;
        const gx0 = Math.min(...L.questoes.map(q => q.x)) - 1, gx1 = Math.max(...xs) + rr, gy0 = Math.min(...ys) - rr - (L.cabecalhos && L.cabecalhos.length ? 3 : 0), gy1 = Math.max(...ys) + rr;
        const gabBox = [[gx0, gy0], [gx1, gy0], [gx1, gy1], [gx0, gy1]].map(([x, y]) => M(x, y));
        return { ok: true, tipo: 'escola', respostas, preench, duvidas, alvos, raioPx: px * (L.raio || 3) * 1.25, contorno: L.marcadores.map(([x, y]) => M(x, y)), qr, qrBox, gabBox, img };
    }

    /* Foto enviada (sem guia): procura os cantos na imagem toda */
    function ler(fonte, L) {
        const img = prepararImagem(fonte, 1600);
        const o = acharCantos(img, L);
        if (!o) return { ok: false, erro: 'Não encontrei os quadrados pretos da área de respostas. Fotografe a parte de baixo da folha, de cima, bem iluminada e sem cortar os quadrados dos cantos.', img };
        return lerSistema(img, L, o);
    }

    /* Câmera: cantos já achados pela guia */
    function lerComCantos(img, L, pts) {
        const o = orientar(img, L, pts) || acharCantos(img, L);
        if (!o) return { ok: false, erro: 'Achei os 4 quadrados, mas não reconheci a folha da escola. É uma folha de outro modelo? Troque para "Outra folha".', img };
        return lerSistema(img, L, o);
    }

    /* =====================================================================
       Folha de outro modelo
       ===================================================================== */

    /* Plano "virtual": largura 1000 entre os quadrados e altura proporcional */
    function planoExterno(pts) {
        const d = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
        const larg = (d(pts[0], pts[1]) + d(pts[3], pts[2])) / 2, alt = (d(pts[0], pts[3]) + d(pts[1], pts[2])) / 2;
        const asp = alt / Math.max(1, larg), V = [[0, 0], [1000, 0], [1000, 1000 * asp], [0, 1000 * asp]];
        return { asp, M: homografia(V, pts.map(p => [p.x, p.y])) };
    }

    /* Posição de cada bolinha (0 a 1) pelo formato informado */
    function posicoesExterno(F, nq, nalt) {
        const passoAlt = nalt > 1 ? (F.p2[0] - F.p1[0]) / (nalt - 1) : 0;
        const passoLin = F.por_coluna > 1 ? (F.p3[1] - F.p1[1]) / (F.por_coluna - 1) : 0;
        const derivaX = F.por_coluna > 1 ? (F.p3[0] - F.p2[0]) / (F.por_coluna - 1) : 0;   // folha levemente torta
        const passoCol = F.colunas > 1 ? [(F.p4[0] - F.p1[0]) / (F.colunas - 1), (F.p4[1] - F.p1[1]) / (F.colunas - 1)] : [0, 0];
        const q = [];
        for (let i = 0; i < nq; i++) {
            const c = Math.floor(i / F.por_coluna), r = i % F.por_coluna;
            const alts = [];
            for (let a = 0; a < nalt; a++) alts.push([F.p1[0] + c * passoCol[0] + a * passoAlt + r * derivaX, F.p1[1] + c * passoCol[1] + r * passoLin]);
            q.push(alts);
        }
        return { q, passoAlt, passoLin };
    }

    function lerExterno(img, pts, F, nq, nalt) {
        const { asp, M } = planoExterno(pts);
        if (!M) return { ok: false, erro: 'Não consegui endireitar a folha.', img };
        const P = posicoesExterno(F, nq, nalt);
        const V = ([u, v]) => [u * 1000, v * 1000 * asp];
        const raio = Math.max(2, 0.36 * Math.min(Math.abs(P.passoAlt) * 1000 || 99, Math.abs(P.passoLin) * 1000 * asp || 99));
        const s = amostrador(img.W, img.H, img.g, M);
        const respostas = [], preench = [], duvidas = [], alvos = [];
        P.q.forEach((alts, qi) => {
            const f = alts.map(a => { const [x, y] = V(a); return s.fracao(x, y, raio * 0.62, s.papel(x, y, raio * 1.45) * 0.62); });
            preench.push(f);
            const [r, duv] = decidir(f);
            if (duv) duvidas.push(qi);
            respostas.push(r);
            alvos.push(alts.map(a => M(...V(a))));
        });
        const px = Math.hypot(...[0, 1].map(k => M(raio, 0)[k] - M(0, 0)[k]));
        return { ok: true, tipo: 'externa', respostas, preench, duvidas, alvos, raioPx: px * 1.2, contorno: pts.map(p => [p.x, p.y]), qr: null, img };
    }

    /* Foto de folha externa: um quadrado em cada quadrante da imagem */
    function cantosNaFoto(img) {
        const { W, H } = img, l = Math.max(W, H);
        const regs = [[0, 0], [1, 0], [1, 1], [0, 1]].map(([i, j]) => ({ x0: i ? W * 0.55 : 0, x1: i ? W : W * 0.45, y0: j ? H * 0.55 : 0, y1: j ? H : H * 0.45, ladoMin: l * 0.008, ladoMax: l * 0.12 }));
        const pts = detectarCantos(img, regs);
        return pts.every(Boolean) ? pts : null;
    }

    /* Imagem endireitada (para o professor ajustar o formato) */
    function retificar(img, pts, largura) {
        const { asp, M } = planoExterno(pts);
        const w = largura, h = Math.round(largura * asp);
        const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
        const ctx = cv.getContext('2d'), src = img.canvas.getContext('2d').getImageData(0, 0, img.W, img.H).data;
        const out = ctx.createImageData(w, h);
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
            const [u, v] = M(x / w * 1000, y / h * 1000 * asp), ix = Math.round(u), iy = Math.round(v), k = (y * w + x) * 4;
            if (ix < 0 || iy < 0 || ix >= img.W || iy >= img.H) { out.data[k + 3] = 255; continue; }
            const j = (iy * img.W + ix) * 4;
            out.data[k] = src[j]; out.data[k + 1] = src[j + 1]; out.data[k + 2] = src[j + 2]; out.data[k + 3] = 255;
        }
        ctx.putImageData(out, 0, 0);
        return cv;
    }

    /* ---------- Desenha a foto com as marcações conferidas ---------- */
    function desenhar(canvas, res, gabarito, respostas) {
        const img = res.img, ctx = canvas.getContext('2d');
        canvas.width = img.W; canvas.height = img.H;
        ctx.drawImage(img.canvas, 0, 0);
        if (!res.ok) return;
        respostas = respostas || res.respostas;
        const r = res.raioPx;
        ctx.lineWidth = Math.max(2, r * 0.18);
        const poli = (pts, cor, tracejado) => { ctx.save(); ctx.strokeStyle = cor; ctx.setLineDash(tracejado ? [r * 0.9, r * 0.6] : []); ctx.beginPath(); pts.forEach(([u, v], i) => i ? ctx.lineTo(u, v) : ctx.moveTo(u, v)); ctx.closePath(); ctx.stroke(); ctx.restore(); };
        poli(res.contorno, 'rgba(30,154,43,.55)', false);
        /* linha pontilhada em cima do gabarito e da identificação do aluno (QR) */
        if (res.gabBox) poli(res.gabBox, '#1e9a2b', true);
        if (res.qrBox) { ctx.save(); ctx.lineWidth = Math.max(3, r * 0.3); poli(res.qrBox, res.qr && res.qr.aluno ? '#1e9a2b' : '#e08a00', true); ctx.restore(); }
        const lw = Math.max(2.5, r * 0.26);
        const anel = (u, v, cor, tracejado) => { ctx.save(); ctx.lineWidth = lw; ctx.strokeStyle = cor; if (tracejado) ctx.setLineDash([r * 0.35, r * 0.28]); ctx.beginPath(); ctx.arc(u, v, r * 1.05, 0, Math.PI * 2); ctx.stroke(); ctx.restore(); };
        res.alvos.forEach((alts, i) => {
            const marcada = respostas[i], certa = gabarito[i];
            alts.forEach(([u, v], a) => {
                const l = LETRAS[a];
                if (l === marcada) {
                    if (certa === '*' || l === certa) {                       // marcou a certa: anel verde cheio
                        ctx.save(); ctx.fillStyle = 'rgba(30,154,43,.28)'; ctx.beginPath(); ctx.arc(u, v, r * 1.05, 0, Math.PI * 2); ctx.fill(); ctx.restore();
                        anel(u, v, '#1e9a2b', false);
                    } else {                                                   // marcou errado: anel vermelho e um X
                        ctx.save(); ctx.fillStyle = 'rgba(224,37,26,.25)'; ctx.beginPath(); ctx.arc(u, v, r * 1.05, 0, Math.PI * 2); ctx.fill(); ctx.restore();
                        anel(u, v, '#e0251a', false);
                        ctx.save(); ctx.strokeStyle = '#e0251a'; ctx.lineWidth = lw; const d = r * 0.55;
                        ctx.beginPath(); ctx.moveTo(u - d, v - d); ctx.lineTo(u + d, v + d); ctx.moveTo(u + d, v - d); ctx.lineTo(u - d, v + d); ctx.stroke(); ctx.restore();
                    }
                } else if (l === certa && marcada !== certa) {                // a certa, que o aluno não marcou: verde tracejado
                    anel(u, v, '#1e9a2b', true);
                } else if (marcada === 'M' && res.preench[i][a] >= 0.45) {    // duas marcadas: amarelo
                    anel(u, v, '#d99a1d', false);
                }
            });
            if (marcada === '-' || marcada === undefined) {                    // em branco: traço amarelo ao lado do número
                const [u0, v0] = alts[0]; ctx.save(); ctx.fillStyle = '#d99a1d'; ctx.beginPath(); ctx.arc(u0 - r * 2.6, v0, r * 0.32, 0, Math.PI * 2); ctx.fill(); ctx.restore();
            }
        });
    }

    function carregarArquivo(arquivo) {
        return new Promise((ok, erro) => {
            const img = new Image();
            img.onload = () => ok(img);
            img.onerror = () => erro(new Error('Não foi possível abrir a imagem.'));
            img.src = URL.createObjectURL(arquivo);
        });
    }

    return { LETRAS, homografia, prepararImagem, detectarCantos, refinarCantos, cantosNaFoto, ler, lerComCantos, lerExterno, posicoesExterno, retificar, desenhar, carregarArquivo, interpretarQR };
})();
