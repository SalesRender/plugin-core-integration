# Plugin Core Integration

> Типоспецифичный фреймворк для интеграционных плагинов SalesRender

## Обзор

`salesrender/plugin-core-integration` -- легковесный пакет ядра для создания плагинов типа **INTEGRATION** на
платформе SalesRender. Интеграционные плагины принимают данные от внешних сервисов (сайтов, лендингов, сторонних
платформ) и создают заказы в SalesRender через GraphQL API.

В отличие от macros-плагинов, интеграционные плагины **не** имеют пакетной обработки и массовых операций с заказами.
Их основные задачи:

- Предоставление формы настроек для конфигурации маппинга входящих данных на заказы SalesRender
- Предоставление пользовательских HTTP-эндпоинтов (actions), принимающих вебхуки или API-вызовы от внешних систем
- Преобразование входящих данных в запросы на создание заказов SalesRender

Данный пакет расширяет базовый `salesrender/plugin-core`, предоставляя собственные классы `WebAppFactory` и
`ConsoleAppFactory`. Поскольку интеграционные плагины не имеют пакетной обработки, фабрики являются тонкими обертками,
которые просто делегируют вызовы родительским реализациям без добавления дополнительных маршрутов или команд. Вся
кастомизация выполняется разработчиком плагина через пользовательские маршруты Slim, добавляемые после
вызова `$factory->build()`.

## Установка

```bash
composer require salesrender/plugin-core-integration
```

**Требования:**
- PHP >= 7.4
- Расширения: `ext-json`

**Зависимости:**
- `salesrender/plugin-core` ^0.4.0

## Архитектура

### Как данное ядро расширяет plugin-core

`plugin-core-integration` предоставляет два класса фабрик в пространстве имен
`SalesRender\Plugin\Core\Integration\Factories`:

#### WebAppFactory

```php
namespace SalesRender\Plugin\Core\Integration\Factories;

class WebAppFactory extends \SalesRender\Plugin\Core\Factories\WebAppFactory
{
    public function build(): App
    {
        return parent::build();
    }
}
```

Интеграционный `WebAppFactory` **не добавляет дополнительных маршрутов** сверх тех, что предоставляет базовый
`plugin-core`. Это прямая передача вызова родителю. Разработчик добавляет пользовательские маршруты для вебхуков
после вызова `$factory->build()`.

Доступные методы, унаследованные от родителя:

- `addCors(string $origin = '*', string $headers = '*')` -- включение CORS-заголовков (полезно для приема вебхуков из браузеров)
- `addBatchActions()` -- обычно не используется для интеграционных плагинов
- `addSpecialRequestAction(SpecialRequestAction $action)` -- добавление обработчика специальных запросов

#### ConsoleAppFactory

```php
namespace SalesRender\Plugin\Core\Integration\Factories;

class ConsoleAppFactory extends \SalesRender\Plugin\Core\Factories\ConsoleAppFactory
{
    public function build(): Application
    {
        return parent::build();
    }
}
```

Интеграционный `ConsoleAppFactory` **не добавляет дополнительных команд** сверх базового набора. Поскольку
интеграционные плагины не имеют пакетной обработки, команды для batch не регистрируются.

### Что предоставляет базовый plugin-core

Базовый фреймворк `plugin-core` (наследуемый данным пакетом) предоставляет:

**HTTP-маршруты (из WebAppFactory):**

| Метод | Путь | Описание |
|---|---|---|
| `GET` | `/info` | Возвращает метаданные плагина |
| `PUT` | `/registration` | Регистрирует плагин для компании |
| `GET` | `/robots.txt` | Блокирует индексацию поисковыми системами |
| `GET` | `/protected/forms/settings` | Возвращает определение формы настроек |
| `GET` | `/protected/data/settings` | Возвращает текущие данные настроек |
| `PUT` | `/protected/data/settings` | Сохраняет данные настроек |
| `GET` | `/protected/autocomplete/{name}` | Подсказки автодополнения |
| `GET` | `/protected/preview/table/{name}` | Предпросмотр таблицы |
| `GET` | `/protected/preview/markdown/{name}` | Предпросмотр markdown |
| `POST` | `/protected/upload` | Загрузка файлов |

**CLI-команды (из ConsoleAppFactory):**

| Команда | Описание |
|---|---|
| `cron:run` | Запускает запланированные cron-задачи |
| `directory:clean` | Очищает временные директории |
| `db:create-tables` | Создает необходимые таблицы базы данных |
| `db:clean-tables` | Очищает устаревшие записи в таблицах |
| `lang:add` | Добавляет новый язык |
| `lang:update` | Обновляет файлы переводов |
| `specialRequest:queue` | Обрабатывает очередь специальных запросов |
| `specialRequest:handle` | Обрабатывает один специальный запрос |

### Что должен реализовать разработчик

