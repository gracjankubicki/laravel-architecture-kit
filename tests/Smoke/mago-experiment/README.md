# Eksperyment z parserem Mago

Mago 1.57.0 zbiera zgodne fakty składniowe z `app/` CRM i Glenross około
8–9 razy szybciej niż adapter nikic/php-parser. To wynik małego, oddzielnego
eksperymentu na macOS ARM64 z 15 października 2026. Aktywna instalacja Architecture
Kit, MCP i hooki Codexa nie używają tego helpera.

## Wynik trzech prób

| Repo | Pliki PHP | Parser PHP, sekundy | Mago, sekundy | RSS PHP | RSS Mago |
|---|---:|---|---|---|---|
| CRM | 6657 | 4,576 / 4,623 / 4,501 | 0,554 / 0,560 / 0,572 | 40,5–40,6 MiB | 13,4–13,6 MiB |
| Glenross | 2045 | 1,809 / 1,828 / 1,722 | 0,198 / 0,205 / 0,202 | 43,1–43,2 MiB | 16,7–17,3 MiB |

CRM ma 104435 faktów i 283 dynamiczne nazwy; Glenross ma 43506 faktów
i 140 dynamicznych nazw. Wszystkie fakty obu adapterów są zgodne, z uwzględnieniem
duplikatów i położeń. Oba parsery nie zgłosiły błędów składni w tych wejściach.
Manifesty przed i po pomiarach są zgodne. Zbadano 13125067 bajtów CRM
oraz 4748320 bajtów Glenross.

Mediany dają przyspieszenie 8,17 razy dla CRM i 8,96 razy dla Glenross.
Oszczędność tego etapu wynosi odpowiednio około 4,02 s i 1,61 s.
Nie jest to pomiar pełnego audytu. Przykładowo, gdyby pełny audyt trwał 20 s,
a dokładnie ten etap zajmował w nim 4,58 s, jego przyspieszenie do 0,56 s
skróciłoby całość do około 15,98 s. Rzeczywisty audyt ma cache, równoległość
i inne operacje, więc wymagałby osobnego sprawdzenia po integracji.

## Wspólny interfejs

Oba adaptery otrzymują na standardowym wejściu JSON Lines. Każda linia jest
bezwzględną ścieżką jednego pliku, zapisaną jako ciąg JSON. Zwracają jedną linię
JSON na plik, w tej samej kolejności:

```json
{"path":"/snapshot/app/Example.php","facts":[{"kind":"method-call","start":80,"end":84,"name":"save","owner":12,"receiver":[70,78]}],"errors":[]}
```

- `kind` obejmuje klasy, klasy anonimowe, interfejsy, traity, enumy, metody,
  wywołania funkcji i metod, wywołania nullsafe i statyczne, tworzenie obiektów
  oraz tworzenie callable przez `...`.
- `start` i `end` to zakres bajtów nazwy lub dynamicznego wyrażenia.
  Początek jest włączony, koniec wyłączony. Nie są to kolumny znaków Unicode.
- `name` jest nazwą zapisaną w kodzie. Dynamiczne wyrażenia mają `@dynamic`.
  `self`, `static` i `parent` zachowują zapis. Nie rozstrzygamy ich celu runtime.
- `owner` to pozycja słowa deklarującego otaczającą klasę, trait, enum lub
  interfejs, albo `null`. Zagnieżdżona klasa anonimowa otrzymuje własnego właściciela.
- `receiver` wskazuje zakres odbiorcy metody lub klasy wywołania statycznego.
  Np. dla `$this->service->save()` wskazuje `$this->service`. Nie odgaduje typu.
- Klasa anonimowa ma nazwę `@anonymous`. Jej utworzenie reprezentuje deklaracja
  `anonymous`; nie dodajemy drugiego faktu `new` dla tej samej klasy.
- `errors` zawiera jawne diagnostyki konkretnego parsera. Błędy lub różnice
  uniemożliwiają uznanie rezultatów za równoważne, nawet jeśli czas jest krótszy.

Mago zachowuje nawiasy i klamry, które nikic pomija w wyrażeniach.
Adapter Mago usuwa te otoczki na podstawie węzłów składni, zachowując zakres
samego wyrażenia. Fixture obejmuje te różnice oraz `self`, `static`, `parent`,
atrybut z `::class`, wywołania dynamiczne, callable i błędną składnię.
Argumenty konstruktora klasy anonimowej zachowują właściciela zewnętrznego,
a jej metody i ich wywołania otrzymują właściciela klasy anonimowej.

Nie zbieramy FQCN, rozwiązanych importów, sygnatur, powiązań dziedziczenia,
grafu wywołań ani znaczenia Laravela. Parser PHP jest tą samą biblioteką
nikic/php-parser co w paczce, ale ten adapter nie wykonuje `FileContext`,
`NameResolver` ani reguł audytu. Helper Rust nie zwraca obiektów PhpParser.

## Co obejmuje pomiar

