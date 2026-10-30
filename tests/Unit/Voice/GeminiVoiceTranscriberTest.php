<?php

namespace App\Tests\Unit\Voice;

use App\Ai\GeminiClient;
use App\Ai\Recording;
use App\Voice\GeminiVoiceTranscriber;
use App\Voice\VoiceException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

class GeminiVoiceTranscriberTest extends TestCase
{
    public function testSendsTheRecordingAndReadsTheFoods(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent) {
            $sent = json_decode($options['body'], true);

            return new JsonMockResponse([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'transcript' => 'Divas olas, rupjmaize ar sviestu un kafija ar pienu.',
                    'foods' => ['divas olas', ' rupjmaize ar sviestu ', '', 'kafija ar pienu', 42],
                ])]]], 'finishReason' => 'STOP']],
                'modelVersion' => 'gemini-test-001',
            ]);
        });

        $heard = (new GeminiVoiceTranscriber(new GeminiClient($http, 'key', 'm', new MockClock())))
            ->foods(new Recording('RIFF....WAVEfmt ', 'audio/wav'), 20.0);

        self::assertSame(['mimeType' => 'audio/wav', 'data' => base64_encode('RIFF....WAVEfmt ')], $sent['contents'][0]['parts'][0]['inlineData'], 'the recording goes first');
        self::assertStringContainsString('Never add foods or amounts they didn\'t say', $sent['systemInstruction']['parts'][0]['text']);
        self::assertSame(['divas olas', 'rupjmaize ar sviestu', 'kafija ar pienu'], $heard->foods, 'trimmed, empty and non-text ones dropped');
        self::assertSame('divas olas, rupjmaize ar sviestu, kafija ar pienu', $heard->asText());
        self::assertSame('Divas olas, rupjmaize ar sviestu un kafija ar pienu.', $heard->transcript);
    }

    public function testFailuresBecomeVoiceExceptions(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['error' => ['message' => 'API key not valid']], ['http_code' => 400]));

        $this->expectException(VoiceException::class);
        (new GeminiVoiceTranscriber(new GeminiClient($http, 'key', 'm', new MockClock())))->foods(new Recording('x', 'audio/wav'));
    }

    public function testOnlyAudioGoesToTheAi(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Recording('x', 'video/mp4');
    }
}
