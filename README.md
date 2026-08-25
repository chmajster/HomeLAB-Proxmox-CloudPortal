# Algen Proxmox Cloud Portal

Lekki portal Self-Service IaaS dla Proxmox VE napisany w zwykłym PHP 8.3 i
MariaDB/MySQL. Instalacja podstawowa działa jak w WordPressie: pobierasz ZIP,
wypakowujesz go w istniejącym katalogu WWW i kończysz konfigurację w
przeglądarce.

## Instalacja z ZIP — bez VirtualHosta i bez Composera

1. Pobierz ZIP wydania i wypakuj całą zawartość do katalogu obsługiwanego przez
   Apache, np. `public_html/cloudportal/` albo bezpośrednio do `public_html/`.
2. W panelu hostingu utwórz pustą bazę MariaDB/MySQL i użytkownika tej bazy.
   Nie importuj żadnego pliku SQL.
3. Otwórz adres wypakowanego katalogu, np.
   `https://example.com/cloudportal/`.
4. Aplikacja automatycznie przekieruje do `/install`. Kreator sprawdzi serwer,
   przetestuje bazę, utworzy schemat i administratora, opcjonalnie sprawdzi
   Proxmox oraz zapisze chronioną konfigurację.
5. Po ekranie sukcesu zaloguj się utworzonym kontem.

To wszystko dla podstawowej instalacji. Nie trzeba:

- tworzyć VirtualHosta ani wskazywać `public/` jako DocumentRoot,
- uruchamiać `composer install` — wydanie ma własny autoloader,
- tworzyć `.env` ani edytować plików PHP,
- ręcznie importować `database/schema.sql`,
- wykonywać SQL w celu utworzenia administratora,
- ustawiać flagi `installed=true`.

## Automatyczna instalacja z `install.json`

Jeżeli w katalogu głównym aplikacji znajduje się plik `install.json`, wejście na
`/install` automatycznie uruchamia instalację bez formularzy. Gdy pliku nie ma,
działa dotychczasowy kreator WWW.

Najprościej skopiować [`install.example.json`](install.example.json) do
`install.json`, uzupełnić dane i otworzyć portal w przeglądarce:

```bash
cp install.example.json install.json
```

Przykładowa struktura:

```json
{
  "database": {
    "driver": "mysql",
    "host": "127.0.0.1",
    "port": 3306,
    "name": "cloudportal",
    "user": "cloudportal",
    "password": "CHANGE_ME_DATABASE_PASSWORD",
    "confirm_existing": false
  },
  "administrator": {
    "username": "admin",
    "email": "admin@example.com",
    "password": "ChangeMeStrong123!",
    "resume_existing": false
  },
  "proxmox": {
    "skip": true
  },
  "portal": {
    "name": "Algen Cloud Portal",
    "url": "https://cloud.example.com",
    "timezone": "Europe/Warsaw",
    "locale": "pl",
    "session_lifetime": 7200
  }
}
```

Sekcje `database` i `administrator` są wymagane. `portal` jest opcjonalny i ma
bezpieczne wartości domyślne; jeśli nie podano `portal.url`, instalator użyje
adresu wykrytego z bieżącego żądania. Brak sekcji `proxmox` oznacza pominięcie
konfiguracji Proxmox. Aby skonfigurować Proxmox od razu, użyj np.:

```json
"proxmox": {
  "skip": false,
  "name": "Primary Proxmox",
  "hostname": "pve.example.com",
  "port": 8006,
  "realm": "pam",
  "token_id": "root@pam!cloudportal",
  "token_secret": "TOKEN_SECRET",
  "verify_ssl": true
}
```

Instalator najpierw parsuje i waliduje wszystkie sekcje JSON, zanim zmodyfikuje
bazę lub zapisze konfigurację. Następnie wykonuje te same testy bazy, schematu,
administratora, Proxmox i finalizacji co kreator WWW. Jeśli baza nie jest pusta,
`database.confirm_existing` musi być ustawione jawnie na `true`. Wznowienie na
istniejącym administratorze wymaga `administrator.resume_existing=true` oraz
identycznych danych uwierzytelniających.

