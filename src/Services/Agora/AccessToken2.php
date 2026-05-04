<?php
declare(strict_types=1);

namespace App\Services\Agora;

use RuntimeException;

/**
 * Agora AccessToken2 ("007") builder.
 *
 * Reference: https://github.com/AgoraIO/Tools/tree/master/DynamicKey/AgoraDynamicKey
 *
 * Token format:
 *   "007" + base64( packString(signature) + info )
 *
 * info:
 *   packString(appId) + uint32(issueTs) + uint32(expire_ttl) + uint32(salt)
 *   + uint16(serviceCount) + each service.pack()
 *
 * Signing key:
 *   k1 = HMAC-SHA256( uint32(issueTs), appCert )
 *   k  = HMAC-SHA256( uint32(salt),   k1 )
 *   signature = HMAC-SHA256( info, k )
 */
final class AccessToken2
{
    public const VERSION = '007';

    private string $appId;
    private string $appCert;
    private int $expireSeconds;
    private int $issueTs;
    private int $salt;
    /** @var array<int,Service> */
    private array $services = [];

    public function __construct(string $appId, string $appCert, int $expireSeconds = 3600)
    {
        $this->appId         = $appId;
        $this->appCert       = $appCert;
        $this->expireSeconds = $expireSeconds;
        $this->issueTs       = time();
        $this->salt          = random_int(1, 99999999);
    }

    public function addService(Service $service): void
    {
        $this->services[$service->getType()] = $service;
    }

    public function build(): string
    {
        if (!Util::isAgoraId($this->appId) || !Util::isAgoraId($this->appCert)) {
            throw new RuntimeException('agora_invalid_credentials');
        }

        $info = Util::packString($this->appId)
              . Util::packUint32($this->issueTs)
              . Util::packUint32($this->expireSeconds)
              . Util::packUint32($this->salt)
              . Util::packUint16(count($this->services));

        foreach ($this->services as $service) {
            $info .= $service->pack();
        }

        $signature = hash_hmac('sha256', $info, $this->signingKey(), true);
        $content   = Util::packString($signature) . $info;

        return self::VERSION . base64_encode($content);
    }

    public function getIssueTs(): int
    {
        return $this->issueTs;
    }

    public function getExpireSeconds(): int
    {
        return $this->expireSeconds;
    }

    private function signingKey(): string
    {
        $k1 = hash_hmac('sha256', Util::packUint32($this->issueTs), $this->appCert, true);
        return hash_hmac('sha256', Util::packUint32($this->salt), $k1, true);
    }
}
