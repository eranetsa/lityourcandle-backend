<div class="card">
  <h2 style="margin-top:0;">إضافة مستشار</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="op" value="create">
    <p><input type="text" name="name" placeholder="الاسم" required></p>
    <p><input type="text" name="specialty" placeholder="التخصص" required></p>
    <p><textarea name="bio" placeholder="نبذة قصيرة"></textarea></p>
    <p><input type="text" name="photo_url" placeholder="رابط الصورة (اختياري)"></p>
    <p><input type="number" step="0.01" name="price" placeholder="سعر الجلسة" required></p>
    <p>
      <label><input type="checkbox" name="types[]" value="chat" checked> دردشة</label>
      <label><input type="checkbox" name="types[]" value="voice" checked> صوت</label>
      <label><input type="checkbox" name="types[]" value="video" checked> فيديو</label>
    </p>
    <p><input type="text" name="languages" placeholder="اللغات" value="ar"></p>
    <p><label><input type="checkbox" name="is_available" checked> متاح للحجز</label></p>
    <button type="submit">إضافة</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;">المستشارون</h2>
  <table>
    <thead><tr><th>#</th><th>الاسم</th><th>التخصص</th><th>السعر</th><th>التقييم</th><th>متاح؟</th><th>إجراء</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><?= htmlspecialchars($r['specialty']) ?></td>
        <td><?= number_format((float)$r['price_per_session'], 0) ?> <?= htmlspecialchars($r['currency']) ?></td>
        <td><?= number_format((float)$r['rating'], 2) ?> (<?= $r['rating_count'] ?>)</td>
        <td><span class="badge <?= $r['is_available']?'b-active':'b-inactive' ?>"><?= $r['is_available']?'نعم':'لا' ?></span></td>
        <td>
          <form method="post" class="inline" onsubmit="return confirm('حذف هذا المستشار؟')">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="op" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button type="submit" class="btn-danger">حذف</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
