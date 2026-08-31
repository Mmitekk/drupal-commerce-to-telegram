# Commerce to Telegram

[![Drupal](https://img.shields.io/badge/Drupal-10%20%7C%2011-0678BE?logo=drupal&logoColor=white)](https://www.drupal.org)
[![Drupal Commerce](https://img.shields.io/badge/Commerce-2.x-0678BE)](https://www.drupal.org/project/commerce)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](LICENSE)
[![Release](https://img.shields.io/badge/Release-v1.0.0-brightgreen)](../../releases)

**[English](README.en.md)** | Русский

---

Модуль для Drupal 10/11, который **автоматически отправляет в Telegram новые
заказы Drupal Commerce** — в группу или канал, в которой(ом) присутствует ваш
бот. Дополнительно (опционально) умеет отправлять заявки из форм Webform.

Каждый оформленный заказ мгновенно появляется в телеграм-группе менеджеров:
номер заказа, состав (позиции и суммы), покупатель, адрес и итог. Не нужно
проверять почту и админку — заказы видны сразу после оформления.

## Возможности

- **Drupal Commerce (основная интеграция)**: уведомление отправляется в
  момент размещения заказа — переход в статус «Завершён» при оформлении
  (событие `ORDER_PLACE`).
- **Webform (опционально)**: если установлен модуль Webform, можно включить
  доставку заявок выбранных форм — всё в тот же чат.
- **Страница настроек в админке** — Конфигурация → Система →
  «Telegram-уведомления: заказы и заявки»
  (`/admin/config/system/commerce-to-telegram`):
  - токен бота (сохранённое значение скрыто, пустое поле = не менять);
  - ID чата или группы;
  - формат сообщения: HTML или простой текст;
  - шаблон сообщения о заказе и шаблон о заявке;
  - чекбоксы форм Webform для доставки;
  - кнопка **«Отправить тестовое сообщение»** для мгновенной проверки.
- **Токены**: стандартные токены Commerce и Webform через модуль Token
  **плюс дополнительные токены модуля**:
  - `[commerce_order:items_table]` — все позиции заказа, по одной в строке
    («2 × Товар — 2 400 руб.»);
  - `[commerce_order:billing_address]` — платёжный адрес;
  - `[commerce_order:shipping_address]` — адрес доставки (нужен Commerce Shipping).
- **Формат HTML**: жирные заголовки, аккуратные переносы; при некорректной
  разметке модуль автоматически повторяет отправку простым текстом —
  уведомление дойдёт в любом случае.
- **Надёжность**: сбой Telegram никогда не прерывает оформление заказа и
  сохранение заявки; ошибки пишутся в журнал Drupal (dblog,
  канал `commerce_to_telegram`).
- **Лимит Telegram 4096 символов** соблюдается автоматически.

## Требования

| Компонент | Версия | Обязательность |
|---|---|---|
| Drupal | ^10 \|\| ^11 | да |
| PHP | >= 8.1 | да |
| [Drupal Commerce](https://www.drupal.org/project/commerce) | ^2.0 | да (устанавливается Composer'ом) |
| [Token](https://www.drupal.org/project/token) | ^1.0 | да (устанавливается Composer'ом) |
| [Webform](https://www.drupal.org/project/webform) | ^6.0 | опционально |

Интеграции включаются автоматически по факту наличия модулей: если Commerce
не установлен, раздел «Заказы» в админке скрыт; если не установлен Webform —
скрыт раздел форм.

## Установка

### Через Composer (рекомендуется)

```bash
# 1. Подключить репозиторий в composer.json проекта
composer config repositories.commerce-to-telegram vcs https://github.com/Mmitekk/drupal-commerce-to-telegram

# 2. Установить модуль последней версии (станет доступен в web/modules/custom/)
composer require drupal/commerce_to_telegram

# 3. Включить модуль
drush en commerce_to_telegram -y
```

Без указания версии Composer поставит **последний стабильный релиз**.
Никаких токенов доступа и регистрации на Packagist не требуется —
репозиторий публичный, Composer забирает пакет напрямую с GitHub.

> Чтобы команда `composer require drupal/commerce_to_telegram` работала
> совсем «классически» (без подключения репозитория), можно один раз
> зарегистрировать пакет на [packagist.org](https://packagist.org):
> Submit → вставить URL этого репозитория. После этого репозиторий в
> composer.json подключать уже не нужно.

### Вручную

1. Скачайте архив: **Code → Download ZIP** или файл
   `commerce_to_telegram-1.0.0.zip` со страницы
   [Releases](../../releases).
2. Распакуйте в `web/modules/custom/` так, чтобы получился путь
   `web/modules/custom/commerce_to_telegram/commerce_to_telegram.info.yml`.
3. Очистите кеш (`drush cr` или `/admin/config/development/performance`).
4. Включите модуль: **Управление → Расширение** (`/admin/modules`).

## Настройка

### 1. Создание бота

1. В Telegram найдите **@BotFather** → команда `/newbot`.
2. Задайте имя бота и получите токен вида
   `123456789:AAE1b2c3D4e5F6g7H8i9J0k1L2m3N4o5p6q`.

### 2. Добавление бота в группу и получение chat_id

1. Добавьте бота в группу (для **канала** — как администратора с правом
   публикации).
2. Узнайте ID группы любым способом:
   - добавьте в группу бота **@getidsbot** — он покажет `chat id`;
   - перешлите любое сообщение из группы боту **@getmyid_bot**;
   - напишите что-нибудь в группу и откройте
     `https://api.telegram.org/bot<ТОКЕН>/getUpdates` — найдите
     `"chat":{"id":-100…`.
3. Супергруппы и каналы имеют отрицательный ID вида `-1001234567890`.

### 3. Настройка модуля

Откройте **Конфигурация → Система → Telegram-уведомления: заказы и заявки**:

1. Вставьте **токен бота**.
2. Укажите **ID чата/группы**.
3. Выберите **формат** (HTML по умолчанию).
4. В разделе **«Заказы Drupal Commerce»** включите отправку и при
   необходимости измените шаблон.
5. Нажмите **«Отправить тестовое сообщение»** — тест придёт в группу, если
   всё настроено верно.
6. Сохраните настройки.

## Как это работает

```mermaid
flowchart LR
    A["Покупатель оформляет заказ в чекауте"] --> B["Заказ переходит в статус «Завершён»"]
    B --> C["Событие ORDER_PLACE"]
    C --> D{"Интеграция Commerce<br>включена?"}
    D -- "нет" --> E["Выход"]
    D -- "да" --> F["Подстановка токенов в шаблон<br>(позиции, суммы, адрес)"]
    F --> G["Telegram Bot API: sendMessage"]
    G -- "ошибка разбора HTML" --> H["Повтор простым текстом"]
    G -- "успех" --> I["Сообщение в группе"]
```

1. Покупатель завершает чекаут — заказ переходит в статус «Завершён».
2. Модуль перехватывает событие размещения заказа (`ORDER_PLACE`).
3. Токены шаблона заменяются данными заказа; список позиций и адреса
   формируются встроенными токенами модуля.
4. Сообщение отправляется через Telegram Bot API
   (`https://api.telegram.org/bot…/sendMessage`) в настроенный чат.
5. Если Telegram отклонил HTML-разметку — отправка повторяется простым
   текстом. Лимит 4096 символов соблюдается.
6. Любая ошибка сети или API **не прерывает оформление заказа** — она
   записывается в журнал: **Отчёты → Последние сообщения журнала**
   (`/admin/reports/dblog`), канал `commerce_to_telegram`.

Весь обмен происходит синхронно с таймаутами 10 с (запрос) и 5 с
(подключение). Заявки Webform обрабатываются аналогично — на событии
создания заявки (`hook_webform_submission_insert`); черновики и тестовые
отправки не дублируются.

## Токены шаблона заказа

| Токен | Что подставляет |
|---|---|
| `[commerce_order:order_number]` | Номер заказа |
| `[commerce_order:items_table]` | Все позиции: «кол-во × название — сумма» |
| `[commerce_order:total_price]` | Итоговая сумма с валютой |
| `[commerce_order:mail]` | E-mail покупателя из заказа |
| `[commerce_order:state]` | Статус заказа |
| `[commerce_order:billing_address]` | Платёжный адрес |
| `[commerce_order:shipping_address]` | Адрес доставки (Commerce Shipping) |
| `[site:name]` | Название сайта |
| `[current-date:medium]` | Дата/время |

Также доступны стандартные токены Commerce (`[commerce_order:…]`,
`[commerce_store:…]`) и глобальные токены Token-модуля — полный список
открывается по ссылке «Просмотр доступных токенов» под полем шаблона.

### Токены шаблона заявки (Webform)

| Токен | Что подставляет |
|---|---|
| `[webform_submission:values]` | Все заполненные поля заявки |
| `[webform_submission:values:telefon]` | Значение конкретного поля (машинное имя элемента) |
| `[webform_submission:sid]` | Номер заявки |
| `[webform_submission:webform:title]` | Название формы |

### Разрешённые HTML-теги Telegram

`<b>`, `<i>`, `<u>`, `<s>`, `<a href="…">`, `<code>`, `<pre>`,
`<blockquote>`. Перенос строки — обычный Enter. Тег `<br>` при подстановке
токенов автоматически заменяется на перевод строки.

## Диагностика ошибок

| Ошибка Telegram | Причина и решение |
|---|---|
| `Unauthorized` | Неверный токен бота — проверьте значение у @BotFather |
| `Bad Request: chat not found` | Неверный ID чата или бот не добавлен в группу |
| `Forbidden: bot was kicked…` | Бота удалили из группы — добавьте снова |
| `Bad Request: can't parse entities` | Ошибка HTML-разметки (модуль сам повторит простым текстом) |
| `Too Many Requests` | Превышен лимит Telegram — кратковременно повторите позже |

Все ошибки также фиксируются в журнале Drupal с указанием номера заказа
или заявки.

## Безопасность

- Доступ к настройкам — только с правом `administer commerce to telegram`
  (по умолчанию только администраторы).
- Токен бота хранится в конфигурации сайта и **не выводится** на странице
  настроек: чтобы оставить прежний токен, оставьте поле пустым.
- При выгрузке конфигурации (`drush cex`) токен попадает в файлы экспорта —
  не публикуйте и не коммитьте их в публичные репозитории.

## Структура модуля

```
commerce_to_telegram/
├── composer.json                            — пакет Composer (drupal-custom-module)
├── commerce_to_telegram.info.yml            — описание модуля
├── commerce_to_telegram.module              — Webform-хук + токены заказа
├── commerce_to_telegram.services.yml        — сервисы (отправка, подписчик событий)
├── commerce_to_telegram.routing.yml         — маршрут страницы настроек
├── commerce_to_telegram.links.menu.yml      — пункт в меню конфигурации
├── commerce_to_telegram.permissions.yml     — право доступа
├── config/install/commerce_to_telegram.settings.yml — настройки по умолчанию
├── config/schema/commerce_to_telegram.schema.yml    — схема конфигурации
├── src/Service/TelegramSender.php           — отправка в Telegram Bot API
├── src/EventSubscriber/OrderEventSubscriber.php — событие размещения заказа
└── src/Form/SettingsForm.php                — страница настроек
```

## Лицензия

[GPL-2.0-or-later](LICENSE) — как и само ядро Drupal.
