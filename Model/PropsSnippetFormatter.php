<?php

declare(strict_types=1);

namespace BA\Svelte\Model;

class PropsSnippetFormatter
{
    private const RUNTIME_PROP_VARIABLES = [
        'containers' => 'containers',
        'default_container' => 'defaultContainer',
        'get_container' => 'getContainer',
        'has_container' => 'hasContainer',
        'ContainerRenderer' => 'ContainerRenderer',
    ];

    /**
     * @var array<int, string>
     */
    private const RESERVED_WORDS = [
        'await',
        'break',
        'case',
        'catch',
        'class',
        'const',
        'continue',
        'debugger',
        'default',
        'delete',
        'do',
        'else',
        'enum',
        'export',
        'extends',
        'false',
        'finally',
        'for',
        'function',
        'if',
        'implements',
        'import',
        'in',
        'instanceof',
        'interface',
        'let',
        'new',
        'null',
        'package',
        'private',
        'protected',
        'public',
        'return',
        'static',
        'super',
        'switch',
        'this',
        'throw',
        'true',
        'try',
        'type',
        'typeof',
        'var',
        'void',
        'while',
        'with',
        'yield',
    ];

    /**
     * @var array<string, string>
     */
    private array $complexTypeCache = [];

    public function format(\BA\Svelte\Model\Dto\SvelteComponentConfig $componentConfig): string
    {
        $typeLines = [];
        $bindingLines = [];
        $usedVariableNames = [];

        foreach ($componentConfig->props as $propName => $value) {
            if (!is_string($propName) || $this->isRuntimeProp($propName)) {
                continue;
            }

            $typeLines[] = sprintf(
                '        %s?: %s;',
                $this->formatTypePropertyName($propName),
                $this->inferType($value, $componentConfig->propTypes[$propName] ?? null)
            );
            $bindingLines[] = sprintf(
                '        %s,',
                $this->formatBinding($propName, null, $usedVariableNames)
            );
        }

        $containerNames = array_values(array_filter(array_map(
            static fn (string $containerName): string => trim($containerName),
            array_keys($componentConfig->containers)
        )));
        if ($containerNames !== []) {
            $containerType = $this->formatContainerNameType($containerNames);

            $typeLines[] = sprintf(
                '        containers?: Partial<Record<%s, unknown[]>>;',
                $containerType
            );
            $typeLines[] = '        default_container?: unknown[];';
            $typeLines[] = sprintf(
                '        get_container?: (name?: %s) => unknown[];',
                $containerType
            );
            $typeLines[] = sprintf(
                '        has_container?: (name?: %s) => boolean;',
                $containerType
            );
            $typeLines[] = '        ContainerRenderer?: unknown;';

            foreach (self::RUNTIME_PROP_VARIABLES as $propName => $variableName) {
                $bindingLines[] = sprintf(
                    '        %s,',
                    $this->formatBinding($propName, $variableName, $usedVariableNames)
                );
            }
        }

        $snippetLines = [
            '<script lang="ts">',
            '    type Props = {',
            ...($typeLines === [] ? ['    };'] : [...$typeLines, '    };']),
            '',
            '    let {',
            ...$bindingLines,
            '    }: Props = $props();',
        ];

        return implode(PHP_EOL, $snippetLines);
    }

    /**
     * @param array<int, string> $usedVariableNames
     */
    private function formatBinding(string $propName, ?string $preferredVariableName, array &$usedVariableNames): string
    {
        $variableName = $preferredVariableName;

        if ($variableName === null) {
            if ($this->isValidIdentifier($propName) && !$this->isReservedWord($propName)) {
                $variableName = $propName;
            } else {
                $variableName = $this->buildVariableName($propName);
            }
        }

        $variableName = $this->ensureUniqueVariableName($variableName, $usedVariableNames);

        if ($variableName === $propName && $this->isValidIdentifier($propName) && !$this->isReservedWord($propName)) {
            return $propName;
        }

        return sprintf('%s: %s', $this->formatTypePropertyName($propName), $variableName);
    }

    /**
     * @param list<string> $containerNames
     */
    private function formatContainerNameType(array $containerNames): string
    {
        $containerNames[] = 'default';
        $containerNames = array_values(array_unique($containerNames));

        return implode(' | ', array_map(
            fn (string $containerName): string => sprintf("'%s'", $this->escapeSingleQuotedString($containerName)),
            $containerNames
        ));
    }