1. **Форма настроек** -- класс, наследующий `SalesRender\Plugin\Components\Form\Form`, определяющий поля конфигурации плагина
2. **Пользовательские Action-классы** -- один или несколько классов, реализующих `SalesRender\Plugin\Core\Actions\ActionInterface`, которые обрабатывают входящие вебхуки/запросы и создают заказы через SalesRender API
3. **bootstrap.php** -- файл конфигурации, связывающий все компоненты воедино

## Начало работы: Создание интеграционного плагина

### Шаг 1: Настройка проекта

```bash
mkdir my-integration-plugin && cd my-integration-plugin
composer init --name="myvendor/my-integration-plugin" --type="project"
composer require salesrender/plugin-core-integration
```

Настройте PSR-4 автозагрузку в `composer.json`:

```json
{
  "autoload": {
    "psr-4": {
      "MyVendor\\Plugin\\Instance\\Integration\\": "src/"
    }
  }
}
```

Создайте структуру директорий проекта:

```bash
mkdir -p src/Actions src/Forms db public runtime
```

### Шаг 2: Конфигурация bootstrap

Создайте `bootstrap.php` в корне проекта:

```php
<?php

use SalesRender\Plugin\Components\Db\Components\Connector;
use SalesRender\Plugin\Components\Info\Developer;
use SalesRender\Plugin\Components\Info\Info;
use SalesRender\Plugin\Components\Info\PluginType;
use SalesRender\Plugin\Components\Settings\Settings;
use SalesRender\Plugin\Components\Translations\Translator;
use Medoo\Medoo;
use MyVendor\Plugin\Instance\Integration\Forms\SettingsForm;
use XAKEPEHOK\Path\Path;

require_once __DIR__ . '/vendor/autoload.php';

# 1. Конфигурация БД (для SQLite файл *.db и его родительская директория должны быть доступны для записи)
Connector::config(new Medoo([
    'database_type' => 'sqlite',
    'database_file' => Path::root()->down('db/database.db')
]));

# 2. Установка языка плагина по умолчанию
Translator::config('ru_RU');

# 3. Конфигурация информации о плагине
Info::config(
    new PluginType(PluginType::INTEGRATION),
    fn() => Translator::get('info', 'Мой интеграционный плагин'),
    fn() => Translator::get('info', 'Описание моего интеграционного плагина'),
    [
        'countries' => ['RU'],
        'codename' => 'MY_INTEGRATION',
    ],
    new Developer(
        'My Company',
        'support@example.com',
        'example.com',
    )
);

# 4. Конфигурация формы настроек
Settings::setForm(fn() => new SettingsForm());
```

Ключевые моменты:
- Тип плагина должен быть `PluginType::INTEGRATION`
- Четвертый аргумент `Info::config()` для интеграционных плагинов -- массив с метаданными (countries, codename),
  а не объект `PluginPurpose`
- Форма настроек передается как callable-фабрика

### Шаг 3: Создание формы настроек

Создайте `src/Forms/SettingsForm.php`:

```php
<?php

namespace MyVendor\Plugin\Instance\Integration\Forms;

use SalesRender\Plugin\Components\Form\FieldDefinitions\ListOfEnum\Limit;
use SalesRender\Plugin\Components\Form\FieldDefinitions\ListOfEnumDefinition;
use SalesRender\Plugin\Components\Form\FieldDefinitions\StringDefinition;
use SalesRender\Plugin\Components\Form\FieldGroup;
use SalesRender\Plugin\Components\Form\Form;
use SalesRender\Plugin\Components\Translations\Translator;

class SettingsForm extends Form
{
    public function __construct()
    {
        $nonEmpty = function ($value) {
            $errors = [];
            if (empty($value)) {
                $errors[] = Translator::get('settings_errors', 'Поле не может быть пустым');
            }
            return $errors;
        };

        parent::__construct(
            Translator::get('settings', 'Настройки'),
            Translator::get('settings', 'Настройте ваш интеграционный плагин'),
            [
                'main' => new FieldGroup(
                    Translator::get('settings', 'Основные настройки'),
                    null,
                    [
                        'apiKey' => new StringDefinition(
                            Translator::get('settings', 'API-ключ'),
                            Translator::get('settings', 'Введите API-ключ внешнего сервиса'),
                            $nonEmpty,
                        ),
                    ]
                ),
            ],
            Translator::get('settings', 'Сохранить'),
        );
    }
}
```

### Шаг 4: Создание Action для вебхука

Создайте `src/Actions/WebhookAction.php`, реализующий `ActionInterface`:

