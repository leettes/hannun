// 자동 새로고침: 남은 시간을 보여 주고 0초가 되면 새로고침한다 (JS 가 꺼져 있으면 <noscript> meta refresh).
// 입력란에 포커스가 있거나, 입력 영역(data-pause-refresh)이 열려 있거나, 모바일 메뉴가 열려 있으면 멈춘다.
// 멈춤이 풀리면 처음부터 다시 센다.
(function () {
    'use strict';

    var el = document.querySelector('.refresh-timer');
    if (!el) return;

    var total = parseInt(el.getAttribute('data-refresh-seconds'), 10) || 60;
    var count = el.querySelector('.refresh-count');
    var bar = el.querySelector('.refresh-bar');
    var deadline = Date.now() + total * 1000;
    var reloading = false;

    function paused() {
        var active = document.activeElement;
        return (active && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName))
            || document.querySelector('[data-pause-refresh][open]') !== null
            || document.documentElement.classList.contains('nav-open');
    }

    function tick() {
        if (reloading) return;
        if (paused()) {
            deadline = Date.now() + total * 1000;
            el.classList.add('is-paused');
            count.textContent = '멈춤';
            bar.style.transform = 'scaleX(1)';
            setTimeout(tick, 500);
            return;
        }
        el.classList.remove('is-paused');

        var left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        count.textContent = left > 0 ? left + '초' : '중…';
        bar.style.transform = 'scaleX(' + (left / total) + ')';
        if (left > 0) {
            setTimeout(tick, 250);
        } else {
            reloading = true;
            location.reload();
        }
    }

    el.addEventListener('click', function () { location.reload(); });
    tick();
})();
