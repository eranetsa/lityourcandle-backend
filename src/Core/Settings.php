<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Tiny key/value store for runtime-tunable settings backed by the
 * `app_settings` table. Used for things admins need to edit (the شمعة AI
 * system prompt, for example) without a code deploy.
 *
 *   $prompt = Settings::get('candle_ai_prompt', $default);
 *   Settings::set('candle_ai_prompt', $newValue);
 */
final class Settings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $row = DB::one('SELECT setting_value FROM app_settings WHERE setting_key = :k', [':k' => $key]);
        if (!$row) return $default;
        return (string)$row['setting_value'];
    }

    public static function set(string $key, string $value): void
    {
        DB::run(
            'INSERT INTO app_settings (setting_key, setting_value)
                  VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [':k' => $key, ':v' => $value]
        );
    }
}
