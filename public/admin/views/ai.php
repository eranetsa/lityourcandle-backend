<div class="card">
  <h2>شمعة AI — الاستخدام</h2>
  <div class="stats" style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));">
    <div class="stat"><div class="n"><?= number_format((int)($stats['total'] ?? 0)) ?></div><div class="l">إجمالي المحادثات</div></div>
    <div class="stat"><div class="n"><?= number_format((int)($stats['today'] ?? 0)) ?></div><div class="l">اليوم</div></div>
    <div class="stat"><div class="n"><?= number_format((int)($stats['escalated'] ?? 0)) ?></div><div class="l">تصعيد لمستشار</div></div>
    <div class="stat"><div class="n"><?= number_format((int)($stats['tokens_in'] ?? 0)) ?></div><div class="l">tokens in</div></div>
    <div class="stat"><div class="n"><?= number_format((int)($stats['tokens_out'] ?? 0)) ?></div><div class="l">tokens out</div></div>
  </div>
</div>

<div class="card">
  <h3>أحدث 50 محادثة</h3>
  <table>
    <thead><tr><th>#</th><th>المستخدم</th><th>المزاج</th><th>الرسالة</th><th>تصعيد؟</th><th>التاريخ</th></tr></thead>
    <tbody>
      <?php foreach ($latest as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= $r['user_id'] ?></td>
        <td><?= htmlspecialchars($r['mood'] ?? '—') ?></td>
        <td style="max-width:480px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars(mb_substr((string)$r['user_message'], 0, 140)) ?></td>
        <td><?= $r['escalated'] ? '<span class="badge b-trial">نعم</span>' : '<span class="badge b-mute">لا</span>' ?></td>
        <td style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars($r['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
