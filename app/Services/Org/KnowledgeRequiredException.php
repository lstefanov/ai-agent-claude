<?php

namespace App\Services\Org;

use RuntimeException;

/**
 * Хвърля се от KnowledgeRequirementService::gate, когато задачата изисква знание (частно
 * или непокрито), което липсва в базата. Контролерите я хващат → 422 + popup „Добави знания";
 * auto/scheduled пътищата → паркират задачата (needs_knowledge) без да я пускат/халюцинират.
 *
 * @var array<int, array<string, mixed>> $requirements блокиращите изисквания
 */
class KnowledgeRequiredException extends RuntimeException
{
    public function __construct(
        public readonly int $taskId,
        public readonly array $requirements = [],
    ) {
        parent::__construct('Задачата изисква знание, което липсва в базата ('.count($requirements).' непокрити).');
    }
}
