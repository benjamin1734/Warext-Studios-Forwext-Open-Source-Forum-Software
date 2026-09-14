<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Upload;

use Forwext\Core\Http\HttpException;

final class UploadNormalizer
{
    /**
     * @param array<string, mixed> $files
     * @return array<string, UploadedFile|array<array-key, mixed>>
     */
    public function normalize(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $specification) {
            if (!is_string($field) || !is_array($specification)) {
                throw new HttpException('Malformed PHP upload specification.');
            }

            $normalized[$field] = $this->normalizeSpecification($specification);
        }

        return $normalized;
    }

    /** @param array<string, mixed> $specification */
    private function normalizeSpecification(array $specification): UploadedFile|array
    {
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $required) {
            if (!array_key_exists($required, $specification)) {
                throw new HttpException(sprintf('Malformed upload: missing "%s" field.', $required));
            }
        }

        if (is_array($specification['name'])) {
            foreach (['type', 'tmp_name', 'error', 'size'] as $field) {
                if (!is_array($specification[$field])) {
                    throw new HttpException('Malformed nested upload specification.');
                }
            }

            return $this->normalizeNested(
                $specification['name'],
                $specification['type'],
                $specification['tmp_name'],
                $specification['error'],
                $specification['size'],
            );
        }

        return $this->leaf(
            $specification['tmp_name'],
            $specification['name'],
            $specification['type'],
            $specification['size'],
            $specification['error'],
        );
    }

    /**
     * @param array<array-key, mixed> $names
     * @param array<array-key, mixed> $types
     * @param array<array-key, mixed> $temporaryPaths
     * @param array<array-key, mixed> $errors
     * @param array<array-key, mixed> $sizes
     * @return array<array-key, UploadedFile|array<array-key, mixed>>
     */
    private function normalizeNested(
        array $names,
        array $types,
        array $temporaryPaths,
        array $errors,
        array $sizes,
    ): array {
        $normalized = [];

        foreach ($names as $key => $name) {
            if (
                !array_key_exists($key, $types)
                || !array_key_exists($key, $temporaryPaths)
                || !array_key_exists($key, $errors)
                || !array_key_exists($key, $sizes)
            ) {
                throw new HttpException('Nested upload arrays do not share the same shape.');
            }

            if (is_array($name)) {
                if (
                    !is_array($types[$key])
                    || !is_array($temporaryPaths[$key])
                    || !is_array($errors[$key])
                    || !is_array($sizes[$key])
                ) {
                    throw new HttpException('Malformed nested upload tree.');
                }

                $normalized[$key] = $this->normalizeNested(
                    $name,
                    $types[$key],
                    $temporaryPaths[$key],
                    $errors[$key],
                    $sizes[$key],
                );
                continue;
            }

            $normalized[$key] = $this->leaf(
                $temporaryPaths[$key],
                $name,
                $types[$key],
                $sizes[$key],
                $errors[$key],
            );
        }

        if (
            count($names) !== count($types)
            || count($names) !== count($temporaryPaths)
            || count($names) !== count($errors)
            || count($names) !== count($sizes)
        ) {
            throw new HttpException('Nested upload arrays do not share the same shape.');
        }

        return $normalized;
    }

    private function leaf(mixed $temporaryPath, mixed $name, mixed $type, mixed $size, mixed $error): UploadedFile
    {
        if (
            !is_string($temporaryPath)
            || (!is_string($name) && $name !== null)
            || (!is_string($type) && $type !== null)
            || !is_int($size)
            || !is_int($error)
        ) {
            throw new HttpException('Malformed upload leaf values.');
        }

        return new UploadedFile($temporaryPath, $name, $type, $size, $error);
    }
}
