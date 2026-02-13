# Plugin Core Integration

> Type-specific core framework for SalesRender integration plugins

## Overview

`salesrender/plugin-core-integration` is a lightweight core package for building **INTEGRATION** type plugins on the
SalesRender platform. Integration plugins receive data from external services (websites, landing pages, third-party
platforms) and create orders in SalesRender via its GraphQL API.

Unlike macros plugins, integration plugins do **not** have batch processing or bulk order handling. They focus on:

- Providing a settings form for configuring how incoming data maps to SalesRender orders
- Exposing custom HTTP endpoints (actions) that accept webhooks or API calls from external systems
- Translating incoming data into SalesRender order creation requests

This package extends the base `salesrender/plugin-core` by providing its own `WebAppFactory` and `ConsoleAppFactory`
classes. Since integration plugins have no batch processing, the factories are thin wrappers that simply delegate to
the parent implementations without adding any extra routes or commands. All the customization is done by the plugin
developer via custom Slim routes added after `$factory->build()`.

## Installation

```bash
composer require salesrender/plugin-core-integration
```

**Requirements:**
- PHP >= 7.4
- Extensions: `ext-json`

**Dependencies:**
- `salesrender/plugin-core` ^0.4.0

## Architecture

### How This Core Extends plugin-core

`plugin-core-integration` provides two factory classes in the `SalesRender\Plugin\Core\Integration\Factories` namespace:

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

The integration `WebAppFactory` adds **no additional routes** beyond what the base `plugin-core` provides. It is a
direct pass-through to the parent. The developer adds custom webhook routes after calling `$factory->build()`.

Available methods inherited from the parent:

- `addCors(string $origin = '*', string $headers = '*')` -- enable CORS headers (useful for receiving webhooks from browsers)
- `addBatchActions()` -- not typically used for integration plugins
- `addSpecialRequestAction(SpecialRequestAction $action)` -- add a special request handler

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

The integration `ConsoleAppFactory` adds **no additional commands** beyond the base set. Since integration plugins do
not have batch processing, no batch commands are registered.

### What the Base plugin-core Provides

The base `plugin-core` framework (inherited by this package) provides:

**HTTP Routes (from WebAppFactory):**

| Method | Path | Description |
|---|---|---|
| `GET` | `/info` | Returns plugin metadata |
| `PUT` | `/registration` | Registers the plugin for a company |
| `GET` | `/robots.txt` | Blocks search engine indexing |
| `GET` | `/protected/forms/settings` | Returns the settings form definition |
| `GET` | `/protected/data/settings` | Returns current settings data |
| `PUT` | `/protected/data/settings` | Saves settings data |
| `GET` | `/protected/autocomplete/{name}` | Autocomplete suggestions |
| `GET` | `/protected/preview/table/{name}` | Table preview data |
| `GET` | `/protected/preview/markdown/{name}` | Markdown preview |
| `POST` | `/protected/upload` | File upload |

**CLI Commands (from ConsoleAppFactory):**

| Command | Description |
|---|---|
| `cron:run` | Runs scheduled cron tasks |
| `directory:clean` | Cleans temporary directories |
| `db:create-tables` | Creates required database tables |
| `db:clean-tables` | Cleans old records from tables |
| `lang:add` | Adds a new language |
| `lang:update` | Updates translation files |
| `specialRequest:queue` | Processes special request queue |
| `specialRequest:handle` | Handles a single special request |

### What the Developer Must Implement

1. **Settings Form** -- a class extending `SalesRender\Plugin\Components\Form\Form` that defines plugin configuration fields
2. **Custom Action(s)** -- one or more classes implementing `SalesRender\Plugin\Core\Actions\ActionInterface` that handle incoming webhooks/requests and create orders via the SalesRender API
3. **bootstrap.php** -- configuration file that wires everything together

## Getting Started: Creating an Integration Plugin

### Step 1: Project Setup

```bash
mkdir my-integration-plugin && cd my-integration-plugin
composer init --name="myvendor/my-integration-plugin" --type="project"
composer require salesrender/plugin-core-integration
```

Set up PSR-4 autoloading in `composer.json`:

```json
{
  "autoload": {
    "psr-4": {
      "MyVendor\\Plugin\\Instance\\Integration\\": "src/"
    }
  }
}
```

Create the project directory structure:

```bash
mkdir -p src/Actions src/Forms db public runtime
```

