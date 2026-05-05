<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
      <h2 style="margin:0;">جلسات محادثة — <?= htmlspecialchars((string)($user['name'] ?? '—')) ?></h2>
      <div style="color:var(--text-muted); font-size:13px; margin-top:4px;">
        <?= htmlspecialchars((string)($user['email'] ?? '')) ?>
        <span class="badge" style="margin-inline-start:8px;"><?= htmlspecialchars((string)$user['role']) ?></span>
      </div>
    </div>
    <a href="?action=ai_users" class="btn btn-ghost">← العودة لقائمة المستخدمين</a>
  </div>
</div>

<div class="card">
  <h3>الجلسات (مجمّعة باليوم)</h3>
  <p style="color:var(--text-muted); margin:0 0 10px;">كل جلسة = نشاط المستخدم خلال يوم تقويمي واحد.</p>
  <?php if (empty($days)): ?>
    <p style="color:var(--text-muted); padding:24px; text-align:center;">لا توجد محادثات لهذا المستخدم.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>التاريخ</th>
          <th>عدد الرسائل</th>
          <th>تصعيد</th>
          <th>أوّل رسالة في اليوم</th>
          <th>الفترة</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($days as $d): ?>
          <tr>
            <td style="white-space:nowrap; font-weight:700;"><?= htmlspecialchars((string)$d['d']) ?></td>
            <td><?= number_format((int)$d['msgs']) ?></td>
            <td><?= ((int)$d['escalated']) > 0
                  ? '<span class="badge b-trial">' . (int)$d['escalated'] . '</span>'
                  : '<span class="badge b-mute">0</span>' ?></td>
            <td style="max-width:420px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--text-muted);"><?= htmlspecialchars(mb_substr((string)($d['opener'] ?? ''), 0, 160)) ?></td>
            <td style="font-size:12px; color:var(--text-muted); white-space:nowrap;">
              <?= htmlspecialchars(substr((string)$d['started_at'], 11, 5)) ?>
              –
              <?= htmlspecialchars(substr((string)$d['ended_at'], 11, 5)) ?>
            </td>
            <td>
              <a href="?action=ai_session&amp;id=<?= (int)$user['id'] ?>&amp;date=<?= urlencode((string)$d['d']) ?>" class="btn">عرض الحوار →</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
