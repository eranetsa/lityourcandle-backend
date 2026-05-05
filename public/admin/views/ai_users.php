<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <h2 style="margin:0;">محادثات شمعة AI — حسب المستخدم</h2>
    <a href="?action=ai" class="btn btn-ghost">← العودة للإحصائيات</a>
  </div>
  <form method="get" action="" style="margin-top:14px;">
    <input type="hidden" name="action" value="ai_users">
    <input type="text" name="q" value="<?= htmlspecialchars($q ?? '') ?>" placeholder="ابحث بالاسم أو البريد…" style="max-width:320px;">
    <button type="submit" class="btn" style="margin-inline-start:8px;">بحث</button>
  </form>

  <table style="margin-top:16px;">
    <thead>
      <tr>
        <th>المستخدم</th>
        <th>البريد</th>
        <th>الدور</th>
        <th>عدد الرسائل</th>
        <th>أيام النشاط</th>
        <th>تصعيد</th>
        <th>آخر نشاط</th>
        <th>آخر رسالة</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="9" style="text-align:center; color:var(--text-muted); padding:24px;">لا توجد محادثات بعد.</td></tr>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars((string)($r['name'] ?? '—')) ?></td>
          <td style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars((string)($r['email'] ?? '—')) ?></td>
          <td><span class="badge"><?= htmlspecialchars((string)$r['role']) ?></span></td>
          <td><?= number_format((int)$r['msgs']) ?></td>
          <td><?= number_format((int)$r['days']) ?></td>
          <td><?= ((int)$r['escalated']) > 0
                ? '<span class="badge b-trial">' . (int)$r['escalated'] . '</span>'
                : '<span class="badge b-mute">0</span>' ?></td>
          <td style="color:var(--text-muted); font-size:12px; white-space:nowrap;"><?= htmlspecialchars((string)$r['last_at']) ?></td>
          <td style="max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--text-muted);"><?= htmlspecialchars(mb_substr((string)($r['last_msg'] ?? ''), 0, 120)) ?></td>
          <td><a href="?action=ai_user&amp;id=<?= (int)$r['id'] ?>" class="btn">المحادثات →</a></td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>
