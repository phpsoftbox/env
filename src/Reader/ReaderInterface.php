<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Reader;

use PhpSoftBox\Env\Variables;

interface ReaderInterface
{
    /**
     * Список env-файлов в порядке чтения (последующие перекрывают предыдущие).
     *
     * @param string|null $environment Окружение; null — APP_ENV из процесса, затем из .env, иначе dev.
     * @return list<string>
     */
    public function files(?string $environment = null): array;

    /**
     * Читает значения только из env-файлов: переменные процесса в результат не попадают.
     *
     * @param string|null $environment Окружение; null — APP_ENV из процесса, затем из .env, иначе dev.
     * @param string|null $prefix Префикс для ключей из файлов.
     * @param bool $strict Бросать исключение, если не найдено ни одного файла.
     * @param bool $interpolateGlobals Использовать переменные процесса как контекст интерполяции (`${HOME}`).
     */
    public function read(
        ?string $environment = null,
        ?string $prefix = null,
        bool $strict = true,
        bool $interpolateGlobals = true,
    ): Variables;
}
