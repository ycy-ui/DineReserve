<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\AdminService;
use DineReserve\DomainException;

/**
 * Merchant sign-in: password login issues a stateless session token that the
 * admin API accepts as `Authorization: Bearer …`.
 */
final class AdminSessionTest extends TestCase
{
    private AdminService $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new AdminService($this->pdo, $this->clock);
    }

    private function assertDomainCode(string $code, callable $call): void
    {
        try {
            $call();
        } catch (DomainException $exception) {
            self::assertSame($code, $exception->errorCode(), 'unexpected: ' . $exception->getMessage());

            return;
        }

        self::fail('expected DomainException with code ' . $code);
    }

    public function testLoginIssuesASessionToken(): void
    {
        $session = $this->admin->login('manager@dinereserve.local', 'DemoPass123!');

        self::assertNotSame('', $session['token']);
        self::assertSame(AdminService::SESSION_TTL_HOURS, $session['expires_in_hours']);
        self::assertSame('manager', $session['role']);
        self::assertSame('manager@dinereserve.local', $session['user']['email']);
        self::assertNotNull($session['expires_at']);
    }

    public function testSessionTokenResolvesBackToTheUser(): void
    {
        $session = $this->admin->login('manager@dinereserve.local', 'DemoPass123!');
        $verified = $this->admin->verifySession($session['token']);

        self::assertSame('manager@dinereserve.local', $verified['user']['email']);
        self::assertSame('manager', $verified['role']);
        self::assertTrue($this->admin->can($verified['role'], 'settings.read'));
        self::assertFalse($this->admin->can($verified['role'], 'users.write'));
    }

    public function testTamperedTokenIsRejected(): void
    {
        $session = $this->admin->login('manager@dinereserve.local', 'DemoPass123!');
        $token = $session['token'];
        $tampered = substr($token, 0, -2) . (substr($token, -2) === 'AA' ? 'BB' : 'AA');

        $this->assertDomainCode('SESSION_INVALID', fn () => $this->admin->verifySession($tampered));
        $this->assertDomainCode('SESSION_INVALID', fn () => $this->admin->verifySession('not-a-token'));
        $this->assertDomainCode('SESSION_INVALID', fn () => $this->admin->verifySession(''));
    }

    public function testExpiredSessionIsRejected(): void
    {
        $session = $this->admin->login('manager@dinereserve.local', 'DemoPass123!');
        $this->clock->advance('+' . (AdminService::SESSION_TTL_HOURS + 1) . ' hours');

        $this->assertDomainCode('SESSION_EXPIRED', fn () => $this->admin->verifySession($session['token']));
    }

    public function testDeactivatedUserLosesTheSession(): void
    {
        $session = $this->admin->login('host@dinereserve.local', 'DemoPass123!');
        $userId = (int) $session['user']['id'];
        $this->admin->setActive($userId, false);

        $this->assertDomainCode('SESSION_INVALID', fn () => $this->admin->verifySession($session['token']));
    }

    public function testWrongPasswordDoesNotIssueASession(): void
    {
        $this->assertDomainCode('INVALID_CREDENTIALS', fn () => $this->admin->login('manager@dinereserve.local', 'wrong-password'));
        $this->assertDomainCode('INVALID_CREDENTIALS', fn () => $this->admin->login('missing@example.com', 'DemoPass123!'));
    }

    public function testPrimaryRolePrefersTheStrongestRole(): void
    {
        self::assertSame('administrator', $this->admin->primaryRole(['host', 'administrator']));
        self::assertSame('manager', $this->admin->primaryRole(['manager', 'host']));
        self::assertSame('diner', $this->admin->primaryRole([]));
        self::assertSame('diner', $this->admin->primaryRole(['diner']));
        self::assertSame('viewer', $this->admin->primaryRole(['viewer']));
    }

    public function testSessionIssuingIsAudited(): void
    {
        $this->admin->login('manager@dinereserve.local', 'DemoPass123!');

        self::assertGreaterThan(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'session.issued'")->fetchColumn()
        );
    }
}
