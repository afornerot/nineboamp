<?php

namespace App\EventListener;

use App\Security\CasService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class LogoutListener
{
    public function __construct(
        private ParameterBagInterface $parameterBag,
        private CasService $casService,
        private RequestStack $requestStack,
    ) {
    }

    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogoutEvent(LogoutEvent $event): void
    {
        if ('CAS' == $this->parameterBag->get('modeAuth')) {
            $request = $this->requestStack->getCurrentRequest();
            if ($request) {
                $this->casService->logout($request);
            }
        }
    }
}
