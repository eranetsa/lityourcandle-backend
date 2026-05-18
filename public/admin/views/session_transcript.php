<?php
/** @var array $session */
/** @var array $messages */
?>
<div class="card">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
      <h2 style="margin:0 0 4px; font-size:18px;">
        جلسة #<?= (int)$session['id'] ?>
        <span class="badge b-mute" style="margin-inline-start:6px;"><?= htmlspecialchars((string)$session['type']) ?></span>
        <span class="badge b-<?= $session['status'] === 'completed' ? 'paid' : ($session['status'] === 'in_progress' ? 'active' : 'mute') ?>"><?= htmlspecialchars((string)$session['status']) ?></span>
      </h2>
      <div style="color:var(--text-muted); font-size:13px;">
        <?= htmlspecialchars((string)$session['user_name']) ?>
        ·
        <?= htmlspecialchars($session['consultant_name'] ?: '—') ?>
        ·
        <?= htmlspecialchars($session['created_at']) ?>
      </div>
    </div>
    <a href="?action=user&amp;id=<?= (int)$session['user_id'] ?>" class="btn-ghost btn-sm">← صفحة المستخدم</a>
  </div>
</div>

<div class="card">
  <h3 style="margin-top:0;">المحادثة (<?= count($messages) ?> رسالة)</h3>
  <?php if (empty($messages)): ?>
    <p style="color:var(--text-muted); text-align:center; padding:14px 0;">
      لا توجد رسائل في هذه الجلسة.
    </p>
  <?php else: ?>
    <div style="display:flex; flex-direction:column; gap:10px;">
      <?php foreach ($messages as $m): ?>
        <?php
          $isCons = $m['sender_role'] === 'consultant';
          $align = $isCons ? 'flex-start' : 'flex-end';
          $bg    = $isCons ? 'var(--surface-2)' : 'rgba(199,36,177,0.10)';
          $border= $isCons ? 'var(--border-soft)' : 'rgba(199,36,177,0.30)';
          $label = $isCons ? 'المستشار' : 'العميل';
        ?>
        <div style="display:flex; justify-content:<?= $align ?>;">
          <div style="max-width:78%; padding:12px 14px; border-radius:14px; background:<?= $bg ?>; border:1px solid <?= $border ?>;">
            <div style="font-size:11px; color:var(--text-muted); margin-bottom:4px;">
              <?= $label ?> · <?= htmlspecialchars((string)$m['created_at']) ?>
              <?php if (!empty($m['read_at'])): ?>
                · <span style="color:#4ade80;">✓ مقروءة</span>
              <?php endif; ?>
            </div>
            <div style="white-space:pre-wrap; line-height:1.7;"><?= htmlspecialchars((string)$m['body']) ?></div>
            <?php if (!empty($m['attachment_url'])): ?>
              <div style="margin-top:6px;">
                <a href="<?= htmlspecialchars($m['attachment_url']) ?>" target="_blank" style="font-size:12px;">📎 مرفق</a>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
