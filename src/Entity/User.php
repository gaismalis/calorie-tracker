<?php

namespace App\Entity;

use App\Profile\ActivityLevel;
use App\Profile\Sex;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const DEFAULT_TIMEZONE = 'Europe/Riga';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 64, options: ['default' => self::DEFAULT_TIMEZONE])]
    #[Assert\NotBlank]
    #[Assert\Timezone]
    private string $timezone = self::DEFAULT_TIMEZONE;

    #[ORM\Column(nullable: true, enumType: Sex::class)]
    private ?Sex $sex = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Assert\LessThan('-13 years', message: 'You must be at least 13 years old.')]
    #[Assert\GreaterThan('-120 years', message: 'Please enter a real birth date.')]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 100, max: 250, notInRangeMessage: 'Height must be between {{ min }} and {{ max }} cm.')]
    private ?float $heightCm = null;

    #[ORM\Column(nullable: true, enumType: ActivityLevel::class)]
    private ?ActivityLevel $activityLevel = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getDateTimeZone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone);
    }

    /** Midnight today in the user's timezone. */
    public function today(?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        return ($now ?? new \DateTimeImmutable())->setTimezone($this->getDateTimeZone())->setTime(0, 0);
    }

    public function getSex(): ?Sex
    {
        return $this->sex;
    }

    public function setSex(?Sex $sex): static
    {
        $this->sex = $sex;

        return $this;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    public function getAge(?\DateTimeImmutable $on = null): ?int
    {
        return $this->birthDate?->diff($on ?? $this->today())->y;
    }

    public function getHeightCm(): ?float
    {
        return $this->heightCm;
    }

    public function setHeightCm(?float $heightCm): static
    {
        $this->heightCm = $heightCm;

        return $this;
    }

    public function getActivityLevel(): ?ActivityLevel
    {
        return $this->activityLevel;
    }

    public function setActivityLevel(?ActivityLevel $activityLevel): static
    {
        $this->activityLevel = $activityLevel;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }
}
