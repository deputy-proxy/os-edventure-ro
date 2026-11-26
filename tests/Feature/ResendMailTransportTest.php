<?php

namespace Tests\Feature;

use Illuminate\Mail\Transport\ResendTransport;
use Tests\TestCase;

class ResendMailTransportTest extends TestCase
{
    public function test_resend_mail_transport_can_be_resolved_with_the_installed_sdk(): void
    {
        config(['services.resend.key' => 're_test_transport_resolution']);

        $transport = app('mail.manager')
            ->mailer('resend')
            ->getSymfonyTransport();

        $this->assertInstanceOf(ResendTransport::class, $transport);
    }
}