```php
<?php

namespace MyVendor\Plugin\Instance\Integration\Actions;

use SalesRender\Plugin\Components\Access\Registration\Registration;
use SalesRender\Plugin\Components\Db\Components\Connector;
use SalesRender\Plugin\Components\Db\Components\PluginReference;
use SalesRender\Plugin\Components\Settings\Settings;
use SalesRender\Plugin\Core\Actions\ActionInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

class WebhookAction implements ActionInterface
{
    public function __invoke(ServerRequest $request, Response $response, array $args): Response
    {
        $companyId = $args['cid'];
        $pluginId = $args['pid'];

        // Поиск настроек для данной связки компания/плагин
        $settings = Connector::db()->select(
            Settings::tableName(),
            ['companyId', 'pluginAlias', 'pluginId'],
            [
                'companyId' => $companyId,
                'pluginId' => $pluginId,
            ]
        );

        if (empty($settings)) {
            return $response->withStatus(404);
        }

        $settings = array_shift($settings);
        Connector::setReference(new PluginReference(
            $settings['companyId'],
            $settings['pluginAlias'],
            $settings['pluginId']
        ));

        $formSettings = Settings::find()->getData();
        $data = $request->getParsedBody();

        // Формирование мутации для создания заказа
        $query = <<<QUERY
mutation (\$input: AddOrderInput!) {
    orderMutation {
        addOrder (input: \$input) {
            id
        }
    }
}
QUERY;

        $vars = [
            'input' => [
                'statusId' => $formSettings->get('main.statusId'),
                'projectId' => $formSettings->get('main.projectId'),
                'orderData' => [
                    'phoneFields' => [
                        [
                            'field' => $formSettings->get('main.phoneField'),
                            'value' => $data['phone'],
                        ]
                    ]
                ],
                'cart' => [],
            ]
        ];

        $registration = Registration::find();
        $registration->makeSpecialRequest(
            'POST',
            "{$registration->getClusterUri()}companies/{$companyId}/CRM/plugin/integration",
            ['query' => $query, 'variables' => $vars],
            300
        );

        return $response->withStatus(200);
    }
}
```

### Шаг 5: Создание точки входа для web

Создайте `public/index.php`:

```php
<?php

use SalesRender\Plugin\Core\Integration\Factories\WebAppFactory;
use MyVendor\Plugin\Instance\Integration\Actions\WebhookAction;

require_once __DIR__ . '/../vendor/autoload.php';

$factory = new WebAppFactory();
$factory->addCors();
$application = $factory->build();

// Добавление пользовательского маршрута для вебхука
$application->post('/webhook/{cid:\d+}/{pid:\d+}', WebhookAction::class);

$application->run();
```

Ключевые моменты:
- `addCors()` вызывается перед `build()` для включения CORS на эндпоинтах вебхуков
- Пользовательские маршруты добавляются **после** `build()`, но **перед** `run()`
- Шаблон маршрута `{cid:\d+}/{pid:\d+}` захватывает ID компании и ID плагина из URL

### Шаг 6: Создание точки входа для консоли

Создайте `console.php`:

```php
#!/usr/bin/env php
<?php

use SalesRender\Plugin\Core\Integration\Factories\ConsoleAppFactory;

require __DIR__ . '/vendor/autoload.php';

$factory = new ConsoleAppFactory();
$application = $factory->build();
$application->run();
```

### Шаг 7: Создание файла .env

Создайте `.env` (используйте `example.env` как шаблон):

```env
LV_PLUGIN_DEBUG=1
LV_PLUGIN_PHP_BINARY=/usr/bin/php
LV_PLUGIN_QUEUE_LIMIT=1
LV_PLUGIN_SELF_URI=https://my-plugin.example.com/

DATABASE_TYPE=sqlite
```

### Шаг 8: Создание иконки плагина

Поместите PNG-изображение размером 128x128 пикселей с прозрачным фоном в `public/icon.png`. Эндпоинт `/info` вернет
ошибку, если этот файл отсутствует.

### Шаг 9: Инициализация и развертывание

```bash
# Создание базы данных
php console.php db:create-tables

# Настройка cron (для специальных запросов и т.д.)
# Добавьте в crontab:
# * * * * * /usr/bin/php /path/to/my-plugin/console.php cron:run
```

## HTTP-маршруты

`WebAppFactory` интеграционного ядра не добавляет маршрутов сверх предоставленных базовым `plugin-core`. Все
маршруты, доступные интеграционным плагинам, наследуются от родителя:

| Метод | Путь | Аутентификация | Описание |
|---|---|---|---|
| `GET` | `/info` | Нет | Метаданные плагина |
| `PUT` | `/registration` | Нет | Регистрация плагина |
| `GET` | `/robots.txt` | Нет | Блокировка индексации |
| `GET` | `/protected/forms/settings` | JWT | Определение формы настроек |
| `GET` | `/protected/data/settings` | JWT | Текущие данные настроек |
| `PUT` | `/protected/data/settings` | JWT | Сохранение данных настроек |
| `GET` | `/protected/autocomplete/{name}` | JWT | Обработчик автодополнения |
| `GET` | `/protected/preview/table/{name}` | JWT | Предпросмотр таблицы |
| `GET` | `/protected/preview/markdown/{name}` | JWT | Предпросмотр markdown |
| `POST` | `/protected/upload` | JWT | Загрузка файлов |

