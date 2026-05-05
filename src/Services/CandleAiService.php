<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
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
    public function generate(string $userMessage, ?string $mood, array $recentMoods): array
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
                    'messages'   => [['role' => 'user', 'content' => $userInput]],
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
دورك: الإصغاء بهدوء، التطمين، وتقديم اقتراحات بسيطة لرفع الحالة النفسية.

قواعد إلزامية:
- لا تقدّمي تشخيصًا طبيًا أو نفسيًا.
- لا تستبدلي الجلسة مع المختص؛ في الحالات الشديدة (إيذاء النفس، اكتئاب حاد، أزمات) أوصي بمستشار بشري.
- استخدمي لغة عربية فصيحة دافئة، جُمل قصيرة.
- اللهجة هادئة، غير وعظية.
- لا تستخدمي الإيموجي أكثر من رمز واحد إذا لزم.

أعيدي الجواب بصيغة JSON فقط دون أي نص خارج JSON، وفق هذا الشكل:
{
  "empathy": "جملة تعاطف قصيرة",
  "reflection": "تأمل مختصر يعكس مشاعر المستخدم",
  "suggestion": "اقتراح بسيط قابل للتنفيذ خلال دقائق",
  "exercise": "تمرين قصير (تنفس، كتابة، تأمل)",
  "dua": "دعاء قصير اختياري أو null",
  "consultant_category": "القلق والتوتر | العلاقات الأسرية | تطوير الذات | الاكتئاب | عام | null",
  "escalate": true|false
}
عيّني escalate = true إذا تضمّن الحديث: إيذاء نفس، انتحار، عنف، أعراض اكتئاب حادة، أو أزمة.
TXT;
    }

    private function systemPrompt(): string
    {
        $override = Settings::get(CANDLE_AI_PROMPT_KEY);
        if ($override !== null && trim($override) !== '') {
            return $override;
        }
        return self::defaultSystemPrompt();
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
