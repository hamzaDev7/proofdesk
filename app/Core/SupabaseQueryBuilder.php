<?php

namespace ProofDesk\Core;

class SupabaseQueryBuilder
{
    private SupabaseClient $client;
    private string $table;
    private array $queryParams = [];
    private array $headers = [];

    public function __construct(SupabaseClient $client, string $table)
    {
        $this->client = $client;
        $this->table = $table;
    }

    public function select(string $columns = '*'): self
    {
        $this->queryParams['select'] = $columns;
        return $this;
    }

    public function eq(string $column, mixed $value): self
    {
        $this->queryParams[$column] = 'eq.' . $value;
        return $this;
    }

    public function neq(string $column, mixed $value): self
    {
        $this->queryParams[$column] = 'neq.' . $value;
        return $this;
    }

    public function gt(string $column, mixed $value): self
    {
        $this->queryParams[$column] = 'gt.' . $value;
        return $this;
    }

    public function gte(string $column, mixed $value): self
    {
        $this->queryParams[$column] = 'gte.' . $value;
        return $this;
    }

    public function lt(string $column, mixed $value): self
    {
        $this->queryParams[$column] = 'lt.' . $value;
        return $this;
    }

    public function lte(string $column, mixed $value): self
    {
        $this->queryParams[$column] = 'lte.' . $value;
        return $this;
    }

    public function like(string $column, string $pattern): self
    {
        $this->queryParams[$column] = 'like.' . $pattern;
        return $this;
    }

    public function ilike(string $column, string $pattern): self
    {
        $this->queryParams[$column] = 'ilike.' . $pattern;
        return $this;
    }

    public function in(string $column, array $values): self
    {
        $this->queryParams[$column] = 'in.(' . implode(',', $values) . ')';
        return $this;
    }

    public function is(string $column, mixed $value): self
    {
        $this->queryParams[$column] = 'is.' . ($value === null ? 'null' : $value);
        return $this;
    }

    public function order(string $column, string $direction = 'asc'): self
    {
        $this->queryParams['order'] = $column . '.' . strtolower($direction);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->queryParams['limit'] = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->queryParams['offset'] = $offset;
        return $this;
    }

    private function buildEndpoint(): string
    {
        $endpoint = '/rest/v1/' . $this->table;
        if (!empty($this->queryParams)) {
            $endpoint .= '?' . http_build_query($this->queryParams);
        }
        return $endpoint;
    }

    public function get(): array
    {
        if (!isset($this->queryParams['select'])) {
            $this->select('*');
        }

        $res = $this->client->request('GET', $this->buildEndpoint(), null, $this->headers);
        if ($res['status'] >= 200 && $res['status'] < 300 && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();
        return !empty($results) ? $results[0] : null;
    }

    public function insert(array $data, bool $returnInserted = true): array
    {
        $headers = [];
        if ($returnInserted) {
            $headers[] = 'Prefer: return=representation';
        }

        $endpoint = '/rest/v1/' . $this->table;
        return $this->client->request('POST', $endpoint, $data, $headers);
    }

    public function update(array $data, bool $returnUpdated = true): array
    {
        $headers = [];
        if ($returnUpdated) {
            $headers[] = 'Prefer: return=representation';
        }

        return $this->client->request('PATCH', $this->buildEndpoint(), $data, $headers);
    }

    public function delete(): array
    {
        return $this->client->request('DELETE', $this->buildEndpoint());
    }

    public function count(): int
    {
        $headers = ['Prefer: count=exact'];
        $endpoint = '/rest/v1/' . $this->table . '?select=id&limit=0';
        $res = $this->client->request('HEAD', $endpoint, null, $headers);
        return 0; // fallback if head headers not captured
    }
}
