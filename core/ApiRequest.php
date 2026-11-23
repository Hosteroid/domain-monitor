<?php

namespace Core;

/**
 * What an API controller works with: who is asking, and the request's values with their types
 * checked. A PHP warning ends in an error answer here, so nothing from the request may reach a
 * string function before its type is known.
 */
class ApiRequest
{
    /**
     * @param array    $user        id, username, role
     * @param array    $token       id, name, can_write, expires_at
     * @param int|null $scopeUserId The user id that limits what is seen in isolated mode, null in shared mode
     * @param array    $params      Parts of the path by name, URL-decoded
     * @param array    $settings    access (read, read_write), app_version
     */
    public function __construct(
        private array $user,
        private array $token,
        private ?int $scopeUserId,
        private array $params,
        private array $query,
        private array $body,
        private array $settings
    ) {
    }

    public function userId(): int
    {
        return (int)$this->user['id'];
    }

    public function username(): string
    {
        return (string)$this->user['username'];
    }

    public function isAdmin(): bool
    {
        return $this->user['role'] === 'admin';
    }

    public function scopeUserId(): ?int
    {
        return $this->scopeUserId;
    }

    public function token(): array
    {
        return $this->token;
    }

    public function setting(string $key): string
    {
        return (string)($this->settings[$key] ?? '');
    }

    public function param(string $name): string
    {
        return (string)($this->params[$name] ?? '');
    }

    /**
     * A query value as text, null when it is missing or empty
     */
    public function query(string $name, int $maxLength = 255): ?string
    {
        $value = $this->query[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ApiError(422, 'invalid', "The parameter \"$name\" must be a single value.");
        }

        $value = trim($value);
        if (strlen($value) > $maxLength) {
            throw new ApiError(422, 'invalid', "The parameter \"$name\" is too long.");
        }

        return $value === '' ? null : $value;
    }

    /**
     * A query value as a whole number, kept between $min and $max
     */
    public function queryInt(string $name, int $default, int $min, int $max): int
    {
        $value = $this->query($name, 12);
        if ($value === null) {
            return $default;
        }
        if (!preg_match('/^[0-9]{1,9}$/', $value)) {
            throw new ApiError(422, 'invalid', "The parameter \"$name\" must be a whole number.");
        }

        return max($min, min($max, (int)$value));
    }

    /**
     * One page of a list that is already in memory, cut by the page and per_page parameters.
     * A page past the last one is empty, so a client that reads until nothing comes back stops.
     *
     * @return array{0: array, 1: array} The rows of the page, and the meta block of the answer
     */
    public function paginate(array $rows): array
    {
        $perPage = $this->queryInt('per_page', 25, 1, 100);
        $page = $this->queryInt('page', 1, 1, 1000000);
        $total = count($rows);

        return [
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int)ceil($total / $perPage)],
        ];
    }

    /**
     * Whether the body holds a key, also when its value is null
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    /**
     * @return string[] Every key of the body
     */
    public function bodyKeys(): array
    {
        return array_map('strval', array_keys($this->body));
    }

    /**
     * A body value that has to be text
     */
    public function string(string $key, int $maxLength): string
    {
        $value = $this->body[$key] ?? null;
        if (!is_string($value)) {
            throw new ApiError(422, 'invalid', "\"$key\" must be text.");
        }
        if (strlen($value) > $maxLength) {
            throw new ApiError(422, 'invalid', "\"$key\" is too long.");
        }

        return $value;
    }

    /**
     * A body value that is text or null
     */
    public function stringOrNull(string $key, int $maxLength): ?string
    {
        return ($this->body[$key] ?? null) === null ? null : $this->string($key, $maxLength);
    }

    /**
     * A body value that has to be true or false. "true", 1 and "yes" are refused: a loose value
     * would switch something the caller did not mean.
     */
    public function bool(string $key): bool
    {
        $value = $this->body[$key] ?? null;
        if (!is_bool($value)) {
            throw new ApiError(422, 'invalid', "\"$key\" must be true or false.");
        }

        return $value;
    }

    /**
     * A body value that is a positive whole number or null
     */
    public function idOrNull(string $key): ?int
    {
        $value = $this->body[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 1) {
            throw new ApiError(422, 'invalid', "\"$key\" must be an id or null.");
        }

        return $value;
    }

    /**
     * A body value that has to be a list of texts
     *
     * @return string[]
     */
    public function stringList(string $key, int $maxItems, int $maxLength): array
    {
        $value = $this->body[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new ApiError(422, 'invalid', "\"$key\" must be a list, such as [\"a\", \"b\"].");
        }
        if (count($value) > $maxItems) {
            throw new ApiError(422, 'invalid', "\"$key\" holds more than $maxItems entries.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || strlen($item) > $maxLength) {
                throw new ApiError(422, 'invalid', "\"$key\" must hold texts of up to $maxLength characters.");
            }
        }

        return $value;
    }
}