Интеграционные плагины обычно добавляют собственные **пользовательские маршруты** (например, эндпоинты для вебхуков)
в `public/index.php` после вызова `$factory->build()`.

## CLI-команды

`ConsoleAppFactory` интеграционного ядра не добавляет команд сверх предоставленных базовым `plugin-core`.

| Команда | Описание |
|---|---|
| `cron:run` | Запускает запланированные cron-задачи |
| `directory:clean` | Очищает временные директории |
| `db:create-tables` | Создает таблицы базы данных |
| `db:clean-tables` | Очищает устаревшие записи |
| `lang:add` | Добавляет новый язык |
| `lang:update` | Обновляет переводы |
| `specialRequest:queue` | Обрабатывает очередь специальных запросов |
| `specialRequest:handle` | Обрабатывает специальный запрос |

## Ключевые интерфейсы

### ActionInterface

```php
namespace SalesRender\Plugin\Core\Actions;

interface ActionInterface
{
    public function __invoke(ServerRequest $request, Response $response, array $args): Response;
}
```

Все пользовательские обработчики вебхуков/действий должны реализовывать этот интерфейс. Обработчик получает объекты
HTTP-запроса и ответа Slim, а также аргументы маршрута, захваченные из шаблона URL.

### Form (абстрактный класс)

```php
namespace SalesRender\Plugin\Components\Form;

class Form
{
    public function __construct(
        string $title,
        ?string $description,
        array $groups,        // массив FieldGroup
        ?string $button = null
    );
}
```

Форма настроек определяет пользовательский интерфейс, который видят администраторы при конфигурации плагина. Группы
полей содержат определения полей (string, boolean, list of enum и т.д.) с валидаторами.

## Пример плагина

[plugin-integration-tilda](https://github.com/SalesRender/plugin-integration-tilda) -- реальный интеграционный
плагин, принимающий вебхуки от конструктора сайтов Tilda и создающий заказы в SalesRender.

### Структура примера

```
plugin-integration-tilda/
    bootstrap.php              # БД, переводы, информация о плагине, форма настроек
    console.php                # Точка входа CLI
    example.env                # Шаблон переменных окружения
    composer.json
    db/
    public/
        index.php              # Точка входа web с пользовательским маршрутом вебхука
        icon.png               # Иконка плагина
    src/
        Actions/
            TildaAction.php    # Обработчик вебхука (ActionInterface)
        Forms/
            SettingsForm.php   # Форма настроек плагина
        Components/
            Enum/              # Пользовательские провайдеры значений перечислений
        ValuesList/
            LeadOptionValues.php
```

### Как работает плагин Tilda

1. Форма настроек позволяет администраторам выбрать статус, проект и поля заказа в SalesRender
2. Генерируется URL вебхука: `{LV_PLUGIN_SELF_URI}webhook/{companyId}/{pluginId}`
3. Tilda отправляет данные форм на этот URL в виде POST-запросов
4. `TildaAction` разбирает входящие данные, сопоставляет их с полями заказа SalesRender согласно настройкам и
   вызывает GraphQL API SalesRender для создания заказа

## Зависимости

| Пакет | Версия | Назначение |
|---|---|---|
| [`salesrender/plugin-core`](https://github.com/SalesRender/plugin-core) | ^0.4.0 | Базовый фреймворк плагинов (Slim 4 + Symfony Console) |

Все транзитивные зависимости (Slim, Symfony Console, Medoo и т.д.) поступают из `plugin-core`.

## Смотрите также

- [plugin-core](https://github.com/SalesRender/plugin-core) -- Базовый фреймворк для всех плагинов SalesRender
- [plugin-core-macros](https://github.com/SalesRender/plugin-core-macros) -- Ядро для macros-плагинов
- [plugin-core-logistic](https://github.com/SalesRender/plugin-core-logistic) -- Ядро для логистических плагинов
- [plugin-core-chat](https://github.com/SalesRender/plugin-core-chat) -- Ядро для chat-плагинов
- [plugin-core-pbx](https://github.com/SalesRender/plugin-core-pbx) -- Ядро для PBX-плагинов
- [plugin-integration-tilda](https://github.com/SalesRender/plugin-integration-tilda) -- Пример: интеграционный плагин Tilda
- [plugin-component-form](https://github.com/SalesRender/plugin-component-form) -- Определения форм и типы полей
- [plugin-component-settings](https://github.com/SalesRender/plugin-component-settings) -- Хранилище настроек
- [plugin-component-db](https://github.com/SalesRender/plugin-component-db) -- Абстракция базы данных
