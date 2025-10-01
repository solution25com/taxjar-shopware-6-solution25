<?php declare(strict_types=1);

namespace Shopware\Core\Framework\MessageQueue;

use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Response;

#[Package('framework')]
class MessageQueueException extends HttpException
{
    public const NO_VALID_RECEIVER_NAME_PROVIDED = 'FRAMEWORK__NO_VALID_RECEIVER_NAME_PROVIDED';
    public const QUEUE_CANNOT_UNSERIALIZE_MESSAGE = 'FRAMEWORK__QUEUE_CANNOT_UNSERIALIZE_MESSAGE';
    public const WORKER_IS_LOCKED = 'FRAMEWORK__WORKER_IS_LOCKED';
    public const CANNOT_FIND_SCHEDULED_TASK = 'FRAMEWORK__CANNOT_FIND_SCHEDULED_TASK';
    public const QUEUE_MESSAGE_SIZE_EXCEEDS = 'FRAMEWORK__QUEUE_MESSAGE_SIZE_EXCEEDS';
    public const QUEUE_STATS_NOT_FOUND = 'FRAMEWORK__QUEUE_STATS_NOT_FOUND';
    public const MISSING_EXTENDS_CODE = 'FRAMEWORK__SCHEDULED_TASK_MISSING_EXTENDS';
    public const NOT_FOUND_CODE = 'FRAMEWORK__SCHEDULED_TASK_NOT_FOUND';

    public static function validReceiverNameNotProvided(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::NO_VALID_RECEIVER_NAME_PROVIDED,
            'No receiver name provided.',
        );
    }

    public static function cannotUnserializeMessage(string $message): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::QUEUE_CANNOT_UNSERIALIZE_MESSAGE,
            'Cannot unserialize message {{ message }}',
            ['message' => $message]
        );
    }

    public static function workerIsLocked(string $receiver): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::WORKER_IS_LOCKED,
            'Another worker is already running for receiver: "{{ receiver }}"',
            ['receiver' => $receiver]
        );
    }

    public static function cannotFindTaskByName(string $name): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::CANNOT_FIND_SCHEDULED_TASK,
            self::$couldNotFindMessage,
            ['entity' => 'scheduled task', 'field' => 'name', 'value' => $name]
        );
    }

    public static function queueMessageSizeExceeded(string $messageName, float $size): self
    {
        $message = 'The message "{{ message }}" exceeds the 256 kB size limit with its size of {{ size }} kB.';

        return new self(
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            self::QUEUE_MESSAGE_SIZE_EXCEEDS,
            $message,
            [
                'message' => $messageName,
                'size' => $size,
            ]
        );
    }

    public static function missingExtends(string $class): self
    {
        return new self(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            self::MISSING_EXTENDS_CODE,
            'Tried to register "{{ class }}" as scheduled task, but class does not extend ScheduledTask',
            ['class' => $class]
        );
    }

    public static function notFound(string $name): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::NOT_FOUND_CODE,
            'Tried to fetch "{{ name }}" scheduled task, but scheduled task does not exist',
            ['name' => $name]
        );
    }
}
