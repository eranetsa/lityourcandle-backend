-- Adds the "اشعل شمعتك" candle-themed daily programs to the catalog so they
-- can be edited from the admin panel instead of being hardcoded in the app.
--
-- - Extends programs.category ENUM with 'candle'
-- - Adds icon + palette columns for branded rendering
-- - Seeds 6 programs and 50 program_days (idempotent via slug uniqueness)

ALTER TABLE programs
  MODIFY COLUMN category ENUM(
    'breathing','self_awareness','lifestyle','anxiety',
    'relationships','self_development','candle'
  ) NOT NULL;

-- New columns for candle branding (also useful for any future themed program)
ALTER TABLE programs
  ADD COLUMN IF NOT EXISTS icon          VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS palette_start CHAR(7)     DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS palette_end   CHAR(7)     DEFAULT NULL;

-- ─── Programs ──────────────────────────────────────────────────────────────
INSERT INTO programs
  (slug, category, title_ar, description_ar, is_premium, sort_order, is_active, icon, palette_start, palette_end)
VALUES
  ('candle-light-7',       'candle', 'اشعل شمعتك في 7 أيام',
   'رحلة قصيرة لإيقاظ النور بداخلك من جديد، خطوة كل يوم.',
   0, 100, 1, 'flame', '#FBEFD0', '#F2D88E'),
  ('candle-morning-spark', 'candle', 'شرارة الصباح',
   'خمس دقائق كل صباح لتشعلي طاقتك قبل أن يبدأ العالم.',
   0, 101, 1, 'sun',   '#FFF5DC', '#F2D88E'),
  ('candle-night-calm',    'candle', 'شمعة المساء',
   'طقس هادئ قبل النوم لإطفاء ضوضاء اليوم.',
   1, 102, 1, 'moon',  '#EEEAFC', '#A8B2FF'),
  ('candle-rebirth-21',    'candle', '21 يوماً نحو نورك',
   'رحلة عميقة لإعادة إشعال داخلك بعد فترة انطفاء.',
   1, 103, 1, 'spark', '#F8F0FF', '#C77BEF'),
  ('candle-breath-fire',   'candle', 'نَفَس الشعلة',
   'تمارين تنفّس قصيرة لإعادة إشعال طاقتك في أي لحظة.',
   0, 104, 1, 'flame', '#FCE7F3', '#EC8AE0'),
  ('candle-self-love',     'candle', 'حب الذات بضوء خفيف',
   'خمسة أيام لتعيدي اللطف لعلاقتك مع نفسك.',
   1, 105, 1, 'leaf',  '#F8F0FF', '#EC8AE0')
ON DUPLICATE KEY UPDATE
  category      = VALUES(category),
  title_ar      = VALUES(title_ar),
  description_ar= VALUES(description_ar),
  is_premium    = VALUES(is_premium),
  sort_order    = VALUES(sort_order),
  icon          = VALUES(icon),
  palette_start = VALUES(palette_start),
  palette_end   = VALUES(palette_end);

-- ─── Days for candle-light-7 ───────────────────────────────────────────────
INSERT INTO program_days (program_id, day_number, title_ar, body_ar, duration_min, is_locked)
SELECT p.id, x.day_number, x.title_ar, x.body_ar, x.duration_min, 0 FROM programs p JOIN (
  SELECT 1 AS day_number, 'أشعلي الشمعة الأولى' AS title_ar,
         'اجلسي خمس دقائق بجوار شمعة، تنفّسي بعمق، وقولي: اليوم أبدأ من جديد.' AS body_ar, 5 AS duration_min UNION ALL
  SELECT 2, 'نور النية',          'اكتبي نية واحدة تريدين أن تضيء يومك.', 4 UNION ALL
  SELECT 3, 'ضوء الامتنان',       'عددي ثلاث نعم تشعرين بها الآن.', 5 UNION ALL
  SELECT 4, 'نَفَس الهدوء',        'تنفّسي 4-7-8 لمدة دقيقتين بجوار شعلتك.', 4 UNION ALL
  SELECT 5, 'احرقي ما يثقلك',    'اكتبي ما يقلقك على ورقة، ثم اطويها وضعيها بعيداً.', 6 UNION ALL
  SELECT 6, 'شعاع لطيف',          'أرسلي رسالة دفء لشخص تحبينه.', 3 UNION ALL
  SELECT 7, 'شمعتي تضيء',         'اجلسي وتأمّلي في كل ما تغيّر بداخلك خلال الأسبوع.', 7
) x WHERE p.slug = 'candle-light-7'
ON DUPLICATE KEY UPDATE title_ar=VALUES(title_ar), body_ar=VALUES(body_ar), duration_min=VALUES(duration_min);

