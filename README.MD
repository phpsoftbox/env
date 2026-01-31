# PhpSoftBox Env

Компонент для загрузки переменных окружения из `.env` файлов с поддержкой интерполяции,
валидации и опционального экспорта в `$_ENV`/`$_SERVER`.

## Установка

```bash
composer require phpsoftbox/env
```

## Быстрый старт

```php
<?php

declare(strict_types=1);

use PhpSoftBox\Env\Environment;
use PhpSoftBox\Env\EnvStorage;

$env = Environment::create(__DIR__ . '/config/env')
    ->setEnvironment(null) // null => APP_ENV
    ->setPrefix('APP_');

$variables = $env->load();

$dbHost = $variables->get('DB_HOST');
$dbPort = $variables->toInt('DB_PORT', 3306);
$debug = $variables->toBool('APP_DEBUG', false);

// Типизированный доступ к текущему EnvStorage.
$debug = EnvStorage::value('APP_DEBUG')->bool(default: false);
$port = EnvStorage::value('DB_PORT')->int(default: 3306);
```

## Производительность и расположение .env

Рекомендуем хранить env-файлы в отдельной папке, например `config/env`, и передавать
её в `Environment::create()`. Это избавляет от лишнего обхода файловой системы и
делает загрузку стабильной на больших проектах.

```php
// Environment::create($baseDir) может рекурсивно обходить ВСЕ поддиректории
// в поисках .env/.env.{env} (в старых версиях или при кастомных ридерах).
// При большом дереве (vendor/node_modules) это даёт секунды TTFB.
// Поэтому .env лучше держать в config/env и читать именно эту папку.
$env = Environment::create($baseDir . '/config/env')
    ->setEnvironment(null)
    ->setPrefix('APP_');
```

## Экспорт в глобальные массивы

По умолчанию библиотека не модифицирует `$_ENV`/`$_SERVER`. Экспорт выполняется явно:

```php
$variables = $env->load();
$variables->toGlobals();
```

## Валидация

```php
<?php

use PhpSoftBox\Env\Environment;
use PhpSoftBox\Env\EnvTypeEnum;
use PhpSoftBox\Env\Validator\RequiredValidator;
use PhpSoftBox\Env\Validator\TypeValidator;

$env = Environment::create(__DIR__)
    ->validate(new RequiredValidator(['DB_HOST', 'DB_NAME']))
    ->validate(new TypeValidator([
        'DB_PORT' => EnvTypeEnum::INT,
        'DEBUG' => EnvTypeEnum::BOOL,
    ]));

$variables = $env->load();
```

## Хелпер env()

Хелпер `env()` читает значения из локального хранилища, затем из `$_ENV` и `$_SERVER`.
Хранилище автоматически заполняется при `load()`/`safeLoad()`/`overload()`.

```php
$variables = $env->load();

$dbHost = env('DB_HOST', 'localhost');
```

`env()` и `EnvStorage::get()` продолжают возвращать исходное значение. Для фильтрации и строгого
преобразования используйте `EnvStorage::value()`, возвращающий `EnvValue`:

```php
use PhpSoftBox\Env\EnvStorage;
use PhpSoftBox\Filter\LowercaseFilter;

$environment = EnvStorage::value('APP_ENV', 'dev')
    ->filtered(new LowercaseFilter())
    ->enum(ApplicationEnvironment::class, ApplicationEnvironment::DEV);
```

Доступны `string()`, `int()`, `float()`, `bool()`, `array()`, `enum()`, `filter()`, `through()` и
`filtered()`. Базовые преобразования делегируются компоненту `phpsoftbox/filter`.
