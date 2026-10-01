<?php

namespace App\Controller;

use App\Entity\User;
use App\Estimation\Estimable;
use Symfony\Component\HttpFoundation\Response;

/** Date/time handling in the user's timezone and "back to the entry's day" redirects, shared by the meal and exercise controllers. */
trait DayAwareController
{

    /**
     * When something was eaten or done, from the optional date (Y-m-d) and time (H:i) fields, in the user's timezone.
     * No date and no time = now. A date without time = 12:00 (or now, for today).
     *
     * @return \DateTimeImmutable|string|null null for "now", a string with the error message if invalid
     */
    private function loggedAt(User $user, string $date, string $time): \DateTimeImmutable|string|null
    {
        $date = trim($date);
        $time = trim($time);
        if ('' === $date && '' === $time) {
            return null;
        }

        $today = $user->today();
        $day = '' === $date ? $today : $this->parseLocalDate($user, $date);
        if (null === $day) {
            return 'Invalid date.';
        }
        if ('' === $time) {
            return $day == $today ? null : $day->setTime(12, 0);
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            return 'Invalid time.';
        }

        $eatenAt = $day->setTime((int) $m[1], (int) $m[2]);
        if ($eatenAt > new \DateTimeImmutable('+5 minutes')) {
            return "You can't log meals in the future.";
        }

        return $eatenAt;
    }


    /** A 'Y-m-d' date and 'H:i' time in the user's timezone, or null if either is invalid. */
    private function parseLocalDateTime(User $user, string $date, string $time): ?\DateTimeImmutable
    {
        $day = $this->parseLocalDate($user, trim($date));
        if (null === $day || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($time), $m)) {
            return null;
        }

        return $day->setTime((int) $m[1], (int) $m[2]);
    }


    /** Midnight of a 'Y-m-d' date in the user's timezone, or null if it isn't a real date. */
    private function parseLocalDate(User $user, string $date): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $user->getDateTimeZone());

        return $parsed && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }

    private function denyUnlessOwner(User $user, Estimable $entry): void
    {
        if ($entry->getUser() !== $user) {
            throw $this->createNotFoundException();
        }
    }

    private function flashAndGoToDay(User $user, \DateTimeImmutable $moment, string $type, string $message): Response
    {
        $this->addFlash($type, $message);

        return $this->goTo($this->dayUrl($user, $moment));
    }

    /**
     * Redirect to a page. Inside the add/adjust dialog (Turbo frame "entry-panel") a plain redirect
     * would only refresh the frame, so the frame is sent to a tiny page that makes the browser
     * visit the URL as a whole page instead.
     */
    private function goTo(string $url): Response
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();
        if ('entry-panel' === $request?->headers->get('Turbo-Frame')) {
            return $this->redirectToRoute('app_frame_exit', ['to' => $url]);
        }

        return $this->redirect($url);
    }


    /**
     * After an action taken inside the day's log (delete, retry, adjust): back to that day with the log
     * open on the same tab ("food" or "exercise"), so the user can carry on where they were.
     */
    private function flashAndGoToLog(string $log, User $user, \DateTimeImmutable $moment, string $type, string $message): Response
    {
        $this->addFlash($type, $message);

        return $this->goTo($this->dayUrl($user, $moment, $log));
    }

    /**
     * Dashboard URL of the user's local day that contains $moment ("/" for today).
     *
     * @param string|null $log "food" or "exercise" to open the day's log on that tab
     */
    private function dayUrl(User $user, \DateTimeImmutable $moment, ?string $log = null): string
    {
        $date = $moment->setTimezone($user->getDateTimeZone())->format('Y-m-d');
        $query = null === $log ? [] : ['log' => $log];

        return $date === $user->today()->format('Y-m-d')
            ? $this->generateUrl('app_dashboard', $query)
            : $this->generateUrl('app_day', ['date' => $date] + $query);
    }
}
