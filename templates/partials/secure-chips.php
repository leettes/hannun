<?php
/** 회의록 화면 머리의 보안 표시. @var int $idleSeconds @var ?string $visibility */
?>
<div class="secure-chips">
    <?php if (($visibility ?? null) !== null): ?>
        <span class="vis<?= $visibility === 'attendees' ? ' vis-attendees' : '' ?>"><svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="6" cy="5.5" r="2.3"/><path d="M1.8 13a4.2 4.2 0 0 1 8.4 0M11 3.5a2.2 2.2 0 0 1 0 4.2M12.5 13a4 4 0 0 0-1.7-3.3"/></svg>공개 범위: <?= e(App\Meetings\MeetingRepository::VISIBILITY_SHORT[$visibility] ?? $visibility) ?></span>
    <?php endif ?>
    <span class="secure-chip"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1.5 2.5 3.8v3.7c0 3.2 2.3 6 5.5 7 3.2-1 5.5-3.8 5.5-7V3.8z"/><path d="m5.6 8 1.7 1.7 3.2-3.4"/></svg>2단계 인증 확인됨</span>
    <span class="secure-chip is-muted"><svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><path d="M8 4.5V8l2.3 1.5"/></svg>활동 없으면 <b data-idle-seconds="<?= (int) $idleSeconds ?>"><?= sprintf('%02d:00', intdiv((int) $idleSeconds, 60)) ?></b> 뒤 재인증</span>
    <span class="secure-chip is-muted"><svg viewBox="0 0 16 16" aria-hidden="true"><rect x="3" y="7" width="10" height="7" rx="1.5"/><path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2"/></svg>본문 암호화 저장</span>
</div>
