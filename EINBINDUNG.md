# Einbindung ins Sensor-Bundle — manuelle Schritte

Der Code in `../symfony-ids` ist bereits umgestellt: `src/EventFormat/` ist gelöscht, alle
Importe zeigen auf `ProjektMotor\IdsEventData\`. Was fehlt, ist die Composer-Verdrahtung —
bis dahin findet der Autoloader die Klassen nicht, und **Tests wie PHPStan schlagen mit
„class not found" fehl**. Das ist der erwartete Zwischenzustand, kein Fehler.

Diese Datei wird nicht mit dem Paket ausgeliefert (`export-ignore`).

---

## 1. Pushen

Commit und Tag `0.1.0` liegen lokal bereit:

```bash
cd /home/soeren/Workspace/ids-event-data
git push -u origin main
git push origin 0.1.0
```

Ohne den Tag findet Composer nur `dev-main`, und `^0.1` löst dann nicht auf.

---

## 2. `composer.json` des Bundles ergänzen

In `/home/soeren/Workspace/symfony-ids/composer.json`. Das Paket ist privat und nicht auf
Packagist — Composer braucht deshalb einen expliziten VCS-Eintrag, sonst sucht es an einer
Stelle, an der es das Paket nie finden wird.

**a) Repository eintragen**, als neuer Block direkt nach `"authors"`:

```json
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:projektmotor/ids-event-data.git"
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

**Auf dem Host** — nutzt den SSH-Agent direkt und ist der kürzeste Weg zur Prüfung, ob
Schritt 1 und 2 stimmen:

```bash
cd /home/soeren/Workspace/symfony-ids
composer update projektmotor/ids-event-data --no-scripts
```

**Im Container** schlägt derselbe Befehl zunächst fehl. Der `php`-Service mountet nur
`.:/app` und kennt weder SSH-Key noch Token; das private Repo ist für ihn nicht lesbar.
Einer der beiden folgenden Wege genügt.

### Weg A — SSH-Agent durchreichen

In `docker-compose.yml` beim Service `php`:

```yaml
    volumes:
      - .:/app
      - composer-cache:/composer-cache
      - ${SSH_AUTH_SOCK}:/ssh-agent          # neu
    environment:
      SSH_AUTH_SOCK: /ssh-agent              # neu
      IDS_REDIS_DSN: ...
```

Zusätzlich braucht der Container `github.com` in den `known_hosts`, sonst bricht git mit
„Host key verification failed" ab. In `docker/php/Dockerfile`:

```dockerfile
RUN apk add --no-cache git unzip openssh-client $PHPIZE_DEPS \
 && ssh-keyscan github.com >> /etc/ssh/ssh_known_hosts \
 && pecl install redis apcu \
 && docker-php-ext-enable redis apcu \
 && apk del $PHPIZE_DEPS
```

Danach `make build && make install`.

Voraussetzung: auf dem Host läuft ein Agent mit geladenem Schlüssel (`ssh-add -l`).

### Weg B — Token statt SSH

Ohne Änderung am Dockerfile. Auf dem Host:

```bash
export COMPOSER_AUTH='{"github-oauth":{"github.com":"<PAT mit repo-Scope>"}}'
```

Und in `docker-compose.yml` beim Service `php` durchreichen:

```yaml
    environment:
      COMPOSER_AUTH: "${COMPOSER_AUTH}"
```

Composer fällt für `github.com`-VCS-Repositories dann automatisch auf die HTTPS-API
zurück. Der Token gehört nicht ins Repository — `.env.local` ist bereits gitignored.

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
