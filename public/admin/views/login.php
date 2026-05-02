<div class="card" style="max-width:380px; margin:80px auto;">
  <h2 style="margin-top:0;">دخول الإدارة</h2>
  <?php if (!empty($err)): ?><p style="color:#b91c1c;"><?= htmlspecialchars($err) ?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <p><input type="text" name="username" placeholder="اسم المستخدم" required autofocus></p>
    <p><input type="password" name="password" placeholder="كلمة المرور" required></p>
    <button type="submit">دخول</button>
  </form>
</div>
