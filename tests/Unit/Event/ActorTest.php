<?php

declare(strict_types=1);

namespace ProjektMotor\IdsEventData\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Event\Actor;
use ProjektMotor\IdsEventData\Event\EventSchema;

final class ActorTest extends TestCase
{
    public function testAnonymousHasNothingButNullValues(): void
    {
        $actor = Actor::anonymous();

        self::assertNull($actor->user);
        self::assertNull($actor->ip);
        self::assertNull($actor->sessionIdHash);
        self::assertNull($actor->clientFingerprint);
    }

    /**
     * Die versuchte Benutzerkennung bei fehlgeschlagener Anmeldung ist
     * angreifergesteuert — Symfonys UserBadge erlaubt bis zu 4096 Zeichen. Ohne
     * Begrenzung könnte ein Angreifer jedes Fehlversuch-Event um 4 KB aufblähen,
     * und gerade diese Events treten bei Brute-Force massenhaft auf.
     */
    public function testUserIdentifierIsTruncated(): void
    {
        $actor = (new Actor())->withUser(str_repeat('a', 4096));

        self::assertNotNull($actor->user);
        self::assertSame(Actor::MAX_USER_LENGTH, mb_strlen($actor->user));
    }

    /**
     * Die Grenze hängt am Konstruktor, nicht an withUser(): sonst ginge jeder
     * Konsument, der `new Actor($kennung)` schreibt, an ihr vorbei — und der
     * angreifergesteuerte Wert stünde ungekürzt auf dem Draht.
     */
    public function testTheConstructorTruncatesAsWell(): void
    {
        $actor = new Actor(str_repeat('a', 4096));

        self::assertNotNull($actor->user);
        self::assertSame(Actor::MAX_USER_LENGTH, mb_strlen($actor->user));
    }

    /**
     * @param callable(string): Actor $baue
     */
    #[DataProvider('wegeZurBenutzerkennung')]
    public function testTruncationIsMultibyteSafe(callable $baue): void
    {
        $actor = $baue(str_repeat('ä', 500));

        self::assertNotNull($actor->user);
        self::assertSame(Actor::MAX_USER_LENGTH, mb_strlen($actor->user));
        // Kein zerschnittenes Zeichen am Ende.
        self::assertSame($actor->user, mb_convert_encoding($actor->user, 'UTF-8', 'UTF-8'));
    }

    /**
     * Beide Wege in einen Actor hinein müssen dieselbe Grenze durchsetzen.
     *
     * @return iterable<string, array{callable(string): Actor}>
     */
    public static function wegeZurBenutzerkennung(): iterable
    {
        yield 'Konstruktor' => [static fn (string $user): Actor => new Actor($user)];
        yield 'withUser()' => [static fn (string $user): Actor => (new Actor())->withUser($user)];
    }

    public function testAShortIdentifierPassesThroughUnchanged(): void
    {
        self::assertSame('alice', (new Actor('alice'))->user);
    }

    public function testWithUserPreservesTheRemainingFields(): void
    {
        $actor = new Actor(null, '203.0.113.42', 'hash', 'fingerprint');

        $withUser = $actor->withUser('alice');

        self::assertSame('alice', $withUser->user);
        self::assertSame('203.0.113.42', $withUser->ip);
        self::assertSame('hash', $withUser->sessionIdHash);
        self::assertSame('fingerprint', $withUser->clientFingerprint);
        self::assertNull($actor->user, 'Das Original bleibt unverändert');
    }

    public function testToArrayUsesTheSchemaFieldNames(): void
    {
        $actor = new Actor('alice', '203.0.113.42', 'hash', 'fingerprint');

        self::assertSame([
            EventSchema::ACTOR_USER => 'alice',
            EventSchema::ACTOR_IP => '203.0.113.42',
            EventSchema::ACTOR_SESSION_ID_HASH => 'hash',
            EventSchema::ACTOR_CLIENT_FINGERPRINT => 'fingerprint',
        ], $actor->toArray());
    }
}
