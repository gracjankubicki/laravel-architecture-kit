# Watch runtime 0.1.0

Osobny helper `architecture-kit-runtime` obserwuje pliki przez notify **8.2.1**.
Nie analizuje źródeł, nie uruchamia PHP aplikacji i nie zależy od parsera Mago.
PHP łączy go z wybranym backendem. Użytkownik otrzymuje gotowe binarium;
Rust jest potrzebny tylko do przygotowania wydania.

## Integracja koordynatora (W04)

1. `RuntimeInstallation($externalToolHome)->binary()` odczytuje instalację i sprawdza
   przypięty manifest, platformę, granice, długość i SHA bez wykonania pliku.
   Brak instalacji to `native_watch_unavailable`; konsument wybiera jawne skanowanie
   w tle i podaje powód. Zapytanie nie wywołuje `install()`.
2. Kontroler przekazuje do `WatchBoundary` listę **kanonicznych dopuszczonych**
   korzeni źródeł i osobnych plików konfiguracji/Composer. Lista pochodzi z istniejącej
   polityki zakresu, profilu i dziedziczenia; obserwator jej nie odkrywa ani nie rozszerza.
   Nie przekazuj całego repo zamiast wybranego zakresu. Aliasy symlinków muszą zostać
   rozstrzygnięte przez istniejącą politykę przed przekazaniem dopuszczonej tożsamości.
3. `WatchClient($binary, $boundary)->start()` ponownie weryfikuje plik, wykonuje
   ograniczone czasem `--version`, następnie odbiera handshake **bez korzeni repo**.
   Dopiero zgodność wersji, platformy i capabilities pozwala wysłać `WatchRoots`.
   `start()` kończy się po zarejestrowaniu watch i odebraniu `watch-ready`.
4. Dopiero teraz zaczyna się skan/restore. `bootstrapEvents()` oddaje ograniczony
   bufor zdarzeń z końca rejestracji; `next($seconds)` oddaje kolejne `WatchEvent` lub
   `null` przy braku danych. Kontroler rozlicza je w swoim pending queue i zachowuje
   zleconą pracę niezależnie od połączenia klienta MCP. `barrier()` porządkuje tylko IPC;
   **nie opróżnia kolejki OS i nie potwierdza treści**.
5. `WatchSession` zaczyna z `rescanRequired=true`. Utrata, luka, błędny protokół lub
   restart pozostają jawne. Po własnej pełnej kontroli treści i rozliczeniu odebranego
   prefiksu kontroler woła `acknowledgeReconcile($sessionId, $observedSequence)`.
   Ack wymaga gotowej żywej sesji i dokładnie aktualnej sekwencji; obcy/stary prefix
   daje `false`. Zdejmuje tylko żądanie pełnego reconcile i ustawia health `observing`.
   Nie daje `content_current`, gwarancji flush OS ani tożsamości wszystkich plików
   w chwili zapytania. Kolejny loss/gap znów wymaga reconcile. W04 nadal odpowiada
   za pending/applied sequence, publikację, ConfirmationState i okresowe pełne skany.
6. Koordynator posiada obiekt `WatchClient`, a nie pojedyncze żądanie MCP. Rozłączenie
   klienta MCP nie wywołuje `close()` i nie usuwa jego przyjętej pracy. Jawne `close()`
   kończy własny helper, opróżnia prywatne pipe'y i reapuje proces; nie jest operacją
   zapisu trwałej kolejki. Śmierć koordynatora wymaga nowej sesji i reconcile po restarcie.
   Żadne API cleanup nie przyjmuje PID. Zatrzymywany jest wyłącznie własny `proc_open`.

W01 nie podłącza obserwatora do automatycznej mapy. Powyższe punkty integracji oraz
trwała praca/ponowne połączenie odbiorcy należą do W04.

## Protokół i granice zasobów

Transport to prywatne pipe'y stdin/stdout helpera. Nie jest to stdout MCP.
Handshake ma dokładnie pola z `shared-contracts.md`, protocol
`architecture-kit-watch-v1`, runtime_version `0.1.0`, capabilities `events-v1` i
`continuity-loss-v1`. Session to 32 losowe bajty zapisane w hex. Handshake zaczyna
sekwencję od stringa `"1"`; każde wyjście WatchEvent zwiększa ją bez wrap do
`"9223372036854775807"`. Wyczerpanie kończy sesję i wymaga restartu/reconcile.

