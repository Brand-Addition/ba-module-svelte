<?php

declare(strict_types=1);

namespace BA\Svelte\Model;

use Magento\Framework\DataObject;
use Magento\Framework\Phrase;

class StructuredDataNormalizer
{
    /**
     * @param array<mixed, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeAssociativeData(array $data): array
    {
        $resolvedData = [];

        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $resolvedData[$key] = $this->normalizeValue($value);
        }

        return $resolvedData;
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return $this->normalizeArrayValue($value);
        }

        if (is_object($value)) {
            return $this->normalizeObjectValue($value);
        }

        return null;
    }

    /**
     * @param array<mixed, mixed> $value
     * @return array<mixed, mixed>
     */
    private function normalizeArrayValue(array $value): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeValue($item);
        }

        return $value;
    }

    private function normalizeObjectValue(object $value): mixed
    {
        if ($value instanceof Phrase) {
            return (string)$value;
        }

        if ($value instanceof DataObject) {
            return $this->normalizeValue($value->getData());
        }

        if ($value instanceof \JsonSerializable) {
            return $this->normalizeValue($value->jsonSerialize());
        }

        if (method_exists($value, 'toArray')) {
            /** @var mixed $arrayValue */
            $arrayValue = $value->toArray();
            return $this->normalizeValue($arrayValue);
        }

        if (method_exists($value, '__toString')) {
            return (string)$value;
        }

        return null;
    }
}
