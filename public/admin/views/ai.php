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
  <h3>ذاكرة الحوار مع AI</h3>
  <p style="color:var(--text-muted); margin:0 0 12px;">
    عدد آخر دورات (تورّن) من حوار المستخدم التي تُرسل لـ Claude مع كل
    طلب جديد ليُحافظ على السياق. كل دورة = رسالة المستخدم + رد شمعة.
    قيمة عالية = ذاكرة أطول لكن استهلاك tokens أكبر.
  </p>
  <form method="post" action="?action=ai" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf()) ?>">
    <label style="color:var(--text-muted); font-size:13px;">آخر … دورة:</label>
    <input
      type="number"
      name="ai_memory_turns"
      min="0" max="50"
      value="<?= (int)($aiMemoryTurns ?? 10) ?>"
      style="width:120px; text-align:center; font-size:16px; font-weight:700;"
    >
    <button type="submit" name="op" value="save_memory_turns" class="btn">حفظ</button>
    <span style="color:var(--text-muted); font-size:12px;">
      (الافتراضي ١٠ — اضبط على ٠ لتعطيل الذاكرة وكل طلب يبدأ من الصفر)
    </span>
  </form>
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
  <h2 style="margin-top:0;">مزوّد الذكاء الاصطناعي</h2>
  <p style="color:var(--text-muted); margin:0 0 14px;">
    اختر المزوّد النشط واملأ مفتاحه. المفاتيح تُخزَّن مشفّرة في قاعدة البيانات
    ولن تظهر مجدداً بعد الحفظ — اتركها فارغة عند الحفظ للاحتفاظ بالقيمة السابقة.
  </p>
  <form method="post" action="?action=ai" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf()) ?>">

    <div style="display:flex; gap:18px; flex-wrap:wrap; margin-bottom:14px;">
      <label style="display:inline-flex; align-items:center; gap:8px;">
        <input type="radio" name="ai_provider" value="anthropic"
          <?= ($effectiveProvider ?? 'anthropic') === 'anthropic' ? 'checked' : '' ?>>
        <span>Anthropic (Claude مباشرة)</span>
      </label>
      <label style="display:inline-flex; align-items:center; gap:8px;">
        <input type="radio" name="ai_provider" value="openrouter"
          <?= ($effectiveProvider ?? '') === 'openrouter' ? 'checked' : '' ?>>
        <span>OpenRouter (يدعم OpenAI / Claude / Google / Meta …)</span>
      </label>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
      <div>
        <label style="display:block; color:var(--text-muted); font-size:13px; margin-bottom:6px;">
          مفتاح Anthropic
          <?php if (!empty($hasAnthropicKey)): ?>
            <span style="color:#4ade80;">✓ محفوظ</span>
          <?php else: ?>
            <span style="color:#f87171;">غير مضبوط</span>
          <?php endif; ?>
        </label>
        <input type="password" name="ai_anthropic_key"
               placeholder="<?= !empty($hasAnthropicKey) ? '••••••••  (اتركه فارغاً للإبقاء)' : 'sk-ant-…' ?>"
               autocomplete="new-password">
        <label style="display:block; color:var(--text-muted); font-size:13px; margin:10px 0 6px;">موديل Anthropic</label>
        <input type="text" name="ai_anthropic_model"
               value="<?= htmlspecialchars($effectiveAnthModel ?? '') ?>"
               placeholder="claude-haiku-4-5-20251001">
      </div>
      <div>
        <label style="display:block; color:var(--text-muted); font-size:13px; margin-bottom:6px;">
          مفتاح OpenRouter
          <?php if (!empty($hasOpenRouterKey)): ?>
            <span style="color:#4ade80;">✓ محفوظ</span>
          <?php else: ?>
            <span style="color:#f87171;">غير مضبوط</span>
          <?php endif; ?>
        </label>
        <input type="password" name="ai_openrouter_key"
               placeholder="<?= !empty($hasOpenRouterKey) ? '••••••••  (اتركه فارغاً للإبقاء)' : 'sk-or-v1-…' ?>"
               autocomplete="new-password">
        <label style="display:block; color:var(--text-muted); font-size:13px; margin:10px 0 6px;">موديل OpenRouter</label>
        <input type="text" name="ai_openrouter_model"
               value="<?= htmlspecialchars($effectiveOrModel ?? '') ?>"
               placeholder="openai/gpt-4o-mini">
        <small style="color:var(--text-muted); display:block; margin-top:6px;">
          أمثلة: <code>anthropic/claude-3.5-sonnet</code> · <code>openai/gpt-4o-mini</code> ·
          <code>google/gemini-2.0-flash-001</code>
        </small>
      </div>
    </div>

    <div style="display:flex; gap:10px; margin-top:14px; flex-wrap:wrap;">
      <button type="submit" name="op" value="save_provider" class="btn">حفظ إعدادات المزوّد</button>
      <button type="submit" name="op" value="clear_anthropic_key" class="btn-ghost btn-sm"
        onclick="return confirm('حذف مفتاح Anthropic من الإعدادات؟');">حذف مفتاح Anthropic</button>
      <button type="submit" name="op" value="clear_openrouter_key" class="btn-ghost btn-sm"
        onclick="return confirm('حذف مفتاح OpenRouter من الإعدادات؟');">حذف مفتاح OpenRouter</button>
    </div>
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
  <h2 style="margin-top:0;">مراجع شمعة AI</h2>
  <p style="color:var(--text-muted); margin:0 0 12px;">
    ارفع ملفات نصية ‎(.txt أو .md)‎ ستُضاف تلقائياً إلى تعليمات الذكاء الاصطناعي
    في كل محادثة، فيستند إليها قبل معرفته العامة. الحد الأقصى ٢ ميجابايت للملف،
    وإجمالي النصوص المُحقَن في البرومبت لا يتجاوز ٦٠ ألف حرف.
  </p>
  <?php if (!empty($refError)): ?>
    <div class="flash flash-error"><?= htmlspecialchars($refError) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" action="?action=ai" style="margin-bottom:18px;">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf()) ?>">
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <input type="file" name="ref" accept=".txt,.md,text/plain,text/markdown" required>
      <button type="submit" name="op" value="upload_reference" class="btn">رفع المرجع</button>
    </div>
  </form>

  <?php if (empty($references)): ?>
    <p style="color:var(--text-muted); text-align:center; padding:18px 0;">
      لم يتم رفع أي مرجع بعد.
    </p>
  <?php else: ?>
  <table>
    <thead><tr><th>#</th><th>الملف</th><th>النوع</th><th>الحجم</th><th>الحالة</th><th>تاريخ الرفع</th><th>إجراء</th></tr></thead>
    <tbody>
      <?php foreach ($references as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= htmlspecialchars((string)$r['original_name']) ?></td>
        <td><?= htmlspecialchars((string)$r['mime']) ?></td>
        <td><?= number_format((int)$r['size_bytes'] / 1024, 1) ?> KB</td>
        <td>
          <span class="badge <?= $r['is_active'] ? 'b-active' : 'b-mute' ?>">
            <?= $r['is_active'] ? 'مفعّل' : 'موقوف' ?>
          </span>
        </td>
        <td style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars((string)$r['created_at']) ?></td>
        <td style="display:flex; gap:6px;">
          <a href="?action=ai_reference&id=<?= (int)$r['id'] ?>" target="_blank"
             class="btn-ghost btn-sm" style="text-decoration:none;">استعراض</a>
          <form method="post" action="?action=ai" class="inline">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf()) ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" name="op" value="toggle_reference" class="btn-ghost btn-sm">
              <?= $r['is_active'] ? 'إيقاف' : 'تفعيل' ?>
            </button>
          </form>
          <form method="post" action="?action=ai" class="inline"
                onsubmit="return confirm('حذف المرجع نهائياً؟');">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf()) ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" name="op" value="delete_reference" class="btn-danger btn-sm">حذف</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
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
