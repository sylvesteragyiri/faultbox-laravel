<?php

// A Mailable and a failing transport for the mail monitoring tests.

namespace Faultbox\Laravel\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class OrderShipped extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your order shipped');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>On its way.</p>');
    }
}

/** Rejects every message, like an SMTP server answering 550. */
class RejectingTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        throw new TransportException('Expected response code 250 but got 550');
    }

    public function __toString(): string
    {
        return 'rejecting';
    }
}
