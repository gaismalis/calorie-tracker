<?php

namespace App\Controller;

use App\Chart\EnergyChart;
use App\Energy\EnergyCalculator;
use App\Energy\EnergyEstimate;
use App\Energy\WeightTrend;
use App\Entity\ExerciseEntry;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Estimation\Estimable;
use App\Exercise\ExerciseEstimation;
use App\Meal\MealEstimation;
use App\Nutrition\FoodCategory;
use App\Repository\ExerciseEntryRepository;
use App\Repository\MealEntryRepository;
use App\Repository\WeightEntryRepository;
use App\Summary\WeeklySummary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** The main page: the day's totals, the last 14 days, the week's summary, and the day's food and exercise. */
class DashboardController extends AbstractController
{
    use DayAwareController;

    /** Chart periods (?chart=…) and their length in days; "all" starts at the first logged meal. Not stored: a refresh shows the week again. */
    public const CHART_PERIODS = ['week' => 7, 'month' => 30, 'all' => null];
    private const DEFAULT_CHART_PERIOD = 'week';
    /** Longer ranges get a bar per week instead of per day. */
    private const MAX_DAILY_BARS = 92;

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    #[Route('/day/{date}', name: 'app_day', requirements: ['date' => '\d{4}-\d{2}-\d{2}'], methods: ['GET'])]
    public function day(
        #[CurrentUser] User $user,
        MealEntryRepository $mealRepository,
        ExerciseEntryRepository $exerciseRepository,
        WeightEntryRepository $weightRepository,
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
        $chartPeriod = array_key_exists($request->query->getString('chart'), self::CHART_PERIODS) ? $request->query->getString('chart') : self::DEFAULT_CHART_PERIOD;
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
            'charts' => $this->charts($user, $mealRepository, $exerciseRepository, $energy, $day, $today, $chartPeriod),
            'chartPeriod' => $chartPeriod,
            'chartPeriods' => array_keys(self::CHART_PERIODS),
            'week' => $this->week($user, $mealRepository, $exerciseRepository, $weightRepository, $energy, $day, $day == $today),
            'retryAt' => $retryAt,
            'hasPending' => array_any([...$meals, ...$exercises], fn (Estimable $e) => $e->isPending()),
            'openLog' => $needsAttention || null !== $returnToLog,
            'returnToLog' => $returnToLog,
        ]);
    }

    /** @return list<EnergyChart> the full-width chart and the compact one for phones; CSS shows the one that fits */
    private function charts(User $user, MealEntryRepository $meals, ExerciseEntryRepository $exercises, EnergyEstimate $energy, \DateTimeImmutable $day, \DateTimeImmutable $today, string $period): array
    {
        $days = self::CHART_PERIODS[$period];
        if (null === $days) { // all: from the first logged meal (at least a week)
            $firstMeal = $meals->firstMealDay($user);
            $days = null === $firstMeal || $firstMeal > $day ? 0 : (int) $firstMeal->diff($day)->days + 1;
            $days = max(self::CHART_PERIODS['week'], $days);
        }
        $daysPerBar = $days > self::MAX_DAILY_BARS ? 7 : 1;
        $days = (int) ceil($days / $daysPerBar) * $daysPerBar; // whole weeks
        $first = $day->modify(sprintf('-%d days', $days - 1));
        $end = $day->modify('+1 day');
        $eaten = $meals->dailyIntake($user, $first, $end);
        $alcohol = $meals->dailyCategoryIntake($user, FoodCategory::Alcohol, $first, $end);
        $exercise = $exercises->dailyExercise($user, $first, $end);

        $burned = [];
        if (null !== $energy->getBaseline()) {
            for ($d = $first; $d < $end; $d = $d->modify('+1 day')) {
                $date = $d->format('Y-m-d');
                $burned[$date] = $energy->burnedWith($exercise[$date] ?? 0.0);
            }
        }

        return array_map(
            fn (int $width) => EnergyChart::build($eaten, $burned, $day->format('Y-m-d'), $today->format('Y-m-d'), $days, $width, $daysPerBar, $alcohol),
            [EnergyChart::WIDTH, EnergyChart::COMPACT_WIDTH],
        );
    }

    /** The 7 full days up to the shown day: today isn't over, so for today the week ends yesterday. */
    private function week(User $user, MealEntryRepository $meals, ExerciseEntryRepository $exercises, WeightEntryRepository $weights, EnergyEstimate $energy, \DateTimeImmutable $day, bool $isToday): WeeklySummary
    {
        $last = $isToday ? $day->modify('-1 day') : $day;
        $first = $last->modify(sprintf('-%d days', WeeklySummary::DAYS - 1));
        $end = $last->modify('+1 day');
        // Warm the trend up with earlier weigh-ins, as on the weight page.
        $trend = WeightTrend::daily($weights->weightsByDate($user, $first->modify('-60 days')));

        return WeeklySummary::calculate(
            $meals->dailyIntake($user, $first, $end),
            $exercises->dailyExercise($user, $first, $end),
            $trend,
            $energy->getBaseline(),
            $last->format('Y-m-d'),
            $user->getWeeklyGoalKg(),
            $meals->dailyCategoryIntake($user, FoodCategory::Alcohol, $first, $end),
            $meals->dailyCategoryIntake($user, FoodCategory::Snack, $first, $end),
        );
    }

    private function entryKey(Estimable $entry): string
    {
        return ($entry instanceof MealEntry ? 'meal-' : 'exercise-').$entry->getId();
    }
}