`install.json` może zawierać hasła i token API, dlatego:

- plik jest ignorowany przez Git,
- Apache blokuje dostęp HTTP do `install.json` i `install.*.json`,
- po udanej instalacji aplikacja próbuje usunąć `install.json`, a jeśli usunięcie
  nie jest możliwe — wyczyścić jego zawartość,
- przy błędzie plik pozostaje na miejscu, aby można było poprawić dane i ponowić
  wejście na `/install`,
- szczegóły błędu są zapisywane do `storage/logs/installer.log` z redakcją
  sekretów.

Dołączony [`.htaccess`](.htaccess) kieruje ruch do front controllera, udostępnia
lokalne assety i blokuje dostęp HTTP do konfiguracji, kodu, schematu, logów,
testów oraz katalogu `public`. Instalacja działa również po wypakowaniu do
podkatalogu. Plik [`config/apache-vhost.conf.example`](config/apache-vhost.conf.example)
jest wyłącznie opcjonalnym wariantem dla administratorów, którzy chcą osobny,
utwardzony VirtualHost.

## Wymagania hostingu

- Apache 2.4 z `mod_rewrite` i obsługą `.htaccess`,
- PHP 8.3+ z: PDO, `pdo_mysql`, cURL, JSON, OpenSSL, mbstring, session, filter,
  fileinfo i sodium,
- MariaDB 10.6+ albo MySQL 8.0+,
- zapis PHP do katalogów `config/`, `storage/`, `storage/logs/` i
  `storage/cache/`,
- HTTPS dla instalacji publicznej,
- połączenie serwera WWW z Proxmox API na TCP/8006, jeżeli Proxmox ma być
  skonfigurowany od razu.

Kreator pokazuje te wymagania jako PASS, WARNING lub ERROR i nie przechodzi
dalej przy błędzie. Typowy hosting PHP spełnia je bez zmiany konfiguracji. Jeśli
serwer nie pozwala na `.htaccess` albo zapis wymaganych katalogów, administrator
hostingu musi włączyć te standardowe funkcje — aplikacja celowo nie próbuje
obchodzić zabezpieczeń serwera.

## Co robi instalator

Kreator ma maksymalnie siedem kontrolowanych etapów: Welcome, Requirements,
Database, Administrator, Proxmox, Portal i Installation, a następnie ekran
Finish. Gdy wszystkie wymagania środowiska mają status PASS, etap Requirements
jest automatycznie ukrywany i pomijany. WARNING lub ERROR pozostawia go
widocznym. Wersjonowany schemat bazy, klucze bezpieczeństwa oraz konfiguracja
runtime są tworzone automatycznie, bez osobnych ekranów.

- test bazy wykonuje prawdziwe połączenie PDO oraz próbę utworzenia tabeli
  tymczasowej,
- schemat jest wersjonowany i idempotentny; istniejące tabele nigdy nie są
  automatycznie usuwane,
- e-mail administratora jest opcjonalny; dla pustego pola instalator tworzy
  niepubliczny adres techniczny w domenie `localhost.invalid`,
- hasło administratora jest hashowane Argon2id, jeśli PHP go obsługuje,
- jawnie wybrany tryb testowy pomija formularz administratora i tworzy konto
  `admin` z hasłem `1`; opcja jest domyślnie wyłączona i nie może być używana w
  instalacji dostępnej z Internetu,
- test Proxmox pobiera cluster status, nodes, version i storage przez REST API,
- token Proxmox jest szyfrowany przed zapisem,
- APP_KEY, klucz szyfrowania i sekret CSRF powstają przez `random_bytes()`,
- `config/runtime.php` i `storage/installed.lock` mają prawa `0600` na systemach
  POSIX; na Windows oraz dyskach Windows zamontowanych w WSL instalator używa
  modelu NTFS ACL i nie interpretuje niemiarodajnych bitów `chmod()`,
