<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_registration', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $locale = 'en';
        $countries = Countries::getNames($locale);
        // Alphabetical order must respect the locale (e.g. "Åland Islands" next to "A").
        $collator = new \Collator($locale);
        uasort($countries, static fn (string $a, string $b): int => $collator->compare($a, $b));

        $options = [];
        foreach ($countries as $code => $name) {
            $options[] = ['code' => $code, 'name' => $name, 'flag' => self::flag($code)];
        }

        $response = $this->render('registration/index.html.twig', ['countries' => $options, 'return_plane' => self::returnPlane($request)]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/recover', name: 'app_recover', methods: ['GET'])]
    public function recover(Request $request): Response
    {
        $response = $this->render('registration/recover.html.twig', ['return_plane' => self::returnPlane($request)]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Plane to reopen after registering or recovering an identity. Only a well-formed UUID is accepted
     * (the target URL is always built by the app), so this cannot be used as an open redirect.
     */
    private static function returnPlane(Request $request): ?string
    {
        $plane = $request->query->getString('plane');

        return Uuid::isValid($plane) ? $plane : null;
    }

    /** Regional indicator symbols, e.g. "ES" => 🇪🇸. */
    private static function flag(string $code): string
    {
        return mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65);
    }
}
