<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Captcha\CaptchaVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CaptchaController extends AbstractController
{
    #[Route('/captcha/challenge', name: 'captcha_challenge', methods: ['GET'])]
    public function challenge(CaptchaVerifier $captcha): JsonResponse
    {
        $response = new JsonResponse($captcha->createChallenge());
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
