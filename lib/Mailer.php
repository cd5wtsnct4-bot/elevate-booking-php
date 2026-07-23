<?php
declare(strict_types=1);

/**
 * Thin wrapper over PHP's mail(). Most cPanel hosts have a working local
 * sendmail transport out of the box, but delivery isn't guaranteed (SPF/DKIM
 * setup, spam filtering, etc). Callers should always also surface the link
 * itself in the admin UI so it can be copied and sent manually as a fallback.
 */
function sendMail(string $toEmail, string $toName, string $subject, string $bodyHtml): bool
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
    ];

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return @mail(
        sprintf('%s <%s>', $toName, $toEmail),
        $encodedSubject,
        $bodyHtml,
        implode("\r\n", $headers)
    );
}

function sendPasswordSetupEmail(string $toEmail, string $toName, string $setupUrl): bool
{
    $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $body = <<<HTML
    <p>Hi {$safeName},</p>
    <p>An account has been created for you on the Elevate SJC booking portal.
    Click the link below to set your password and log in:</p>
    <p><a href="{$setupUrl}">{$setupUrl}</a></p>
    <p>This link expires in 72 hours. If you didn't expect this, you can ignore this email.</p>
    HTML;

    return sendMail($toEmail, $toName, 'Set up your Elevate SJC booking account', $body);
}
