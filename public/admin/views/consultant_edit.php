<?php /** @var array $consultant */ ?>

<?php if (!empty($error)): ?>
  <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="margin-bottom: 18px;">
  <a href="?action=consultants" class="btn btn-ghost btn-sm">← العودة لقائمة المستشارين</a>
</div>

<div class="card">
  <h2 style="margin-top:0;">
    تعديل المستشار
    <small style="color:var(--text-muted); font-weight:500;">— #<?= (int)$consultant['id'] ?></small>
  </h2>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="op" value="update">
    <input type="hidden" name="id" value="<?= (int)$consultant['id'] ?>">

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <p><label>الاسم</label><input type="text" name="name" value="<?= htmlspecialchars($consultant['name']) ?>" required></p>
      <p><label>التخصص</label><input type="text" name="specialty" value="<?= htmlspecialchars($consultant['specialty']) ?>" required></p>
    </div>

    <p><label>نبذة قصيرة</label><textarea name="bio" rows="3"><?= htmlspecialchars($consultant['bio'] ?? '') ?></textarea></p>

    <?php if (!empty($consultant['photo_url'])): ?>
      <p>
        <label>الصورة الحالية</label>
        <img src="<?= htmlspecialchars($consultant['photo_url']) ?>" alt=""
             style="width:80px; height:80px; border-radius:50%; object-fit:cover; border:1px solid var(--border-soft); display:block;">
      </p>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <p>
        <label>تغيير الصورة <small style="color:var(--text-muted);">(JPG / PNG / WebP — حتى 5MB)</small></label>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
      </p>
      <p>
        <label>أو رابط صورة خارجية</label>
        <input type="text" name="photo_url" value="<?= htmlspecialchars($consultant['photo_url'] ?? '') ?>" placeholder="https://...">
      </p>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <p><label>سعر الجلسة (ريال)</label><input type="number" step="0.01" name="price" value="<?= htmlspecialchars((string)$consultant['price_per_session']) ?>" required></p>
      <p><label>اللغات</label><input type="text" name="languages" value="<?= htmlspecialchars($consultant['languages'] ?? 'ar') ?>"></p>
    </div>

    <p>
      <label>أنواع الجلسات</label>
      <?php $types = explode(',', $consultant['session_types'] ?? ''); ?>
      <div class="checks" style="margin-top:6px;">
        <label><input type="checkbox" name="types[]" value="chat"  <?= in_array('chat',  $types, true) ? 'checked' : '' ?>> دردشة</label>
        <label><input type="checkbox" name="types[]" value="voice" <?= in_array('voice', $types, true) ? 'checked' : '' ?>> صوت</label>
        <label><input type="checkbox" name="types[]" value="video" <?= in_array('video', $types, true) ? 'checked' : '' ?>> فيديو</label>
        <label><input type="checkbox" name="is_available" <?= $consultant['is_available'] ? 'checked' : '' ?>> متاح للحجز</label>
      </div>
    </p>

    <h3 style="margin-top:20px;">
      بيانات الدخول لمنصة المستشارين
      <small style="color:var(--text-muted); font-weight:500;">
        <?php if (!empty($consultant['login_email'])): ?>
          — مفعّل (<?= htmlspecialchars($consultant['login_email']) ?>)
        <?php else: ?>
          — غير مفعّل بعد
        <?php endif; ?>
      </small>
    </h3>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <p>
        <label>البريد الإلكتروني</label>
        <input type="email" name="login_email"
               value="<?= htmlspecialchars($consultant['login_email'] ?? '') ?>"
               placeholder="consultant@example.com">
      </p>
      <p>
        <label>كلمة المرور
          <small style="color:var(--text-muted);">(اتركها فارغة لإبقاء الحالية)</small>
        </label>
        <input type="password" name="login_password" placeholder="جديدة فقط إذا أردت تغييرها">
      </p>
    </div>

    <button type="submit" style="margin-top: 14px;">💾 حفظ التعديلات</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0;">إحصاءات</h3>
  <table>
    <tbody>
      <tr><td style="color:var(--text-muted); width:140px;">التقييم</td>
          <td><?= number_format((float)$consultant['rating'], 2) ?> ⭐ من <?= (int)$consultant['rating_count'] ?> مراجعة</td></tr>
      <tr><td style="color:var(--text-muted);">العملة</td>
          <td><?= htmlspecialchars($consultant['currency']) ?></td></tr>
      <tr><td style="color:var(--text-muted);">تاريخ الإنشاء</td>
          <td><?= htmlspecialchars($consultant['created_at']) ?></td></tr>
      <tr><td style="color:var(--text-muted);">آخر تحديث</td>
          <td><?= htmlspecialchars($consultant['updated_at']) ?></td></tr>
    </tbody>
  </table>
</div>

<div class="card">
  <form method="post" onsubmit="return confirm('حذف هذا المستشار نهائياً؟ سيتم حذف ربط الحساب أيضاً.')">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="op" value="delete">
    <input type="hidden" name="id" value="<?= (int)$consultant['id'] ?>">
    <button type="submit" class="btn-danger">🗑 حذف هذا المستشار</button>
  </form>
</div>