-- ─── Days for candle-morning-spark ─────────────────────────────────────────
INSERT INTO program_days (program_id, day_number, title_ar, body_ar, duration_min, is_locked)
SELECT p.id, x.day_number, x.title_ar, x.body_ar, x.duration_min, 0 FROM programs p JOIN (
  SELECT 1 AS day_number, 'نور قبل الهاتف' AS title_ar,
         'ابتعدي عن الشاشة أول 10 دقائق وابدئي بنَفَس عميق.' AS body_ar, 5 AS duration_min UNION ALL
  SELECT 2, 'كلمة اليوم',  'اختاري كلمة واحدة (هدوء، شجاعة، حب) واتركيها ترافقك.', 3 UNION ALL
  SELECT 3, 'تمدد لطيف',   'ثلاث حركات تمدد بطيء مع ابتسامة.', 5 UNION ALL
  SELECT 4, 'ماء النور',   'اشربي كوب ماء بوعي واشكري جسدك.', 2 UNION ALL
  SELECT 5, 'نية مضيئة',   'اكتبي: كيف أريد أن أشعر اليوم؟', 4
) x WHERE p.slug = 'candle-morning-spark'
ON DUPLICATE KEY UPDATE title_ar=VALUES(title_ar), body_ar=VALUES(body_ar), duration_min=VALUES(duration_min);

-- ─── Days for candle-night-calm ────────────────────────────────────────────
INSERT INTO program_days (program_id, day_number, title_ar, body_ar, duration_min, is_locked)
SELECT p.id, x.day_number, x.title_ar, x.body_ar, x.duration_min, 0 FROM programs p JOIN (
  SELECT 1 AS day_number, 'خفّفي الإضاءة' AS title_ar,
         'أطفئي الأضواء البيضاء وأشعلي شمعة دافئة.' AS body_ar, 4 AS duration_min UNION ALL
  SELECT 2, 'ثلاثة ممتنّات', 'اكتبي ثلاث لحظات صغيرة جميلة من يومك.', 5 UNION ALL
  SELECT 3, 'إفراغ العقل',   'دوّني كل ما يدور في ذهنك بدون ترتيب.', 6 UNION ALL
  SELECT 4, 'تنفّس بطيء',   'شهيق 4، حبس 4، زفير 6 — لمدة دقيقتين.', 4 UNION ALL
  SELECT 5, 'وداع لطيف',     'ضعي يدك على قلبك وقولي: شكراً لي اليوم.', 3 UNION ALL
  SELECT 6, 'صوت الهدوء',    'استمعي لصوت طبيعي 5 دقائق قبل النوم.', 5 UNION ALL
  SELECT 7, 'إطفاء الشمعة',  'أغلقي اليوم بنَفَس عميق وأطفئي الشمعة بهدوء.', 3
) x WHERE p.slug = 'candle-night-calm'
ON DUPLICATE KEY UPDATE title_ar=VALUES(title_ar), body_ar=VALUES(body_ar), duration_min=VALUES(duration_min);

