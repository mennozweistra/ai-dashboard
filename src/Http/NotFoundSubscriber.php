<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Twig\Environment;

final readonly class NotFoundSubscriber implements EventSubscriberInterface
{
    public function __construct(private Environment $twig) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $cause = $event->getThrowable();
        while ($cause instanceof \Throwable) {
            if ($cause instanceof ResourceNotFoundException) {
                $html = $this->twig->render('not_found.html.twig');
                $event->setResponse(new Response($html, 404, ['Content-Type' => 'text/html; charset=utf-8']));
                return;
            }
            // A method mismatch on a known route (introduced by ticket 137's
            // POST-only action route) is a caller error, not an unexpected
            // failure — respond with 405 and the allowed methods rather than
            // letting it surface as an uncaught exception.
            if ($cause instanceof MethodNotAllowedHttpException) {
                $event->setResponse(new Response(
                    'Method Not Allowed',
                    405,
                    ['Content-Type' => 'text/plain; charset=utf-8', ...$cause->getHeaders()],
                ));
                return;
            }
            $cause = $cause->getPrevious();
        }
    }
}
