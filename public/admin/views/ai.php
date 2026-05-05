<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <h2 style="margin:0;">شمعة AI — الاستخدام</h2>
    <a href="?action=ai_users" class="btn">تصفّح محادثات المستخدمين ←</a>
  </div>
  <?php if (isset($aiCfg)): ?>
    <?php if ($aiCfg['ready']): ?>
      <div style="margin-top:14px; padding:12px 14px; border-radius:10px; background:rgba(34,197,94,0.10); border:1px solid rgba(34,197,94,0.35); color:#0a8a3a;">
        ✅ خدمة Claude متصلة. النموذج: <code><?= htmlspecialchars($aiCfg['model'] ?: '—') ?></code>
      </div>
    <?php else: ?>
      <div style="margin-top:14px; padding:14px; border-radius:10px; background:rgba(239,68,68,0.10); border:1px solid rgba(239,68,68,0.35); color:#b91c1c;">
        ⚠ <strong>AI يعمل في وضع الـ fallback</strong> — يرجع نفس النص الثابت لكل المستخدمين بدون استدعاء Claude.
        <div style="margin-top:8px; font-size:13px; color:#7f1d1d;">
          سبب: <?= !$aiCfg['has_key']
            ? 'متغيّر <code>ANTHROPIC_API_KEY</code> فارغ في <code>/var/www/lityourcandle/.env</code>'
            : ('<code>AI_PROVIDER</code> ≠ <code>anthropic</code> (القيمة الحالية: <code>' . htmlspecialchars($aiCfg['provider']) . '</code>)') ?>.
          عدِّل <code>.env</code> ثم نفّذ <code>systemctl reload apache2</code>.
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
  <div class="stats" style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); margin-top:14px;">
    <div class="stat"><div class="n"><?= number_format((int)($stats['total'] ?? 0)) ?></div><div class="l">إجمالي المحادثات</div></div>
    <div class="stat"><div class="n"><?= number_format((int)($stats['today'] ?? 0)) ?></div><div class="l">اليوم</div></div>
    <div class="stat"><div class="n"><?= number_format((int)($stats['escalated'] ?? 0)) ?></div><div class="l">تصعيد لمستشار</div></div>
    <div class="stat"><div class="n"><?= number_format((int)($stats['tokens_in'] ?? 0)) ?></div><div class="l">tokens in</div></div>
    <div class="stat"><div class="n"><?= number_format((int)($stats['tokens_out'] ?? 0)) ?></div><div class="l">tokens out</div></div>
  </div>
</div>

<div class="card">
  <h3>الحد اليومي المجاني لمحادثة AI</h3>
  <p style="color:var(--text-muted); margin:0 0 12px;">
    عدد الرسائل التي يستطيع المستخدم في الباقة المجانية إرسالها خلال
    اليوم لـ شمعة AI. عند تجاوزه يظهر للمستخدم زرّ الترقية. مشتركو
    الباقات المدفوعة (أسبوعية / شهرية / سنوية) و الـ trial غير محدودين.
  </p>
  <form method="post" action="?action=ai" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf()) ?>">
    <label style="color:var(--text-muted); font-size:13px;">رسائل / يوم:</label>
    <input
      type="number"
      name="ai_free_daily_limit"
      min="0"
      value="<?= (int)($aiFreeLimit ?? 3) ?>"
      style="width:120px; text-align:center; font-size:16px; font-weight:700;"
    >
    <button type="submit" name="op" value="save_free_limit" class="btn">حفظ الحد</button>
    <span style="color:var(--text-muted); font-size:12px;">
      (الافتراضي ٣ — اضبط على ٠ لتعطيل AI تمامًا للباقة المجانية)
    </span>
  </form>
</div>

<div class="card">
  <h3>برومبت شمعة AI (System Prompt)</h3>
  <p style="color:var(--text-muted); margin:0 0 12px;">
    النص الذي يُرسل لـ Claude كـ <code>system</code> في كل طلب AI داخل التطبيق.
    اتركه فارغًا لاستخدام البرومبت الافتراضي (المعروض كـ placeholder).
  </p>
  <?php if (!empty($saved)): ?>
    <div style="background:#E8F5E9; border:1px solid #1B5E20; color:#1B5E20; padding:10px 14px; border-radius:8px; margin-bottom:14px;">
      ✓ تم حفظ البرومبت. سيستخدمه التطبيق فورًا في الطلب التالي.
    </div>
  <?php endif; ?>
  <form method="post" action="?action=ai">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf()) ?>">
    <textarea
      name="prompt"
      dir="rtl"
      style="width:100%; min-height:340px; padding:14px; font-family:'SF Mono', monospace; font-size:13px; line-height:1.6; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text); resize:vertical;"
      placeholder="<?= htmlspecialchars($defaultPrompt ?? '') ?>"><?= htmlspecialchars($currentPrompt ?? '') ?></textarea>
    <div style="display:flex; gap:10px; margin-top:14px; justify-content:flex-start;">
      <button type="submit" name="op" value="save_prompt" class="btn">حفظ البرومبت</button>
      <button type="submit" name="op" value="reset_prompt" class="btn btn-ghost"
        onclick="return confirm('استعادة البرومبت الافتراضي؟ سيُحذف النص الحالي.')">
        استعادة الافتراضي
      </button>
    </div>
  </form>
  <details style="margin-top:18px;">
    <summary style="cursor:pointer; color:var(--text-muted);">عرض البرومبت الافتراضي</summary>
    <pre style="margin-top:10px; padding:14px; background:var(--bg); border:1px solid var(--border); border-radius:8px; white-space:pre-wrap; direction:rtl; font-size:12px; line-height:1.7;"><?= htmlspecialchars($defaultPrompt ?? '') ?></pre>
  </details>
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
