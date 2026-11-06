// 표 공통 처리: 머리글(th)의 열 종류(col-*)와 제목을 각 칸(td)에 옮긴다.
// - col-* : 열 기본 폭·줄바꿈 (app.css '표 열 종류')
// - data-label : 작은 화면에서 표를 카드로 바꿀 때 칸 앞에 붙는 제목
// 처리한 표에는 is-responsive 를 붙여, JS 가 꺼져 있으면 카드로 바꾸지 않고 가로 스크롤만 한다.
// 숫자가 빽빽한 표(table-grid)는 카드로 바꾸지 않는다.
(function () {
    'use strict';

    function columns(table) {
        var head = table.tHead && table.tHead.rows[0];
        if (!head) return null;
        var cols = [];
        Array.prototype.forEach.call(head.cells, function (th) {
            var kinds = th.className.match(/\bcol-[a-z-]+/g) || [];
            var label = th.getAttribute('data-label') || th.textContent.replace(/\s+/g, ' ').trim();
            for (var i = 0; i < (th.colSpan || 1); i++) cols.push({ kinds: kinds, label: label });
        });
        return cols;
    }

    // 카드에서 '제목 | 값' 두 칸이 되도록 칸 내용을 한 덩어리로 묶는다 (내용이 여러 줄이어도 값 칸 안에 머문다)
    function wrap(cell) {
        if (cell.firstElementChild && cell.firstElementChild.classList.contains('cell-value')) return;
        var box = document.createElement('div');
        box.className = 'cell-value';
        while (cell.firstChild) box.appendChild(cell.firstChild);
        cell.appendChild(box);
    }

    function enhance(table) {
        var cols = columns(table);
        if (!cols) return;
        Array.prototype.forEach.call(table.tBodies, function (body) {
            Array.prototype.forEach.call(body.rows, function (row) {
                var index = 0;
                Array.prototype.forEach.call(row.cells, function (cell) {
                    var col = cols[index];
                    index += cell.colSpan || 1;
                    if (!col) return;
                    col.kinds.forEach(function (k) { cell.classList.add(k); });
                    if (!cell.hasAttribute('data-label')) cell.setAttribute('data-label', col.label);
                    wrap(cell);
                });
            });
        });
        if (!table.classList.contains('table-grid')) table.classList.add('is-responsive');
    }

    function run() {
        Array.prototype.forEach.call(document.querySelectorAll('table.table'), enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