Każdy adapter działa w jednym nowym procesie na całe repo. Czas obejmuje start,
odczyt plików, parsowanie, ekstrakcję, zapis transportu JSON oraz jego odczyt
i dekodowanie przez runner. Porównanie i przygotowanie kopii wejść są poza pomiarem.
Nie wliczamy pobrania toolchainu i kompilacji. Pierwsza kompilacja release helpera
z zależnościami trwała 29,44 s. Zależności są przypięte w `Cargo.lock`.
Użyto nikic/php-parser 5.7.0 i PHP 8.5.10. W `results.json` zapisujemy również
wersję PHP, listę rozszerzeń, ścieżkę konfiguracji i limit pamięci.

RSS pochodzi z macOS `/usr/bin/time -l` i dotyczy procesu adaptera. To pamięć
rezydentna, nie sama sterta PHP. Runner Python i jego pamięć dekodowanych wyników
nie są objęte tą liczbą. Stertę PHP zapisujemy dodatkowo jako
`php_allocator_peak_bytes`, bez porównywania jej z RSS Rust.

Trzy próby startują naprzemiennie od PHP i Mago. Nie czyścimy cache systemu
operacyjnego i nie nazywamy ich zimnymi przebiegami. Brak cache analizy w adapterach.
Kod aplikacji nie jest wykonywany. Odczytujemy tylko zwykłe pliki `.php` w `app/`;
pomijamy dowiązania symboliczne. Kopie oraz wyniki pozostają pod ignorowanym
`build/mago-experiment/`. Nie czytamy katalogu `vendor` badanych aplikacji.

## Odtworzenie

Wymagane są zależności Composer tej paczki, PHP CLI, Python 3.10+, macOS
oraz Rust 1.99.0. Runner domyślnie wskazuje lokalne CRM i Glenross; można podać
własne ścieżki przez `--repos`. Nie instaluje ani nie przełącza produkcyjnej paczki.

Przy dostępnej instalacji Cargo:

```sh
CARGO_TARGET_DIR="$PWD/build/mago-experiment/target" cargo build --release --locked --manifest-path tests/Smoke/mago-experiment/rust/Cargo.toml
python3 tests/Smoke/mago-experiment/run.py --fixtures
python3 tests/Smoke/mago-experiment/run.py --repos /path/to/crm /path/to/glenross
composer lint -- tests/Smoke/mago-experiment/php.php
```

W tym eksperymencie toolchain jest lokalny, poza globalnym PATH. Zamiast `cargo`
można użyć następującego polecenia:

```sh
CARGO_HOME="$PWD/build/mago-experiment/cargo" RUSTUP_HOME="$PWD/build/mago-experiment/rustup" CARGO_TARGET_DIR="$PWD/build/mago-experiment/target" build/mago-experiment/cargo/bin/cargo build --release --locked --manifest-path tests/Smoke/mago-experiment/rust/Cargo.toml
```

Nie uruchamiaj runnera równolegle z drugim przebiegiem w tym samym katalogu
wyników. Nazwy repo w pojedynczym przebiegu muszą być różne. Nie modyfikuj
wejściowych aplikacji podczas kopiowania lub pomiaru. Zmiana manifestu unieważnia wynik.
Nie publikuj kopii aplikacji ani surowych wyników bez sprawdzenia ich zawartości.

## Mapa zmiany i dowodów

`run.py` przygotowuje identyczne wejścia, mierzy adaptery i porównuje fakty.
`php.php` zbiera fakty z nikic. `rust/src/main.rs` zbiera je z Mago.
Żaden plik `src/` paczki nie został zmieniony przez ten eksperyment.

| Kryterium | Dowód lokalny | Wynik |
|---|---|---|
| Wspólny format i istotne przypadki | `build/mago-experiment/fixture.log`, `fixture-comparison.json` | PASS |
| Identyczne pliki, bez wykonywania aplikacji | manifesty CRM/Glenross, `input_unchanged` w `results.json`; kod adapterów | PASS |
| Jawne różnice i błędy | fixture błędnej składni; porównanie wszystkich faktów w `results.json` | PASS |
| 3 próby każdego adaptera na repo | `results.json`, 12 logów i plików `.status.json`, `benchmark.log` | PASS |
| Poprawny format PHP | `build/mago-experiment/lint.log`, exit 0 | PASS |
| Niezależne review i brak aktywacji | raport końcowego review w Cairn; pełny zakres i delta v2, READY | PASS |

Target State i plan są w Cairn, cel `13D30072-4A7B-490A-A3D3-F1FEE8AE05C4`.
Wcześniejszy cel pełnego audytu do 20 s nie jest zamykany przez ten eksperyment.

## Źródła i ograniczenie wniosku

- [Mago 1.57.0](https://github.com/carthage-software/mago/releases/tag/1.57.0)
- [API parsera](https://github.com/carthage-software/mago/blob/1.57.0/crates/syntax/src/parser/mod.rs)
- [Przechodzenie po CST](https://github.com/carthage-software/mago/blob/1.57.0/crates/syntax/src/cst/node.rs)
- [Licencja MIT](https://github.com/carthage-software/mago/blob/1.57.0/LICENSE-MIT)

Wynik uzasadnia rozważenie Mago jako silnika ekstrakcji faktów. Nie dowodzi,
że można podmienić parser w obecnym kodzie bez dostosowania konsumentów ani
że dowolne repo będzie równie szybkie. Zebrany kontrakt jest znacznie mniejszy
niż informacje potrzebne do wszystkich reguł Architecture Kit.
