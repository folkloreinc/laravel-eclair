<?php

namespace Folklore\Eclair\Data;

/**
 * The stable error codes of a failed task. Eclair can add codes: an unknown one is read as
 * `OperationFailed`.
 */
enum ErrorCode: string
{
    case InputUnreadable = 'input_unreadable';
    case UnsupportedInput = 'unsupported_input';
    case Timeout = 'timeout';
    case LimitExceeded = 'limit_exceeded';
    case OperationFailed = 'operation_failed';
    case StorageFailed = 'storage_failed';
    case ProviderFailed = 'provider_failed';
    case DependencyFailed = 'dependency_failed';

    public static function read(string $code): self
    {
        return self::tryFrom($code) ?? self::OperationFailed;
    }
}