-- ─── Days for candle-rebirth-21 ────────────────────────────────────────────
INSERT INTO program_days (program_id, day_number, title_ar, body_ar, duration_min, is_locked)
SELECT p.id, x.day_number, x.title_ar, x.body_ar, x.duration_min, 0 FROM programs p JOIN (
  SELECT 1  AS day_number, 'اعترفي بالظلمة' AS title_ar,
         'اكتبي بصدق: ما الذي أطفأ شمعتي؟' AS body_ar, 8 AS duration_min UNION ALL
  SELECT 2,  'أوّل عود ثقاب', 'افعلي شيئاً صغيراً كنتِ تؤجلينه.', 10 UNION ALL
  SELECT 3,  'حدّ لطيف',       'قولي «لا» لشيء يستنزفك اليوم.', 4 UNION ALL
  SELECT 4,  'جسد دافئ',       'حمام دافئ أو مشي بطيء 10 دقائق.', 10 UNION ALL
  SELECT 5,  'أصوات داعمة',    'استمعي لصوت يلهمك ويرفع طاقتك.', 8 UNION ALL
  SELECT 6,  'غذاء للنور',     'وجبة واحدة تأكلينها بوعي بدون شاشة.', 15 UNION ALL
  SELECT 7,  'شمعة الأسبوع',   'تأمّلي: ماذا تغيّر؟', 6 UNION ALL
  SELECT 8,  'صفحة بيضاء',     'اكتبي رسالة لنفسك في المستقبل.', 10 UNION ALL
  SELECT 9,  'حركة بسيطة',     'ارقصي على أغنية تحبينها.', 5 UNION ALL
  SELECT 10, 'تنظيف زاوية',    'رتّبي مكاناً صغيراً يضيء يومك.', 12 UNION ALL
  SELECT 11, 'حوار مع الخوف',  'اكتبي ما يخيفك ثم ردّي عليه بحب.', 8 UNION ALL
  SELECT 12, 'نور خارجي',      'اخرجي للشمس 10 دقائق.', 10 UNION ALL
  SELECT 13, 'حدود رقمية',     'ساعة واحدة بدون هاتف.', 60 UNION ALL
  SELECT 14, 'منتصف الطريق',   'اكتبي ثلاث ملاحظات عن نفسك الجديدة.', 7 UNION ALL
  SELECT 15, 'صفح صغير',       'سامحي نفسك على شيء واحد.', 6 UNION ALL
  SELECT 16, 'هدية للذات',     'أعطي نفسك شيئاً بسيطاً يفرحها.', 5 UNION ALL
  SELECT 17, 'حضور كامل',      'تنفّسي بوعي 5 دقائق بدون أي تشتيت.', 5 UNION ALL
  SELECT 18, 'صوت قلبك',       'اسألي: ماذا أحتاج فعلاً الآن؟ ثم نفّذي.', 8 UNION ALL
  SELECT 19, 'نور للآخرين',    'ساعدي شخصاً ولو بكلمة.', 4 UNION ALL
  SELECT 20, 'صورة النور',     'ارسمي أو صوّري شيئاً يمثّل نورك.', 10 UNION ALL
  SELECT 21, 'شمعتك تضيء',     'اكتبي رسالة شكر لرحلتك ولنفسك.', 12
) x WHERE p.slug = 'candle-rebirth-21'
ON DUPLICATE KEY UPDATE title_ar=VALUES(title_ar), body_ar=VALUES(body_ar), duration_min=VALUES(duration_min);

-- ─── Days for candle-breath-fire ───────────────────────────────────────────
INSERT INTO program_days (program_id, day_number, title_ar, body_ar, duration_min, is_locked)
SELECT p.id, x.day_number, x.title_ar, x.body_ar, x.duration_min, 0 FROM programs p JOIN (
  SELECT 1 AS day_number, 'نَفَس الصندوق' AS title_ar,
         'شهيق 4، حبس 4، زفير 4، حبس 4 — 5 جولات.' AS body_ar, 4 AS duration_min UNION ALL
  SELECT 2, 'تنفّس 4-7-8',  'شهيق 4، حبس 7، زفير 8 — 4 جولات.', 4 UNION ALL
  SELECT 3, 'تنفّس الشمعة', 'تخيّلي شمعة أمامك وانفخي ببطء بدون أن تطفئيها.', 5 UNION ALL
  SELECT 4, 'تنفّس البطن',  'ضعي يدك على بطنك ونفّسي بعمق 3 دقائق.', 3 UNION ALL
  SELECT 5, 'تنفّس متناوب', 'تنفّسي من فتحة أنف ثم الأخرى بهدوء.', 5
) x WHERE p.slug = 'candle-breath-fire'
ON DUPLICATE KEY UPDATE title_ar=VALUES(title_ar), body_ar=VALUES(body_ar), duration_min=VALUES(duration_min);

-- ─── Days for candle-self-love ─────────────────────────────────────────────
INSERT INTO program_days (program_id, day_number, title_ar, body_ar, duration_min, is_locked)
SELECT p.id, x.day_number, x.title_ar, x.body_ar, x.duration_min, 0 FROM programs p JOIN (
  SELECT 1 AS day_number, 'مرآة لطيفة' AS title_ar,
         'انظري في المرآة وقولي: أنا أكفي.' AS body_ar, 3 AS duration_min UNION ALL
  SELECT 2, 'رسالة لذاتي',  'اكتبي رسالة قصيرة لطفلتك الداخلية.', 8 UNION ALL
  SELECT 3, 'حدود محبّة',   'حدّدي شيئاً واحداً تستحقين قول «لا» له.', 5 UNION ALL
  SELECT 4, 'احتفال صغير',  'احتفلي بإنجاز صغير من أسبوعك.', 4 UNION ALL
  SELECT 5, 'وعد لطيف',     'اكتبي وعداً واحداً ستحفظينه لنفسك.', 5
) x WHERE p.slug = 'candle-self-love'
ON DUPLICATE KEY UPDATE title_ar=VALUES(title_ar), body_ar=VALUES(body_ar), duration_min=VALUES(duration_min);
