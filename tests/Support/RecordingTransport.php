<?php

namespace Tests\Support;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Test-only mail transport sitting where SMTP would — records the real
 * envelope recipients and headers of every message the mailer hands it, and
 * rejects (like an SMTP 5xx) any message addressed to one of `$rejecting`.
 * Lets tests assert what actually reached the transport, not merely what
 * was passed to the `Mail` facade.
 */
class RecordingTransport extends AbstractTransport
{
    /** @var array<int, array{envelope: array<int, string>, to: array<int, string>, cc: array<int, string>, bcc: array<int, string>, subject: ?string, html: ?string}> */
    public static array $messages = [];

    /** @var array<int, string> */
    public static array $rejecting = [];

    public static bool $down = false;

    public static function reset(): void
    {
        static::$messages = [];
        static::$rejecting = [];
        static::$down = false;
    }

    protected function doSend(SentMessage $message): void
    {
        $envelope = array_map(fn (Address $a) => strtolower($a->getAddress()), $message->getEnvelope()->getRecipients());

        if (static::$down) {
            throw new TransportException('Connection could not be established with host "smtp.test".');
        }

        foreach ($envelope as $address) {
            if (in_array($address, static::$rejecting, true)) {
                throw new TransportException("Expected response code \"250\" but got code \"550\", with message \"550 5.1.1 <{$address}>: Recipient address rejected\".");
            }
        }

        $original = $message->getOriginalMessage();
        $addresses = fn (array $list) => array_map(fn (Address $a) => strtolower($a->getAddress()), $list);

        static::$messages[] = [
            'envelope' => $envelope,
            'to' => $original instanceof Email ? $addresses($original->getTo()) : [],
            'cc' => $original instanceof Email ? $addresses($original->getCc()) : [],
            'bcc' => $original instanceof Email ? $addresses($original->getBcc()) : [],
            'subject' => $original instanceof Email ? $original->getSubject() : null,
            'html' => $original instanceof Email ? $original->getHtmlBody() : null,
        ];
    }

    public function __toString(): string
    {
        return 'recording://';
    }

    /** @return array<int, string> every primary To address, in send order */
    public static function toAddresses(): array
    {
        return array_merge(...array_map(fn ($m) => $m['to'], static::$messages ?: [['to' => []]]));
    }
}
