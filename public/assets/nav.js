// 모바일 햄버거 메뉴: 버튼으로 열고 닫는다. 메뉴는 콘텐츠 위에 뜨고,
// 열려 있는 동안 뒤 화면은 스크롤되지 않는다. Esc, 어두운 배경 누르기, 데스크톱 폭으로 바뀌면 닫힌다.
(function () {
    'use strict';

    var sidebar = document.querySelector('.sidebar');
    var toggle = sidebar && sidebar.querySelector('.nav-toggle');
    if (!toggle) return;

    function isOpen() { return sidebar.classList.contains('is-open'); }

    function setOpen(open) {
        sidebar.classList.toggle('is-open', open);
        document.documentElement.classList.toggle('nav-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? '메뉴 닫기' : '메뉴 열기');
    }

    toggle.addEventListener('click', function () { setOpen(!isOpen()); });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) {
            setOpen(false);
            toggle.focus();
        }
    });

    // 어두운 배경은 .sidebar 의 ::after 라서, 상단 바와 메뉴 패널 밖을 누르면 닫는다
    document.addEventListener('click', function (e) {
        if (isOpen() && !e.target.closest('.sidebar-top, .sidebar-menu')) setOpen(false);
    });

    var desktop = window.matchMedia('(min-width: 761px)');
    var onChange = function () { if (desktop.matches && isOpen()) setOpen(false); };
    if (desktop.addEventListener) desktop.addEventListener('change', onChange);
})();
