<?php
declare(strict_types=1);

namespace App\Services\Agora;

final class ServiceRtc extends Service
{
    public const SERVICE_TYPE = 1;

    public const PRIVILEGE_JOIN_CHANNEL        = 1;
    public const PRIVILEGE_PUBLISH_AUDIO_STREAM = 2;
    public const PRIVILEGE_PUBLISH_VIDEO_STREAM = 3;
    public const PRIVILEGE_PUBLISH_DATA_STREAM  = 4;

    private string $channelName;
    private string $account;

    public function __construct(string $channelName, string $account)
    {
        parent::__construct(self::SERVICE_TYPE);
        $this->channelName = $channelName;
        $this->account     = $account;
    }

    public function pack(): string
    {
        return parent::pack()
            . Util::packString($this->channelName)
            . Util::packString($this->account);
    }
}
