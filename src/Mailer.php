<?php

declare(strict_types=1);

namespace DineReserve;

/**
 * Delivery port for outbound notifications (REQ-1.4).
 *
 * The production build binds this to an SMTP/transactional-mail adapter; the
 * repository ships FileMailer so the whole pipeline stays runnable without
 * credentials, and the test suite binds an in-memory double.
 */
interface Mailer
{
    /**
     * Deliver one message. Throw on failure: NotificationService turns the
     * exception into a retry (1 / 5 / 15 min) and finally a FAILED row.
     *
     * @throws \RuntimeException
     */
    public function send(string $to, string $subject, string $body): void;
}
