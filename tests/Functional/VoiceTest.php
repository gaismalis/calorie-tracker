<?php

namespace App\Tests\Functional;

use App\Ai\Recording;
use App\Controller\VoiceController;
use App\Demo\DemoAccount;
use App\Entity\User;
use App\Repository\AiUsageRepository;
use App\Tests\Support\FakeVoiceTranscriber;
use App\Voice\VoiceTranscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class VoiceTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // keep the fake transcriber across requests
        $this->user = (new User())->setEmail('me@example.com')->setPassword('x');
        $this->em()->persist($this->user);
        $this->em()->flush();
        $this->client->loginUser($this->user);
    }

    public function testTheMealDialogHasSayIt(): void
    {
        $crawler = $this->client->request('GET', '/meals/new');

        $voice = $crawler->filter('form.meal-form [data-controller=voice]');
        self::assertSame('/meals/voice', $voice->attr('data-voice-url-value'));
        self::assertNotEmpty($voice->attr('data-voice-token-value'));
        self::assertStringContainsString('Say it', $voice->filter('button[data-action="voice#start"]')->text());
        self::assertSame('We got this:', $voice->filter('.voice-result .voice-title')->text());
        self::assertSame(['Log it', 'Fix it', 'Try again'], $voice->filter('.voice-actions button')->each(fn ($b) => $b->text()));
    }

    public function testAVoiceMessageBecomesTheFoodsHeard(): void
    {
        $token = $this->token();
        $this->transcriber()->willHear('2 eggs', 'a slice of rye bread', 'a coffee with milk');

        $this->send($token);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'foods' => ['2 eggs', 'a slice of rye bread', 'a coffee with milk'],
            'text' => '2 eggs, a slice of rye bread, a coffee with milk',
            'transcript' => '2 eggs and a slice of rye bread and a coffee with milk',
        ], $this->json());
        self::assertSame('audio/wav', $this->transcriber()->received[0]->mimeType);
        self::assertSame(1, static::getContainer()->get(AiUsageRepository::class)->usedOn($this->user, 'voice', $this->user->today()->format('Y-m-d')));
    }

    public function testNoFoodHeard(): void
    {
        $token = $this->token();
        $this->transcriber()->willHear();

        $this->send($token);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString("I didn't hear any food", $this->json()['error']);
    }

    public function testTheAiFailing(): void
    {
        $token = $this->token();
        $this->transcriber()->willFail();

        $this->send($token);

        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('Please try again or type it', $this->json()['error']);
    }

    public function testOnlyAudioIsAccepted(): void
    {
        $token = $this->token();
        $file = $this->file('not audio', 'note.txt');

        $this->client->request('POST', '/meals/voice', ['_token' => $token], ['voice' => $file], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(415);
        self::assertSame([], $this->transcriber()->received);
    }

    public function testAValidTokenIsNeeded(): void
    {
        $this->send('wrong');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->transcriber()->received);
    }

    public function testAtMost30VoiceMessagesADay(): void
    {
        $token = $this->token();
        $usage = static::getContainer()->get(AiUsageRepository::class);
        for ($i = 0; $i < VoiceController::DAILY_LIMIT; ++$i) {
            $usage->record($this->user, 'voice', $this->user->today()->format('Y-m-d'));
        }

        $this->send($token);

        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('type the meal instead', $this->json()['error']);
        self::assertSame([], $this->transcriber()->received);
    }

    public function testNotInTheDemo(): void
    {
        $this->client->request('GET', '/logout');
        $this->client->request('POST', '/demo');
        $token = $this->token();

        $this->send($token);

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString("This is the demo", $this->json()['error']);
        self::assertSame([], $this->transcriber()->received, 'no AI for the demo');
        self::assertSame(DemoAccount::EMAIL, $this->client->getContainer()->get('security.token_storage')->getToken()?->getUserIdentifier());
    }

    public function testTheRecorderInTheBrowser(): void
    {
        $script = file_get_contents(__DIR__.'/../../assets/controllers/voice_controller.js');

        self::assertStringContainsString('const MAX_SECONDS = '.Recording::MAX_SECONDS.';', $script, 'stops where the server stops accepting');
        self::assertStringContainsString("type: 'audio/wav'", $script, 'WAV: every browser can make it and Gemini takes it');
        self::assertStringNotContainsString('this.context =', $script, 'Stimulus controllers use `context` themselves');
        self::assertStringContainsString('this.source = this.audio.createMediaStreamSource', $script, 'kept, or Chrome garbage-collects it and the recording goes silent');
        self::assertStringContainsString("headers: { Accept: 'application/json' }", $script, 'so the demo answers in JSON');
    }

    private function send(string $token): void
    {
        $this->client->request('POST', '/meals/voice', ['_token' => $token], ['voice' => $this->file($this->wavBytes(), 'voice.wav')], ['HTTP_ACCEPT' => 'application/json']);
    }

    private function token(): string
    {
        return $this->client->request('GET', '/meals/new')->filter('[data-controller=voice]')->attr('data-voice-token-value');
    }

    /** A real (silent, 0.1 s) WAV, so the MIME type is detected as audio. */
    private function wavBytes(): string
    {
        $samples = str_repeat("\0\0", 1600);

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
    }

    private function file(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'voice');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function transcriber(): FakeVoiceTranscriber
    {
        return static::getContainer()->get(VoiceTranscriber::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
