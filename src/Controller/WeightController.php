<?php

namespace App\Controller;

use App\Chart\WeightChart;
use App\Energy\WeightTrend;
use App\Entity\User;
use App\Entity\WeightEntry;
use App\Form\WeightEntryFormType;
use App\Repository\WeightEntryRepository;
use App\Weight\WeightRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class WeightController extends AbstractController
{
    use DayAwareController;

    /** Chart ranges in days. */
    private const CHART_RANGES = [30, 90, 365];
    private const DEFAULT_CHART_RANGE = 90;

    #[Route('/weight', name: 'app_weight')]
    public function index(
        #[CurrentUser] User $user,
        Request $request,
        WeightEntryRepository $weights,
        WeightRecorder $recorder,
    ): Response {
        // The form works with plain dates in UTC; "today" is the user's local calendar date.
        $today = new \DateTimeImmutable($user->today()->format('Y-m-d'), new \DateTimeZone('UTC'));
        $latest = $weights->findLatest($user);

        $form = $this->createForm(WeightEntryFormType::class, ['date' => $today, 'weightKg' => $latest?->getWeightKg()], ['today' => $today]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ['date' => $date, 'weightKg' => $weightKg] = $form->getData();
            $recorder->record($user, $date, $weightKg);

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

        $range = in_array($request->query->getInt('range'), self::CHART_RANGES, true) ? $request->query->getInt('range') : self::DEFAULT_CHART_RANGE;

        return $this->render('weight/index.html.twig', [
            'form' => $form,
            'rows' => $rows,
            'charts' => $this->charts($user, $weights, $range),
            'range' => $range,
            'ranges' => self::CHART_RANGES,
        ]);
    }

    /** @return list<WeightChart> the full-width chart and the compact one for phones; CSS shows the one that fits */
    private function charts(User $user, WeightEntryRepository $weights, int $days): array
    {
        $today = $user->today()->format('Y-m-d');
        $first = (new \DateTimeImmutable($today))->modify(sprintf('-%d days', $days - 1))->format('Y-m-d');
        $inRange = fn (string $date) => $date >= $first && $date <= $today;

        // Warm the trend up with earlier weigh-ins so it doesn't start at the first visible weight.
        $all = $weights->weightsByDate($user, (new \DateTimeImmutable($first))->modify('-60 days'));
        $trend = WeightTrend::daily($all);

        return array_map(fn (int $width) => WeightChart::build(
            array_filter($all, $inRange, ARRAY_FILTER_USE_KEY),
            array_filter($trend, $inRange, ARRAY_FILTER_USE_KEY),
            $today,
            $days,
            $weights->findLatest($user)?->getWeightKg(),
            $width,
        ), [WeightChart::WIDTH, WeightChart::COMPACT_WIDTH]);
    }

    /**
     * Quick weigh-in from the main page: a slider in the dialog (Turbo frame "entry-panel"), starting at
     * the last logged weight. Saves for today, or for the day being viewed (?date=).
     */
    #[Route('/weight/quick', name: 'app_weight_quick', methods: ['GET', 'POST'])]
    public function quick(#[CurrentUser] User $user, Request $request, WeightEntryRepository $weights, WeightRecorder $recorder): Response
    {
        $today = $user->today();
        $requested = $this->parseLocalDate($user, $request->query->getString('date'));
        $day = null !== $requested && $requested < $today ? $requested : $today;
        $date = new \DateTimeImmutable($day->format('Y-m-d'), new \DateTimeZone('UTC'));

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('weight_quick', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            $value = str_replace(',', '.', trim($request->request->getString('weight_kg')));
            if (!is_numeric($value) || (float) $value < WeightRecorder::MIN_KG || (float) $value > WeightRecorder::MAX_KG) {
                $error = sprintf('Weight must be between %d and %d kg.', WeightRecorder::MIN_KG, WeightRecorder::MAX_KG);
            } else {
                $entry = $recorder->record($user, $date, (float) $value);
                $this->addFlash('success', sprintf('Saved %s kg%s.', number_format($entry->getWeightKg(), 1), $day == $today ? '' : ' for '.$day->format('j M')));

                return $this->goTo($this->dayUrl($user, $day));
            }
        }

        $sameDay = $weights->findForDate($user, $date);
        $start = $sameDay ?? $weights->findLatest($user);

        return $this->render('weight/quick.html.twig', [
            'day' => $day,
            'isToday' => $day == $today,
            'sameDay' => $sameDay,
            'start' => $start?->getWeightKg(),
            'value' => $request->request->getString('weight_kg') ?: ($start?->getWeightKg() ?? 75.0),
            'error' => $error,
        ], new Response(status: $error ? 422 : 200));
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
