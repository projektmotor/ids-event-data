<?php

declare(strict_types=1);

namespace ProjektMotor\IdsEventData\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Hält fest, was dieses Paket zu einem Paket macht.
 *
 * WOZU
 *
 * Der Wert dieses Pakets liegt nicht im Code — es sind elf Klassen ohne Logik. Er
 * liegt darin, dass zwei Bundles es gemeinsam benutzen können, ohne sich gegenseitig
 * mitzuschleppen: der Sensor schreibt das Format, das Backend liest es. Diese Zusage
 * hängt an einer einzigen Eigenschaft — das Paket importiert nichts. Ein einziger
 * `use Symfony\...` macht aus einer Abhängigkeit auf drei Enums eine auf das halbe
 * Framework, und niemandem fiele es im Review auf.
 *
 * Der Test stammt aus dem Sensor-Bundle, wo er die Auslösbarkeit dieses Verzeichnisses
 * bewachte, solange es noch dort lag. Er wandert mit: dort hat er sein Objekt verloren,
 * hier hat er es.
 *
 * Bewusst über Dateiinhalte statt über Reflection: der Test soll auch dann etwas
 * sagen, wenn eine Klasse gar nicht ladbar ist.
 */
final class ArchitectureTest extends TestCase
{
    private const SRC = __DIR__.'/../../src';

    /**
     * Wer darf wen kennen — als Zahl statt als Absicht.
     *
     * Die Ränge halten die Verschachtelung des Drahtformats fest, von außen nach
     * innen gelesen: Vocabulary/ und Payload/ importieren nichts, Event/ liest aus
     * Vocabulary/, Frame/ liest aus Event/.
     *
     * Vier Einträge und kein Sammel-Eintrag für src/ selbst: eine Datei, die jemand
     * direkt unter src/ ablegt, lässt den Test fehlschlagen, bis sie eingeordnet ist.
     * Das ist die zweite, wichtigere Aufgabe dieser Tabelle — ohne sie wüchse das
     * Paket still um eine Gruppe, die niemand eingeordnet hat.
     *
     * @var array<string, int>
     */
    private const RANGFOLGE = [
        'Vocabulary' => 0,
        'Payload' => 0,
        'Event' => 1,
        'Frame' => 2,
    ];

    /**
     * Das Paket muss ohne alles andere übersetzbar bleiben.
     *
     * Kein Symfony, kein PSR, kein anderes ProjektMotor-Paket: das Format ist reines
     * PHP und soll es bleiben, damit ein Consumer es ohne Framework lesen kann.
     *
     * WARUM NICHT „gar keine Importe"
     *
     * Die vier Untergruppen greifen übereinander, und das muss erlaubt sein: ein Paket
     * darf sich selbst importieren. Geprüft wird deshalb die Herkunft jedes Imports,
     * nicht seine Existenz.
     *
     * WARUM AUCH DOCBLOCKS
     *
     * Ein {@see \ProjektMotor\IdsSensor\Sensor\CapturedEvent} erzeugt keine
     * Abhängigkeit für den Übersetzer, wohl aber einen toten Verweis über die
     * Paketgrenze hinweg. Genau so einer stand vor der Ausgliederung in
     * NormalizedEvent. Geprüft wird deshalb nicht die use-Zeile, sondern jede Nennung
     * eines fremden Wurzel-Namensraums.
     */
    public function testImportsNothingForeign(): void
    {
        foreach (self::quelldateien() as $relativ => $inhalt) {
            preg_match_all('/^use\s+([^;]+);$/m', $inhalt, $treffer);

            foreach ($treffer[1] as $import) {
                self::assertStringStartsWith(
                    'ProjektMotor\\IdsEventData\\',
                    $import,
                    \sprintf(
                        '%s importiert %s. Das Paket muss autark bleiben — sonst zieht '
                        .'jeder Konsument mit drei Enums ein ganzes Framework mit. '
                        .'Erlaubt sind ausschließlich Importe aus dem Paket selbst.',
                        $relativ,
                        $import,
                    ),
                );
            }

            preg_match_all('/\b(Symfony|Psr|Doctrine|Monolog)\\\\/', $inhalt, $fremde);

            self::assertSame([], $fremde[1], \sprintf(
                '%s nennt einen Fremd-Namensraum. Auch in einem Docblock ist das ein '
                .'Verweis, der beim Consumer ohne dieses Paket ins Leere zeigt — '
                .'als Prosa schreiben statt als {@see}.',
                $relativ,
            ));

            preg_match_all('/ProjektMotor\\\\([A-Za-z]+)/', $inhalt, $nennungen);

            foreach ($nennungen[1] as $paket) {
                self::assertSame('IdsEventData', $paket, \sprintf(
                    '%s nennt ProjektMotor\\%s — das ist ein Verweis über die '
                    .'Paketgrenze hinweg. Als Prosa schreiben statt als {@see}.',
                    $relativ,
                    $paket,
                ));
            }
        }

        self::assertNotEmpty(self::quelldateien());
    }

