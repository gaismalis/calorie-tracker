<?php

namespace App\Controller;

use App\Chart\EnergyChart;
use App\Energy\EnergyCalculator;
use App\Energy\EnergyEstimate;
use App\Entity\ExerciseEntry;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Estimation\Estimable;
use App\Exercise\ExerciseEstimation;
use App\Meal\MealEstimation;
use App\Repository\ExerciseEntryRepository;
use App\Repository\MealEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** The main page: the day's totals, the last 14 days, and the day's food and exercise. */
class DashboardController extends AbstractController
{
    use DayAwareController;

    private const CHART_DAYS = 14;

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    #[Route('/day/{date}', name: 'app_day', requirements: ['date' => '\d{4}-\d{2}-\d{2}'], methods: ['GET'])]
    public function day(
        #[CurrentUser] User $user,
        MealEntryRepository $mealRepository,
        ExerciseEntryRepository $exerciseRepository,
        EnergyCalculator $energyCalculator,
        MealEstimation $mealEstimation,
        ExerciseEstimation $exerciseEstimation,
        Request $request,
        ?string $date = null,
    ): Response {
        $today = $user->today();
        $day = null === $date ? $today : $this->parseLocalDate($user, $date);
        if (null === $day) {
            throw $this->createNotFoundException('Invalid date.');
        }
        if ($day >= $today && null !== $date) {
            return $this->redirectToRoute('app_dashboard'); // today and the future live at "/"
        }

        $meals = $mealRepository->findForDay($user, $day);
        $exercises = $exerciseRepository->findForDay($user, $day);

        $totals = ['kcal' => 0, 'protein' => 0, 'carbs' => 0, 'fat' => 0];
        foreach ($meals as $meal) {
            $totals['kcal'] += $meal->getKcal();
            $totals['protein'] += $meal->getProtein();
            $totals['carbs'] += $meal->getCarbs();
            $totals['fat'] += $meal->getFat();
        }
        $exerciseKcal = array_sum(array_map(fn (ExerciseEntry $e) => $e->getKcal(), $exercises));

        $energy = $energyCalculator->estimate($user);
        $retryAt = [];
        foreach ([...$meals, ...$exercises] as $entry) {
            if ($entry->isFailed()) {
                $workflow = $entry instanceof MealEntry ? $mealEstimation : $exerciseEstimation;
                $retryAt[$this->entryKey($entry)] = $workflow->canRetryManually($entry) ? null : $workflow->manualRetryAvailableAt($entry);
            }
        }
        $needsAttention = array_any([...$meals, ...$exercises], fn (Estimable $e) => $e->isPending() || $e->isFailed());
        // Coming back from an action inside the log (delete, retry, adjust): keep it open on that tab.
        $returnToLog = in_array($request->query->get('log'), ['food', 'exercise'], true) ? $request->query->get('log') : null;

        return $this->render('dashboard/index.html.twig', [
            'day' => $day,
            'isToday' => $day == $today,
            'previousDayUrl' => $this->dayUrl($user, $day->modify('-1 day')),
            'nextDayUrl' => $day < $today ? $this->dayUrl($user, $day->modify('+1 day')) : null,
            'meals' => $meals,
            'exercises' => $exercises,
            'totals' => $totals,
            'exerciseKcal' => $exerciseKcal,
            'energy' => $energy,
            'burned' => $energy->burnedWith($exerciseKcal),
            'target' => $energy->targetFor($user->getWeeklyGoalKg(), $exerciseKcal),
            'chart' => $this->chart($user, $mealRepository, $exerciseRepository, $energy, $day, $today),
            'retryAt' => $retryAt,
            'hasPending' => array_any([...$meals, ...$exercises], fn (Estimable $e) => $e->isPending()),
            'openLog' => $needsAttention || null !== $returnToLog,
            'returnToLog' => $returnToLog,
        ]);
    }

    private function chart(User $user, MealEntryRepository $meals, ExerciseEntryRepository $exercises, EnergyEstimate $energy, \DateTimeImmutable $day, \DateTimeImmutable $today): EnergyChart
    {
        $first = $day->modify(sprintf('-%d days', self::CHART_DAYS - 1));
        $end = $day->modify('+1 day');
        $eaten = $meals->dailyIntake($user, $first, $end);
        $exercise = $exercises->dailyExercise($user, $first, $end);

        $burned = [];
        if (null !== $energy->getBaseline()) {
            for ($d = $first; $d < $end; $d = $d->modify('+1 day')) {
                $date = $d->format('Y-m-d');
                $burned[$date] = $energy->burnedWith($exercise[$date] ?? 0.0);
            }
        }

        return EnergyChart::build($eaten, $burned, $day->format('Y-m-d'), $today->format('Y-m-d'), self::CHART_DAYS);
    }

    private function entryKey(Estimable $entry): string
    {
        return ($entry instanceof MealEntry ? 'meal-' : 'exercise-').$entry->getId();
    }
}
