// 다크/라이트 테마. <head> 에서 바로(defer 없이) 실행해 페이지가 그려지기 전에 적용한다 (깜빡임 방지).
// 고른 값은 localStorage 에 두고, 고른 적이 없으면 OS 설정을 따른다(data-theme 없음 → CSS 미디어 쿼리).
(function () {
    'use strict';

    var KEY = 'hannun-theme';
    var root = document.documentElement;
    var dark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function stored() {
        try {
            var v = localStorage.getItem(KEY);
            return v === 'light' || v === 'dark' ? v : null;
        } catch (e) {
            return null;
        }
    }

    var saved = stored();
    if (saved) root.setAttribute('data-theme', saved);

    function current() {
        return root.getAttribute('data-theme') || (dark && dark.matches ? 'dark' : 'light');
    }

    function label(button) {
        var next = current() === 'dark' ? '라이트' : '다크';
        button.setAttribute('aria-label', next + ' 모드로 바꾸기');
        button.title = next + ' 모드로 바꾸기';
    }

    document.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('.theme-toggle');
        Array.prototype.forEach.call(buttons, function (button) {
            label(button);
            button.addEventListener('click', function () {
                var next = current() === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', next);
                try { localStorage.setItem(KEY, next); } catch (e) { /* 저장 못 해도 이 화면에서는 바뀐다 */ }
                Array.prototype.forEach.call(buttons, label);
            });
        });

        // 고른 적이 없으면 OS 설정이 바뀔 때 버튼 설명도 따라 바꾼다
        if (dark && dark.addEventListener) {
            dark.addEventListener('change', function () {
                if (!root.getAttribute('data-theme')) Array.prototype.forEach.call(buttons, label);
            });
        }
    });
})();
