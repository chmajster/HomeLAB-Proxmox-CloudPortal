# Aktualizacje i rollback

Od wersji 1.4.0 instalacje ZIP/self-hosted mogą być aktualizowane przez `update.sh`.
Narzędzie jest przeznaczone do uruchamiania lokalnie na serwerze portalu przez
administratora systemu. Nie jest endpointem HTTP i nie pobiera paczek z Internetu.

## Wymagania

Serwer musi mieć:

- PHP CLI,
- `rsync`,
- `unzip` i `sha256sum`,
- klienta `mysql` i `mysqldump`,
- prawa zapisu do katalogu aplikacji oraz `storage/`,
- dostęp do tej samej bazy danych, z której korzysta portal.

Jeżeli worker działa jako usługa systemd, użytkownik wykonujący aktualizację musi
mieć prawo zatrzymać i uruchomić tę usługę. Domyślna nazwa to
`algen-cloud-worker.service`; inną można podać przez `--worker-service` lub
`CLOUDPORTAL_WORKER_SERVICE`.

Na hostingu współdzielonym bez tych narzędzi należy nadal wykonać ręczny backup
bazy i plików oraz wdrożyć kompletne wydanie zgodnie z procedurą operatora hostingu.

## Bezpieczna aktualizacja ZIP

Pobierz ZIP wydania i odpowiadający mu SHA-256. Następnie z katalogu aktualnie
zainstalowanego portalu uruchom:

```bash
bash update.sh \
  --package /tmp/Algen-Proxmox-CloudPortal-1.4.0.zip \
  --sha256 <64-znakowy-sha256>
```

Jeżeli obok ZIP-a znajduje się plik o nazwie `<pakiet>.sha256`, parametr
`--sha256` można pominąć. Aktualizator odczyta pierwszy hash z tego pliku.

Brak sumy kontrolnej jest domyślnie błędem. Tylko świadome użycie
`--no-checksum` wyłącza weryfikację integralności paczki.

Downgrade jest blokowany. Do świadomego cofnięcia kodu przy użyciu paczki służy
`--allow-downgrade`, natomiast do odtworzenia dokładnego poprzedniego stanu
preferowany jest opisany niżej rollback z backupu.

## Co wykonuje aktualizator

Przed modyfikacją aplikacji skrypt:

1. zakłada wyłączny lock `storage/update.lock`,
2. sprawdza, czy nie ma jobów `queued` ani `running`,
3. weryfikuje strukturę ZIP-a, wersję i SHA-256,
4. tworzy prywatny plik opcji klienta MySQL z prawami `0600`; hasło bazy nie jest
   przekazywane w linii poleceń,
5. włącza `storage/maintenance.json`, przez co HTTP zwraca `503` i `Retry-After: 60`,
6. ponownie sprawdza kolejkę, aby zamknąć wyścig z nową operacją,
7. zatrzymuje aktywną usługę workera, jeśli jest dostępna,
8. tworzy katalog backupu w `storage/backups/updates/`,
9. kopiuje poprzedni kod oraz wykonuje pełny `mysqldump`,
10. wdraża nowy kod, zachowując `config/runtime.php`, `storage/`, `.git/` i `vendor/`,
11. uruchamia `bin/migrate.php`,
12. uruchamia `tests/smoke.php`, jeśli plik jest częścią wydania,
13. sprawdza, czy aplikacja raportuje oczekiwaną wersję,
14. wyłącza maintenance i ponownie uruchamia worker, jeżeli działał przed aktualizacją.

Backup pozostaje na dysku po udanej aktualizacji. Historia sukcesów jest dopisywana
do `storage/update-history.jsonl`.

## Automatyczny rollback po błędzie

Po utworzeniu kompletnego backupu każdy błąd podczas wdrożenia, migracji lub smoke
testu uruchamia automatyczne odtworzenie:

- poprzedniego kodu,
- pełnego dumpa bazy danych.

Po poprawnym odtworzeniu maintenance jest wyłączany i wcześniej aktywny worker jest
uruchamiany ponownie.

Jeżeli automatyczne odtworzenie samo zakończy się błędem, aktualizator działa
fail-closed: pozostawia `storage/maintenance.json`, nie udaje sukcesu i wypisuje
ścieżkę do backupu. W takim przypadku nie usuwaj backupu i napraw przyczynę przed
ponowną próbą.

## Ręczny rollback

Aby odtworzyć najnowszy backup:

```bash
bash update.sh --rollback latest
```

Albo konkretny katalog widoczny w `storage/backups/updates/`:

```bash
bash update.sh --rollback 20260825T141500Z-from-1.3.0-to-1.4.0
```

Rollback również odmawia rozpoczęcia, gdy istnieją joby `queued` lub `running`.
Podczas ręcznego rollbacku błąd pozostawia portal w maintenance mode zamiast
wystawiać potencjalnie częściowo odtworzony system do ruchu HTTP.

## Worker i maintenance

`bin/worker.php` sprawdza marker maintenance przed startem oraz przed pobraniem
kolejnego joba. Worker uruchomiony przez Cron zakończy się bez pobrania pracy.
Długowieczny worker pozwoli zakończyć już wykonywaną iterację, a następnie nie
pobierze kolejnego zadania.

## Odzyskiwanie ręczne

Każdy backup aktualizacji zawiera:

- `code/` — poprzedni kod aplikacji,
- `database.sql` — pełny dump bazy,
- `runtime.php` i `installed.lock` jako dodatkową kopię kluczowych plików instalacji,
- `metadata.json` z wersją źródłową, docelową i hashem pakietu.

Nie przechowuj katalogu `storage/backups/` w publicznym repozytorium. Jest on
ignorowany przez `.gitignore` i powinien być chroniony tak samo jak baza danych
oraz `config/runtime.php`.
