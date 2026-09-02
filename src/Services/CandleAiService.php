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
        // Admin Settings override the .env defaults so the provider can
        // be flipped from the panel without a redeploy.
        $provider = (string)(Settings::get('ai_provider') ?: App::config('ai.provider'));
        $provider = $provider !== '' ? $provider : 'anthropic';

        $system    = $this->systemPrompt();
        $userInput = $this->buildUserInput($userMessage, $mood, $recentMoods);

        // Build a generic OpenAI/Anthropic-shaped message list. Each
        // provider adapter below accepts the same array.
        $messages = [];
        foreach ($history as $turn) {
            $um = trim((string)($turn['user_message'] ?? ''));
            $am = trim((string)($turn['response_json'] ?? ''));
            if ($um === '' || $am === '') continue;
            $messages[] = ['role' => 'user',      'content' => $um];
            $messages[] = ['role' => 'assistant', 'content' => $am];
        }
        $messages[] = ['role' => 'user', 'content' => $userInput];

        $result = $provider === 'openrouter'
            ? $this->callOpenRouter($system, $messages)
            : $this->callAnthropic($system, $messages);

        if (!$result) {
            return $this->fallback($mood, $recentMoods);
        }
        $json = $this->extractJson($result['text']);
        if (!$json) {
            Logger::warn('candle_ai_parse_fail', ['provider' => $provider, 'raw' => $result['text']]);
            return $this->fallback($mood, $recentMoods);
        }
        return [
            'data'      => $json,
            'tokens_in' => $result['tokens_in']  ?? null,
            'tokens_out'=> $result['tokens_out'] ?? null,
        ];
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @return ?array{text:string, tokens_in:?int, tokens_out:?int}
     */
    private function callAnthropic(string $system, array $messages): ?array
    {
        $key   = (string)(Settings::get('ai_anthropic_key') ?: App::config('ai.anthropic_key'));
        $model = (string)(Settings::get('ai_anthropic_model') ?: App::config('ai.anthropic_model'));
        if ($key === '') {
            Logger::warn('candle_ai_fallback_no_key', ['provider' => 'anthropic']);
            return null;
        }
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
            return [
                'text'      => (string)($resp['content'][0]['text'] ?? ''),
                'tokens_in' => $resp['usage']['input_tokens']  ?? null,
                'tokens_out'=> $resp['usage']['output_tokens'] ?? null,
            ];
        } catch (\Throwable $e) {
            Logger::error('candle_ai_error', ['provider' => 'anthropic', 'msg' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * OpenRouter exposes an OpenAI-compatible /chat/completions endpoint
     * with the system prompt represented as a leading {role:"system"}
     * message rather than a top-level field. Every other concept maps
     * one-to-one with the Anthropic path above.
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @return ?array{text:string, tokens_in:?int, tokens_out:?int}
     */
    private function callOpenRouter(string $system, array $messages): ?array
    {
        $key   = (string)(Settings::get('ai_openrouter_key') ?: App::config('ai.openrouter_key'));
        $model = (string)(Settings::get('ai_openrouter_model') ?: App::config('ai.openrouter_model'));
        if ($key === '') {
            Logger::warn('candle_ai_fallback_no_key', ['provider' => 'openrouter']);
            return null;
        }
        $appUrl = (string)App::config('app.url', 'https://lityourcandle.com');
        $payloadMessages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $messages
        );
        try {
            $client = new Client(['timeout' => 30]);
            $r = $client->post('https://openrouter.ai/api/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type'  => 'application/json',
                    // OpenRouter asks for these so they can attribute
                    // traffic and show the app in their dashboard.
                    'HTTP-Referer'  => $appUrl,
                    'X-Title'       => 'Lit Your Candle',
                ],
                'json' => [
                    'model'      => $model,
                    'messages'   => $payloadMessages,
                    'max_tokens' => 600,
                ],
            ]);
            $resp = json_decode((string)$r->getBody(), true) ?: [];
            return [
                'text'      => (string)($resp['choices'][0]['message']['content'] ?? ''),
                'tokens_in' => $resp['usage']['prompt_tokens']     ?? null,
                'tokens_out'=> $resp['usage']['completion_tokens'] ?? null,
            ];
        } catch (\Throwable $e) {
            Logger::error('candle_ai_error', ['provider' => 'openrouter', 'msg' => $e->getMessage()]);
            return null;
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
- لا تصفي أي منتج أو دواء طبي؛ إذا طلبه المستخدم فاطلبي منه حجز جلسة مع مستشار. يُستثنى من ذلك الفيتامينات الوارد ذكرها صراحةً في المراجع المعتمدة المرفقة بنهاية هذا الدليل — يمكنك اقتراحها.
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