    private function formatTypePropertyName(string $propName): string
    {
        if ($this->isValidIdentifier($propName) && !$this->isReservedWord($propName)) {
            return $propName;
        }

        return sprintf("'%s'", $this->escapeSingleQuotedString($propName));
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function inferArrayType(array $value): string
    {
        if ($value === []) {
            return 'unknown[]';
        }

        if (!array_is_list($value)) {
            return $this->inferObjectShapeType($value);
        }

        $types = array_values(array_unique(array_map($this->inferType(...), $value)));
        if ($types === []) {
            return 'unknown[]';
        }

        if (count($types) === 1) {
            $itemType = $types[0];

            return $this->isSimpleType($itemType)
                ? sprintf('%s[]', $itemType)
                : sprintf('Array<%s>', $itemType);
        }

        return sprintf('Array<%s>', implode(' | ', $types));
    }

    private function inferType(mixed $value, ?string $declaredType = null): string
    {
        if ($declaredType !== null && $declaredType !== '') {
            $inferredDeclaredType = $this->inferDeclaredType($declaredType);
            if ($inferredDeclaredType !== 'unknown') {
                return $inferredDeclaredType;
            }
        }

        return $this->inferValueType($value);
    }

    private function inferValueType(mixed $value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_int($value) || is_float($value)) {
            return 'number';
        }

        if (is_string($value)) {
            return 'string';
        }

        if (is_array($value)) {
            return $this->inferArrayType($value);
        }

        if ($value === null) {
            return 'null';
        }

        return 'unknown';
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function inferObjectShapeType(array $value): string
    {
        if ($value === []) {
            return 'Record<string, unknown>';
        }

        $properties = [];

        foreach ($value as $propertyName => $propertyValue) {
            if (!is_string($propertyName)) {
                return 'Record<string, unknown>';
            }

            $properties[] = sprintf(
                '%s?: %s;',
                $this->formatTypePropertyName($propertyName),
                $this->inferValueType($propertyValue)
            );
        }

        return sprintf('{ %s }', implode(' ', $properties));
    }

    private function inferDeclaredType(string $declaredType): string
    {
        $declaredType = trim($declaredType);
        if ($declaredType === '') {
            return 'unknown';
        }

        if (str_starts_with($declaredType, '?')) {
            $declaredType = substr($declaredType, 1) . '|null';
        }

        if (str_contains($declaredType, '|')) {
            $parts = array_values(array_filter(array_map('trim', explode('|', $declaredType))));
            $types = array_values(array_unique(array_map($this->inferDeclaredType(...), $parts)));

            return $types === [] ? 'unknown' : implode(' | ', $types);
        }

        if (str_ends_with($declaredType, '[]')) {
            return sprintf('Array<%s>', $this->inferDeclaredType(substr($declaredType, 0, -2)));
        }

        return match (strtolower(ltrim($declaredType, '\\'))) {
            'string' => 'string',
            'int', 'integer', 'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'null' => 'null',
            'mixed' => 'unknown',
            'array' => 'unknown[]',
            default => $this->inferComplexDeclaredType($declaredType),
        };
    }

    /**
     * @param array<string, bool> $visitedTypes
     */
    private function inferComplexDeclaredType(string $declaredType, array $visitedTypes = []): string
    {
        $normalizedType = ltrim(trim($declaredType), '\\');
        if ($normalizedType === '') {
            return 'unknown';
        }

        if (isset($this->complexTypeCache[$normalizedType])) {
            return $this->complexTypeCache[$normalizedType];
        }

        if (isset($visitedTypes[$normalizedType]) || (!class_exists($normalizedType) && !interface_exists($normalizedType))) {
            return 'unknown';
        }

        $visitedTypes[$normalizedType] = true;
        $reflection = new \ReflectionClass($normalizedType);
        $methods = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $method): bool => $this->isReflectableGetter($method, $reflection)
        );

        usort(
            $methods,
            static fn (\ReflectionMethod $left, \ReflectionMethod $right): int => $left->getStartLine() <=> $right->getStartLine()
        );

        $properties = [];
        foreach ($methods as $method) {
            $fieldName = $this->getFieldNameForMethodName($method->getName());
            if (!is_string($fieldName) || $fieldName === '') {
                continue;
            }

            $propertyType = $this->inferMethodReturnType($method, $visitedTypes);
            $properties[] = sprintf(
                '%s?: %s;',
                $this->formatTypePropertyName($fieldName),
                $propertyType
            );
        }

        if ($properties === []) {
            return 'unknown';
        }

