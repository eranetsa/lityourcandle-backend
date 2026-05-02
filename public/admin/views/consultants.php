<?php if (!empty($error)): ?>
  <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
  <h2>إضافة مستشار</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="op" value="create">

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <p><label>الاسم</label><input type="text" name="name" placeholder="مثال: د. سارة العتيبي" required></p>
      <p><label>التخصص</label><input type="text" name="specialty" placeholder="مثال: القلق والتوتر" required></p>
    </div>

    <p><label>نبذة قصيرة</label><textarea name="bio" placeholder="نبذة عن خبرة المستشار"></textarea></p>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <p>
        <label>صورة المستشار <small style="color:var(--text-muted);">(JPG / PNG / WebP — حتى 5MB)</small></label>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
      </p>
      <p>
        <label>أو رابط صورة خارجية</label>
        <input type="text" name="photo_url" placeholder="https://...">
      </p>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <p><label>سعر الجلسة (ريال)</label><input type="number" step="0.01" name="price" placeholder="250" required></p>
      <p><label>اللغات</label><input type="text" name="languages" placeholder="ar,en" value="ar"></p>
    </div>

    <p>
      <label>أنواع الجلسات</label>
      <div class="checks" style="margin-top:6px;">
        <label><input type="checkbox" name="types[]" value="chat" checked> دردشة</label>
        <label><input type="checkbox" name="types[]" value="voice" checked> صوت</label>
        <label><input type="checkbox" name="types[]" value="video" checked> فيديو</label>
        <label><input type="checkbox" name="is_available" checked> متاح للحجز</label>
      </div>
    </p>

    <button type="submit">➕ إضافة مستشار</button>
  </form>
</div>

<div class="card">
  <h2>المستشارون</h2>
  <table>
    <thead><tr><th>#</th><th>الصورة</th><th>الاسم</th><th>التخصص</th><th>السعر</th><th>التقييم</th><th>متاح؟</th><th>إجراء</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td>
          <?php if (!empty($r['photo_url'])): ?>
            <img src="<?= htmlspecialchars($r['photo_url']) ?>" class="table-photo" alt="">
          <?php else: ?>
            <span class="table-photo-empty"><?= mb_substr((string)$r['name'], 0, 1) ?></span>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><?= htmlspecialchars($r['specialty']) ?></td>
        <td><?= number_format((float)$r['price_per_session'], 0) ?> <small style="color:var(--text-muted);"><?= htmlspecialchars($r['currency']) ?></small></td>
        <td><?= number_format((float)$r['rating'], 2) ?> <small style="color:var(--text-muted);">(<?= $r['rating_count'] ?>)</small></td>
        <td><span class="badge <?= $r['is_available'] ? 'b-active' : 'b-inactive' ?>"><?= $r['is_available'] ? 'نعم' : 'لا' ?></span></td>
        <td>
          <form method="post" class="inline" onsubmit="return confirm('حذف هذا المستشار؟')">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="op" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button type="submit" class="btn-danger btn-sm">حذف</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
