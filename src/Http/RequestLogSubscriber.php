<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestLogSubscriber implements EventSubscriberInterface
{
    /** @var array<int, float> */
    private array $startTimes = [];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onRequest', 0],
            KernelEvents::RESPONSE  => ['onResponse', 0],
            KernelEvents::EXCEPTION => ['onException', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $this->startTimes[spl_object_id($event->getRequest())] = microtime(true);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $ms = $this->elapsedMs($request);
        $ts = new DateTimeImmutable()->format(DateTimeInterface::ATOM);
        file_put_contents('php://stderr', "[{$ts}] {$request->getMethod()} {$request->getPathInfo()} {$event->getResponse()->getStatusCode()} {$ms}ms\n", FILE_APPEND);
        unset($this->startTimes[spl_object_id($request)]);
    }

    public function onException(ExceptionEvent $event): void
    {
        if ($event->hasResponse()) {
            return;
        }
        $request = $event->getRequest();
        $e = $event->getThrowable();
        $ms = $this->elapsedMs($request);
        $ts = new DateTimeImmutable()->format(DateTimeInterface::ATOM);
        file_put_contents('php://stderr', "[{$ts}] {$request->getMethod()} {$request->getPathInfo()} 500 {$ms}ms " . $e::class . ': ' . $e->getMessage() . "\n", FILE_APPEND);
    }

    private function elapsedMs(object $request): int
    {
        $start = $this->startTimes[spl_object_id($request)] ?? microtime(true);
        return (int) round((microtime(true) - $start) * 1000);
    }
}
