# 🏷️ Generator etykiet produktów z pliku CSV

Prosta aplikacja webowa w **PHP** do wyszukiwania produktów z pliku **CSV** i generowania **etykiet z kodem kreskowym** do druku na kartce A4.

---

## ✨ Funkcje

* 📂 Wczytywanie pliku CSV z danymi produktów
* 🔍 Wyszukiwanie po **referencji** lub **fragmencie nazwy**
* 🔢 Obsługa wielu zapytań naraz
* 🧾 Generowanie etykiet z kodem kreskowym (Code 128 – SVG)
* 🖨️ Automatyczne okno wydruku
* 📄 Układy etykiet:

  * 1 etykieta na A4
  * 2 etykiety na A4
  * 4 etykiety na A4
  * 3 etykiety (paski pionowe)
* 💾 Dane zapisywane w sesji

---

## 📋 Wymagania

| Wymaganie    | Wersja                        |
| ------------ | ----------------------------- |
| PHP          | 7.4+                          |
| Przeglądarka | Chrome / Edge / Firefox       |
| Serwer       | Apache / Nginx / XAMPP / WAMP |

---

## 📁 Format pliku CSV

Plik musi zawierać nagłówki:

```
Referencja,Nazwa referencji,Nazwa dostawcy,Gama
```

### Przykład pliku CSV

```
Referencja,Nazwa referencji,Nazwa dostawcy,Gama,Cena standardowa
ABC123,Produkt testowy,Dostawca A,Gama 1,12.50
XYZ789,Inny produkt,Dostawca B,Gama 2,9.99
```

### Obsługiwane separatory:

* przecinek `,`
* średnik `;`
* tabulator
* `|`

### Kodowanie:

* UTF-8 (zalecane)
* ANSI

---

## 🚀 Instalacja

1. Skopiuj plik `index.php` na serwer
2. Uruchom serwer PHP:

```bash
php -S localhost:8000
```

3. Otwórz w przeglądarce:

```
http://localhost:8000
```

---

## 🧑‍💻 Jak używać

1. Wgraj plik CSV
2. Wpisz referencje lub nazwy produktów, np:

   ```
   ABC123 XYZ789 śruba M8
   ```
3. Wybierz układ etykiet
4. Kliknij **„Otwórz etykiety w nowej zakładce”**
5. Wydrukuj

---

## 🏷️ Co zawiera etykieta

* 📅 Data
* 🏭 Dostawca
* 📦 Gama
* 📊 Kod kreskowy
* 🔢 Referencja
* 📝 Nazwa produktu

---

## 🔒 Bezpieczeństwo

Aplikacja:

* sprawdza rozszerzenie pliku `.csv`
* limit pliku: **10 MB**
* używa `htmlspecialchars()`
* nie zapisuje plików na stałe
* działa bez bazy danych

---

## 📝 Licencja

Do użytku prywatnego i firmowego. Można dowolnie modyfikować.

---
