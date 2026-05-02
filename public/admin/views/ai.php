<div class="card">
  <h2 style="margin-top:0;">شمعة - استخدام الذكاء الاصطناعي</h2>
  <div class="stat"><div class="n"><?= $stats['total'] ?? 0 ?></div><div class="l">إجمالي المحادثات</div></div>
  <div class="stat"><div class="n"><?= $stats['today'] ?? 0 ?></div><div class="l">اليوم</div></div>
  <div class="stat"><div class="n"><?= $stats['escalated'] ?? 0 ?></div><div class="l">تصعيد لمستشار</div></div>
  <div class="stat"><div class="n"><?= $stats['tokens_in'] ?? 0 ?></div><div class="l">tokens in</div></div>
  <div class="stat"><div class="n"><?= $stats['tokens_out'] ?? 0 ?></div><div class="l">tokens out</div></div>
</div>
<div class="card">
  <h3>أحدث 50 محادثة</h3>
  <table>
    <thead><tr><th>#</th><th>مستخدم</th><th>المزاج</th><th>الرسالة</th><th>تصعيد؟</th><th>التاريخ</th></tr></thead>
    <tbody>
      <?php foreach ($latest as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= $r['user_id'] ?></td>
        <td><?= htmlspecialchars($r['mood'] ?? '-') ?></td>
        <td><?= htmlspecialchars(mb_substr((string)$r['user_message'], 0, 120)) ?></td>
        <td><?= $r['escalated'] ? 'نعم' : 'لا' ?></td>
        <td><?= htmlspecialchars($r['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
