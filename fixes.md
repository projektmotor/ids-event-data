# Befunde in `projektmotor/ids-event-data`

Aufgefallen beim Code-Check des `ids-sensor-bundle` (2026-08-16). Geprüft am Stand
`0.1.0` in `vendor/projektmotor/ids-event-data/`. Alle drei bestehen dort noch; hier
steht nur, **was** und **wo** — nicht wie.

---

## 1. `MAX_USER_LENGTH` wird vom Konstruktor nicht durchgesetzt

**`src/Event/Actor.php`, Zeile 31** (Konstruktor) gegen **Zeile 47** (`MAX_USER_LENGTH = 200`)
und **Zeile 85** (`truncateUser()`).

Gekürzt wird ausschließlich in `withUser()` (Zeile 58). `new Actor($langerName)` geht an
der Grenze vorbei.

Die Begründung der Grenze steht zehn Zeilen darüber in derselben Klasse: Die
Benutzerkennung ist bei fehlgeschlagener Anmeldung angreifergesteuert, Symfonys
`UserBadge` erlaubt bis zu 4096 Zeichen, und genau diese Events treten bei Brute-Force
massenhaft auf. Über den Konstruktor lässt sich damit jedes Fehlversuch-Event um 4 KB
aufblähen.

**Wer betroffen ist:** jeder Konsument, der `Actor` direkt erzeugt. Das
`ids-sensor-bundle` nicht — es geht über `CapturedEvent::setActorUser()` und damit über
`withUser()`.

---

## 2. Die Längengrenze 64 steht zweimal

**`src/Event/SensorIdentity.php`, Zeilen 32 und 34:**

- `public const MAX_ID_LENGTH = 64;`
- `private const ID_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/';`

Wer die Konstante ändert, ändert die Prüfung in `isValidId()` (Zeile 53) nicht.
`validate()` (ab Zeile 60) gibt `MAX_ID_LENGTH` in beiden Beanstandungstexten aus —
die Meldung nennt dann eine Grenze, die nicht gilt.

---

## 3. Zwei Tests sind beim Ausgliedern verschwunden — Verbleib unbestätigt

`ActorTest` und `NormalizedEventTest` wurden im `ids-sensor-bundle` mit Commit `c3e2121`
(„extracts EventData") gelöscht, weil sie zum Ereignisformat gehören.

Ob sie in diesem Repository angekommen sind, ist von außen **nicht** feststellbar: Das
Dist-Archiv des Pakets enthält nur `src/`, `README.md`, `CHANGELOG.md` und `LICENSE` —
kein `tests/`. Für eine Composer-Installation ist das richtig.

Nachzusehen: `tests/Unit/Event/ActorTest.php` und `tests/Unit/Event/NormalizedEventTest.php`.

Falls sie fehlen, sind die Pflichtfelder aus Konzept Abschnitt 3 ungeprüft — in einem
Paket, das der Vertrag zwischen `ids-sensor-bundle` und `ids-backend-bundle` ist und das
seinerseits nichts importiert.

---

**Hinweis zur Version:** Punkt 1 ändert Laufzeitverhalten für Aufrufer des Konstruktors
(eine überlange Kennung wird gekürzt statt durchgereicht) und damit den Wert auf dem
Draht. In `0.x` genügt eine Minor; ein `### Changed`-Eintrag im dortigen `CHANGELOG.md`
gehört dazu.
