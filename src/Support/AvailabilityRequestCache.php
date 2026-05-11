<?php

namespace Reach\StatamicResrv\Support;

class AvailabilityRequestCache
{
    /**
     * @var array<string, array{result: array, sortedIds: ?array, orderApplied: bool}>
     */
    protected array $store = [];

    public function put(array $searchData, array $result, ?array $sortedIds = null, bool $orderApplied = false): void
    {
        $this->store[$this->key($searchData)] = [
            'result' => $result,
            'sortedIds' => $sortedIds,
            'orderApplied' => $orderApplied,
        ];
    }

    /**
     * @return array{result: array, sortedIds: ?array, orderApplied: bool}|null
     */
    public function get(array $searchData): ?array
    {
        return $this->store[$this->key($searchData)] ?? null;
    }

    public function has(array $searchData): bool
    {
        return isset($this->store[$this->key($searchData)]);
    }

    public function forget(array $searchData): void
    {
        unset($this->store[$this->key($searchData)]);
    }

    public function flush(): void
    {
        $this->store = [];
    }

    protected function key(array $searchData): string
    {
        $advanced = $searchData['advanced'] ?? '';
        if (is_array($advanced)) {
            sort($advanced);
            $advanced = implode(',', $advanced);
        }

        return md5(json_encode([
            'date_start' => (string) ($searchData['date_start'] ?? ''),
            'date_end' => (string) ($searchData['date_end'] ?? ''),
            'quantity' => (int) ($searchData['quantity'] ?? 1),
            'advanced' => (string) $advanced,
        ]));
    }
}
