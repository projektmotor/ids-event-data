<?php

declare(strict_types=1);

namespace ProjektMotor\IdsEventData\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Event\SensorIdentity;
use ProjektMotor\IdsEventData\Vocabulary\Environment;

/**
 * application_id, instance_id und environment sind collectorseitig NOT NULL und die
 * Grundlage jeder Aggregation. Der springende Punkt dieser Klasse ist aber, dass sie
 * NICHT wirft: die Werte stammen aus Umgebungsvariablen, und ein Wurf im Konstruktor
 * verstieße gegen fail-open — ein Sensor, der die Anwendung lahmlegt, ist schlimmer
 * als einer, der schlecht beschriftete Events sendet.
 */
final class SensorIdentityTest extends TestCase
{
    #[DataProvider('gueltigeKennungen')]
    public function testValidIdentifiersAreAccepted(string $id): void
    {
        self::assertTrue(SensorIdentity::isValidId($id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gueltigeKennungen(): iterable
    {
        yield 'schlicht' => ['shop-api'];
        yield 'alle erlaubten Sonderzeichen' => ['a.b_c:d-e'];
        yield 'nur Ziffern' => ['12345'];
        yield 'ein Zeichen' => ['a'];
        yield 'genau MAX_ID_LENGTH' => [str_repeat('a', SensorIdentity::MAX_ID_LENGTH)];
    }

    #[DataProvider('ungueltigeKennungen')]
    public function testInvalidIdentifiersAreRejected(string $id): void
    {
        self::assertFalse(SensorIdentity::isValidId($id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ungueltigeKennungen(): iterable
    {
        yield 'leer' => [''];
        yield 'ein Zeichen über MAX_ID_LENGTH' => [str_repeat('a', SensorIdentity::MAX_ID_LENGTH + 1)];
        yield 'Leerzeichen' => ['shop api'];
        yield 'Schrägstrich' => ['shop/api'];
        yield 'Umlaut' => ['shöp'];
        yield 'Zeilenumbruch in der Mitte' => ["shop\napi"];
    }

    /**
     * Festgehalten, was heute gilt — nicht, was wünschenswert wäre.
     *
     * `$` in PCRE passt ohne den D-Modifikator auch VOR einem abschließenden
     * Zeilenumbruch. Eine application_id, die aus einer Datei oder aus `env` mit
     * Zeilenumbruch am Ende gelesen wurde, kommt damit durch die Prüfung und reist
     * mit dem Umbruch zum Collector.
     *
     * Der Test steht hier, damit die Eigenschaft bekannt ist und nicht in einem
     * Feldbericht auftaucht. Wer sie ändern will, ergänzt den D-Modifikator in
     * SensorIdentity::ID_PATTERN und dreht diese Erwartung um — das ist eine
     * Verschärfung der Prüfung und gehört in den CHANGELOG.
     */
    public function testATrailingNewlineCurrentlyPasses(): void
    {
        self::assertTrue(SensorIdentity::isValidId("shop-api\n"));
    }

    public function testValidateIsSilentForAWellFormedIdentity(): void
    {
        $identity = new SensorIdentity('shop-api', 'web-03', Environment::Prod);

        self::assertSame([], $identity->validate());
    }

    /**
     * Beanstandungen werden zurückgegeben, nicht geworfen — der Aufrufer protokolliert
     * sie beim Bootstrap, und ids:sensor:setup-check macht sie zum Deploy-Zeitpunkt hart.
     */
    public function testValidateReportsInsteadOfThrowing(): void
    {
        $identity = new SensorIdentity('', 'web 03', Environment::Prod);

        $probleme = $identity->validate();

        self::assertCount(2, $probleme);
        self::assertStringContainsString('application_id ist leer', $probleme[0]);
        self::assertStringContainsString('instance_id "web 03"', $probleme[1]);
    }

    public function testValidateNamesOnlyTheOffendingField(): void
    {
        $identity = new SensorIdentity('shop-api', '', Environment::Prod);

        self::assertSame(['instance_id ist leer'], $identity->validate());
    }

    public function testTheIdentityKeepsItsValues(): void
    {
        $identity = new SensorIdentity('shop-api', 'web-03', Environment::Staging);

        self::assertSame('shop-api', $identity->applicationId);
        self::assertSame('web-03', $identity->instanceId);
        self::assertSame(Environment::Staging, $identity->environment);
    }
}
