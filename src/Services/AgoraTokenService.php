<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Services\Agora\AccessToken2;
use App\Services\Agora\ServiceRtc;
use Throwable;

/**
 * Agora RTC token (AccessToken2 / "007" format) builder.
 * Replaces the old hand-rolled "006" implementation which produced malformed
 * tokens — Agora accepted the channel join silently but rejected the publish
 * privileges, so audio/video never flowed.
 */
final class AgoraTokenService
{
    public function buildRtcToken(string $channel, int $uid, int $ttlSeconds = 3600, string $role = 'publisher'): array
    {
        $appId   = (string)App::config('agora.app_id');
        $appCert = (string)App::config('agora.app_certificate');
        if (!$appId || !$appCert) {
            return ['token' => null, 'app_id' => $appId, 'note' => 'agora_not_configured'];
        }

        $privilegeExpireTs = time() + $ttlSeconds;

        $rtc = new ServiceRtc($channel, (string)$uid);
        $rtc->addPrivilege(ServiceRtc::PRIVILEGE_JOIN_CHANNEL, $privilegeExpireTs);
        if ($role === 'publisher') {
            $rtc->addPrivilege(ServiceRtc::PRIVILEGE_PUBLISH_AUDIO_STREAM, $privilegeExpireTs);
            $rtc->addPrivilege(ServiceRtc::PRIVILEGE_PUBLISH_VIDEO_STREAM, $privilegeExpireTs);
            $rtc->addPrivilege(ServiceRtc::PRIVILEGE_PUBLISH_DATA_STREAM,  $privilegeExpireTs);
        }

        $access = new AccessToken2($appId, $appCert, $ttlSeconds);
        $access->addService($rtc);

        try {
            $token = $access->build();
        } catch (Throwable $e) {
            return ['token' => null, 'app_id' => $appId, 'note' => 'agora_invalid_credentials'];
        }

        return [
            'token'      => $token,
            'app_id'     => $appId,
            'channel'    => $channel,
            'uid'        => $uid,
            'role'       => $role,
            'expires_at' => date('c', $privilegeExpireTs),
        ];
    }
}
