<?php

declare(strict_types=1);

namespace ProjektMotor\IdsEventData\Tests\Unit\Frame;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Event\Actor;
use ProjektMotor\IdsEventData\Event\EventSchema;
use ProjektMotor\IdsEventData\Event\NormalizedEvent;
use ProjektMotor\IdsEventData\Event\SensorIdentity;
use ProjektMotor\IdsEventData\Frame\DispatchPath;
use ProjektMotor\IdsEventData\Frame\Frame;
use ProjektMotor\IdsEventData\Vocabulary\Environment;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsEventData\Vocabulary\Severity;

/**
 * Der Frame ist das Format auf der Leitung UND im Spool — beides liest ein fremder
 * Prozess, der diesen Code nicht kennt. Deshalb prüft dieser Test die Schlüssel des
 * serialisierten Umschlags namentlich und nicht über Konstanten: eine Umbenennung soll
 * hier auffallen und nicht stillschweigend mitwandern.
 */
final class FrameTest extends TestCase
{
    public function testTheFrameVersionIsHardWired(): void
    {
        self::assertSame(1, Frame::FRAME_VERSION);
        self::assertSame(1, $this->frame()->toArray()['v']);
    }

    /**
     * Die Struktur aus der Sicht des Consumers. Der Sensor-Kontext liegt unter
     * "sensor", die Events unter "events" — die Verschachtelung ist der Vertrag.
     */
    public function testSerializationHasTheEnvelopeStructure(): void
    {
        $data = $this->frame()->toArray();

        self::assertSame(
            ['v', 'sensor', 'flushed_at', 'dispatch_path', 'spool_delay_ms', 'counters', 'events'],
            array_keys($data),
        );

        self::assertIsArray($data['sensor']);
        self::assertSame([
            EventSchema::FIELD_APPLICATION_ID => 'shop-api',
            EventSchema::FIELD_INSTANCE_ID => 'web-03',
            EventSchema::FIELD_ENVIRONMENT => 'prod',
            'process_epoch' => 'epoch-1',
            'pid' => 4711,
        ], $data['sensor']);
    }

    /**
     * Der Frame ist KEIN Event: er umhüllt sie, und die eingebetteten Events sind
     * exakt das, was NormalizedEvent::toArray() liefert. Ein zweiter Serialisierungsweg
     * wäre eine zweite Gelegenheit, das Event-Schema zu verletzen.
     */
    public function testEventsAreEmbeddedThroughTheirOwnSerialization(): void
    {
        $event = $this->event();

        $data = $this->frame(events: [$event])->toArray();

        self::assertIsArray($data['events']);
        self::assertCount(1, $data['events']);
        self::assertSame($event->toArray(), $data['events'][0]);
    }

    /**
     * Dasselbe Zeitformat wie im Event — der Collector misst daraus die Uhrendrift
     * und braucht dafür ein einziges Format, nicht zwei.
     */
    public function testFlushedAtUsesTheSchemaTimestampFormat(): void
    {
        $data = $this->frame(
            flushedAt: new \DateTimeImmutable('2026-08-14T10:15:32.421000+00:00'),
        )->toArray();

        self::assertSame('2026-08-14T10:15:32.421Z', $data['flushed_at']);
    }

    public function testIsEmptyAndCountReportTheEvents(): void
    {
        self::assertTrue($this->frame(events: [])->isEmpty());
        self::assertSame(0, $this->frame(events: [])->count());

        $gefuellt = $this->frame(events: [$this->event(), $this->event()]);

        self::assertFalse($gefuellt->isEmpty());
        self::assertSame(2, $gefuellt->count());
    }

    /**
     * Beim Drain lernt der Umschlag, auf welchem Weg er gereist ist — die Events
     * darin bleiben unangetastet, weil ein zweiter Redaktionsdurchlauf eine zweite
     * Gelegenheit wäre, es falsch zu machen.
     */
    public function testAsDeferredChangesOnlyTheDispatchMetadata(): void
    {
        $original = $this->frame();

        $nachgesendet = $original->asDeferred(DispatchPath::Recovered, 45_000);

        self::assertSame(DispatchPath::Recovered, $nachgesendet->dispatchPath);
        self::assertSame(45_000, $nachgesendet->spoolDelayMs);

        self::assertSame($original->identity, $nachgesendet->identity);
        self::assertSame($original->events, $nachgesendet->events);
        self::assertSame($original->flushedAt, $nachgesendet->flushedAt);
        self::assertSame($original->counters, $nachgesendet->counters);
        self::assertSame($original->processEpoch, $nachgesendet->processEpoch);
        self::assertSame($original->pid, $nachgesendet->pid);

        self::assertSame(DispatchPath::Direct, $original->dispatchPath, 'Das Original bleibt unverändert');
        self::assertSame(0, $original->spoolDelayMs);
    }

    public function testDispatchPathAndCountersAreSerializedAsScalars(): void
    {
        $data = $this->frame(counters: ['dropped' => 3])->toArray();

        self::assertSame('direct', $data['dispatch_path']);
        self::assertSame(0, $data['spool_delay_ms']);
        self::assertSame(['dropped' => 3], $data['counters']);
    }

    /**
     * @param list<NormalizedEvent>|null $events
     * @param array<string, int>         $counters
     */
    private function frame(
        ?array $events = null,
        ?\DateTimeImmutable $flushedAt = null,
        array $counters = [],
    ): Frame {
        return new Frame(
            new SensorIdentity('shop-api', 'web-03', Environment::Prod),
            $events ?? [$this->event()],
            $flushedAt ?? new \DateTimeImmutable('2026-08-14T10:15:32.421000+00:00'),
            DispatchPath::Direct,
            0,
            $counters,
            'epoch-1',
            4711,
        );
    }

    private function event(): NormalizedEvent
    {
        return new NormalizedEvent(
            'b3f1e6b0-6e3a-4c9a-9f2e-2a6a2f4b9c11',
            new \DateTimeImmutable('2026-08-14T10:15:32.421000+00:00'),
            Layer::Kernel,
            'kernel.request',
            'req-7f2a1c',
            Severity::Info,
            new SensorIdentity('shop-api', 'web-03', Environment::Prod),
            new Actor('alice', '203.0.113.42', 'a3f9c1d8', 'c71b04ae'),
            ['method' => 'GET', 'path' => '/api/orders/42'],
        );
    }
}
