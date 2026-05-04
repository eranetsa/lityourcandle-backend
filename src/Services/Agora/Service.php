<?php
declare(strict_types=1);

namespace App\Services\Agora;

abstract class Service
{
    protected int $type;
    /** @var array<int,int> privilege_id => expire_unix_ts */
    protected array $privileges = [];

    public function __construct(int $type)
    {
        $this->type = $type;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function addPrivilege(int $privilege, int $expireUnixTs): void
    {
        $this->privileges[$privilege] = $expireUnixTs;
    }

    public function pack(): string
    {
        return Util::packUint16($this->type) . Util::packMapUint32($this->privileges);
    }
}
