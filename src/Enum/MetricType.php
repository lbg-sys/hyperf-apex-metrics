<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Enum;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

/**
 * 指标类型。
 *
 * backing value 直接就是 Prometheus 文本格式里 `# TYPE` 的取值，因此一旦发布不得修改。
 */
#[Constants]
enum MetricType: string implements EnumInterface
{
    use EnumTrait;

    #[Message('计数器')]
    case COUNTER = 'counter';

    #[Message('直方图')]
    case HISTOGRAM = 'histogram';

    #[Message('仪表')]
    case GAUGE = 'gauge';
}
