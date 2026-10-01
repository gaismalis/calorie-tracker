<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:gemini:models', description: 'List Gemini models your API key can use for generateContent (pick one for GEMINI_MODEL)')]
final class GeminiModelsCommand
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $apiKey,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if ('' === $this->apiKey) {
            $io->error('GEMINI_API_KEY is not set. Add it to .env.local.');

            return Command::FAILURE;
        }

        $response = $this->httpClient->request('GET', 'https://generativelanguage.googleapis.com/v1beta/models', [
            'headers' => ['x-goog-api-key' => $this->apiKey],
            'query' => ['pageSize' => 1000],
        ]);
        $body = $response->toArray(false);
        if (200 !== $response->getStatusCode()) {
            $io->error($body['error']['message'] ?? 'Request failed');

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($body['models'] ?? [] as $model) {
            if (in_array('generateContent', $model['supportedGenerationMethods'] ?? [], true)) {
                $rows[] = [substr($model['name'], strlen('models/')), $model['displayName'] ?? ''];
            }
        }
        $io->table(['GEMINI_MODEL value', 'Name'], $rows);

        return Command::SUCCESS;
    }
}
