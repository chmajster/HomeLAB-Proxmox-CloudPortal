# Centralny backend infrastruktury

Ta wersja portalu obsługuje backend `chmajster/Cloudportal-backed`, API `/api/v1`, OpenAPI `/openapi.json`. Nowy frontend PHP nie wymaga lokalnej bazy MySQL: przechowuje sesję użytkownika oraz chronioną konfigurację połączenia.

## Pierwsze uruchomienie

1. Zainstaluj powiązaną wersję Cloudportal-backed zgodnie z jego README. Zapisz jednorazowo wyświetlone hasło administratora i bootstrap API token.
2. Na serwerze PHP uruchom jako użytkownik aplikacji `php bin/backend-setup.php`. Polecenie zapisuje tylko hash losowego klucza konfiguracji w `storage/backend-setup.token` (0600).
3. Otwórz `/settings/infrastructure/backend`. Podaj klucz konfiguracji, HTTPS Backend URL, API Token, timeout i ustaw weryfikację TLS. Dla własnego CA ustaw `CP_BACKEND_CA_FILE` na lokalny plik z zaufanym certyfikatem.
4. Przycisk **Testuj połączenie** wywołuje `/api/v1/health` oraz `/api/v1/auth/me`. Zapis aktywuje tryb centralny.
5. Zaloguj się kontem backendu. Administracja → Tokeny API → **Utwórz konto i token serwisowy portalu** tworzy konto bez logowania hasłem, rolę z `portal.connect` i token o tym samym zakresie. Zastąp token bootstrap w ustawieniach nowym tokenem serwisowym.
6. Unieważnij token bootstrap i zmień początkowe hasło administratora.

W istniejącej, zainstalowanej aplikacji dostęp do ekranu przełączenia ma lokalny administrator (`admin.access`). Po przełączeniu wymagane jest backendowe `settings.update`. Awaria backendu nie powoduje powrotu do lokalnej autoryzacji. W razie błędnego adresu uprawniony administrator serwera może poprawić `config/backend.json` lokalnie (0600), bez ujawniania tokena w HTML.

Konfiguracja może również pochodzić ze zmiennych `CP_BACKEND_URL`, `CP_BACKEND_TOKEN` oraz `CP_BACKEND_CA_FILE`. Zmienne mają pierwszeństwo przed plikiem i muszą być zarządzane przez administratora serwera. Plik `config/backend.json` znajduje się poza publicznym webroot i jest blokowany także przez reguły `.htaccess` instalacji w katalogu głównym.

## Autoryzacja i UI

Każde żądanie portalu odczytuje aktualny profil, role i permissions przez `/auth/me`. BackendSession przechowuje opaque access/refresh token w sesji serwera PHP, automatycznie rotuje refresh i nie wysyła go do JavaScript. InfrastructureBackendClient **nigdy nie zastępuje tokena użytkownika tokenem serwisowym**. Wspólny token służy testom połączenia; zasoby są pobierane z tożsamością użytkownika.

Administracja: użytkownicy, role, permissions, tokeny, audit. Infrastruktura: stan komponentów, połączenia/odkrywanie Proxmox, credentiale, szablony, deploymenty, zadania, logi i kontrolowane playbooki Ansible. Formularze wysyłają granularne create/update/delete; nie ma osobnej bazy RBAC w PHP.

Credentiale są write-only: formularz edycji nie otrzymuje sekretów z backendu. Puste pola zachowują wartość; zmiana adresu/identity/TLS wymaga ponownego podania kompletu. Sekret zapisany w backendzie nie trafia do sesji, kodu strony ani logów portalu. Token tworzony przez administratora jest wyświetlany jednorazowo i usuwany z DOM po zamknięciu okna.

Operacje provisioning używają `Idempotency-Key` UUID. Ten sam formularz zachowuje klucz podczas ponowienia nieudanego requestu. UUID `X-Request-ID` jest przekazywany do API i widoczny w zadaniach/audycie. Frontend nie wykonuje shell, Terraform ani Ansible, nie przechowuje state.

## Przełączenie istniejącej instalacji

Tryb centralny blokuje stare trasy aplikacji, lokalny worker oraz lokalny console gateway. Dawne konta/sesje/RBAC nie dają dostępu do centralnego backendu. Lokalna baza nie jest kasowana ani automatycznie importowana. Przed przełączeniem przenieś potrzebne konta/role/połączenia do backendu i zabezpiecz archiwum starej bazy i state. Dawne projekty, IPAM i konsola VM pozostają funkcjami starego trybu i nie są migrowane przez ten PR. Istniejące VM nie stają się automatycznie zarządzanymi deploymentami Terraform.

## Testy

`tests/Unit/InfrastructureBackendClientTest.php` sprawdza tożsamość użytkownika, brak fallbacku do service token, nagłówki korelacji/idempotencji, 403, nieprawidłowe odpowiedzi, niedostępnego workera, HTTPS URL i uprawnienia pliku konfiguracji. Zmiany API wymagają równoczesnej aktualizacji tego klienta, formularzy i testów w obu repozytoriach.
