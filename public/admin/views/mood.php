<div class="card">
  <h2 style="margin-top:0;">تحليلات المزاج (آخر 30 يومًا)</h2>
  <table>
    <thead><tr><th>التاريخ</th><th>المزاج</th><th>العدد</th></tr></thead>
    <tbody>
      <?php foreach ($byDay as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['logged_on']) ?></td>
        <td><?= htmlspecialchars($r['mood']) ?></td>
        <td><?= (int)$r['n'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
