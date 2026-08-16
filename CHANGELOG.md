# Changelog

Alle nennenswerten Änderungen an diesem Paket werden hier festgehalten.

Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung [Semantic Versioning](https://semver.org/lang/de/).

Semver gilt für das **gesamte** Paket. Es gibt hier nichts Internes: jede Konstante,
jeder Enum-Wert und jeder Feldname in `toArray()` ist Vertragstext, auf den sich die
Gegenseite verlässt.

## [0.1.1] — 2026-08-16

### Changed — `Actor::__construct()` kürzt die Benutzerkennung jetzt selbst

`MAX_USER_LENGTH` wurde bisher nur in `withUser()` durchgesetzt. `new Actor($kennung)`
ging an der Grenze vorbei und reichte den Wert ungekürzt weiter.

Das ist keine Formalie: bei fehlgeschlagener Anmeldung ist die Benutzerkennung
angreifergesteuert, Symfonys `UserBadge` erlaubt bis zu 4096 Zeichen, und genau diese
Events treten bei Brute-Force massenhaft auf. Über den Konstruktor ließ sich damit jedes
Fehlversuch-Event um 4 KB aufblähen.

**Wirkung auf den Draht:** wer `Actor` direkt erzeugt und dabei eine Kennung über 200
Zeichen übergibt, bekommt jetzt den gekürzten Wert im Event statt des vollständigen.
`ids-sensor-bundle` ist nicht betroffen — es geht über `CapturedEvent::setActorUser()`
und damit schon immer über `withUser()`.

`$user` ist deshalb keine promoted Property mehr, sondern wird im Konstruktorrumpf
gesetzt; `readonly` und die Signatur bleiben unverändert. `Actor::truncateUser()` bleibt
öffentlich.

### Fixed — `SensorIdentity`: die Längengrenze steht nur noch an einer Stelle

`MAX_ID_LENGTH = 64` und `ID_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/'` nannten die 64
getrennt voneinander. Wer die Konstante änderte, änderte die Prüfung in `isValidId()`
nicht — `validate()` hätte dann eine Grenze gemeldet, die nicht gilt.

`ID_PATTERN` wird jetzt aus `MAX_ID_LENGTH` und der neuen privaten Konstante
`ID_CHARACTERS` zusammengesetzt, aus der auch die Beanstandungstexte stammen. Verhalten
und Meldungstexte sind bei unverändertem Wert 64 identisch.

## [0.1.0] — 2026-08-15

Erste Fassung. Das Paket entsteht durch Ausgliederung aus
[`projektmotor/ids-sensor-bundle`](https://github.com/projektmotor/ids-sensor-bundle),
wo dieser Code als `src/EventFormat/` lag.

### Warum ausgegliedert

Das Format ist der Vertrag zwischen zwei Paketen: `IdsSensorBundle` schreibt es,
`IdsBackendBundle` liest es. Solange es im Sensor-Bundle lag, hätte das Backend das
komplette Bundle mitsamt Symfony Messenger, HttpFoundation und Redis ziehen müssen, nur
um drei Enums zu kennen. Jetzt hängen beide an denselben elf Klassen und an sonst nichts.

Die Ausgliederung war vorbereitet: `src/EventFormat/` importierte schon dort nichts
Fremdes — weder aus dem Bundle noch aus Symfony, und auch nicht in Docblocks. Ein Test
hat das bewacht. Er ist mitgewandert und heißt hier
`ArchitectureTest::testImportsNothingForeign()`.

### Changed — der Namensraum verkürzt sich

`ProjektMotor\IdsSensor\EventFormat\` → `ProjektMotor\IdsEventData\`

Beides ändert sich: `IdsSensor` fällt weg, weil das Paket keinem der beiden Konsumenten
gehört, und die Zwischenebene `EventFormat\` fällt weg, weil der Paketname sie bereits
sagt. Die vier Untergruppen bleiben unverändert:

| vorher | jetzt |
|---|---|
| `…\IdsSensor\EventFormat\Event\NormalizedEvent` | `ProjektMotor\IdsEventData\Event\NormalizedEvent` |
| `…\IdsSensor\EventFormat\Frame\Frame` | `ProjektMotor\IdsEventData\Frame\Frame` |
| `…\IdsSensor\EventFormat\Payload\KernelPayload` | `ProjektMotor\IdsEventData\Payload\KernelPayload` |
| `…\IdsSensor\EventFormat\Vocabulary\Severity` | `ProjektMotor\IdsEventData\Vocabulary\Severity` |

Der Umbau fand statt, **bevor** das Sensor-Bundle je getaggt wurde — danach wäre er ein
Bruch gewesen.

### Unverändert — das Drahtformat

`schema_version` bleibt `1`, `Frame::FRAME_VERSION` bleibt `1`. Kein Feldname, kein
Enum-Wert und keine Struktur in `toArray()` hat sich geändert. Für Konsumenten des JSON
ist die Ausgliederung nicht beobachtbar — das ist der Punkt, an dem sie hätte scheitern
können, und der Grund, warum die Golden-File-Tests des Sensor-Bundles unverändert gelten.

### Added — Abdeckung für den bisher ungetesteten Teil

Aus dem Bundle kamen nur Tests für `Actor` und `NormalizedEvent` mit. Neu geschrieben und
damit erstmals abgedeckt: `Frame`, `DispatchPath`, `SensorIdentity`, `EventSchema` sowie
`Layer`, `Severity` und `Environment`.

Zwei davon sind mehr als Formalie:

- `EventSchemaTest` prüft die Konstanten **gegen** die Listen `MANDATORY_FIELDS` und
  `ACTOR_FIELDS`, in beide Richtungen. Ein Feld, das als Konstante existiert, aber in
  keiner Liste steht — oder umgekehrt — fällt jetzt auf, statt erst beim Collector.
- `SensorIdentityTest::testATrailingNewlineCurrentlyPasses()` hält eine bekannte Laxheit
  fest: `$` in PCRE passt ohne den `D`-Modifikator auch vor einem abschließenden
  Zeilenumbruch, eine `application_id` mit `\n` am Ende kommt also durch die Prüfung.
  Der Test dokumentiert den Zustand, er billigt ihn nicht.

### Added — `ext-mbstring` ist jetzt deklariert

`Actor::truncateUser()` ruft `mb_substr()`. Das Sensor-Bundle hat die Extension nie in
seiner `composer.json` genannt; beim Auslagern ist es aufgefallen und mitgenommen worden.

`ext-json` steht dagegen nur unter `require-dev`: das Paket kodiert kein JSON — `toArray()`
ist die Grenze, die Serialisierung liegt beim Konsumenten. Nur ein Test prüft die
Kodierbarkeit.
