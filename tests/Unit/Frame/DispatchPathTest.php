<?php

declare(strict_types=1);

namespace ProjektMotor\IdsEventData\Tests\Unit\Frame;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Frame\DispatchPath;

/**
 * Die drei Werte wertet der Collector aus; die Tabelle in Konzept 3.3.1 schreibt ihm je
 * Zustand ein anderes Verhalten vor. Ein vierter Fall oder ein geänderter Backing-Wert
 * ist damit eine Absprache mit der Gegenseite und kein Refactoring.
 */
final class DispatchPathTest extends TestCase
{
    public function testTheVocabularyIsClosedAndStable(): void
    {
        self::assertSame(
            ['direct', 'deferred', 'recovered'],
            array_map(static fn (DispatchPath $p): string => $p->value, DispatchPath::cases()),
        );
    }

    /**
     * Genau der Grund, warum das kein binäres „late"-Flag ist: unter mod_php läuft
     * JEDER Frame planmäßig über den Spool. Wäre Deferred nicht echtzeitfähig, wäre
     * die Erkennung dort dauerhaft abgeschaltet.
     */
    #[DataProvider('realtimeProvider')]
    public function testOnlyRecoveredIsExcludedFromRealtime(DispatchPath $path, bool $expected): void
    {
        self::assertSame($expected, $path->isEligibleForRealtime());
    }

    /**
     * @return iterable<string, array{DispatchPath, bool}>
     */
    public static function realtimeProvider(): iterable
    {
        yield 'direct ist echtzeitfähig' => [DispatchPath::Direct, true];
        yield 'deferred bleibt echtzeitfähig' => [DispatchPath::Deferred, true];
        yield 'recovered nicht mehr' => [DispatchPath::Recovered, false];
    }
}
