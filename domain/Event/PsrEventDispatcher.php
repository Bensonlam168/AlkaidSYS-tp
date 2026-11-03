<?php

declare(strict_types=1);

namespace Domain\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * PSR-14 兼容分发器，包装内部 EventService。
 */
class PsrEventDispatcher implements EventDispatcherInterface, ListenerProviderInterface
{
    public function __construct(protected EventService $eventService)
    {
    }

    /**
     * Dispatch event via internal EventService.
     */
    public function dispatch(object $event)
    {
        // 如果已经是 EventPayload，则直接使用内部 trigger
        if ($event instanceof EventPayload) {
            $this->eventService->trigger($event->getEvent(), $event->getData(), $event->getContext());
            return $event;
        }

        $eventName = method_exists($event, 'getName') ? $event->getName() : get_class($event);

        $payload = new EventPayload($eventName, $event, EventContext::fromGlobals());
        $this->eventService->trigger($payload->getEvent(), $payload->getData(), $payload->getContext());

        return $payload;
    }

    /**
     * Return listeners registered for the given event (priority sorted).
     *
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        $eventName = $event instanceof EventPayload
            ? $event->getEvent()
            : (method_exists($event, 'getName') ? $event->getName() : get_class($event));

        yield from $this->eventService->getPriorityListeners($eventName);
    }
}
