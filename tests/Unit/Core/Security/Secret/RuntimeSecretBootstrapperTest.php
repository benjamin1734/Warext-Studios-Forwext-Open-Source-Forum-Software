<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Security\Secret;

use Forwext\Core\Security\Secret\RuntimeSecretBootstrapper;
use Forwext\Core\Security\Secret\SecretKey;
use Forwext\Core\Security\Secret\SecretStore;
use PHPUnit\Framework\TestCase;

final class RuntimeSecretBootstrapperTest extends TestCase
{
    public function testItCreatesMissingFingerprintSecretsAndKeepsThemStable(): void
    {
        $store = new class implements SecretStore {
            /** @var array<string,string> */
            public array $values = [];

            public function has(string $name): bool
            {
                return array_key_exists($name, $this->values);
            }

            public function get(string $name): ?string
            {
                return $this->values[$name] ?? null;
            }

            public function set(string $name, string $value): void
            {
                $this->values[$name] = $value;
            }

            public function delete(string $name): bool
            {
                if (!array_key_exists($name, $this->values)) {
                    return false;
                }
                unset($this->values[$name]);

                return true;
            }

            public function all(): array
            {
                return $this->values;
            }
        };

        $key = SecretKey::fromBase64(base64_encode(str_repeat('K', 32)));
        $bootstrapper = new RuntimeSecretBootstrapper($store, $key);
        $bootstrapper->ensure();

        $authentication = $store->get(RuntimeSecretBootstrapper::AUTHENTICATION_FINGERPRINT);
        $registration = $store->get(RuntimeSecretBootstrapper::REGISTRATION_FINGERPRINT);

        self::assertIsString($authentication);
        self::assertIsString($registration);
        self::assertGreaterThanOrEqual(32, strlen($authentication));
        self::assertGreaterThanOrEqual(32, strlen($registration));
        self::assertNotSame($authentication, $registration);

        $bootstrapper->ensure();

        self::assertSame(
            $authentication,
            $store->get(RuntimeSecretBootstrapper::AUTHENTICATION_FINGERPRINT),
        );
        self::assertSame(
            $registration,
            $store->get(RuntimeSecretBootstrapper::REGISTRATION_FINGERPRINT),
        );
    }

    public function testItPreservesExistingSecureSecret(): void
    {
        $store = new class implements SecretStore {
            /** @var array<string,string> */
            public array $values = [
                RuntimeSecretBootstrapper::AUTHENTICATION_FINGERPRINT => 'existing-secure-authentication-fingerprint-secret',
            ];

            public function has(string $name): bool
            {
                return array_key_exists($name, $this->values);
            }

            public function get(string $name): ?string
            {
                return $this->values[$name] ?? null;
            }

            public function set(string $name, string $value): void
            {
                $this->values[$name] = $value;
            }

            public function delete(string $name): bool
            {
                if (!array_key_exists($name, $this->values)) {
                    return false;
                }
                unset($this->values[$name]);

                return true;
            }

            public function all(): array
            {
                return $this->values;
            }
        };

        (new RuntimeSecretBootstrapper(
            $store,
            SecretKey::fromBase64(base64_encode(str_repeat('M', 32))),
        ))->ensure();

        self::assertSame(
            'existing-secure-authentication-fingerprint-secret',
            $store->get(RuntimeSecretBootstrapper::AUTHENTICATION_FINGERPRINT),
        );
        self::assertNotNull($store->get(RuntimeSecretBootstrapper::REGISTRATION_FINGERPRINT));
    }
}
