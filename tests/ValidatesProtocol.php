<?php

namespace Folklore\Eclair\Tests;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Checks requests and responses against the protocol's JSON Schema, copied from
 * `@eclair-js/protocol` in `tests/protocol` (see `tests/protocol/update.sh`).
 */
trait ValidatesProtocol
{
    private static ?Validator $protocol = null;

    /**
     * @param  mixed  $data  A decoded value, where arrays become JSON objects, or a JSON string
     */
    protected function assertMatchesProtocol(string $definition, mixed $data): void
    {
        $value = is_string($data) ? json_decode($data) : json_decode((string) json_encode($data));
        if (self::$protocol === null) {
            self::$protocol = new Validator;
            self::$protocol->resolver()?->registerFile('urn:eclair:protocol:v1', __DIR__.'/protocol/eclair.schema.json');
        }
        $result = self::$protocol->validate($value, 'urn:eclair:protocol:v1#/$defs/'.$definition);
        $errors = $result->error() === null ? [] : (new ErrorFormatter)->format($result->error());

        $this->assertTrue($result->isValid(), sprintf(
            "Not a valid %s:\n%s\n%s",
            $definition,
            json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ));
    }
}
