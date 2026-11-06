// 응답 시간 차트: 서버가 넣어 둔 JSON(.chart-data)으로 SVG 를 그리고, 마우스를 올리면 그 시각의 값을 보여 준다.
// 시각은 unix 초, tzOffset(초)을 더해 UTC 메서드로 읽으면 서버 시간대(KST) 기준이 된다.
(function () {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var HEIGHT = 240;
    var TICK_STEPS = [60, 300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 604800];

    function el(name, attrs, parent) {
        var node = document.createElementNS(NS, name);
        for (var key in attrs) node.setAttribute(key, attrs[key]);
        if (parent) parent.appendChild(node);
        return node;
    }

    function pad(n) { return n < 10 ? '0' + n : String(n); }

    function ms(v) { return v === null ? '-' : Math.round(v).toLocaleString('ko-KR') + 'ms'; }

    function niceStep(raw) {
        var pow = Math.pow(10, Math.floor(Math.log10(raw)));
        var steps = [1, 2, 2.5, 5, 10];
        for (var i = 0; i < steps.length; i++) {
            if (steps[i] * pow >= raw) return steps[i] * pow;
        }
        return 10 * pow;
    }

    function setup(card) {
        var data = JSON.parse(card.querySelector('.chart-data').textContent);
        var plot = card.querySelector('.chart-plot');
        var legend = card.querySelector('.chart-legend');
        var off = data.tzOffset;
        var b = data.bucket;
        var multi = data.series.length > 1;

        function local(t) { return new Date((t + off) * 1000); }
        function hm(d) { return pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()); }
        function md(d) { return pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
        // 서버 시간대 기준으로 step 의 배수가 되는 시각 (정시·자정 정렬)
        function floorLocal(t, step) { return Math.floor((t + off) / step) * step - off; }

        // 색은 리소스 순서(등록 순)를 따른다. 필터로 바뀌지 않는다
        data.series.forEach(function (s, i) {
            s.color = 'var(--series-' + (i % 8 + 1) + ')';
            s.byTime = {};
            s.points.forEach(function (p) { s.byTime[p[0]] = p; });
        });

        var timeSet = {};
        var maxAvg = 0;
        var anyDown = false;
        data.series.forEach(function (s) {
            s.points.forEach(function (p) {
                timeSet[p[0]] = true;
                if (p[1] !== null && p[1] > maxAvg) maxAvg = p[1];
                if (p[3] > 0) anyDown = true;
            });
        });
        var times = Object.keys(timeSet).map(Number).sort(function (a, c) { return a - c; });

        // 범례: 2개 이상일 때 서비스, 장애가 있으면 장애 구간 표시
        legend.textContent = '';
        if (multi) {
            data.series.forEach(function (s) {
                var item = document.createElement('span');
                item.className = 'legend-item';
                var key = document.createElement('span');
                key.className = 'legend-line';
                key.style.background = s.color;
                item.appendChild(key);
                item.appendChild(document.createTextNode(s.name));
                legend.appendChild(item);
            });
        }
        if (anyDown) {
            var down = document.createElement('span');
            down.className = 'legend-item';
            var box = document.createElement('span');
            box.className = 'legend-down';
            down.appendChild(box);
            down.appendChild(document.createTextNode('장애 발생 구간'));
            legend.appendChild(down);
        }
        legend.hidden = legend.childNodes.length === 0;

        // y 축: 평균 최댓값 기준, 느림 기준선이 가까우면 함께 보이게
        var top = maxAvg;
        if (maxAvg >= data.slowMs * 0.6) top = Math.max(top, data.slowMs * 1.05);
        var yStep = top > 0 ? niceStep(top / 4) : 25;
        var yMax = top > 0 ? Math.ceil(top / yStep) * yStep : 100;

        var x0 = floorLocal(data.from, b);
        var x1 = data.to;

        var tip = document.createElement('div');
        tip.className = 'chart-tip';
        tip.hidden = true;

        function render() {
            var w = plot.clientWidth;
            if (w < 80) return;
            var labels = multi && w >= 520;
            var m = { l: 56, r: labels ? 112 : 16, t: 12, b: 26 };
            var pw = w - m.l - m.r;
            var ph = HEIGHT - m.t - m.b;
            function x(t) { return m.l + (t - x0) / (x1 - x0) * pw; }
            function y(v) { return m.t + ph - v / yMax * ph; }

            plot.textContent = '';
            var svg = el('svg', { width: w, height: HEIGHT, class: 'chart-svg' }, plot);

            // 가로 눈금선
            for (var v = 0; v <= yMax + 1e-9; v += yStep) {
                el('line', { x1: m.l, x2: m.l + pw, y1: y(v), y2: y(v), class: v === 0 ? 'chart-base' : 'chart-grid' }, svg);
                el('text', { x: m.l - 8, y: y(v), class: 'chart-axis', 'text-anchor': 'end', 'dominant-baseline': 'middle' }, svg)
                    .textContent = v.toLocaleString('ko-KR') + 'ms';
            }

            // 장애 구간
            data.series.forEach(function (s) {
                s.points.forEach(function (p) {
                    if (p[3] === 0) return;
                    var left = Math.max(m.l, x(p[0]));
                    el('rect', { x: left, y: m.t, width: Math.max(2, Math.min(m.l + pw, x(p[0] + b)) - left), height: ph, class: 'chart-down' }, svg);
                });
            });

            // 느림 기준선
            if (data.slowMs <= yMax) {
                el('line', { x1: m.l, x2: m.l + pw, y1: y(data.slowMs), y2: y(data.slowMs), class: 'chart-slow' }, svg);
                el('text', { x: m.l + 6, y: y(data.slowMs) - 5, class: 'chart-axis' }, svg)
                    .textContent = '느림 기준 ' + ms(data.slowMs);
            }

            // 가로축 시각
            var span = x1 - x0;
            var step = TICK_STEPS[TICK_STEPS.length - 1];
            for (var i = 0; i < TICK_STEPS.length; i++) {
                if (span / TICK_STEPS[i] <= pw / 90) { step = TICK_STEPS[i]; break; }
            }
            for (var t = floorLocal(x0, step) + step; t < x1; t += step) {
                var d = local(t);
                var midnight = d.getUTCHours() === 0 && d.getUTCMinutes() === 0;
                el('text', { x: x(t), y: HEIGHT - 8, class: 'chart-axis', 'text-anchor': 'middle' }, svg)
                    .textContent = step >= 86400 || (span > 86400 && midnight) ? md(d) : hm(d);
            }

            // 선: 응답이 없는 묶음(전부 장애)이나 점검이 오래 빈 곳에서 끊는다
            var maxGap = Math.max(3 * b, 900);
            var ends = [];
            data.series.forEach(function (s) {
                var segments = [];
                var current = null;
                var prevT = null;
                s.points.forEach(function (p) {
                    if (p[1] === null) { current = null; prevT = null; return; }
                    if (current === null || p[0] - prevT > maxGap) {
                        current = [];
                        segments.push(current);
                    }
                    current.push([x(p[0] + b / 2), y(p[1])]);
                    prevT = p[0];
                });
                segments.forEach(function (seg) {
                    if (seg.length === 1) {
                        el('circle', { cx: seg[0][0], cy: seg[0][1], r: 2.5, fill: s.color }, svg);
                        return;
                    }
                    el('path', {
                        d: 'M' + seg.map(function (pt) { return pt[0].toFixed(1) + ' ' + pt[1].toFixed(1); }).join('L'),
                        class: 'chart-line',
                        stroke: s.color,
                    }, svg);
                });
                var last = segments.length ? segments[segments.length - 1] : null;
                if (last) ends.push({ s: s, y: last[last.length - 1][1] });
            });

            // 오른쪽 끝 이름표 (겹치지 않게 아래로 민다)
            if (labels) {
                ends.sort(function (a, c) { return a.y - c.y; });
                var prevY = -Infinity;
                ends.forEach(function (e) {
                    var ly = Math.min(m.t + ph, Math.max(e.y, prevY + 14));
                    prevY = ly;
                    el('line', { x1: m.l + pw + 6, x2: m.l + pw + 14, y1: ly, y2: ly, stroke: e.s.color, class: 'chart-key' }, svg);
                    el('text', { x: m.l + pw + 18, y: ly, class: 'chart-label', 'dominant-baseline': 'middle' }, svg)
                        .textContent = e.s.name;
                });
            }

            // 마우스 위치의 시각 표시
            var cross = el('line', { y1: m.t, y2: m.t + ph, class: 'chart-cross', visibility: 'hidden' }, svg);
            var dots = el('g', {}, svg);
            var hit = el('rect', { x: m.l, y: m.t, width: pw, height: ph, fill: 'transparent' }, svg);
            plot.appendChild(tip);

            function nearest(px) {
                var target = x0 + (px - m.l) / pw * (x1 - x0) - b / 2;
                var lo = 0;
                var hi = times.length - 1;
                while (hi - lo > 1) {
                    var mid = (lo + hi) >> 1;
                    if (times[mid] < target) lo = mid; else hi = mid;
                }
                return Math.abs(times[lo] - target) <= Math.abs(times[hi] - target) ? times[lo] : times[hi];
            }

            function show(evt) {
                var rect = svg.getBoundingClientRect();
                var t = nearest(evt.clientX - rect.left);
                var cx = x(t + b / 2);
                cross.setAttribute('x1', cx);
                cross.setAttribute('x2', cx);
                cross.setAttribute('visibility', 'visible');

                dots.textContent = '';
                tip.textContent = '';
                var head = document.createElement('div');
                head.className = 'chart-tip-time';
                var start = local(t);
                head.textContent = md(start) + ' ' + hm(start) + (b > 60 ? ' ~ ' + hm(local(t + b)) : '');
                tip.appendChild(head);

                data.series.forEach(function (s) {
                    var p = s.byTime[t];
                    if (p && p[1] !== null) {
                        el('circle', { cx: cx, cy: y(p[1]), r: 4, fill: s.color, class: 'chart-dot' }, dots);
                    }
                    var row = document.createElement('div');
                    row.className = 'chart-tip-row';
                    var key = document.createElement('span');
                    key.className = 'legend-line';
                    key.style.background = s.color;
                    var value = document.createElement('strong');
                    value.textContent = p ? ms(p[1]) : '기록 없음';
                    var name = document.createElement('span');
                    name.className = 'muted';
                    name.textContent = s.name;
                    row.appendChild(key);
                    row.appendChild(value);
                    row.appendChild(name);
                    tip.appendChild(row);

                    if (p && (b > 60 || p[3] > 0)) {
                        var extra = document.createElement('div');
                        extra.className = 'chart-tip-extra muted';
                        extra.textContent = (b > 60 ? '최대 ' + ms(p[2]) + ' · 점검 ' + p[4] + '회' : '') +
                            (b > 60 && p[3] > 0 ? ' · ' : '');
                        if (p[3] > 0) {
                            var downText = document.createElement('span');
                            downText.className = 't-down';
                            downText.textContent = '장애 ' + p[3] + '회';
                            extra.appendChild(downText);
                        }
                        tip.appendChild(extra);
                    }
                });

                tip.hidden = false;
                var left = cx + 14;
                if (left + tip.offsetWidth > w) left = cx - 14 - tip.offsetWidth;
                tip.style.left = Math.max(0, left) + 'px';
                tip.style.top = m.t + 'px';
            }

            function hide() {
                cross.setAttribute('visibility', 'hidden');
                dots.textContent = '';
                tip.hidden = true;
            }

            hit.addEventListener('pointermove', show);
            hit.addEventListener('pointerdown', show);
            hit.addEventListener('pointerleave', hide);
        }

        render();
        var lastWidth = plot.clientWidth;
        if (window.ResizeObserver) {
            new ResizeObserver(function () {
                if (plot.clientWidth === lastWidth) return;
                lastWidth = plot.clientWidth;
                window.requestAnimationFrame(render);
            }).observe(plot);
        }
    }

    document.querySelectorAll('.chart-card').forEach(function (card) {
        if (card.querySelector('.chart-data')) setup(card);
    });
})();
