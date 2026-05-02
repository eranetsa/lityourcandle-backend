<div class="auth-card">
  <div class="brand-row">
    <svg class="logo"><use href="#lytcLogo"/></svg>
    <h2>أشعل شمعتك</h2>
  </div>
  <p class="sub">مرحبًا بك في لوحة الإدارة 🕯️</p>
  <?php if (!empty($err)): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <p><input type="text" name="username" placeholder="اسم المستخدم" required autofocus></p>
    <p><input type="password" name="password" placeholder="كلمة المرور" required></p>
    <button type="submit">دخول</button>
  </form>
</div>
