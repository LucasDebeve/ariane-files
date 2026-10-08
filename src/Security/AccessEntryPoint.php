<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Anonymous visitors are sent to the share code page; the certified and admin
 * areas send them to the login form instead.
 */
final class AccessEntryPoint implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $path = $request->getPathInfo();
        $needsAccount = 1 === preg_match('#^/(certifie|admin|compte)(/|$)#', $path);

        if ($request->isMethodSafe() && !$request->isXmlHttpRequest() && $request->hasSession()) {
            $this->saveTargetPath($request->getSession(), 'main', $request->getUri());
        }

        return new RedirectResponse($this->urlGenerator->generate($needsAccount ? 'app_login' : 'access_code'));
    }
}
