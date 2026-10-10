<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Enum;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

#[Constants]
enum AlertLevel: string implements EnumInterface
{
    use EnumTrait;

    #[Message('正常')]
    case OK = 'ok';

    #[Message('警告')]
    case WARNING = 'warning';

    #[Message('严重')]
    case CRITICAL = 'critical';

    /**
     * 取更严重的一档。
     *
     * 大屏的总判定是多条指标里最差的那条：只要有一项进了严重，整体就不该显示正常。
     */
    public function worst(self $other): self
    {
        return $this->severity() >= $other->severity() ? $this : $other;
    }

    private function severity(): int
    {
        return match ($this) {
            self::OK => 0,
            self::WARNING => 1,
            self::CRITICAL => 2,
        };
    }
}
