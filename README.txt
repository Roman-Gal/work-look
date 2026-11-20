WorkLook — приватний AI-агент пошуку роботи
Стан на 2026-11-20: локальний MVP та ізольована демоверсія на MyDevil MD2.
Демостенд: https://rh-additional.usermd.net/ . Справжні API-ключі та користувацькі дані не переносилися.
Дані нового тестового акаунта — тільки у локальному ігнорованому var/deploy/mydevil-demo-access.txt.
Поточне розміщення, перевірки та режим очікування ботів: docs/mydevil-deployment.txt.

ЩО ВЖЕ ПРАЦЮЄ

Symfony 7.4 LTS, Twig, Symfony UX Turbo/Stimulus, AssetMapper без Node build.
Основний шрифт у всьому інтерфейсі та PDF CV — Zakon Text, чотири оригінальні TTF
накреслення локально в assets/fonts/zakon/. Підтримку українських, польських та англійських
літер перевірено. Джерело, автори й контрольні суми: assets/fonts/zakon/NOTICE.txt.
CSS @font-face використовує font-display:swap; відвідувачу не потрібно встановлювати шрифт.
CvPdfRenderer вбудовує шрифт у PDF; кеш метрик у var/pdf-fonts/ (каталог має бути writable).
Жодних CDN або зовнішніх font-запитів при перегляді/генерації документа немає.
Інтерфейс українською, польською та англійською; перемикач UA / PL / EN на вході й у кабінеті.
Видиме UA відповідає внутрішній локалі uk у Symfony та атрибуті HTML lang.
Українська — стандартна. Вибір зберігається cookie у цьому браузері на рік, без зміни БД.
Переклади: translations/messages.uk.yaml, messages.pl.yaml, messages.en.yaml;
Twig використовує Symfony Translator, форми автоматично перекладають labels/help/placeholders.
Новий запуск зберігає ui_locale у profileSnapshot; агент отримує інструкцію відповідати
цією мовою. Уже збережені AI-пояснення та оригінальні описи вакансій не перекладаються
при перемиканні UI. Текст фактів CV завжди копіюється без змін; перекладаються тільки
заголовки шаблону PDF. LocaleSubscriber перевіряє cookie за allowlist uk/pl/en.
POST /language/{locale} захищений CSRF і повертає лише на відомі локальні сторінки.
Додавання тексту: один стабільний message_id та переклад у кожному з трьох YAML-файлів.
Довідка Symfony Translator: https://symfony.com/doc/7.4/translation.html
Приватний вхід, профіль із канонічними фактами CV, кнопка запуску, Messenger-черга,
сторінка прогресу, пояснення відповідності, статуси вакансій і приватне PDF CV.
Агент сам обирає пошукові запити й інструменти в межах вибраних джерел.
Пошук запускається користувачем. Немає фонового розкладу пошуку чи автовідгуків.

Демонстрація — окремий режим із явно вигаданою вакансією й умовною оцінкою.
У ньому немає викликів моделі або зовнішніх job API. Він перевіряє чергу та UI.
Справжній модельний цикл реалізовано, але платний end-to-end запуск без API-ключа
не проводився. Протокол function calling перевіряється із MockHttpClient.

ЛОКАЛЬНИЙ ЗАПУСК У WINDOWS

Команди нижче виконуються з каталогу work-look. PHP 8.2+; рекомендовано актуальний
patch PHP 8.4. На цьому ПК php.exe розташований у C:\Php82\php.exe.
MySQL Workbench є клієнтом адміністрування; він сам не замінює сервер MySQL.
Локально використовується SQLite (файл var/worklook_dev.db), тому Workbench не потрібний.
PDO SQLite, mbstring, XML/DOM/SimpleXML і залежності перевіряє Composer/runtime.

Після нового копіювання репозиторію:
  composer install
  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
Збережіть згенероване значення як APP_SECRET у .env.local (цей файл ігнорується Git).
  php bin/console doctrine:migrations:migrate --no-interaction
  php bin/console app:user:create your@email.example
Пароль вводиться приховано; CLI створює довірений підтверджений акаунт.
Email-реєстрація: /register; налаштування та правила — docs/email-registration.txt.

Два окремі термінали PowerShell:
  C:\Php82\php.exe -S 127.0.0.1:8000 -t public tools/router.php
  C:\Php82\php.exe bin/console messenger:consume async --time-limit=3600 --memory-limit=128M --no-interaction

