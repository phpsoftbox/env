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
    ->setEnvironment(null) // null => APP_ENV процесса, затем из .env, иначе dev
    ->setPrefix('APP_');

$variables = $env->load();

$dbHost = $variables->get('DB_HOST');
$dbPort = $variables->toInt('DB_PORT', 3306);
$debug = $variables->toBool('APP_DEBUG', false);

// Типизированный доступ к текущему EnvStorage.
$debug = EnvStorage::value('APP_DEBUG')->bool(default: false);
$port = EnvStorage::value('DB_PORT')->int(default: 3306);
```

## Расположение и порядок чтения .env

Рекомендуем хранить env-файлы в отдельной папке, например `config/env`, и передавать её в
`Environment::create()`. Для каталога читаются только файлы в нём самом (подкаталоги не обходятся):

1. `.env` — базовые значения;
2. `.env.{env}` — значения окружения, перекрывают `.env`.

Окружение определяется так: значение `setEnvironment()`, затем `APP_ENV` процесса (`$_ENV`, `$_SERVER`,
`getenv()`), затем `APP_ENV` из `.env`, иначе `dev`. Поэтому `APP_ENV`, заданный только в `.env`, тоже выбирает
файл `.env.{env}`.

`Environment::createFromFile()` читает указанный файл как есть, `Environment::createFromPaths()` — несколько
каталогов/файлов по порядку (последующие перекрывают предыдущие).

Обход подкаталогов включается явно. Скрытые каталоги, `vendor` и `node_modules` пропускаются, файлы вложенных
каталогов перекрывают файлы корня:

```php
$env = Environment::create($baseDir . '/config/env')
    ->setRecursive(true);
```

## Синтаксис .env

```dotenv
# Комментарий
APP_NAME=demo              # комментарий после значения (перед # нужен пробел)
export APP_URL=https://demo.local
local SECRET=only-here     # не экспортируется в toGlobals()/toPutEnv()
SINGLE='без $интерполяции'
DOUBLE="строка\nс переводом"
MULTI="первая строка
вторая строка"
KEY=-----BEGIN PRIVATE KEY-----
...
-----END PRIVATE KEY-----
```

- Значение в кавычках может занимать несколько строк; текст после закрывающей кавычки игнорируется.
  Незакрытая кавычка приводит к `EnvException` с указанием файла и строки начала значения.
- Escape-последовательности разбираются за один проход, поэтому `"C:\\new"` даёт `C:\new`, а `"dir\\"`
  корректно закрывается.
  - в двойных кавычках: `\\` → `\`, `\"` → `"`, `\n`, `\r`, `\t` → управляющие символы, `\$` → `$`;
  - в одинарных кавычках: `\\` → `\`, `\'` → `'`;
  - без кавычек: только `\$` → `$`;
  - неизвестная последовательность (`\q`) сохраняется как есть.

### Интерполяция

В значениях без кавычек и в двойных кавычках подставляются `$NAME`, `${NAME}`, `${NAME:-default}` (значение по
умолчанию, если переменная пуста или не задана) и `${NAME:+alt}` (`alt`, если переменная не пуста). Доступны
переменные, объявленные выше в том же или предыдущих файлах, а также переменные процесса (если не выключено
`includeGlobals(false)`). Неизвестная переменная заменяется пустой строкой.

Ограничения:

- вложенные подстановки не поддерживаются: `${A:-${B}}` читается до первой `}` — значение по умолчанию будет
  литералом `${B`, за которым следует `}`;
- `default`/`alt` подставляются как текст, без интерполяции внутри; в двойных кавычках они не могут содержать `"`
  (такое выражение не считается подстановкой);
- любой `$`, за которым идёт имя, считается подстановкой: `pa$$word` превратится в `pa$` (`$word` — пустая
  переменная). Для литерального `$` используйте `\$` (`pa\$\$word`) или одинарные кавычки (`'pa$$word'`).

## Переменные процесса и кеш

При `includeGlobals(true)` (по умолчанию) результат `load()`/`safeLoad()`/`overload()` содержит значения из файлов
и переменные процесса из `$_ENV`/`$_SERVER`. `load()` и `safeLoad()` отдают приоритет переменным процесса,
`overload()` — значениям из файлов.

Кеш (`setCache()`) хранит только значения из файлов. Переменные процесса и данные запроса (`HTTP_*` в `$_SERVER`)
в кеш и в хранилище `env()` не копируются, а читаются при каждой загрузке/обращении — поэтому заголовки одного
запроса не видны в следующих, а изменения окружения процесса видны сразу. Обратите внимание: подстановка
переменной процесса в `.env` (`LOG_DIR=${HOME}/logs`) выполняется при чтении файла, и результат кешируется.

## Префикс

`setPrefix('APP_')` добавляет префикс только к ключам из env-файлов (`DB_HOST` → `APP_DB_HOST`), ключи уже с
префиксом не меняются. Переменные процесса (`PATH`, `HOME`, ...) остаются без префикса. При поиске
(`get('DB_HOST')`, `env('DB_HOST')`) ключ сначала ищется с префиксом, затем как есть.

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

Хелпер `env()` читает значения из хранилища (значения из файлов) и из переменных процесса (`$_ENV`, `$_SERVER`,
`getenv()`). Хранилище автоматически заполняется при `load()`/`safeLoad()`/`overload()`. После `load()`/`safeLoad()`
с globals переменная процесса перекрывает значение из файла; после `overload()` или с `includeGlobals(false)`
приоритет у файла, а переменные процесса используются для отсутствующих ключей.

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

`array()` принимает JSON (`["a","b"]`, `{"k":"v"}`), список в скобках без кавычек (`[a, b]`), список через запятую
(`a, b`) и одиночное значение (`a` → `['a']`). Элементы обрезаются, пустые отбрасываются; некорректный JSON-объект
(`{...}`) возвращает значение по умолчанию.
