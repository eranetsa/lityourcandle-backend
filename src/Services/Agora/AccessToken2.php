<?php
declare(strict_types=1);

namespace App\Services\Agora;

use RuntimeException;

/**
 * Agora AccessToken2 ("007") builder.
 *
 * Reference: https://github.com/AgoraIO/Tools/blob/master/DynamicKey/AgoraDynamicKey/php/src/AccessToken2.php
 *
 * Token format:
 *   "007" + base64( deflate( packString(signature) + info ) )
 *
 * info:
 *   packString(appId) + uint32(issueTs) + uint32(expire_ttl) + uint32(salt)
 *   + uint16(serviceCount) + each service.pack() (services keyed/sorted by type)
 *
 * Signing key (note: in HMAC the *short* uint32 is the KEY, the cert is the data):
 *   k1 = HMAC-SHA256( msg=appCert, key=uint32(issueTs) )
 *   k  = HMAC-SHA256( msg=k1,      key=uint32(salt)    )
 *   signature = HMAC-SHA256( msg=info, key=k )
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

        ksort($this->services);
        foreach ($this->services as $service) {
            $info .= $service->pack();
        }

        $signature = hash_hmac('sha256', $info, $this->signingKey(), true);
        $payload   = Util::packString($signature) . $info;
        $deflated  = zlib_encode($payload, ZLIB_ENCODING_DEFLATE);
        if ($deflated === false) {
            throw new RuntimeException('agora_deflate_failed');
        }

        return self::VERSION . base64_encode($deflated);
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
        // hash_hmac($algo, $data, $key) — KEY is the 3rd arg.
        // Agora keys the HMAC with the short uint32 (issueTs / salt) and uses
        // the longer cert / intermediate hash as the *message*.
        $k1 = hash_hmac('sha256', $this->appCert, Util::packUint32($this->issueTs), true);
        return hash_hmac('sha256', $k1, Util::packUint32($this->salt), true);
    }
}
