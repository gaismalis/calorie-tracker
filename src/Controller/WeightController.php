<?php

namespace App\Controller;

use App\Energy\WeightTrend;
use App\Entity\User;
use App\Entity\WeightEntry;
use App\Form\WeightEntryFormType;
use App\Repository\WeightEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class WeightController extends AbstractController
{
    #[Route('/weight', name: 'app_weight')]
    public function index(
        #[CurrentUser] User $user,
        Request $request,
        WeightEntryRepository $weights,
        EntityManagerInterface $entityManager,
    ): Response {
        // The form works with plain dates in UTC; "today" is the user's local calendar date.
        $today = new \DateTimeImmutable($user->today()->format('Y-m-d'), new \DateTimeZone('UTC'));
        $latest = $weights->findLatest($user);

        $form = $this->createForm(WeightEntryFormType::class, ['date' => $today, 'weightKg' => $latest?->getWeightKg()], ['today' => $today]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ['date' => $date, 'weightKg' => $weightKg] = $form->getData();

            $entry = $weights->findForDate($user, $date);
            if ($entry) {
                $entry->setWeightKg($weightKg);
            } else {
                $entityManager->persist(new WeightEntry($user, $date, $weightKg));
            }
            $entityManager->flush();

            $this->addFlash('success', sprintf('Saved %s kg for %s.', round($weightKg, 1), $date->format('j M Y')));

            return $this->redirectToRoute('app_weight');
        }

        $entries = $weights->findRecent($user);
        $oldestShown = end($entries) ?: null;
        // Warm the trend up with older weigh-ins so the oldest rows shown aren't just the raw weight.
        $trend = $oldestShown ? WeightTrend::daily($weights->weightsByDate($user, $oldestShown->getDate()->modify('-60 days'))) : [];
        $rows = [];
        foreach ($entries as $i => $entry) {
            $previous = $entries[$i + 1] ?? null;
            $rows[] = [
                'entry' => $entry,
                'change' => $previous ? round($entry->getWeightKg() - $previous->getWeightKg(), 1) : null,
                'trend' => $trend[$entry->getDate()->format('Y-m-d')] ?? null,
            ];
        }

        return $this->render('weight/index.html.twig', ['form' => $form, 'rows' => $rows]);
    }

    #[Route('/weight/{id}/delete', name: 'app_weight_delete', methods: ['POST'])]
    public function delete(#[CurrentUser] User $user, WeightEntry $entry, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($entry->getUser() !== $user) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('weight_delete_'.$entry->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($entry);
        $entityManager->flush();

        return $this->redirectToRoute('app_weight');
    }
}
