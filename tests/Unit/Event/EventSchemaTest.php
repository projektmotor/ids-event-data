<?php

declare(strict_types=1);

namespace ProjektMotor\IdsEventData\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Event\EventSchema;

/**
 * EventSchema ist eine Konstantensammlung — daran gibt es wenig zu testen und genau
 * eine Sache, die sich still verschieben kann: die Beziehung zwischen den einzelnen
 * FIELD_*-Konstanten und den Listen MANDATORY_FIELDS und ACTOR_FIELDS.
 *
 * Wer ein Feld hinzufügt, schreibt eine Konstante und vergisst die Liste; wer eines
 * entfernt, löscht die Konstante und lässt den Eintrag stehen. Beides bemerkt sonst
 * erst der Collector, und zwar an fehlenden Pflichtfeldern in echten Events.
 */
final class EventSchemaTest extends TestCase
{
    public function testTheSchemaVersionIsOne(): void
    {
        self::assertSame(1, EventSchema::SCHEMA_VERSION);
    }

    /**
     * Die Pflichtfelder aus Konzept Abschnitt 3, namentlich. Bewusst als Literale und
     * nicht über die Konstanten: sonst prüfte der Test nur, dass eine Liste mit sich
     * selbst übereinstimmt. Diese zwölf Zeichenketten stehen im Vertrag mit dem
     * Collector — eine Umbenennung ist ein schema_version-Bump.
     */
    public function testTheMandatoryFieldsAreTheContract(): void
    {
        self::assertSame([
            'schema_version',
            'event_id',
            'timestamp',
            'layer',
            'event_type',
            'correlation_id',
            'event_severity',
            'application_id',
            'instance_id',
            'environment',
            'actor',
            'payload',
        ], EventSchema::MANDATORY_FIELDS);
    }

    public function testTheActorFieldsAreTheContract(): void
    {
        self::assertSame(
            ['user', 'ip', 'session_id_hash', 'client_fingerprint'],
            EventSchema::ACTOR_FIELDS,
        );
    }

    /**
     * Jede FIELD_*-Konstante gehört entweder in MANDATORY_FIELDS oder ist eines der
     * beiden dokumentiert optionalen Felder. Eine neue Konstante, die in keiner der
     * beiden Kategorien landet, ist ein vergessener Listeneintrag.
     */
    public function testEveryFieldConstantIsAccountedFor(): void
    {
        $optional = [EventSchema::FIELD_RAW, EventSchema::FIELD_SAMPLING_RATE];

        foreach ($this->konstanten('FIELD_') as $name => $wert) {
            self::assertTrue(
                \in_array($wert, EventSchema::MANDATORY_FIELDS, true)
                || \in_array($wert, $optional, true),
                \sprintf(
                    '%s ("%s") steht weder in MANDATORY_FIELDS noch unter den optionalen '
                    .'Feldern. Entweder fehlt der Listeneintrag, oder das Feld ist neu '
                    .'optional und gehört hier eingetragen.',
                    $name,
                    $wert,
                ),
            );
        }
    }

    public function testEveryActorConstantIsListed(): void
    {
        foreach ($this->konstanten('ACTOR_') as $name => $wert) {
            self::assertContains(
                $wert,
                EventSchema::ACTOR_FIELDS,
                \sprintf('%s ("%s") fehlt in ACTOR_FIELDS.', $name, $wert),
            );
        }
    }

    /**
     * Die Gegenrichtung: kein Eintrag ohne Konstante. Sonst überlebt ein Feldname eine
     * Umbenennung in der Liste, obwohl ihn niemand mehr schreibt.
     */
    public function testEveryListedFieldHasAConstant(): void
    {
        $werte = array_values($this->konstanten('FIELD_'));

        foreach (EventSchema::MANDATORY_FIELDS as $feld) {
            self::assertContains($feld, $werte, \sprintf('"%s" hat keine FIELD_*-Konstante.', $feld));
        }

        $actorWerte = array_values($this->konstanten('ACTOR_'));

        foreach (EventSchema::ACTOR_FIELDS as $feld) {
            self::assertContains($feld, $actorWerte, \sprintf('"%s" hat keine ACTOR_*-Konstante.', $feld));
        }
    }

    /**
     * UTC, Millisekunden, literales Z. Das Konzept zeigt in Abschnitt 3 nur ein
     * Beispiel; verbindlich ist es, weil die Uhrendrift-Messung des Collectors ein
     * stabiles Format braucht.
     */
    public function testTheTimestampFormatIsUtcWithMilliseconds(): void
    {
        $zeitpunkt = new \DateTimeImmutable('2026-08-14T10:15:32.421000+00:00');

        self::assertSame(
            '2026-08-14T10:15:32.421Z',
            $zeitpunkt->format(EventSchema::TIMESTAMP_FORMAT),
        );
    }

    /**
     * Das Z ist literal und keine Zeitzonenangabe — ein Zeitpunkt in einer anderen
     * Zone würde ohne vorherige Umrechnung falsch beschriftet. Der Test hält fest,
     * dass die Umrechnung Aufgabe des Aufrufers ist.
     */
    public function testTheTrailingZIsLiteral(): void
    {
        $zeitpunkt = new \DateTimeImmutable('2026-08-14T12:15:32.421000+02:00');

        self::assertSame(
            '2026-08-14T10:15:32.421Z',
            $zeitpunkt->setTimezone(new \DateTimeZone('UTC'))->format(EventSchema::TIMESTAMP_FORMAT),
        );
    }

    public function testTheSchemaIsNotInstantiable(): void
    {
        $konstruktor = (new \ReflectionClass(EventSchema::class))->getConstructor();

        self::assertNotNull($konstruktor);
        self::assertTrue($konstruktor->isPrivate());
    }

    /**
     * @return array<string, string> Konstantenname => Wert, nur die string-wertigen
     */
    private function konstanten(string $praefix): array
    {
        $ergebnis = [];

        foreach ((new \ReflectionClass(EventSchema::class))->getConstants() as $name => $wert) {
            if (str_starts_with($name, $praefix) && \is_string($wert)) {
                $ergebnis[$name] = $wert;
            }
        }

        self::assertNotEmpty($ergebnis, \sprintf('Keine Konstante mit Präfix %s gefunden.', $praefix));

        return $ergebnis;
    }
}