Відкрити http://127.0.0.1:8000/ . Обидва процеси потрібні: без worker запуск лишається
у черзі. Worker завершується після однієї години (після поточного повідомлення);
повторіть його команду. Ctrl+C завершує процес у відповідному терміналі.
Для вже підготовленого локального кабінету дані входу збережено лише у
var/local-access.txt. Цей файл, база, логи й .env.local не потрапляють у Git.
Тестові демонстрації у локальній БД не є справжніми результатами пошуку роботи.

ПІДКЛЮЧЕННЯ AI

У .env.local додайте:
  OPENAI_API_KEY=ваш_ключ
  OPENAI_MODEL=gpt-6.1-terra
Після зміни env або коду перезапустіть worker. Заповніть профіль справжніми фактами,
побажаннями та пошуковими словами, виберіть AI-пошук і натисніть «Почати пошук».
Ключ не вводиться у вебформу і не має зберігатися в чаті/репозиторії.

OpenAI Responses API отримує текст запиту, побажання, заголовок і факти CV, а також
потрібні описи вакансій. Окремі поля імені та контактів не передаються інструментом
get_my_profile; якщо вписати персональні дані у факти/запит, вони також потраплять у API.
Запити мають store=false; це не обіцянка відсутності всіх provider-side журналів.
Модель та доступність залежать від вашого API-акаунта; модель задається через env.
Офіційні джерела, перевірено 2026-11-20:
  https://developers.openai.com/api/docs/models
  https://developers.openai.com/api/docs/guides/function-calling
  https://developers.openai.com/api/docs/guides/reasoning
  https://developers.openai.com/api/docs/guides/your-data

Межі запуску: 12 звернень до моделі, 24 виклики інструментів, 4 пошукові запити,
10 вакансій на сторінку; SOLID сторінки 0–2, RSS лише сторінка 0.
Після отриманої відповіді контролюються сумарні 60000 токенів; між кроками — 300 секунд.
Один останній API-виклик може перевищити ці межі. Це не точний ліміт вартості в грошах.
Output максимум 3500 токенів/відповідь; timeout моделі до 60 секунд.
Після «Зупинити» поточний HTTP-виклик може завершитися; зупинка перевіряється між кроками.
Queued-запуск теж потребує працюючого worker для обробки запиту на зупинку.
Автоматичних повторів платного запуску немає; повторна доставка завершеної задачі
не запускає її знову. Після аварійного знищення процесу статус running може залишитися
до ручного відновлення адміністратором; автоматичного crash recovery у цьому MVP немає.

ДЖЕРЕЛА

Реалізовано й перевірено реальними одноразовими GET 2026-11-20:
  SOLID.Jobs — офіційний JSON API, включені описи, зарплати з валютою/періодом/контрактом.
  DOU — публічний RSS, останнє вікно вакансій, скорочені дані.
  Djinni — публічний RSS, останнє вікно вакансій, скорочені дані.
RSS не гарантує повного охоплення чи права працювати з Польщі. Невідомі поля лишаються
невідомими. Опис get_job_details читає вже отримані дані, не парсить HTML-сторінку.
Для RSS-only записів генерація CV заблокована, бо немає повного опису.
SOLID completeness=full — поточна евристика: inline description щонайменше 300 символів;
це потребує подальшої перевірки на ширшій вибірці, а не гарантія повноти всіх записів.
GET кешуються на годину; HTTPS allowlist, без редиректів, максимум 3 MB, timeout 25 секунд.
429 та зміни схеми показують помилку; нескінченних retry або обходу захисту немає.
JustJoinIT, NoFluffJobs, Pracuj, Work.ua й інші досліджені, але не підключені до MVP.
Повний огляд, посилання та локальні HTTP-докази: docs/research/02-job-sources.txt.

Ручна діагностика (не розклад і не скрапер):
  php bin/console app:sources:check solidjobs PHP
  php bin/console app:sources:check dou PHP
  php bin/console app:sources:check djinni PHP

CV І МЕЖІ ПЕРШОЇ ВЕРСІЇ

Один самодостатній факт на рядок канонічного CV, зі справжніми компаніями, датами та
навичками. Кожен рядок має стабільний fact_id; запуск зберігає незмінний знімок профілю.
Модель обирає й упорядковує тільки відомі ID; сервер відхиляє підставні та дубльовані ID.
PDF копіює оригінальний текст. Вільне перефразування, переклад, cover letter, геокодинг
і час доїзду ще не реалізовані. Імпорт PDF CV ще не реалізований: факти вводяться вручну.
Оцінка відповідності й пояснення є AI-судженнями й потребують перевірки користувачем.

