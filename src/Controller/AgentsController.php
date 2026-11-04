<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AgentsController extends AbstractController
{
    /** Public presentation of the MCP integration. The MCP URL is built from the current request host. */
    #[Route('/agents', name: 'app_agents', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('agents/index.html.twig');
    }
}
