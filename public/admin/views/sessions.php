<div class="card">
  <h2 style="margin-top:0;">الجلسات</h2>
  <table>
    <thead><tr><th>#</th><th>المستخدم</th><th>المستشار</th><th>النوع</th><th>الحالة</th><th>المجدول</th><th>المدة (د)</th><th>التقييم</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= htmlspecialchars($r['user_name']) ?></td>
        <td><?= htmlspecialchars($r['consultant_name']) ?></td>
        <td><?= $r['type'] ?> / <?= $r['mode'] ?></td>
        <td><?= htmlspecialchars($r['status']) ?></td>
        <td><?= htmlspecialchars($r['scheduled_at'] ?? '-') ?></td>
        <td><?= $r['duration_min'] ?? '-' ?></td>
        <td><?= $r['post_rating'] ?? '-' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
