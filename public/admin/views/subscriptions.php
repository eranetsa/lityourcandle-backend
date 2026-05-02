<div class="card">
  <h2 style="margin-top:0;">الاشتراكات</h2>
  <table>
    <thead><tr><th>#</th><th>المستخدم</th><th>الباقة</th><th>الحالة</th><th>المتجر</th><th>الانتهاء</th><th>جلسات متبقية</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= htmlspecialchars($r['user_name']) ?> <small>(<?= htmlspecialchars($r['email']) ?>)</small></td>
        <td><?= htmlspecialchars($r['plan']) ?></td>
        <td><span class="badge b-<?= $r['status']==='trial'?'trial':($r['status']==='active'?'active':'inactive') ?>"><?= htmlspecialchars($r['status']) ?></span></td>
        <td><?= htmlspecialchars($r['store']) ?></td>
        <td><?= htmlspecialchars($r['expires_at'] ?? '-') ?></td>
        <td><?= $r['sessions_remaining'] ?> / <?= $r['sessions_total'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
