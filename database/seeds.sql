-- Minimal seed data for development

-- Admin user is created via the CLI script:
--   php cron/create_admin.php <username> <email> <password> <full_name>
-- (see README). seeds.sql intentionally does not insert one to avoid shipping
-- a known credential.

INSERT INTO daily_messages (text_ar, text_en, is_active) VALUES
('أشعل شمعتك اليوم… وابدأ من جديد', 'Light your candle today... and start anew', 1),
('كل صباح فرصة لتشعل ضوءًا جديدًا في داخلك', 'Every morning is a chance to light a new flame within', 1),
('الراحة ليست ضعفًا، بل حكمة قلبٍ يعرف متى يستريح', 'Rest is not weakness; it is the wisdom of a heart that knows when to pause', 1),
('خذ نفسًا عميقًا… أنت بخير، وأنت تكفي', 'Take a deep breath… you are okay, and you are enough', 1),
('الهدوء يبدأ من الداخل، اسمح لنفسك أن تشعر به', 'Calm begins within. Allow yourself to feel it', 1);

INSERT INTO consultants (name, photo_url, specialty, bio, rating, rating_count, price_per_session, session_types, is_available, languages) VALUES
('د. سارة العتيبي', NULL, 'القلق والتوتر', 'أخصائية نفسية بخبرة 12 سنة في علاج اضطرابات القلق', 4.85, 142, 250.00, 'chat,voice,video', 1, 'ar,en'),
('أ. محمد الحربي', NULL, 'العلاقات الأسرية', 'مستشار أسري معتمد، متخصص في الإرشاد الزوجي', 4.70, 98, 220.00, 'chat,voice', 1, 'ar'),
('د. ليلى القحطاني', NULL, 'تطوير الذات', 'مدربة معتمدة في برامج التنمية الذاتية والتأمل الواعي', 4.92, 210, 280.00, 'chat,voice,video', 1, 'ar,en');

INSERT INTO programs (slug, category, title_ar, title_en, description_ar, description_en, is_premium, sort_order) VALUES
('breathing-101', 'breathing', 'تنفّس الهدوء', 'Calm Breathing', 'تمارين تنفس بسيطة لتهدئة الجهاز العصبي', 'Simple breathing exercises to calm the nervous system', 0, 1),
('self-awareness-7d', 'self_awareness', 'سبعة أيام مع نفسك', '7 Days With Yourself', 'رحلة قصيرة لاكتشاف ما يدور بداخلك', 'A short journey to discover what is going on inside you', 1, 2),
('anxiety-relief', 'anxiety', 'تخفيف القلق', 'Anxiety Relief', 'برنامج تدريبي قصير للتعامل مع القلق اليومي', 'A short training program for daily anxiety', 1, 3);

INSERT INTO program_days (program_id, day_number, title_ar, body_ar, duration_min, is_locked) VALUES
(1, 1, 'النفس المربع', 'تنفس 4 ثوانٍ، احبس 4، أخرج 4، اثبت 4. كرر 5 مرات.', 3, 0),
(1, 2, 'النفس البطني', 'ضع يدك على بطنك واشعر بحركتها مع كل نفس عميق.', 3, 0),
(1, 3, 'تنفس 4-7-8', 'استنشق 4 ثوانٍ، احبس 7، أخرج 8. كرر 4 مرات قبل النوم.', 4, 1),
(2, 1, 'وقفة مع الذات', 'اجلس بهدوء لخمس دقائق ولاحظ مشاعرك دون حكم.', 5, 0),
(3, 1, 'لاحظ القلق', 'سجّل ثلاث لحظات شعرت فيها بالقلق اليوم وما الذي سبقها.', 5, 0);