        return $this->complexTypeCache[$normalizedType] = sprintf('{ %s }', implode(' ', $properties));
    }

    /**
     * @param array<string, bool> $visitedTypes
     */
    private function inferMethodReturnType(\ReflectionMethod $method, array $visitedTypes): string
    {
        $returnType = $method->getReturnType();
        if ($returnType instanceof \ReflectionNamedType) {
            $methodType = $returnType->getName();
            if (!$returnType->isBuiltin()) {
                $methodType = '\\' . ltrim($methodType, '\\');
            }

            if ($returnType->allowsNull() && strtolower($methodType) !== 'null') {
                $methodType .= '|null';
            }

            return $this->inferDeclaredTypeFromVisitedTypes($methodType, $visitedTypes);
        }

        if ($returnType instanceof \ReflectionUnionType) {
            $types = [];

            foreach ($returnType->getTypes() as $namedType) {
                $types[] = $this->reflectTypeToDeclaredType($namedType, $visitedTypes);
            }

            $types = array_values(array_unique($types));

            return $types === [] ? 'unknown' : implode(' | ', $types);
        }

        return 'unknown';
    }

    /**
     * @param array<string, bool> $visitedTypes
     */
    private function reflectTypeToDeclaredType(\ReflectionType $type, array $visitedTypes): string
    {
        if (!$type instanceof \ReflectionNamedType) {
            return 'unknown';
        }

        $typeName = $type->getName();
        if (!$type->isBuiltin()) {
            $typeName = '\\' . ltrim($typeName, '\\');
        }

        return $this->inferDeclaredTypeFromVisitedTypes($typeName, $visitedTypes);
    }

    /**
     * @param array<string, bool> $visitedTypes
     */
    private function inferDeclaredTypeFromVisitedTypes(string $declaredType, array $visitedTypes): string
    {
        $declaredType = trim($declaredType);
        if ($declaredType === '') {
            return 'unknown';
        }

        if (str_starts_with($declaredType, '?')) {
            $declaredType = substr($declaredType, 1) . '|null';
        }

        if (str_contains($declaredType, '|')) {
            $parts = array_values(array_filter(array_map('trim', explode('|', $declaredType))));
            $types = array_values(array_unique(array_map(
                fn (string $part): string => $this->inferDeclaredTypeFromVisitedTypes($part, $visitedTypes),
                $parts
            )));

            return $types === [] ? 'unknown' : implode(' | ', $types);
        }

        if (str_ends_with($declaredType, '[]')) {
            return sprintf(
                'Array<%s>',
                $this->inferDeclaredTypeFromVisitedTypes(substr($declaredType, 0, -2), $visitedTypes)
            );
        }

        return match (strtolower(ltrim($declaredType, '\\'))) {
            'string' => 'string',
            'int', 'integer', 'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'null' => 'null',
            'mixed' => 'unknown',
            'array' => 'unknown[]',
            default => $this->inferComplexDeclaredType($declaredType, $visitedTypes),
        };
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    private function isReflectableGetter(\ReflectionMethod $method, \ReflectionClass $reflection): bool
    {
        if ($method->isConstructor()
            || $method->isDestructor()
            || $method->isStatic()
            || str_starts_with($method->getName(), '__')
            || $method->getNumberOfRequiredParameters() > 0
        ) {
            return false;
        }

        if (!$reflection->isInterface() && $method->getDeclaringClass()->getName() !== $reflection->getName()) {
            return false;
        }

        return str_starts_with($method->getName(), 'get')
            || str_starts_with($method->getName(), 'is')
            || str_starts_with($method->getName(), 'has');
    }

    private function getFieldNameForMethodName(string $methodName): ?string
    {
        if (str_starts_with($methodName, 'is')) {
            return $this->camelCaseToSnakeCase(substr($methodName, 2));
        }

        if (str_starts_with($methodName, 'has') || str_starts_with($methodName, 'get')) {
            return $this->camelCaseToSnakeCase(substr($methodName, 3));
        }

        return null;
    }

    private function camelCaseToSnakeCase(string $value): string
    {
        $value = preg_replace('/(.)([A-Z][a-z]+)/', '$1_$2', $value) ?? $value;
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value) ?? $value;

        return strtolower($value);
    }

    private function buildVariableName(string $propName): string
    {
        $segments = preg_split('/[^A-Za-z0-9_$]+/', $propName) ?: [];
        $segments = array_values(array_filter($segments, static fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return 'propValue';
        }

        $variableName = strtolower(array_shift($segments));
        foreach ($segments as $segment) {
            $variableName .= ucfirst(strtolower($segment));
        }

        if ($variableName === '' || ctype_digit($variableName[0])) {
            $variableName = 'prop' . ucfirst($variableName);
        }

        if (!$this->isValidIdentifier($variableName) || $this->isReservedWord($variableName)) {
            $variableName = 'prop' . ucfirst(preg_replace('/[^A-Za-z0-9_$]/', '', $variableName) ?: 'Value');
        }

        return $variableName;
    }

    /**
     * @param array<int, string> $usedVariableNames
     */
    private function ensureUniqueVariableName(string $variableName, array &$usedVariableNames): string
    {
        $candidate = $variableName;
        $suffix = 2;

        while (in_array($candidate, $usedVariableNames, true)) {
            $candidate = sprintf('%s%d', $variableName, $suffix);
            $suffix++;
        }

        $usedVariableNames[] = $candidate;

        return $candidate;
    }

    private function escapeSingleQuotedString(string $value): string
    {
        return str_replace(['\\', '\''], ['\\\\', '\\\''], $value);
    }

    private function isRuntimeProp(string $propName): bool
    {
        return array_key_exists($propName, self::RUNTIME_PROP_VARIABLES);
    }

    private function isSimpleType(string $type): bool
    {
        return !str_contains($type, ' ') && !str_contains($type, '<');
    }

    private function isReservedWord(string $value): bool
    {
        return in_array($value, self::RESERVED_WORDS, true);
    }

    private function isValidIdentifier(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $value);
    }
}
