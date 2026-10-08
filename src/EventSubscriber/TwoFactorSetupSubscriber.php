<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * 2FA is mandatory for certified users: until TOTP is configured, a logged-in
 * user can only reach the setup page (or log out).
 */
final class TwoFactorSetupSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = ['account_2fa_setup', 'app_logout', '_wdt', '_profiler'];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (priority 8).
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isTotpAuthenticationEnabled()) {
            return;
        }
        if (!$this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            return;
        }
        $route = (string) $event->getRequest()->attributes->get('_route');
        if (\in_array($route, self::ALLOWED_ROUTES, true) || str_starts_with($route, '_')) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('account_2fa_setup')));
    }
}
