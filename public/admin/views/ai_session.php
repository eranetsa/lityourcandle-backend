<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
      <h2 style="margin:0;">حوار <?= htmlspecialchars((string)($user['name'] ?? '—')) ?> · <?= htmlspecialchars((string)$date) ?></h2>
      <div style="color:var(--text-muted); font-size:13px; margin-top:4px;">
        <?= htmlspecialchars((string)($user['email'] ?? '')) ?>
        — <?= count($turns ?? []) ?> رسالة
      </div>
    </div>
    <a href="?action=ai_user&amp;id=<?= (int)$user['id'] ?>" class="btn btn-ghost">← العودة لجلسات هذا المستخدم</a>
  </div>
</div>

<div class="card">
  <?php if (empty($turns)): ?>
    <p style="color:var(--text-muted); text-align:center; padding:30px;">لا توجد رسائل لهذا اليوم.</p>
  <?php else: ?>
    <div style="display:flex; flex-direction:column; gap:18px;">
      <?php foreach ($turns as $t): ?>
        <?php
          $resp = json_decode((string)($t['response_json'] ?? ''), true);
          $resp = is_array($resp) ? $resp : [];
          $time = htmlspecialchars(substr((string)$t['created_at'], 11, 8));
          $mood = $t['mood'] ?? null;
          $tIn  = $t['tokens_in']  ?? null;
          $tOut = $t['tokens_out'] ?? null;
        ?>

        <div>
          <!-- meta strip -->
          <div style="font-size:12px; color:var(--text-muted); margin-bottom:6px; display:flex; gap:10px; flex-wrap:wrap;">
            <span>#<?= (int)$t['id'] ?></span>
            <span>الوقت: <?= $time ?></span>
            <?php if ($mood): ?><span>المزاج: <?= htmlspecialchars((string)$mood) ?></span><?php endif; ?>
            <?php if ($tIn !== null || $tOut !== null): ?>
              <span>tokens in/out: <?= (int)($tIn ?? 0) ?> / <?= (int)($tOut ?? 0) ?></span>
            <?php endif; ?>
            <?php if ((int)($t['escalated'] ?? 0)): ?><span class="badge b-trial">تصعيد لمستشار</span><?php endif; ?>
          </div>

          <!-- user bubble -->
          <div style="background:rgba(185,138,224,0.08); border:1px solid rgba(185,138,224,0.2); border-radius:14px; padding:14px 16px; margin-inline-start:auto; max-width:85%;">
            <div style="font-size:11px; color:var(--text-muted); font-weight:700; margin-bottom:6px;">المستخدم</div>
            <div style="white-space:pre-wrap; line-height:1.7;"><?= htmlspecialchars((string)$t['user_message']) ?></div>
          </div>

          <!-- AI bubble -->
          <div style="background:rgba(255,199,107,0.08); border:1px solid rgba(255,199,107,0.25); border-radius:14px; padding:14px 16px; margin-top:8px; margin-inline-end:auto; max-width:85%;">
            <div style="font-size:11px; color:var(--text-muted); font-weight:700; margin-bottom:8px;">شمعة AI</div>
            <?php
              $sections = [
                'empathy'    => 'تعاطف',
                'reflection' => 'تأمّل',
                'suggestion' => 'اقتراح',
                'exercise'   => 'تمرين',
                'dua'        => 'دعاء',
              ];
              $hasAny = false;
              foreach ($sections as $key => $label):
                $val = $resp[$key] ?? null;
                if ($val === null || $val === '' || $val === false) continue;
                $hasAny = true;
            ?>
              <div style="margin-bottom:6px; line-height:1.7;">
                <span style="display:inline-block; min-width:60px; color:var(--text-muted); font-weight:700; font-size:12px;"><?= $label ?></span>
                <?= htmlspecialchars((string)$val) ?>
              </div>
            <?php endforeach; ?>
            <?php if (!empty($resp['consultant_category'])): ?>
              <div style="margin-top:8px; font-size:12px; color:var(--text-muted);">
                توصية مستشار: <span class="badge"><?= htmlspecialchars((string)$resp['consultant_category']) ?></span>
              </div>
            <?php endif; ?>
            <?php if (!$hasAny && !empty($resp)): ?>
              <pre style="white-space:pre-wrap; font-family:'SF Mono', monospace; font-size:12px; color:var(--text);"><?= htmlspecialchars(json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
