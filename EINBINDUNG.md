# Einbindung ins Sensor-Bundle — manuelle Schritte

Der Code in `../symfony-ids` ist bereits umgestellt: `src/EventFormat/` ist gelöscht, alle
Importe zeigen auf `ProjektMotor\IdsEventData\`. Was fehlt, ist die Composer-Verdrahtung —
bis dahin findet der Autoloader die Klassen nicht, und **Tests wie PHPStan schlagen mit
„class not found" fehl**. Das ist der erwartete Zwischenzustand, kein Fehler.

Diese Datei wird nicht mit dem Paket ausgeliefert (`export-ignore`).

## Stand

| | |
|---|---|
| Repository öffentlich | ja — anonym lesbar, keine Credentials nötig |
| `main` gepusht | ja |
| Tag `0.1.0` gepusht | **nein, nur lokal** — siehe Schritt 1 |
| Auf Packagist | nein — der `repositories`-Eintrag aus Schritt 2 bleibt deshalb nötig |

Dass das Repository öffentlich ist, spart den gesamten Credential-Teil: Composer zieht es
im Container über HTTPS ohne SSH-Agent und ohne Token. Öffentlich heißt aber **nicht**
auffindbar — Packagist ist ein Index, in den ein Paket eingetragen werden muss. Solange
das nicht geschehen ist, weiß Composer nichts von diesem Paket, egal wie öffentlich es
ist. Genau diese Lücke schließt der `repositories`-Eintrag.

---

## 1. Tag nachschieben

`main` ist gepusht, der Tag nicht — auf der Gegenseite existiert bisher nur der Branch:

```bash
cd /home/soeren/Workspace/ids-event-data
git push origin 0.1.0
```

**Ohne diesen Schritt schlägt Schritt 3 fehl.** Composer löst Versionen aus Tags auf; ohne
Tag kennt es nur `dev-main`, und `^0.1` findet nichts. Zur Kontrolle — die Zeile mit
`refs/tags/0.1.0` muss erscheinen:

```bash
git ls-remote --tags origin
```

---

## 2. `composer.json` des Bundles ergänzen

In `/home/soeren/Workspace/symfony-ids/composer.json`.

**a) Repository eintragen**, als neuer Block direkt nach `"authors"`. HTTPS und nicht SSH:
das Repo ist öffentlich, damit funktioniert der Eintrag auch dort, wo kein Schlüssel liegt
— im Container und in jeder CI:

```json
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/projektmotor/ids-event-data.git"
        }
    ],
```

**b) Abhängigkeit ergänzen**, in `"require"` (alphabetisch vor `"psr/log"`, damit
`sort-packages` nichts umsortiert):

```json
        "projektmotor/ids-event-data": "^0.1",
```

Der resultierende `require`-Block:

```json
    "require": {
        "php": ">=8.2",
        "ext-json": "*",
        "projektmotor/ids-event-data": "^0.1",
        "psr/log": "^1.1|^2.0|^3.0",
        "symfony/config": "^6.4|^7.0",
        ...
    },
```

> Bei `0.x` verhält sich `^0.1` wie `~0.1.0` — es erlaubt `0.1.1`, aber nicht `0.2.0`.
> Das ist gewollt, solange die Paketgrenze frisch ist. Nach dem ersten `1.0.0` wird
> daraus `^1.0`.

---

## 3. Installieren

Auf dem Host:

```bash
cd /home/soeren/Workspace/symfony-ids
composer update projektmotor/ids-event-data --no-scripts
```

Im Container genauso, ohne jede Vorbereitung:

```bash
make install
```

Weder `docker-compose.yml` noch `docker/php/Dockerfile` müssen angefasst werden. Das war
nur nötig, solange das Repository privat war — dann hätte der `php`-Service einen
SSH-Agent oder einen Token gebraucht, den er mit dem Mount `.:/app` nicht bekommt. Bei
einem öffentlichen Repo entfällt das ersatzlos; `git` und `unzip` sind im Image bereits
vorhanden.

Schlägt es trotzdem fehl, ist fast immer der Tag aus Schritt 1 die Ursache:

```
Could not find a matching version of package projektmotor/ids-event-data.
```

Composer sagt das auch, wenn das Repository erreichbar ist und nur die Version fehlt. Zur
Gegenprobe, was Composer tatsächlich sieht:

```bash
composer show projektmotor/ids-event-data --all
```

---

## 4. Prüfen

```bash
cd /home/soeren/Workspace/symfony-ids

# Es darf keine Nennung des alten Namensraums mehr geben:
grep -rn 'IdsSensor\\EventFormat' src tests config doc README.md CHANGELOG.md CLAUDE.md

make test     # unit + integration
make stan
make cs
```

Der empfindlichste Punkt sind die 14 Golden Files unter
`tests/Fixtures/container-fingerprints/`. Sie enthalten den FQCN von
`Vocabulary\Environment` wörtlich und doppelt maskiert
(`ProjektMotor\\IdsEventData\\Vocabulary\\Environment`); schlagen `BundleBootTest` oder
die Fingerprint-Vergleiche fehl, liegt es dort und nicht am Autoloading.

Zum Schluss der Beleg, dass sich am Drahtformat nichts geändert hat:

```bash
make test-redis     # tests/Functional/RedisStreamTest.php
```

Der Test schickt ein Event real durch Redis-Streams und prüft es gegen `EventSchema`.
Läuft er durch, ist `schema_version: 1` über die neue Paketgrenze hinweg unverändert —
und genau das ist die Zusage, die diese Umstellung nicht brechen durfte.

---

## Optional: auf Packagist eintragen

Erst damit wird aus „öffentlich lesbar" auch „auffindbar". Der Nutzen ist konkret: der
`repositories`-Block aus Schritt 2 entfällt, und zwar **in jedem** konsumierenden Projekt —
also auch im künftigen IdsBackendBundle und in jeder Anwendung, die das Sensor-Bundle
einbindet. Ohne Packagist muss jedes dieser Projekte den Eintrag selbst mitführen, denn
Composer wertet `repositories` nur im Wurzelpaket aus: der Eintrag im Sensor-Bundle
vererbt sich **nicht** an dessen Konsumenten. Sie bekämen sonst beim `composer require`
einen Fehler über ein unauffindbares Paket, ohne zu wissen, warum.

1. Auf [packagist.org](https://packagist.org) mit dem GitHub-Konto anmelden
2. *Submit* → `https://github.com/projektmotor/ids-event-data`
3. Den GitHub-Webhook einrichten, damit neue Tags automatisch erscheinen — Packagist
   bietet das nach dem Submit als Ein-Klick-Schritt an

Danach reicht in jedem Projekt:

```bash
composer require projektmotor/ids-event-data
```

Solange das nicht passiert ist, bleibt der `repositories`-Eintrag Pflicht — er ist kein
Übergangszustand, sondern der vollwertige Ersatz dafür.
