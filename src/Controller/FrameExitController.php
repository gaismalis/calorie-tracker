<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Leaves the add/adjust dialog: renders the dialog's Turbo frame with an instruction to visit
 * {@see $to} as a whole page (see frame_exit_controller.js). Only same-site paths are allowed.
 */
class FrameExitController extends AbstractController
{
    #[Route('/frame-exit', name: 'app_frame_exit', methods: ['GET'])]
    public function exit(Request $request): Response
    {
        $to = $request->query->getString('to');
        if (!str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')) {
            $to = $this->generateUrl('app_dashboard');
        }

        return $this->render('frame_exit.html.twig', ['to' => $to]);
    }
}
