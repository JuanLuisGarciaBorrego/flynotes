<?php

declare(strict_types=1);

namespace App\Controller;

use App\Helper\CurrentParticipantHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaperPlaneController extends AbstractController
{
    /** Only the page shell: assets/js/my-planes.js loads the planes and the totals from the API. */
    #[Route('/my-planes', name: 'app_my_planes', methods: ['GET'])]
    public function myPlanes(CurrentParticipantHelper $currentParticipant): Response
    {
        if (null === $currentParticipant->get()) {
            return $this->redirectToRoute('app_registration');
        }

        $response = $this->render('paper_plane/my_planes.html.twig');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/planes/new', name: 'app_paper_plane_new', methods: ['GET'])]
    public function new(CurrentParticipantHelper $currentParticipant): Response
    {
        if (null === $currentParticipant->get()) {
            return $this->redirectToRoute('app_registration');
        }

        $response = $this->render('paper_plane/new.html.twig');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
