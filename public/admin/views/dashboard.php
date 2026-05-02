<?php
$ICONS = [
  'users'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  'cons'    => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  'sess'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
  'sub'     => '<path d="M21 4H3a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><path d="M1 10h22"/>',
  'ai'      => '<path d="M12 2v6"/><path d="M12 22v-6"/><path d="M4.93 4.93l4.24 4.24"/><path d="M14.83 14.83l4.24 4.24"/><path d="M2 12h6"/><path d="M16 12h6"/>',
  'mood'    => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
];
$tiles = [
  ['n' => $stats['users'],        'l' => 'إجمالي المستخدمين', 'i' => $ICONS['users']],
  ['n' => $stats['consultants'],  'l' => 'المستشارون',        'i' => $ICONS['cons']],
  ['n' => $stats['sessions_30d'], 'l' => 'جلسات 30 يومًا',     'i' => $ICONS['sess']],
  ['n' => $stats['paid_subs'],    'l' => 'اشتراكات مدفوعة',   'i' => $ICONS['sub']],
  ['n' => $stats['ai_today'],     'l' => 'محادثات شمعة اليوم','i' => $ICONS['ai']],
  ['n' => $stats['mood_today'],   'l' => 'تسجيلات المزاج اليوم','i' => $ICONS['mood']],
];
?>
<div class="stats">
  <?php foreach ($tiles as $t): ?>
    <div class="stat">
      <div class="ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $t['i'] ?></svg>
      </div>
      <div class="n"><?= number_format((int)$t['n']) ?></div>
      <div class="l"><?= htmlspecialchars($t['l']) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card" style="margin-top:18px;">
  <h2>أهلاً بك في لوحة التحكم 🕯️</h2>
  <p style="color:var(--text-2); margin:0; line-height:1.7;">
    من هنا يمكنك إدارة المستخدمين، المستشارين، الجلسات، الاشتراكات، البرامج اليومية، ورسائل الإلهام،
    ومراقبة استخدام شمعة AI وتحليلات المزاج. كل تغيير يُسجَّل في سجل المراجعة.
  </p>
</div>
