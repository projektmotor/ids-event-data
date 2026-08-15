<?php

declare(strict_types=1);

namespace ProjektMotor\IdsEventData\Tests\Unit\Vocabulary;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Vocabulary\Environment;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsEventData\Vocabulary\Severity;

/**
 * Die drei geschlossenen Wertelisten, gemeinsam geprüft — sie tragen dieselbe Zusage.
 *
 * Jede spiegelt einen collectorseitigen ENUM-Typ aus Konzept 4.2.1: layer_type,
 * severity_level, env_type. Ein vierter Fall löst dort einen Insert-Fehler aus und
 * verliert damit alle Events dieser Instanz still — von einem toten Sensor nicht
 * unterscheidbar. Deshalb sind die Werte hier namentlich festgeschrieben: ein neuer
 * Fall soll nicht durch einen Test rutschen, sondern eine Datenbankmigration auslösen.
 */
final class VocabularyTest extends TestCase
{
    /**
     * @param list<string> $erwartet
     */
    #[DataProvider('wertelisten')]
    public function testTheVocabularyIsClosed(string $enum, array $erwartet): void
    {
        /** @var list<\BackedEnum> $faelle */
        $faelle = $enum::cases();

        self::assertSame(
            $erwartet,
            array_map(static fn (\BackedEnum $fall): string|int => $fall->value, $faelle),
            \sprintf('%s weicht vom collectorseitigen ENUM ab.', $enum),
        );
    }

    /**
     * @return iterable<string, array{class-string<\BackedEnum>, list<string>}>
     */
    public static function wertelisten(): iterable
    {
        yield 'layer_type' => [Layer::class, ['kernel', 'security', 'business']];
        yield 'severity_level' => [Severity::class, ['info', 'warning', 'critical']];
        yield 'env_type' => [Environment::class, ['prod', 'staging', 'dev']];
    }

    /**
     * Konzept Abschnitt 3: raw wird nur für warning und critical übertragen. Der
     * info-Pfad ist die Masse aller Events und soll nichts für Header-Kopien und
     * Redaktion zahlen.
     */
    #[DataProvider('severityPolitik')]
    public function testTheSeverityPolicies(Severity $severity, bool $carriesRaw, bool $isSampleable): void
    {
        self::assertSame($carriesRaw, $severity->carriesRaw());
        self::assertSame($isSampleable, $severity->isSampleable());
    }

    /**
     * @return iterable<string, array{Severity, bool, bool}>
     */
    public static function severityPolitik(): iterable
    {
        // info: kein raw, aber sampelbar — genau umgekehrt zu den beiden anderen.
        yield 'info' => [Severity::Info, false, true];
        yield 'warning' => [Severity::Warning, true, false];
        yield 'critical' => [Severity::Critical, true, false];
    }

    /**
     * Die beiden Prädikate sind komplementär und nicht bloß zufällig gegenläufig:
     * was raw trägt, ist zu wertvoll zum Wegsampeln. Konzept 4.2.3 — warning und
     * critical werden nie gesampelt.
     */
    public function testCarriesRawAndIsSampleableAreComplementary(): void
    {
        foreach (Severity::cases() as $severity) {
            self::assertNotSame(
                $severity->carriesRaw(),
                $severity->isSampleable(),
                \sprintf('%s trägt raw und ist sampelbar — das widerspricht sich.', $severity->value),
            );
        }
    }

    /**
     * Ein Wert, den der Collector nicht kennt, darf hier nicht entstehen. tryFrom ist
     * der Weg, auf dem Konfiguration ins Vokabular kommt (services.yaml reicht
     * %ids_sensor.environment_fallback% an Environment::from durch).
     */
    public function testUnknownValuesDoNotResolve(): void
    {
        self::assertNull(Environment::tryFrom('test'));
        self::assertNull(Environment::tryFrom('prod_eu'));
        self::assertNull(Layer::tryFrom('heartbeat'));
        self::assertNull(Severity::tryFrom('error'));
    }
}
