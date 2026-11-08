// 대시보드 상세 패널: 탭 전환(고른 탭은 자동 새로고침 뒤에도 유지)과 서비스 선택.
// JS 가 없으면 모든 탭 내용이 차례로 보인다.
(function () {
    'use strict';

    var panel = document.querySelector('[data-detail-panel]');
    if (!panel) return;

    var KEY = 'hannun.dashboard.tab';
    var tabs = panel.querySelectorAll('[data-tab]');
    var bodies = panel.querySelectorAll('[data-panel]');

    function show(name, focus) {
        var found = false;
        tabs.forEach(function (t) { if (t.getAttribute('data-tab') === name) found = true; });
        if (!found) name = 'overview';
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            if (on && focus) t.focus();
        });
        bodies.forEach(function (b) { b.hidden = b.getAttribute('data-panel') !== name; });
        try { sessionStorage.setItem(KEY, name); } catch (e) { /* 저장 불가 환경 */ }
    }

    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { show(t.getAttribute('data-tab')); });
        t.addEventListener('keydown', function (ev) {
            var d = ev.key === 'ArrowRight' ? 1 : ev.key === 'ArrowLeft' ? -1 : 0;
            if (!d) return;
            ev.preventDefault();
            show(tabs[(i + d + tabs.length) % tabs.length].getAttribute('data-tab'), true);
        });
    });

    var saved = null;
    try { saved = sessionStorage.getItem(KEY); } catch (e) { /* 저장 불가 환경 */ }
    show(saved || 'overview');
    panel.classList.add('is-ready');

    var select = panel.querySelector('[data-autosubmit]');
    if (select) {
        select.addEventListener('change', function () { select.form.submit(); });
    }
})();