WatchEvent `kind` to `watch-ready`, `changes`, `loss` albo `barrier`; health to
`observing` lub `unverified`. Każdy loss ma reason i rescan_required. Ścieżki to
base64 surowych bajtów OS (`paths_encoding=base64-os-bytes`), bez stratnej konwersji
UTF-8. PHP sprawdza base64, NUL, długość, absolutną ścieżkę, symlinki i zakres przed
udostępnieniem ścieżki do odczytu. Ancestor event oznacza zmianę zakresu, nie zgodę
na czytanie całego rodzica. Linux wspiera nie-UTF-8 nazwy; macOS odrzuca nielegalne
sekwencje przy tworzeniu pliku. Spacje, Unicode i newline nie rozbijają ramek.

Ramka z newline ma maksimum 65 536 bajtów, surowa ścieżka 16 384 bajty. Nadmiar
zdarzenia daje bezpieczny rescan zamiast ucięcia. Kolejka zatrzymuje do 64 zdarzeń,
64 ścieżek oraz 1 MiB zakodowanych danych i payloadów, mniejszy limit wygrywa.
`ARCHITECTURE_KIT_WATCH_QUEUE_EVENTS=1..64` i
`ARCHITECTURE_KIT_WATCH_QUEUE_BYTES=1..1048576` mogą obniżyć nastawy.
Loss ma osobne ograniczone miejsce poza tą kolejką, więc nie ginie w overflow.
Bufor startowy PHP ma również limit 64 zdarzeń/ścieżek i 1 MiB.

Rekurencyjne subskrypcje obejmują tylko jawne istniejące korzenie katalogów.
Rodzice plików, zastępowanych i brakujących korzeni są obserwowani nierekurencyjnie.
Plan usuwa pokrywające się subskrypcje. Pętla kontroluje tożsamość dev/inode;
po zmianie korzenia lub pojawieniu kolejnego brakującego segmentu najpierw zgłasza
`watch_scope_changed`, potem odnawia subskrypcje. Luka rejestracji jest pokryta
obowiązkiem reconcile. Rename nie wymaga sparowania; odbiorca sprawdza obecny stan.
Linux ma wyłączone śledzenie symlinków w notify; zdarzenie unsafe symlink daje loss.

Wyłącznie `ARCHITECTURE_KIT_WATCH_TEST_MODE=1` pozwala na komendę `simulate-loss`.
Próba przepuszcza syntetyczny notify Rescan przez produkcyjną konwersję i dodaje
`synthetic=true`; zwykły klient odrzuca to dodatkowe pole. Jest to **syntetyczna utrata
OS**, odrębna od rzeczywistego testu przepełnienia własnej kolejki.

## Instalacja i dystrybucja

`architecture-kit runtime install` pobiera tylko przypięte wydanie
`runtime-v0.1.0` z manifestem schema=1. `runtime status` niczego nie instaluje.
Przed lokalną publikacją pliku instalator sprawdza manifest/bytes/SHA/boundary,
potem `--version` i root-free handshake. Błąd/przerwany zapis nie daje gotowej
instalacji. Granice prywatnego home i blokada publikacji pochodzą z `StateStore`.

Workflow `runtime.yml` buduje i testuje osobno macOS ARM64/x64 i Linux ARM64/x64.
Linux używa statycznego musl i kontroluje pomyślny odczyt readelf bez INTERP.
macOS zachowuje domyślny deployment target istniejącego toolchainu 1.99.0, jak
engine.yml; CI zapisuje rzeczywiste load commands. Natywne próby odbywają się na
macOS 15 i Ubuntu 24.04, bez deklaracji uruchomienia na starszych OS.
`release-manifest.py` wymaga wszystkich czterech artefaktów i zgodnych descriptorów.
Workflow przygotowuje pliki; nie publikuje wydania.

## Sprawdzenie lokalne

Używaj istniejącego toolchainu zgodnie z handoffem środowiska, bez globalnego upgrade.

```sh
cargo test --manifest-path runtime/Cargo.toml --locked
cargo build --manifest-path runtime/Cargo.toml --locked --release
ARCHITECTURE_KIT_TEST_RUNTIME=/absolute/runtime/target/release/architecture-kit-runtime \
  composer test:filter -- 'WatchProtocolTest|WatchBoundaryTest|RuntimeDistributionTest|WatchBootstrapTest'
composer lint -- src/Standalone/Watch src/Standalone/Distribution src/Standalone/Runner.php tests/Unit/Standalone/Watch tests/Integration/Runtime
composer stan -- src/Standalone/Watch src/Standalone/Distribution src/Standalone/Runner.php
```

Bez wskazanego binarium testy natywne jawnie zgłaszają brak dowodu. W CI helper jest
obowiązkowym wynikiem wcześniejszego buildu. Pełne lokalne logi i wersje tej realizacji
są w ignorowanym `runtime/evidence/`; odbiór czterech platform, QA i integracja W04
pozostają osobnymi krokami.