    /**
     * Das ganze Paket ist öffentliche Fläche.
     *
     * Im Sensor-Bundle war das die Ausnahme und musste gegen @internal abgegrenzt
     * werden; hier ist es die Regel. Eine Datei mit @internal wäre ein Widerspruch zum
     * Zweck des Pakets: es gibt nichts hier, das nicht Vertrag wäre.
     */
    public function testNothingIsInternal(): void
    {
        foreach (self::quelldateien() as $relativ => $inhalt) {
            self::assertStringNotContainsString('@internal', $inhalt, \sprintf(
                '%s trägt @internal. Dieses Paket IST der öffentliche Vertrag — was '
                .'nicht öffentlich sein soll, gehört nicht hierher.',
                $relativ,
            ));
        }
    }

    /**
     * Die Namensräume bilden eine Schichtung, keine Wolke.
     *
     * Ein einziger Import in die Gegenrichtung bricht nichts und fällt in keinem
     * Review auf; erst der zweite schließt den Kreis, und dann ist die Reparatur
     * teuer.
     */
    public function testGroupsFormALayering(): void
    {
        foreach (self::quelldateien() as $relativ => $inhalt) {
            $eigenerRang = self::rang($relativ);
            self::assertNotNull($eigenerRang, self::fehlenderRang($relativ));

            foreach (self::eigeneImporte($inhalt) as $ziel) {
                $zielRang = self::rang($ziel);
                self::assertNotNull($zielRang, self::fehlenderRang($ziel));

                self::assertLessThanOrEqual($eigenerRang, $zielRang, \sprintf(
                    '%s (Rang %d) importiert aus %s (Rang %d). Abhängigkeiten zeigen nach '
                    .'unten, nie zurück — sonst ist der nächste Import ein Zyklus.',
                    $relativ,
                    $eigenerRang,
                    $ziel,
                    $zielRang,
                ));
            }
        }
    }

    /**
     * Jeder {@see}-Verweis auf eine Klasse muss auflösbar sein.
     *
     * Die Docblocks sind hier Primärdokumentation — die Begründungsessays verweisen
     * aufeinander. Weder PHPStan noch php-cs-fixer prüfen diese Verweise; nach einer
     * Umsortierung zeigen sie lautlos ins Leere.
     *
     * Geprüft werden beide Schreibweisen. Die kurze ist die häufigere — im Bestand
     * steht {@see KernelPayload} und kein einziger voll qualifizierter Name — und
     * gerade sie ist die anfälligere: sie hängt am Namensraum der verweisenden Datei,
     * bricht also schon beim Verschieben einer Datei, ohne dass jemand den Docblock
     * angefasst hätte. Ein Test, der nur FQCN prüft, sähe hier gar nichts.
     *
     * Verweise auf Methoden der eigenen Klasse ({@see toArray()}, {@see self::x()})
     * bleiben außen vor: sie beginnen klein bzw. mit self und tragen kein Ziel, das
     * über die Datei hinausreicht.
     */
    public function testDocblockReferencesDoNotDangle(): void
    {
        $geprueft = 0;

        foreach (self::quelldateien() as $relativ => $inhalt) {
            $eigenerNamensraum = self::namensraum($inhalt);

            preg_match_all('/\{@see\s+(\\\\?[A-Z][A-Za-z0-9_\\\\]*)/', $inhalt, $treffer);

            foreach ($treffer[1] as $verweis) {
                $fqcn = self::aufloesen(ltrim($verweis, '\\'), $eigenerNamensraum, $inhalt);

                self::assertStringStartsWith('ProjektMotor\\IdsEventData\\', $fqcn, \sprintf(
                    '%s verweist mit {@see %s} aus dem Paket heraus.',
                    $relativ,
                    $verweis,
                ));

                $pfad = self::SRC.'/'.str_replace('\\', '/', substr($fqcn, \strlen('ProjektMotor\\IdsEventData\\'))).'.php';

                self::assertFileExists($pfad, \sprintf(
                    '%s verweist auf %s (aufgelöst zu %s) — die Klasse gibt es nicht.',
                    $relativ,
                    $verweis,
                    $fqcn,
                ));

                ++$geprueft;
            }
        }

        // Ohne das wäre der Test nach einer Umformulierung der Docblocks still leer —
        // er meldete dann Erfolg, weil er nichts mehr findet.
        self::assertGreaterThan(0, $geprueft, 'Kein einziger {@see}-Verweis gefunden.');
    }

