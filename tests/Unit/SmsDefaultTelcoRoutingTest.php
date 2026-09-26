<?php

declare(strict_types=1);

use Core\Factories\CommunicationFactory;
use Infrastructure\SMS\SmsNotificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every phone number has an SMS route. A number on a network whose own
 * gateway is enabled goes straight to it; every other number goes through
 * communication.json's sms_gateway.default_telco. Before, a Mascom or Orange
 * number had no route at all: sign-up could never send it a code, and
 * transactional SMS to it only pretended to send.
 *
 * Nothing here sends anything: building a client makes no request.
 */
final class SmsDefaultTelcoRoutingTest extends TestCase
{
    private const BOTSWANA_COMMUNICATION = __DIR__ . '/../../src/Core/Config/Countries/Botswana/communication.json';

    public static function setUpBeforeClass(): void
    {
        // Building an SMS client starts the KeyVault, which refuses to run
        // without a 32-byte key. No secret is read or encrypted here.
        if (strlen((string)(getenv('APP_ENCRYPTION_KEY') ?: getenv('ENCRYPTION_KEY'))) < 32) {
            putenv('ENCRYPTION_KEY=' . str_repeat('k', 32));
        }
    }

    public static function phoneNumbers(): array
    {
        return [
            'Cazacom 70'          => ['+26770123456'],
            'Mascom 71'           => ['+26771234567'],
            'Mascom 75'           => ['+26775678901'],
            'Orange 76'           => ['+26776123456'],
            'Orange 78'           => ['+26778123456'],
            'local, no dial code' => ['74123456'],
            'South African'       => ['+27821234567'],
        ];
    }

    #[DataProvider('phoneNumbers')]
    public function testSignUpCodesCanBeSentToEveryNumber(string $phone): void
    {
        $client = CommunicationFactory::createForPhone('sms', $phone);

        $this->assertSame('Cazacom', $client->getProviderName());
    }

    #[DataProvider('phoneNumbers')]
    public function testTransactionalSmsCanBeSentToEveryNumber(string $phone): void
    {
        $service = $this->smsService($this->botswanaConfig());

        $this->assertSame('cazacom', $this->resolveTelco($service, $phone));
    }

    public function testANetworkWithItsOwnGatewayEnabledIsNotSentThroughTheDefault(): void
    {
        $config = $this->botswanaConfig();
        $config['telcos']['mascom']['enabled'] = true;
        $config['telcos']['mascom']['sms_enabled'] = true;
        $service = $this->smsService($config);

        $this->assertSame('mascom', $this->resolveTelco($service, '+26771234567'));
        $this->assertSame('cazacom', $this->resolveTelco($service, '+26776123456'), 'Orange is still off');
    }

    public function testWithoutADefaultAnUnclaimedNumberStillHasNoRoute(): void
    {
        $config = $this->botswanaConfig();
        unset($config['sms_gateway']['default_telco']);
        $service = $this->smsService($config);

        $this->assertNull($this->resolveTelco($service, '+26771234567'));
        $this->assertSame('cazacom', $this->resolveTelco($service, '+26770123456'));
    }

    public function testTheDefaultMustBeATelcoWithSmsSwitchedOn(): void
    {
        $telcos = ['cazacom' => ['enabled' => true, 'sms_enabled' => true, 'prefixes' => ['70']]];
        $withDefault = fn (string $name, array $telcos): array => [
            'sms_gateway' => ['default_telco' => $name],
            'telcos' => $telcos,
        ];

        $this->assertSame('cazacom', CommunicationFactory::defaultSmsTelco($withDefault('cazacom', $telcos)));
        $this->assertNull(CommunicationFactory::defaultSmsTelco(['sms_gateway' => [], 'telcos' => $telcos]), 'not configured');
        $this->assertNull(CommunicationFactory::defaultSmsTelco($withDefault('btc', $telcos)), 'no such telco');

        foreach (['enabled', 'sms_enabled'] as $switch) {
            $off = $telcos;
            $off['cazacom'][$switch] = false;
            $this->assertNull(CommunicationFactory::defaultSmsTelco($withDefault('cazacom', $off)), "{$switch} = false");
            $this->assertNull($this->resolveTelco($this->smsService($withDefault('cazacom', $off)), '+26771234567'), "{$switch} = false");
        }
    }

    private function botswanaConfig(): array
    {
        return json_decode((string)file_get_contents(self::BOTSWANA_COMMUNICATION), true, 512, JSON_THROW_ON_ERROR);
    }

    private function smsService(array $config): SmsNotificationService
    {
        // The constructor creates sms_logs with Postgres-only SQL. Routing
        // never touches the database, so skip that rather than log an
        // expected failure for every service built here.
        $db = new class ('sqlite::memory:') extends PDO {
            public function exec(string $statement): int|false
            {
                return 0;
            }
        };

        return new SmsNotificationService($db, $config);
    }

    private function resolveTelco(SmsNotificationService $service, string $phone): ?string
    {
        $match = (new ReflectionMethod($service, 'resolveTelcoForPhone'))->invoke($service, $phone);

        return $match[0] ?? null;
    }
}
