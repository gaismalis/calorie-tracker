<?php

namespace App\Tests\Functional\Admin;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Entity\WeightEntry;
use App\Nutrition\EstimatedItem;
use App\Nutrition\MealEstimate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAnonymousUsersGetTheAdminLoginScreen(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name=_username]');
        // A real per-session token, not the placeholder of a stateless token that needs JavaScript to fill in.
        self::assertGreaterThan(20, strlen($this->client->getCrawler()->filter('input[name=_csrf_token]')->attr('value')));
    }

    public function testUsersLoggedIntoTheAppGetTheAdminLoginScreenNotAnError(): void
    {
        $this->client->loginUser($this->user('user@example.com')); // app session only

        foreach (['/admin', '/admin/user', '/admin/meal-entry', '/admin/weight-entry'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/admin/login', message: $url);
        }

        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.topbar a[href="/admin"]');
    }

    public function testLoggingOutOfOneDoesNotLogYouOutOfTheOther(): void
    {
        $user = $this->userWithPassword('admin@example.com', 'secret-pass', admin: true);
        $this->client->loginUser($user);           // app
        $this->client->loginUser($user, 'admin');  // admin

        $this->client->request('GET', '/admin/logout');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful('still logged into the app after admin logout');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');

        $this->client->loginUser($user, 'admin');
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful('still logged into the admin after app logout');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testRegularUsersCannotLogInToAdmin(): void
    {
        $this->userWithPassword('user@example.com', 'secret-pass', admin: false);

        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', ['_username' => 'user@example.com', '_password' => 'secret-pass']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', "This account doesn't have admin access.");
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    public function testAdminsLogInSeparatelyAndCanLogOut(): void
    {
        $this->userWithPassword('admin@example.com', 'secret-pass', admin: true);

        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', ['_username' => 'admin@example.com', '_password' => 'secret-pass']);
        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Overview');

        $this->client->request('GET', '/', server: []);
        self::assertResponseRedirects('/login', message: 'the admin session does not log you into the app');

        $this->client->request('GET', '/admin/logout');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    public function testAppAndAdminDoNotLinkToEachOther(): void
    {
        $admin = $this->user('admin@example.com', admin: true);
        $this->client->loginUser($admin);
        $this->client->loginUser($admin, 'admin');

        $this->client->request('GET', '/');
        self::assertSelectorNotExists('a[href^="/admin"]');

        $this->client->request('GET', '/admin');
        self::assertSelectorNotExists('a[href="/"]');
        self::assertSelectorTextNotContains('body', 'Back to the app');
    }

    public function testOverviewShowsCounts(): void
    {
        $admin = $this->user('admin@example.com', admin: true);
        $this->meal($admin, 'apple');
        $this->meal($admin, 'stuck', estimated: false);
        $this->client->loginUser($admin, 'admin');

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $stats = $crawler->filter('.card-body')->each(fn ($c) => trim(preg_replace('/\s+/', ' ', $c->text())));
        self::assertContains('Users 1', $stats);
        self::assertContains('Meals logged 2', $stats);
        self::assertContains('Meals waiting for the AI 1', $stats);
        self::assertSelectorTextContains('body', 'make worker');
    }

    public function testUsersListAndRoleChange(): void
    {
        $admin = $this->user('admin@example.com', admin: true);
        $member = $this->user('member@example.com');
        $this->client->loginUser($admin, 'admin');

        $this->client->request('GET', '/admin/user');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'member@example.com');

        $this->client->request('GET', '/admin/user/'.$member->getId().'/edit');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Save changes', ['User[roles]' => ['ROLE_ADMIN']]);
        self::assertResponseRedirects();

        $this->em()->clear();
        self::assertContains('ROLE_ADMIN', $this->em()->find(User::class, $member->getId())->getRoles());
    }

    public function testUsersCannotBeCreatedFromAdmin(): void
    {
        $this->client->loginUser($this->user('admin@example.com', admin: true), 'admin');

        $this->client->request('GET', '/admin/user/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteIsNotOfferedForYourOwnAccount(): void
    {
        $admin = $this->user('admin@example.com', admin: true);
        $other = $this->user('other@example.com');
        $this->client->loginUser($admin, 'admin');

        $this->client->request('GET', '/admin/user/'.$admin->getId());
        self::assertSelectorNotExists('.action-delete');

        $this->client->request('GET', '/admin/user/'.$other->getId());
        self::assertSelectorExists('.action-delete');
    }

    public function testAdminCannotDeleteThemselves(): void
    {
        $admin = $this->user('admin@example.com', admin: true);
        $this->client->loginUser($admin, 'admin');
        $this->client->catchExceptions(false);

        $crawler = $this->client->request('GET', '/admin/user');
        $token = $crawler->filter('[name="token"], input[name="token"]')->count()
            ? $crawler->filter('input[name="token"]')->attr('value')
            : static::getContainer()->get('security.csrf.token_manager')->getToken('ea-delete')->getValue();

        $this->expectException(\LogicException::class);
        $this->client->request('POST', '/admin/user/'.$admin->getId().'/delete', ['token' => $token]);
    }

    public function testMealsListAndDetailShowEstimateAndFailure(): void
    {
        $admin = $this->user('admin@example.com', admin: true);
        $meal = $this->meal($this->user('eater@example.com'), '400 g yogurt');
        $failed = $this->meal($admin, 'mystery', estimated: false, error: 'HTTP 503: overloaded');
        $this->client->loginUser($admin, 'admin');

        $this->client->request('GET', '/admin/meal-entry');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', '400 g yogurt');
        self::assertSelectorTextContains('table', 'eater@example.com');

        $this->client->request('GET', '/admin/meal-entry/'.$meal->getId());
        self::assertResponseIsSuccessful();
        $row = $this->client->getCrawler()->filter('table.table-sm tbody tr')->first()->filter('td')->each(fn ($td) => $td->text());
        self::assertSame(['Yogurt', '400', '240', '16', '18', '12', 'plain'], $row);

        $this->client->request('GET', '/admin/meal-entry/'.$failed->getId());
        self::assertSelectorTextContains('body', 'HTTP 503: overloaded');
    }

    public function testMealsAndWeightsCannotBeEditedOrCreated(): void
    {
        $admin = $this->user('admin@example.com', admin: true);
        $meal = $this->meal($admin, 'apple');
        $weight = new WeightEntry($admin, new \DateTimeImmutable('2026-09-01'), 80);
        $this->em()->persist($weight);
        $this->em()->flush();
        $this->client->loginUser($admin, 'admin');

        foreach (['/admin/meal-entry/'.$meal->getId().'/edit', '/admin/meal-entry/new', '/admin/weight-entry/'.$weight->getId().'/edit', '/admin/weight-entry/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->client->request('GET', '/admin/weight-entry');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', '80.0');
    }

    private function userWithPassword(string $email, string $password, bool $admin): User
    {
        $user = (new User())->setEmail($email)->setRoles($admin ? ['ROLE_ADMIN'] : []);
        $user->setPassword(static::getContainer()->get('security.user_password_hasher')->hashPassword($user, $password));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function user(string $email, bool $admin = false): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setRoles($admin ? ['ROLE_ADMIN'] : []);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function meal(User $user, string $text, bool $estimated = true, ?string $error = null): MealEntry
    {
        $entry = new MealEntry($user, $text, new \DateTimeImmutable());
        if ($estimated) {
            $entry->applyEstimate(new MealEstimate([new EstimatedItem('Yogurt', 400, 240, 16, 18, 12, 'plain')], 'test'), new \DateTimeImmutable());
        } elseif ($error) {
            $entry->estimationFailed($error, new \DateTimeImmutable(), giveUp: true);
        }
        $this->em()->persist($entry);
        $this->em()->flush();

        return $entry;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
