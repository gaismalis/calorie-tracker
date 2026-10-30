<?php

namespace App\Voice;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Picks the VoiceTranscriber named by NUTRITION_PROVIDER ("random" or "gemini"). */
final class VoiceTranscriberFactory
{
    public function __construct(
        #[AutowireLocator([
            RandomVoiceTranscriber::NAME => RandomVoiceTranscriber::class,
            'gemini' => GeminiVoiceTranscriber::class,
        ])]
        private readonly ContainerInterface $providers,
        #[Autowire(env: 'NUTRITION_PROVIDER')]
        private readonly string $provider,
    ) {
    }

    public function create(): VoiceTranscriber
    {
        if (!$this->providers->has($this->provider)) {
            throw new \InvalidArgumentException(sprintf('Unknown NUTRITION_PROVIDER "%s". Use "random" or "gemini".', $this->provider));
        }

        return $this->providers->get($this->provider);
    }
}