    /**
     * Löst einen Docblock-Verweis so auf, wie PHP einen Klassennamen auflösen würde:
     * erst über die use-Zeilen, sonst relativ zum eigenen Namensraum.
     */
    private static function aufloesen(string $verweis, string $namensraum, string $inhalt): string
    {
        if (str_contains($verweis, '\\') && str_starts_with($verweis, 'ProjektMotor\\')) {
            return $verweis;
        }

        $ersterTeil = explode('\\', $verweis)[0];

        preg_match_all('/^use\s+([^;]+);$/m', $inhalt, $importe);

        foreach ($importe[1] as $import) {
            $kurz = substr(strrchr($import, '\\') ?: '\\'.$import, 1);

            if ($kurz === $ersterTeil) {
                return $import.substr($verweis, \strlen($ersterTeil));
            }
        }

        return $namensraum.'\\'.$verweis;
    }

    private static function namensraum(string $inhalt): string
    {
        return 1 === preg_match('/^namespace\s+([^;]+);$/m', $inhalt, $treffer)
            ? $treffer[1]
            : '';
    }

    private static function fehlenderRang(string $pfad): string
    {
        return \sprintf(
            '%s liegt in keinem Namensraum aus RANGFOLGE. Ein neuer Namensraum gehört dort '
            .'eingetragen — sonst wächst src/ still um eine Gruppe, die niemand eingeordnet '
            .'hat, und die Schichtung sagt nichts mehr aus.',
            $pfad,
        );
    }

    /**
     * @return list<string> Importe aus dem eigenen Wurzel-Namensraum, in Pfadform
     */
    private static function eigeneImporte(string $inhalt): array
    {
        preg_match_all('/^use\s+ProjektMotor\\\\IdsEventData\\\\([^;]+);$/m', $inhalt, $treffer);

        return array_map(
            static fn (string $fqcn): string => str_replace('\\', '/', $fqcn),
            $treffer[1],
        );
    }

    /**
     * Der längste passende Eintrag gewinnt.
     */
    private static function rang(string $pfad): ?int
    {
        $laengsterTreffer = -1;
        $rang = null;

        foreach (self::RANGFOLGE as $namensraum => $kandidat) {
            $passt = $pfad === $namensraum || str_starts_with($pfad, $namensraum.'/');

            if ($passt && \strlen($namensraum) > $laengsterTreffer) {
                $laengsterTreffer = \strlen($namensraum);
                $rang = $kandidat;
            }
        }

        return $rang;
    }

    /**
     * @return array<string, string> Pfad relativ zu src/ => Dateiinhalt
     */
    private static function quelldateien(): array
    {
        $dateien = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC)),
            '/\.php$/',
        );

        $ergebnis = [];

        foreach ($dateien as $datei) {
            \assert($datei instanceof \SplFileInfo);

            $relativ = str_replace('\\', '/', substr($datei->getPathname(), \strlen(self::SRC) + 1));
            $ergebnis[$relativ] = file_get_contents($datei->getPathname()) ?: '';
        }

        ksort($ergebnis);

        return $ergebnis;
    }
}
