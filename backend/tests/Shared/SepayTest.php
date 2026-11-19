<?php

namespace Tests\Shared;

use App\Shared\Exceptions\BusinessException;
use App\Shared\Support\Sepay;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SepayTest extends TestCase
{
    #[DataProvider('headers')]
    public function test_header_resolution_matches_all_three_java_webhooks(?string $header, ?string $authorization, ?string $expected): void
    {
        $this->assertSame($expected, Sepay::resolveSecret($header, $authorization));
    }

    public static function headers(): iterable
    {
        yield [null, null, null];
        yield ['', null, ''];
        yield ['secret', 'Apikey ignored', 'secret'];
        yield [' secret ', 'Apikey ignored', ' secret '];
        yield [null, 'Apikey secret ', 'secret'];
        yield [' ', ' secret ', 'secret'];
        yield [null, 'apikey secret', 'apikey secret'];
        yield [null, 'Bearer secret', 'Bearer secret'];
        yield [null, ' Apikey secret ', 'Apikey secret'];
        yield [null, 'Apikey ', ''];
        yield ["\u{2003}", 'Apikey secret', 'secret'];
        yield ["\u{00a0}", 'Apikey secret', "\u{00a0}"];
    }

    #[DataProvider('secrets')]
    public function test_secret_comparison_is_exact(?string $configured, ?string $actual, ?string $error): void
    {
        config(['sepay.webhook-secret' => $configured]);
        if ($error !== null) {
            $this->expectException(BusinessException::class);
            $this->expectExceptionMessage($error);
        }
        Sepay::validateSepaySecret($actual);
        $this->addToAssertionCount(1);
    }

    public static function secrets(): iterable
    {
        yield [null, null, 'SePay webhook secret is not configured'];
        yield ["\u{2003}", 'secret', 'SePay webhook secret is not configured'];
        yield ['secret', null, 'Invalid SePay webhook secret'];
        yield ['secret', 'SECRET', 'Invalid SePay webhook secret'];
        yield ['secret', ' secret ', 'Invalid SePay webhook secret'];
        yield ['secret', 'secret', null];
        yield [' secret ', ' secret ', null];
    }

    #[DataProvider('transfers')]
    public function test_transfer_checks_preserve_java_order_and_messages(?array $request, ?string $account, string $context, ?string $error): void
    {
        config(['sepay.account-number' => $account]);
        if ($error !== null) {
            $this->expectException(BusinessException::class);
            $this->expectExceptionMessage($error);
        }
        Sepay::validateIncomingTransfer($request, $context);
        $this->addToAssertionCount(1);
    }

    public static function transfers(): iterable
    {
        yield [null, null, 'appointments', 'SePay webhook payload must not be null'];
        yield [[], '123', 'appointments', 'Only incoming transfers can confirm appointments'];
        yield [['transferType' => 'out'], '123', 'payments', 'Only incoming transfers can confirm payments'];
        yield [['transferType' => ' in'], null, 'appointments', 'Only incoming transfers can confirm appointments'];
        yield [['transferType' => 'in'], '123', 'appointments', 'Webhook account number does not match configured SePay account'];
        yield [['transferType' => 'in', 'accountNumber' => '123 '], '123', 'payments', 'Webhook account number does not match configured SePay account'];
        yield [['transferType' => 'in', 'transferAmount' => 0], null, 'appointments', 'Webhook transfer amount must be greater than zero'];
        yield [['transferType' => 'in', 'transferAmount' => -1], '', 'payments', 'Webhook transfer amount must be greater than zero'];
        yield [['transferType' => 'IN', 'transferAmount' => 1], null, 'appointments', null];
        yield [['transferType' => 'in', 'transferAmount' => PHP_INT_MAX, 'accountNumber' => '123'], '123', 'payments', null];
    }
}