### Step 2: Bootstrap Configuration

Create `bootstrap.php` in the project root:

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

# 1. Configure DB (for SQLite *.db file and parent directory should be writable)
Connector::config(new Medoo([
    'database_type' => 'sqlite',
    'database_file' => Path::root()->down('db/database.db')
]));

# 2. Set plugin default language
Translator::config('ru_RU');

# 3. Configure info about plugin
Info::config(
    new PluginType(PluginType::INTEGRATION),
    fn() => Translator::get('info', 'My Integration Plugin'),
    fn() => Translator::get('info', 'Description of my integration plugin'),
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

# 4. Configure settings form
Settings::setForm(fn() => new SettingsForm());
```

Key points:
- Plugin type must be `PluginType::INTEGRATION`
- The fourth argument to `Info::config()` for integration plugins is an array with metadata (countries, codename),
  not a `PluginPurpose` object
- Settings form is provided as a factory callable

### Step 3: Create the Settings Form

Create `src/Forms/SettingsForm.php`:

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
                $errors[] = Translator::get('settings_errors', 'Field cannot be empty');
            }
            return $errors;
        };

        parent::__construct(
            Translator::get('settings', 'Settings'),
            Translator::get('settings', 'Configure your integration plugin'),
            [
                'main' => new FieldGroup(
                    Translator::get('settings', 'Main settings'),
                    null,
                    [
                        'apiKey' => new StringDefinition(
                            Translator::get('settings', 'API Key'),
                            Translator::get('settings', 'Enter your external service API key'),
                            $nonEmpty,
                        ),
                    ]
                ),
            ],
            Translator::get('settings', 'Save'),
        );
    }
}
```

### Step 4: Create the Webhook Action

Create `src/Actions/WebhookAction.php` implementing `ActionInterface`:

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

        // Look up settings for the given company/plugin combination
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

        // Build the order creation mutation
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

### Step 5: Create the Web Entry Point

Create `public/index.php`:

```php
<?php

use SalesRender\Plugin\Core\Integration\Factories\WebAppFactory;
use MyVendor\Plugin\Instance\Integration\Actions\WebhookAction;

require_once __DIR__ . '/../vendor/autoload.php';

$factory = new WebAppFactory();
$factory->addCors();
$application = $factory->build();

// Add your custom webhook route
$application->post('/webhook/{cid:\d+}/{pid:\d+}', WebhookAction::class);

$application->run();
```

Key points:
- `addCors()` is called before `build()` to enable CORS for webhook endpoints
- Custom routes are added **after** `build()` but **before** `run()`
- The route pattern `{cid:\d+}/{pid:\d+}` captures company ID and plugin ID from the URL

### Step 6: Create the Console Entry Point

Create `console.php`:

```php
#!/usr/bin/env php
<?php

use SalesRender\Plugin\Core\Integration\Factories\ConsoleAppFactory;

require __DIR__ . '/vendor/autoload.php';

$factory = new ConsoleAppFactory();
$application = $factory->build();
$application->run();
```

### Step 7: Create the .env File

Create `.env` (use `example.env` as a template):

```env
LV_PLUGIN_DEBUG=1
LV_PLUGIN_PHP_BINARY=/usr/bin/php
LV_PLUGIN_QUEUE_LIMIT=1
LV_PLUGIN_SELF_URI=https://my-plugin.example.com/

DATABASE_TYPE=sqlite
```

### Step 8: Create the Plugin Icon

Place a 128x128 pixel PNG image with a transparent background at `public/icon.png`. The `/info` endpoint will return
an error if this file is missing.

### Step 9: Initialize and Deploy

```bash
# Create the database
php console.php db:create-tables

# Set up cron (for special requests, etc.)
# Add to crontab:
# * * * * * /usr/bin/php /path/to/my-plugin/console.php cron:run
```

## HTTP Routes

The integration core's `WebAppFactory` does not add any routes beyond those provided by the base `plugin-core`. All
routes available to integration plugins are inherited from the parent:

| Method | Path | Auth | Description |
|---|---|---|---|
| `GET` | `/info` | No | Plugin metadata |
| `PUT` | `/registration` | No | Plugin registration |
| `GET` | `/robots.txt` | No | Robots exclusion |
| `GET` | `/protected/forms/settings` | JWT | Settings form definition |
| `GET` | `/protected/data/settings` | JWT | Current settings data |
| `PUT` | `/protected/data/settings` | JWT | Save settings data |
| `GET` | `/protected/autocomplete/{name}` | JWT | Autocomplete handler |
| `GET` | `/protected/preview/table/{name}` | JWT | Table preview |
| `GET` | `/protected/preview/markdown/{name}` | JWT | Markdown preview |
| `POST` | `/protected/upload` | JWT | File upload |

Integration plugins typically add their own **custom routes** (e.g. webhook endpoints) in `public/index.php` after
calling `$factory->build()`.

## CLI Commands

The integration core's `ConsoleAppFactory` does not add any commands beyond those provided by the base `plugin-core`.

| Command | Description |
|---|---|
| `cron:run` | Runs scheduled cron tasks |
| `directory:clean` | Cleans temporary directories |
| `db:create-tables` | Creates database tables |
| `db:clean-tables` | Cleans old database records |
| `lang:add` | Adds a new language |
| `lang:update` | Updates translations |
| `specialRequest:queue` | Processes special request queue |
| `specialRequest:handle` | Handles a special request |

## Key Interfaces

### ActionInterface

```php
namespace SalesRender\Plugin\Core\Actions;

interface ActionInterface
{
    public function __invoke(ServerRequest $request, Response $response, array $args): Response;
}
```

All custom webhook/action handlers must implement this interface. The handler receives the Slim HTTP request and
response objects, plus any route arguments captured from the URL pattern.

### Form (abstract class)

```php
namespace SalesRender\Plugin\Components\Form;

class Form
{
    public function __construct(
        string $title,
        ?string $description,
        array $groups,        // array of FieldGroup
        ?string $button = null
    );
}
```

The settings form defines the UI that administrators see when configuring the plugin. Field groups contain field
definitions (string, boolean, list of enum, etc.) with validators.

## Example Plugin

The [plugin-integration-tilda](https://github.com/SalesRender/plugin-integration-tilda) is a real-world integration
plugin that accepts webhooks from Tilda website builder and creates orders in SalesRender.

### Example Project Structure

```
plugin-integration-tilda/
    bootstrap.php              # DB, translations, plugin info, settings form
    console.php                # CLI entry point
    example.env                # Environment variable template
    composer.json
    db/
    public/
        index.php              # Web entry point with custom webhook route
        icon.png               # Plugin icon
    src/
        Actions/
            TildaAction.php    # Webhook handler (ActionInterface)
        Forms/
            SettingsForm.php   # Plugin settings form
        Components/
            Enum/              # Custom enum value providers
        ValuesList/
            LeadOptionValues.php
```

### How the Tilda Plugin Works

1. The settings form lets administrators configure which SalesRender status, project, and order fields to use
2. A webhook URL is generated: `{LV_PLUGIN_SELF_URI}webhook/{companyId}/{pluginId}`
3. Tilda sends form submissions to this URL as POST requests
4. `TildaAction` parses the incoming data, maps it to SalesRender order fields per the settings, and calls the
   SalesRender GraphQL API to create the order

## Dependencies

| Package | Version | Purpose |
|---|---|---|
| [`salesrender/plugin-core`](https://github.com/SalesRender/plugin-core) | ^0.4.0 | Base plugin framework (Slim 4 + Symfony Console) |

All transitive dependencies (Slim, Symfony Console, Medoo, etc.) come from `plugin-core`.

## See Also

- [plugin-core](https://github.com/SalesRender/plugin-core) -- Base framework for all SalesRender plugins
- [plugin-core-macros](https://github.com/SalesRender/plugin-core-macros) -- Core for macros plugins
- [plugin-core-logistic](https://github.com/SalesRender/plugin-core-logistic) -- Core for logistic plugins
- [plugin-core-chat](https://github.com/SalesRender/plugin-core-chat) -- Core for chat plugins
- [plugin-core-pbx](https://github.com/SalesRender/plugin-core-pbx) -- Core for PBX plugins
- [plugin-integration-tilda](https://github.com/SalesRender/plugin-integration-tilda) -- Example: Tilda integration plugin
- [plugin-component-form](https://github.com/SalesRender/plugin-component-form) -- Form definitions and field types
- [plugin-component-settings](https://github.com/SalesRender/plugin-component-settings) -- Settings storage
- [plugin-component-db](https://github.com/SalesRender/plugin-component-db) -- Database abstraction
