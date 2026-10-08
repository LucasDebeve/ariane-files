<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\CspNonce;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Strict CSP, HSTS, nosniff and related headers on every response.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CspNonce $nonce,
        #[Autowire(env: 'FILES_PUBLIC_URL')]
        private readonly string $filesPublicUrl,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -10]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        if ('prod' === $this->environment) {
            $headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        }

        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->policy());
        }
    }

    private function policy(): string
    {
        $nonce = "'nonce-".$this->nonce->get()."'";
        $files = $this->filesOrigin();

        $directives = [
            "default-src 'self'",
            "script-src 'self' $nonce",
            "style-src 'self' $nonce",
            // Inline style attributes only (progress bars, pdf.js text layer); no inline <style> blocks.
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob: $files",
            "font-src 'self' data:",
            "connect-src 'self' $files",
            "worker-src 'self' blob:",
            'frame-src https://www.youtube-nocookie.com https://player.vimeo.com',
            "media-src 'self' $files",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];
        if ('prod' === $this->environment) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    private function filesOrigin(): string
    {
        $parts = parse_url($this->filesPublicUrl);
        if (!isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
