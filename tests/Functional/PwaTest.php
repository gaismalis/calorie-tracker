<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Installable app (PWA): head tags, manifest, icons, service worker and offline page. */
class PwaTest extends WebTestCase
{
    private const PUBLIC_DIR = __DIR__.'/../../public';

    public function testPagesLinkTheManifestAndIcons(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertSame('/manifest.webmanifest', $crawler->filter('link[rel=manifest]')->attr('href'));
        self::assertSame('/icons/apple-touch-icon.png', $crawler->filter('link[rel=apple-touch-icon]')->attr('href'));
        self::assertSame('#2f855a', $crawler->filter('meta[name=theme-color]')->attr('content'));
        foreach ($crawler->filter('link[rel=manifest], link[rel=apple-touch-icon], link[rel=icon]') as $link) {
            self::assertFileExists(self::PUBLIC_DIR.$link->getAttribute('href'));
        }
    }

    public function testManifestMakesTheAppInstallable(): void
    {
        $manifest = json_decode(file_get_contents(self::PUBLIC_DIR.'/manifest.webmanifest'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Calorie Tracker', $manifest['name']);
        self::assertSame('standalone', $manifest['display'], 'opens full screen, without the browser bar');
        self::assertSame('/', $manifest['start_url']);
        $sizes = [];
        foreach ($manifest['icons'] as $icon) {
            self::assertFileExists(self::PUBLIC_DIR.$icon['src']);
            if ('image/png' === $icon['type']) {
                [$width, $height] = getimagesize(self::PUBLIC_DIR.$icon['src']);
                self::assertSame($icon['sizes'], $width.'x'.$height, $icon['src'].' is really the size it claims');
                $sizes[] = $icon['sizes'];
            }
        }
        self::assertContains('192x192', $sizes, 'required for installing');
        self::assertContains('512x512', $sizes, 'required for installing');
        self::assertContains('maskable', array_column($manifest['icons'], 'purpose'), 'Android adaptive icon');
        self::assertSame([180, 180], array_slice(getimagesize(self::PUBLIC_DIR.'/icons/apple-touch-icon.png'), 0, 2));
    }

    public function testServiceWorkerOnlyCachesTheOfflinePage(): void
    {
        $worker = file_get_contents(self::PUBLIC_DIR.'/sw.js');

        self::assertStringContainsString("'/offline.html'", $worker);
        self::assertFileExists(self::PUBLIC_DIR.'/offline.html');
        self::assertStringContainsString("request.method !== 'GET'", $worker, 'form posts are never intercepted');
        self::assertStringContainsString("navigator.serviceWorker.register('/sw.js')", file_get_contents(__DIR__.'/../../assets/app.js'));
    }
}
