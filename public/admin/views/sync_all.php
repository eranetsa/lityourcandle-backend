<?php
/** @var int $since */
/** @var int $limit */
/** @var ?array $lastRun */
?>

<div class="card">
  <h2 style="margin-top:0;">مزامنة RevenueCat — دفعة جماعية</h2>
  <p style="color:var(--text-muted);">
    تجلب هذه الصفحة آخر حالة اشتراك لـ <?= (int)$limit ?> مستخدم من RevenueCat
    وتحدّث الجداول المحلية. يمكنك الضغط مرارًا للتقدّم. للمسح الكامل لقاعدة
    بيانات كبيرة، استخدم الأمر: <code>php bin/sync_rc_subscribers.php</code>
  </p>

  <form method="POST" action="/admin/?action=sync_all">
    <input type="hidden" name="csrf"  value="<?= csrf() ?>">
    <input type="hidden" name="since" value="<?= (int)$since ?>">
    <input type="hidden" name="limit" value="<?= (int)$limit ?>">
    <button type="submit" class="btn-primary">
      مزامنة الـ <?= (int)$limit ?> التالين (بدءًا من المعرّف &gt; <?= (int)$since ?>)
    </button>
  </form>

  <?php if ($lastRun !== null): ?>
    <?php if (!empty($lastRun['error'])): ?>
      <p style="color:#f87171; margin-top:16px;">
        خطأ: <?= htmlspecialchars((string)$lastRun['error']) ?>
      </p>
    <?php else: ?>
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-top:18px;">
        <div>
          <div style="color:var(--text-muted); font-size:12px;">تم فحصهم</div>
          <div style="font-size:22px;"><?= (int)$lastRun['users_checked'] ?></div>
        </div>
        <div>
          <div style="color:var(--text-muted); font-size:12px;">لديهم اشتراك نشط</div>
          <div style="font-size:22px;"><?= (int)$lastRun['users_with_active_sub'] ?></div>
        </div>
        <div>
          <div style="color:var(--text-muted); font-size:12px;">تم تحديثهم</div>
          <div style="font-size:22px;"><?= (int)$lastRun['users_updated'] ?></div>
        </div>
        <div>
          <div style="color:var(--text-muted); font-size:12px;">أرصدة جلسات إضافية</div>
          <div style="font-size:22px;"><?= (int)$lastRun['non_sub_credits'] ?></div>
        </div>
        <div>
          <div style="color:var(--text-muted); font-size:12px;">أخطاء</div>
          <div style="font-size:22px; color:<?= (int)$lastRun['errors'] > 0 ? '#f87171' : 'inherit' ?>;">
            <?= (int)$lastRun['errors'] ?>
          </div>
        </div>
        <div>
          <div style="color:var(--text-muted); font-size:12px;">آخر معرّف</div>
          <div style="font-size:22px;"><?= $lastRun['last_user_id'] !== null ? (int)$lastRun['last_user_id'] : '—' ?></div>
        </div>
      </div>

      <?php if (!empty($lastRun['per_user'])): ?>
        <h3 style="margin-top:24px;">تفاصيل آخر دفعة</h3>
        <table>
          <thead><tr><th>#</th><th>مطابق</th><th>نشط</th><th>محدّث</th><th>أرصدة</th><th>ملاحظات</th></tr></thead>
          <tbody>
            <?php foreach ($lastRun['per_user'] as $uid => $r): ?>
              <tr>
                <td><a href="?action=user&amp;id=<?= (int)$uid ?>"><?= (int)$uid ?></a></td>
                <td><?= htmlspecialchars((string)($r['matched_id'] ?? '—')) ?></td>
                <td><?= (int)($r['active_subs_seen'] ?? 0) ?></td>
                <td><?= (int)($r['sub_rows_written'] ?? 0) ?></td>
                <td><?= (int)($r['non_sub_credits']  ?? 0) ?></td>
                <td style="color:var(--text-muted); font-size:12px;">
                  <?php if (!empty($r['error'])): ?>
                    <span style="color:#f87171;"><?= htmlspecialchars((string)$r['error']) ?></span>
                  <?php else: ?>
                    <?= htmlspecialchars(implode(' / ', (array)($r['notes'] ?? []))) ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