- lock jest tworzony jako ostatnia operacja; bez niego aplikacja pozostaje w
  trybie instalacji i można bezpiecznie ponowić proces,
- po sukcesie backend blokuje `/install` oraz `/install/*` kodem HTTP 403.

## Pierwsze uruchomienie

Pierwszy administrator widzi checklistę konfiguracji. Brak Proxmox, sieci lub
template'ów nie blokuje panelu. Połączenie Proxmox można pominąć w instalatorze i
dodać później w sekcji administracyjnej.

Do tokenu Proxmox stosuj minimalne ACL i osobnego użytkownika. Token ID może mieć
format `user!token` lub `user@realm!token`. Weryfikację TLS wyłączaj wyłącznie w
izolowanym środowisku testowym.

Pola `API Token ID` i `API Token Secret` nie przyjmują loginu i hasła konta.
Przykładowy identyfikator tokenu użytkownika root to `root@pam!cloudportal`;
sekretem jest wartość wygenerowana podczas tworzenia tego tokenu w Proxmox.

## Przetwarzanie operacji Proxmox

Tworzenie i operacje na VM korzystają z trwałej kolejki, aby odświeżenie strony
nie powielało zadań. Na zwykłym hostingu dodaj po instalacji zadanie Cron
wywołujące co minutę:

```text
php /pełna/ścieżka/do/cloudportal/bin/worker.php --once
```

Na własnym serwerze można zamiast Crona użyć opcjonalnej usługi
[`config/algen-cloud-worker.service.example`](config/algen-cloud-worker.service.example).
Worker nie jest potrzebny do samego instalatora, logowania ani konfiguracji
panelu, ale jest potrzebny do wykonania kolejkowanych operacji Proxmox.

## Aktualizacje

Od wersji 1.4.0 instalacja ZIP/self-hosted ma transakcyjny aktualizator CLI.
Przykład aktualizacji zweryfikowaną paczką:

```bash
bash update.sh --package /tmp/Algen-Proxmox-CloudPortal-1.4.0.zip --sha256 <SHA256>
```

Aktualizator nie rozpocznie pracy przy aktywnych jobach, włącza HTTP 503
maintenance mode, tworzy kopię kodu i pełny dump bazy, wykonuje migracje i smoke
test, a po błędzie automatycznie odtwarza poprzedni stan. Ręczny rollback
najnowszej kopii:

```bash
bash update.sh --rollback latest
```

Pełna procedura, wymagania oraz scenariusze awaryjne są opisane w
[`docs/UPDATES.md`](docs/UPDATES.md).

## Bezpieczeństwo i odzyskiwanie

- wykonuj wspólny backup bazy, `config/runtime.php` i
  `storage/installed.lock`; bez klucza runtime nie można odszyfrować tokenów,
- nie publikuj logu `storage/logs/installer.log`,
- samo usunięcie locka nie usuwa ani nie nadpisuje danych,
- ponowną instalację uruchamiaj dopiero po backupie i świadomym usunięciu
  runtime/locka,
- ścieżka HTTP portalu nie wykonuje dowolnych poleceń shell ani SSH; Proxmox jest
  obsługiwany przez HTTPS REST API. Opcjonalny managed provisioning może wywołać
  wyłącznie skonfigurowany, absolutny adapter Terraform jako tablicę argumentów
  z pominięciem shella, a `update.sh` jest świadomie uruchamianym narzędziem CLI.

Opis warstw, RBAC, quota, IPAM, kolejki i mechanizmów rollback znajduje się w
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Testy deweloperskie

Composer jest potrzebny tylko deweloperowi do PHPUnit, nie użytkownikowi ZIP-a:

```bash
composer install
composer lint
composer test
composer test:smoke
```

Integracje MariaDB używają wyłącznie izolowanej bazy zawierającej słowo `test`
w `TEST_DB_DSN`. Testy obejmują także przerwaną finalizację, retry, lock,
redakcję sekretów, ochronę kroków, prawdziwy import schematu i brak duplikacji
administratora.
