<?php

namespace App\Account;

use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/** Account emails: confirm your address, reset your password. Sent through Messenger (the worker). */
final class AccountMailer
{
    /** Minimum time between two confirmation emails for the same user. */
    public const RESEND_INTERVAL = '1 minute';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function sendEmailConfirmation(User $user): void
    {
        $signature = $this->verifyEmailHelper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),
            $user->getEmail(),
            ['id' => $user->getId()],
        );

        $this->mailer->send((new TemplatedEmail())
            ->to(new Address($user->getEmail()))
            ->subject('Confirm your email address')
            ->htmlTemplate('emails/confirm_email.html.twig')
            ->context(['signedUrl' => $signature->getSignedUrl()]));

        $user->setVerificationSentAt($this->clock->now());
    }

    public function canResendConfirmation(User $user): bool
    {
        $sentAt = $user->getVerificationSentAt();

        return null === $sentAt || $this->clock->now() >= $sentAt->modify('+'.self::RESEND_INTERVAL);
    }

    public function sendPasswordReset(User $user, ResetPasswordToken $token): void
    {
        $this->mailer->send((new TemplatedEmail())
            ->to(new Address($user->getEmail()))
            ->subject('Reset your password')
            ->htmlTemplate('emails/reset_password.html.twig')
            // Built here, not in the template: the template is rendered by the worker, which has no request to take the host from.
            ->context(['resetUrl' => $this->urls->generate('app_reset_password', ['token' => $token->getToken()], UrlGeneratorInterface::ABSOLUTE_URL)]));
    }
}
