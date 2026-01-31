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

$env = Environment::create(__DIR__)
    ->setEnvironment(null) // null => APP_ENV
    ->setPrefix('APP_');

$variables = $env->load();

$dbHost = $variables->get('DB_HOST');
$dbPort = $variables->toInt('DB_PORT', 3306);
$debug = $variables->toBool('APP_DEBUG', false);
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
