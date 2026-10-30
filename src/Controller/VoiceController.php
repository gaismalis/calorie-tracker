<?php

namespace App\Controller;

use App\Ai\Recording;
use App\Entity\User;
use App\Repository\AiUsageRepository;
use App\Voice\VoiceException;
use App\Voice\VoiceTranscriber;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * 🎤 in the meal dialog (voice_controller.js): a voice message → the foods it mentions, shown as "We got this:".
 * Nothing is saved; "Log it" then sends the foods as the meal's text through the normal estimate.
 * The recording is only sent to the AI, never stored.
 */
class VoiceController extends AbstractController
{
    public const DAILY_LIMIT = 30;
    public const USAGE_FEATURE = 'voice';
    /** Seconds the request waits for the AI. */
    private const TIME_LIMIT = 20.0;

    #[Route('/meals/voice', name: 'app_meal_voice', methods: ['POST'])]
    public function foods(#[CurrentUser] User $user, Request $request, VoiceTranscriber $transcriber, AiUsageRepository $usage, LoggerInterface $logger): JsonResponse
    {
        if (!$this->isCsrfTokenValid('meal_voice', $request->request->getString('_token'))) {
            return $this->error('The form expired. Close it and open it again.', 403);
        }

        $file = $request->files->get('voice');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->error("The recording didn't arrive. Please try again.", 400);
        }
        if ($file->getSize() > Recording::MAX_BYTES) {
            return $this->error(sprintf('That recording is too long. Keep it under %d seconds.', Recording::MAX_SECONDS), 413);
        }
        $type = $file->getMimeType();
        if (!in_array($type, Recording::MIME_TYPES, true)) {
            return $this->error("That recording couldn't be read. Please try again or type it.", 415);
        }

        $type = in_array($type, ['audio/x-wav', 'audio/wave'], true) ? 'audio/wav' : $type; // the name Gemini lists

        $today = $user->today()->format('Y-m-d');
        if ($usage->usedOn($user, self::USAGE_FEATURE, $today) >= self::DAILY_LIMIT) {
            return $this->error(sprintf("That's %d voice messages today; please type the meal instead.", self::DAILY_LIMIT), 429);
        }
        $usage->record($user, self::USAGE_FEATURE, $today);

        try {
            $heard = $transcriber->foods(new Recording(file_get_contents($file->getPathname()), $type), self::TIME_LIMIT);
        } catch (VoiceException $e) {
            $logger->warning('Voice message failed', ['error' => $e->getMessage()]);

            return $this->error("Couldn't understand the recording just now. Please try again or type it.", 502);
        }

        if ([] === $heard->foods) {
            return $this->error("I didn't hear any food. Try again, e.g. “two eggs and a coffee with milk”.", 422, $heard->transcript);
        }

        return new JsonResponse(['foods' => $heard->foods, 'text' => $heard->asText(), 'transcript' => $heard->transcript]);
    }

    private function error(string $message, int $status, string $transcript = ''): JsonResponse
    {
        return new JsonResponse(['error' => $message, 'transcript' => $transcript], $status);
    }
}