БЕЗПЕКА ТА ЗБЕРІГАННЯ

Вхід із password hashing, CSRF, login throttling; зміни стану POST, owner-check для
запусків, результатів і PDF. Спільний каталог Job містить лише публічні вакансії.
Приватні дані пов'язані з User; модель не може обрати інший user_id або довільний URL.
Промпт відхиляє інструкції всередині вакансій. Привілеї обмежуються серверними tools.
Пояснення моделі екрануються Twig; PDF не виконує JS/PHP і не завантажує зовнішні файли.
Журнал прогресу показує дії та помилки, не приховані міркування моделі.
SQL logging вимкнено; worker не записує промпти/API-ключ. Каталог var містить приватні
дані й потребує обмежених файлових прав та резервних копій поза public/.
SQLite і знімки профілю не зашифровані самим застосунком. Контроль ОС/бекапів потрібний.
Це реалізовані захисні заходи, а не завершений незалежний аудит безпеки.

ПЕРЕВІРКИ

  php vendor/phpunit/phpunit/phpunit
  php bin/console lint:container
  php bin/console lint:twig templates
  php bin/console doctrine:schema:validate
  composer audit

Тести використовують окремий var/worklook_test.db, перевіряють його назву перед
пересозданням схеми. Вони охоплюють owner isolation, CSRF, вхід, екранування профілю,
PDF, незмінність фактів, джерела/XXE, demo/idempotence та модельний tool loop із mock API.
Реальні GET до джерел виконуються діагностичною командою, а не автоматично з тестів.

РОЗГОРТАННЯ MYDEVIL MD2

Локально — SQLite; для хоста підготовлено PostgreSQL і Messenger Doctrine transport.
Нижче — загальні команди розгортання;
фактичні каталоги й перевірки поточного стенда описано в docs/mydevil-deployment.txt.
Docker і власний Node server для цього застосунку не потрібні.
На хості скопіюйте .env.prod.example у .env.local; впишіть реальні secret, DB host
і serverVersion із акаунта. APP_ENV=prod у .env.local обирає production і для вебзапитів.
Міграції генерують SQL через DBAL для SQLite/PostgreSQL/MySQL. Імпортер
app:database:import-sqlite приймає консистентний знімок SQLite і порожню PostgreSQL,
перевіряє кожне поле та кількість рядків, відновлює identity sequences; помилка дає rollback.

На сервері використовувати PHP 8.4 і для вебсайту, і для CLI (php84 у MyDevil).
Встановити Composer-залежності з --no-dev --optimize-autoloader у prod env.
Налаштувати HTTPS і web root ЛИШЕ на work-look/public/, не на каталог усього репозиторію.
Решта коду, .env.local, vendor і var не повинні бути публічними.
Маршрути, що не є статичними файлами, направляти в public/index.php засобами вебсервера.
Вимкнути debug; налаштувати секрет, БД та HTTPS/proxy до відкриття зовнішнього доступу.
Виконати після налаштування env і резервної копії БД:
  php84 bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction
  php84 bin/console asset-map:compile --env=prod --no-debug
  php84 bin/console cache:clear --env=prod --no-debug
  php84 bin/console app:user:create your@email.example --env=prod --no-debug
Worker запускати окремим процесом з обмеженнями часу/RAM і перезапуском засобами хоста.
На демостенді cron запускає bounded worker під FreeBSD lockf; це обробка вже
натиснутих кнопок, а не самостійний щогодинний пошук. Боти без зовнішніх ключів не запускаються.
Після оновлення коду: messenger:stop-workers та повторний запуск worker.

Ngrok поки не запущений. Для зовнішнього перегляду спочатку потрібні prod env,
довірений proxy для конкретного тунелю, правильний HTTPS session cookie та hostname.
PHP built-in server і локальний dev env не є production-сервером.

Офіційні deployment-довідки:
  https://pomoc.mydevil.net/PHP/
  https://pomoc.mydevil.net/Strona_WWW/
  https://pomoc.mydevil.net/PostgreSQL/
  https://symfony.com/doc/7.4/deployment.html
  https://symfony.com/doc/7.4/messenger.html

Карта коду й контракти: docs/architecture.txt.
Специфікація і рішення хостингу: docs/specification.txt,
docs/hosting.txt. Актуальні обмеження MVP — у цьому README.
