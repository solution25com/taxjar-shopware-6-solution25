<?php

declare(strict_types=1);

namespace solu1TaxJar\Core\TaxJar;

use Shopware\Core\Framework\Struct\ArrayStruct;

final class TaxJarCalculation
{
    public const EXTENSION_NAME = 'taxjar_calculation';

    public const ORDER_CUSTOM_FIELD = 'taxjar_calculation';

    public const ORDER_REFUND_CUSTOM_FIELD = 'taxjar_refund_calculations';

    public const SCHEMA_VERSION = 1;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_BYPASSED = 'bypassed';

    public const STATUS_ADDRESS_MISMATCH = 'address_mismatch';

    public const SOURCE_API = 'api';

    public const SOURCE_CACHE = 'cache';

    public static function entry(
        string $status,
        ?string $reason = null,
        ?string $source = null,
        bool $addressFallback = false,
        ?bool $sandbox = null,
        ?array $request = null,
        ?array $response = null,
        mixed $error = null
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'source' => $source,
            'addressFallback' => $addressFallback,
            'sandbox' => $sandbox,
            'request' => $request,
            'response' => $response,
            'error' => $error,
        ];
    }

    public static function create(array $calculations): ArrayStruct
    {
        $sandbox = null;
        $entries = [];

        foreach ($calculations as $calculation) {
            if ($sandbox === null && isset($calculation['sandbox'])) {
                $sandbox = (bool) $calculation['sandbox'];
            }
            unset($calculation['sandbox']);
            $entries[] = $calculation;
        }

        return new ArrayStruct([
            'version' => self::SCHEMA_VERSION,
            'status' => self::aggregateStatus($entries),
            'calculatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'sandbox' => $sandbox,
            'calculations' => $entries,
        ], self::EXTENSION_NAME);
    }

    public static function refund(string $returnId, array $calculation): array
    {
        $sandbox = isset($calculation['sandbox']) ? (bool) $calculation['sandbox'] : null;
        unset($calculation['sandbox'], $calculation['addressFallback']);

        return [
            'version' => self::SCHEMA_VERSION,
            'returnId' => $returnId,
            'calculatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'sandbox' => $sandbox,
        ] + $calculation;
    }

    public static function notApplicable(): ArrayStruct
    {
        return new ArrayStruct([
            'version' => self::SCHEMA_VERSION,
            'status' => self::STATUS_SKIPPED,
            'reason' => 'not_applicable',
            'calculatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'sandbox' => null,
            'calculations' => [],
        ], self::EXTENSION_NAME);
    }

    private static function aggregateStatus(array $entries): string
    {
        $statuses = array_values(array_unique(array_column($entries, 'status')));

        if ($statuses === []) {
            return self::STATUS_SKIPPED;
        }

        if (\count($statuses) === 1) {
            return $statuses[0];
        }

        if (\in_array(self::STATUS_SUCCESS, $statuses, true)) {
            return self::STATUS_PARTIAL;
        }

        if (\in_array(self::STATUS_ADDRESS_MISMATCH, $statuses, true)) {
            return self::STATUS_ADDRESS_MISMATCH;
        }

        return self::STATUS_FAILED;
    }
}
