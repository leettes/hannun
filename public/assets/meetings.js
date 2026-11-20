// 회의록 화면: 표에서 고른 값 바로 저장, 재인증까지 남은 시간 표시
(function () {
    'use strict';

    document.documentElement.classList.add('js');

    // <select data-autosubmit> 를 바꾸면 그 폼을 바로 보낸다 (스크립트가 없으면 옆의 저장 버튼)
    document.addEventListener('change', function (event) {
        var select = event.target;
        if (select.matches && select.matches('select[data-autosubmit]') && select.form) {
            select.form.submit();
        }
    });

    // 활동이 없으면 재인증 (서버는 요청마다 시간을 새로 센다. 화면은 남은 시간만 보여 준다)
    var idle = document.querySelector('[data-idle-seconds]');
    if (idle) {
        var left = parseInt(idle.getAttribute('data-idle-seconds'), 10) || 0;
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var tick = function () {
            idle.textContent = left > 0 ? pad(Math.floor(left / 60)) + ':' + pad(left % 60) : '지금';
            left = Math.max(0, left - 1);
        };
        tick();
        setInterval(tick, 1000);
    }

    // 목차: 지금 보고 있는 섹션 강조
    var links = Array.prototype.slice.call(document.querySelectorAll('.toc a[href^="#"]'));
    if (links.length) {
        var heads = links.map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); }).filter(Boolean);
        var onScroll = function () {
            var current = heads.length ? heads[0].id : null;
            heads.forEach(function (h) { if (h.getBoundingClientRect().top < 120) current = h.id; });
            links.forEach(function (a) { a.classList.toggle('is-active', a.getAttribute('href') === '#' + current); });
        };
        addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }
})();
