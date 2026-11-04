<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SkyPulseController extends AbstractController
{
    /** Only the page shell, public: assets/js/sky-pulse.js loads the mission from the API. */
    #[Route('/sky-pulse', name: 'app_sky_pulse', methods: ['GET'])]
    public function index(): Response
    {
        $response = $this->render('sky_pulse/index.html.twig');
        // The decision buttons depend on the session: going back after signing up must not show the old page.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
