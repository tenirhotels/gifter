# Шрифты витрины

Montserrat (лицензия OFL) — вспомогательный шрифт брендбука TENIR для подписей и кнопок.
Положите сюда файлы (можно скачать с fonts.google.com/specimen/Montserrat → «Get font» или google-webfonts-helper):

- `montserrat-latin.woff2` — латиница (переменный шрифт, веса 400–600)
- `montserrat-cyrillic.woff2` — кириллица (казахские буквы — в расширенной кириллице, «cyrillic-ext»; при желании добавьте `montserrat-cyrillic-ext.woff2`)

Пока файлов нет, витрина использует запасные шрифты, ничего не ломается. Gill Sans — основной шрифт брендбука — платный; веб-версию можно подключить, получив лицензию (файл `gillsans.woff2` + правка `--sans` в `resources/views/shop/layout.blade.php`).
