# SimPay – płatności online dla OpenCart (v4.x)

Moduł **SimPay** umożliwia prostą realizację płatności online w sklepach opartych o system **OpenCart 4**.  
Integracja jest w pełni osadzona w procesie zakupowym (checkout), z automatycznym przetwarzaniem zwrotnych komunikatów o statusie transakcji (IPN / Webhook).

---

## Spis treści

- [Cechy](#cechy)
- [Wymagania](#wymagania)
- [Instalacja](#instalacja)
- [Aktualizacja](#aktualizacja)
- [Konfiguracja](#konfiguracja)
- [Znane problemy](#znane-problemy)
- [Wsparcie](#wsparcie)

---

## Cechy

Moduł dodaje do OpenCart 4 obsługę płatności SimPay oraz umożliwia m.in.:

- płatności osadzone w ścieżce zamówienia,
- przekierowanie klienta do w pełni zabezpieczonej bramki SimPay,
- automatyczna zmiana statusu zamówienia w oparciu o odbierane powiadomienia (Webhook),
- logowanie operacji i powiadomień do wbudowanego pliku logów w OpenCart (system/storage/logs/simpay.log),
- wbudowane powiadomienia o dostępności nowej wersji wtyczki bezpośrednio w Panelu Administracyjnym,
- projekt w pełni zoptymalizowany pod nowoczesną architekturę i standardy platformy OpenCart 4 (Event-driven & Native Models).

---

## Wymagania

- Minimalna wersja OpenCart: **4.0.0.0**
- PHP: zgodne z wymaganiami Twojej wersji OpenCart (zalecane PHP 8.0+)
- Dostęp do panelu SimPay w celu pobrania danych integracyjnych (Service ID, Bearer Token, IPN Signature Hash).

> **Uwaga dotycząca bezpieczeństwa oraz kompatybilności**  
> Moduł **SimPay został napisany i zoptymalizowany wyłącznie dla OpenCart 4.x**.  
> Wersje starsze (OpenCart 3.x, 2.x) ze względu na przestarzałą strukturę kontrolerów i frameworka **nie są i nie będą obsługiwane** z poziomu tej paczki.

---

## Instalacja

### Instalacja przez Panel Administratora (Zalecane)

1. Pobierz najnowszą paczkę modułu `simpay.ocmod.zip` z sekcji **Releases**.
2. Zaloguj się do panelu administracyjnego OpenCart.
3. Przejdź do: **Extensions → Installer** (Rozszerzenia → Instalator).
4. Kliknij przycisk **Upload** (Prześlij) i wskaż pobrany plik `simpay.ocmod.zip`.
5. Po udanym przesłaniu przejdź do: **Extensions → Extensions**.
6. Z rozwijanej listy wybierz kategorię **Payments** (Płatności).
7. Znajdź na liście **SimPay.pl Przelewy / BLIK** i kliknij zielony przycisk **Install** na wysokości nazwy wtyczki.

---

## Aktualizacja

1. Przejdź do zakładki **Extensions → Installer** w Panelu Administratora.
2. Usuń stare archiwum rozszerzenia SimPay za pomocą przycisku kosza (jeśli jest).
3. Wgraj nową paczkę `simpay.ocmod.zip` tak samo jak w punkcie Instalacja.
4. Przejdź do **Extensions → Extensions → Payments**, wejdź w konfigurację upewniając się, że ustawienia zachowały się poprawnie, a pliki wtyczki zostały pomyślnie zaktualizowane.

---

## Konfiguracja

W konfiguracji modułu przejdź do zakładki **Rozszerzenia** (Payments), odszukaj SimPay i kliknij ikonę ołówka (Edytuj).  
Uzupełnij odpowiednie dane powiązane z usługą SimPay:

- **Hasło / Bearer Token** – Z zakładki Konto Klienta > API w Panelu SimPay.
- **ID usługi** – Z zakładki Płatności online > Usługi w Panelu SimPay.
- **Klucz sygnatury IPN (Service Hash)** – Ustawiony w opcjach Usługi w celu walidacji powiadomień transakcyjnych.
- **Status zamówienia przy opłaconej transakcji** – Wybierz na jaki status OpenCart ma przechodzić uiszczone zamówienie (domyślnie to `Processing` lub `Complete`).
- **Status modułu** – Pamiętaj, aby opcja była oznaczona na Włączone (Enabled), żeby metoda pojawiła się w checkoucie.
- **Kolejność sortowania (Sort Order)** – Pozwala pozycjonować SimPay względem innych opcji zapłaty.

### Adresy komunikacji (Webhook / IPN)
Widoczny na froncie pliku konfiguracyjnego błękitny baner zawiera adres URL Webhooka, np.:  
`https://twoja-domena.pl/index.php?route=extension/simpay/payment/simpaywebhook`

Musisz skopiować ten adres i wkleić w odpowiednie pole adresu IPN w Panelu Usługi w platformie SimPay.

---

## Znane problemy

- **Niestandardowe moduły w koszyku**. Moduły takie jak (Journal3 czy inne nakładki na One Page Checkout) mogą mieć własny bufor zapisu z opcjami płatności. Zazwyczaj działają poprawnie na starcie, ale w przypadku ich używania, sprawdź, czy metoda SimPay płynnie się doczytuje.
- Sprawdź logi (dostępne w Panelu OC > **System → Maintenance → Error Logs**) z dopiskiem `SimPay Error:` jeżeli występuje jakikolwiek problem po stronie komunikacji IPN.  
- Jeśli sklep korzysta chociaż częściowo z certyfikatów bez szyfrowania SSL na etapie developmentu lub zawiera `.htpasswd` - Webhook IPN SimPay może nie móc dotrzeć z weryfikacją płatności.



