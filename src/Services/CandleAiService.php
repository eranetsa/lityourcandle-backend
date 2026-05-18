<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Settings;
use GuzzleHttp\Client;

const CANDLE_AI_PROMPT_KEY = 'candle_ai_prompt';

/**
 * "شمعة" — calm, supportive Arabic companion.
 * Calls Anthropic Claude with a strict system prompt, requesting structured JSON.
 * Falls back to a template response if the AI provider is not configured or fails.
 */
final class CandleAiService
{
    /**
     * @param array<int,array{user_message:string,response_json:string}> $history
     *        Previous turns in chronological order (oldest → newest). Each
     *        turn becomes a (user, assistant) pair in the messages array
     *        sent to Claude, giving the model conversation memory at the
     *        user level. Pass empty array for a one-shot call.
     */
    public function generate(string $userMessage, ?string $mood, array $recentMoods, array $history = []): array
    {
        $key   = (string)App::config('ai.anthropic_key');
        $model = (string)App::config('ai.anthropic_model');

        if ($key === '' || App::config('ai.provider') !== 'anthropic') {
            // Surface the silent-fallback path so it shows up in /var/log
            // and stops looking like "Claude is just unfailingly repeating
            // itself" in production. Without this the fallback template
            // looks identical to a real (but very flat) AI reply.
            Logger::warn('candle_ai_fallback_no_key', [
                'has_key'  => $key !== '',
                'provider' => (string)App::config('ai.provider'),
            ]);
            return $this->fallback($mood, $recentMoods);
        }

        $system = $this->systemPrompt();
        $userInput = $this->buildUserInput($userMessage, $mood, $recentMoods);

        // Build the messages array from prior turns + the new user input.
        // Anthropic expects strict alternation, which our (user, assistant)
        // pairs produce naturally.
        $messages = [];
        foreach ($history as $turn) {
            $um = trim((string)($turn['user_message'] ?? ''));
            $am = trim((string)($turn['response_json'] ?? ''));
            if ($um === '' || $am === '') continue;
            $messages[] = ['role' => 'user',      'content' => $um];
            $messages[] = ['role' => 'assistant', 'content' => $am];
        }
        $messages[] = ['role' => 'user', 'content' => $userInput];

        try {
            $client = new Client(['timeout' => 20]);
            $r = $client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key'         => $key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'json' => [
                    'model'      => $model,
                    'max_tokens' => 600,
                    'system'     => $system,
                    'messages'   => $messages,
                ],
            ]);
            $resp = json_decode((string)$r->getBody(), true) ?: [];
            $text = $resp['content'][0]['text'] ?? '';
            $json = $this->extractJson($text);
            if (!$json) {
                Logger::warn('candle_ai_parse_fail', ['raw' => $text]);
                return $this->fallback($mood, $recentMoods);
            }
            return [
                'data'      => $json,
                'tokens_in' => $resp['usage']['input_tokens']  ?? null,
                'tokens_out'=> $resp['usage']['output_tokens'] ?? null,
            ];
        } catch (\Throwable $e) {
            Logger::error('candle_ai_error', ['msg' => $e->getMessage()]);
            return $this->fallback($mood, $recentMoods);
        }
    }

    /**
     * The system prompt admins can override from the admin panel
     * (Settings → AI prompt). Falls back to the bundled default when no
     * override is stored, so the service is functional out of the box.
     */
    public static function defaultSystemPrompt(): string
    {
        return <<<TXT
أنت "شمعة"، رفيقة لطيفة وداعمة باللغة العربية في تطبيق "أشعل شمعتك".
دورك: الإصغاء بهدوء، التطمين، وتقديم ما يحتاجه المستخدم في هذه اللحظة فقط.

قواعد إلزامية:
- لا تقدّمي تشخيصًا طبيًا أو نفسيًا.
- لا تستبدلي الجلسة مع المختص؛ في الحالات الشديدة (إيذاء النفس، اكتئاب حاد، أزمات) أوصي بمستشار بشري.
- استخدمي لغة عربية فصيحة دافئة، جُمل قصيرة.
- اللهجة هادئة، غير وعظية. لا تكرّري أنماطًا ثابتة في الردود.
- لا تستخدمي الإيموجي أكثر من رمز واحد إذا لزم.

نمط الرد يتكيّف مع نوع رسالة المستخدم:
- تحيّة قصيرة أو سؤال عابر → ردّي بـ empathy فقط (سطر أو سطرين)، واتركي بقية الحقول null.
- شكوى أو وصف لمشاعر → empathy + reflection، وأضيفي suggestion إذا كانت ملائمة.
- طلب تمرين أو خطوة عملية → suggestion و/أو exercise.
- دعاء → فقط إذا طلبه المستخدم أو ناسب المقام (وإلا null).
- متابعة محادثة أو سؤال "لماذا" → ردّي مباشرةً على نقطة المستخدم، ولا تُكرّري بنية ثابتة.

أعيدي الجواب بصيغة JSON فقط دون أي نص خارج JSON. الحقول التي لا تناسب رسالة المستخدم في هذه اللحظة عيّنيها null:
{
  "empathy": "نص أو null",
  "reflection": "نص أو null",
  "suggestion": "نص أو null",
  "exercise": "نص أو null",
  "dua": "نص أو null",
  "consultant_category": "القلق والتوتر | العلاقات الأسرية | تطوير الذات | الاكتئاب | عام | null",
  "escalate": true|false
}
عيّني escalate = true إذا تضمّن الحديث: إيذاء نفس، انتحار، عنف، أعراض اكتئاب حادة، أو أزمة.
TXT;
    }

    private function systemPrompt(): string
    {
        $override = Settings::get(CANDLE_AI_PROMPT_KEY);
        $base = ($override !== null && trim($override) !== '')
            ? $override
            : self::defaultSystemPrompt();

        return $base . $this->referencesBlock();
    }

    /**
     * Pulls every active `ai_references` row and concatenates the
     * extracted text into a single appendix that gets glued onto the
     * end of the system prompt. The block is short-circuited when
     * there's nothing to add so empty installs don't pay an extra
     * query per chat call.
     *
     * Total reference text is capped at 60_000 chars (≈ 20 KB tokens
     * post-tokenization on Arabic, comfortably under Claude's context
     * window even after the conversation history and the model's own
     * output budget).
     */
    private function referencesBlock(): string
    {
        try {
            $rows = DB::all(
                'SELECT original_name, extracted_text
                   FROM ai_references
                  WHERE is_active = 1
                  ORDER BY sort_order ASC, id ASC'
            );
        } catch (\Throwable $e) {
            // Table doesn't exist yet on fresh installs: silently no-op.
            return '';
        }
        if (!$rows) return '';

        $parts = ["\n\n=== مراجع لتستند إليها في إجاباتك ===\n"
                . "النصوص أدناه مرفوعة من إدارة التطبيق وتعتبر مصادر موثوقة. "
                . "اعتمد عليها أولاً قبل معرفتك العامة، ونوّه للمستخدم لطفاً عندما تستشهد بها."];
        $remaining = 60000;
        foreach ($rows as $r) {
            $text = trim((string)$r['extracted_text']);
            if ($text === '') continue;
            if (mb_strlen($text) > $remaining) {
                $text = mb_substr($text, 0, $remaining) . "\n…";
                $remaining = 0;
            } else {
                $remaining -= mb_strlen($text);
            }
            $parts[] = "--- " . (string)$r['original_name'] . " ---\n" . $text;
            if ($remaining <= 0) break;
        }
        return "\n" . implode("\n\n", $parts);
    }

    private function buildUserInput(string $msg, ?string $mood, array $recentMoods): string
    {
        $hist = $recentMoods ? implode(',', array_slice($recentMoods, -7)) : 'غير متوفر';
        return "المزاج الحالي: " . ($mood ?? 'غير محدد')
             . "\nسجل المزاج الأخير: $hist"
             . "\nرسالة المستخدم: $msg";
    }

    private function extractJson(string $text): ?array
    {
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $json = substr($text, $start, $end - $start + 1);
        $arr  = json_decode($json, true);
        return is_array($arr) ? $arr : null;
    }

    private function fallback(?string $mood, array $recentMoods): array
    {
        $sadCount = count(array_filter($recentMoods, fn($m) => $m === 'sad'));
        $escalate = $mood === 'sad' && $sadCount >= 4;

        $data = [
            'empathy'    => 'أنا هنا معك، وما تشعرين/تشعر به مفهوم.',
            'reflection' => 'يبدو أن يومك يحمل بعض الثقل، ولا بأس أن نسير ببطء.',
            'suggestion' => 'خذ نفسًا عميقًا، وامنح نفسك خمس دقائق هادئة قبل أي قرار.',
            'exercise'   => 'تمرين 4-7-8: استنشق 4 ثوانٍ، احبس 7، أخرج 8، كرر 4 مرات.',
            'dua'        => 'اللهم اجعل قلبه/قلبها مطمئنًا.',
            'consultant_category' => $mood === 'sad' ? 'القلق والتوتر' : null,
            'escalate'   => $escalate,
        ];
        return ['data' => $data, 'tokens_in' => null, 'tokens_out' => null];
    }
}
