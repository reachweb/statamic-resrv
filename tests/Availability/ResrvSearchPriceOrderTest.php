<?php

namespace Reach\StatamicResrv\Tests\Availability;

use PHPUnit\Framework\TestCase;
use Reach\StatamicResrv\Scopes\ResrvSearch;

class ResrvSearchPriceOrderTest extends TestCase
{
    public function test_apply_price_order_emits_case_sql_with_positional_bindings(): void
    {
        $builder = new FakePriceOrderBuilder;
        $scope = new ResrvSearch;

        $applied = $this->invokeProtected($scope, 'applyPriceOrder', [$builder, ['101', '202', '303']]);

        $this->assertTrue($applied);
        $this->assertSame(1, $builder->reorderCalls);
        $this->assertSame(
            'CASE WHEN id = ? THEN ? WHEN id = ? THEN ? WHEN id = ? THEN ? END',
            $builder->rawSql,
        );
        $this->assertSame(['101', 0, '202', 1, '303', 2], $builder->rawBindings);
    }

    public function test_apply_price_order_returns_false_when_builder_lacks_order_by_raw(): void
    {
        $builder = new \stdClass;
        $scope = new ResrvSearch;

        $applied = $this->invokeProtected($scope, 'applyPriceOrder', [$builder, ['1']]);

        $this->assertFalse($applied);
    }

    public function test_can_apply_raw_order_recognizes_order_by_raw_method(): void
    {
        $builder = new FakePriceOrderBuilder;
        $scope = new ResrvSearch;

        $this->assertTrue($this->invokeProtected($scope, 'canApplyRawOrder', [$builder]));
    }

    public function test_can_apply_raw_order_rejects_plain_object(): void
    {
        $scope = new ResrvSearch;

        $this->assertFalse($this->invokeProtected($scope, 'canApplyRawOrder', [new \stdClass]));
    }

    public function test_bindings_are_positional_not_interpolated(): void
    {
        // Defense against SQL injection: even if an entryId contains SQL meta-chars,
        // the raw SQL string itself must never include the id value — it must travel
        // as a parameter binding.
        $builder = new FakePriceOrderBuilder;
        $scope = new ResrvSearch;
        $maliciousId = '1; DROP TABLE entries; --';

        $applied = $this->invokeProtected($scope, 'applyPriceOrder', [$builder, [$maliciousId]]);

        $this->assertTrue($applied);
        $this->assertStringNotContainsString('DROP TABLE', (string) $builder->rawSql);
        $this->assertSame([$maliciousId, 0], $builder->rawBindings);
    }

    private function invokeProtected(object $instance, string $method, array $args)
    {
        $ref = new \ReflectionMethod($instance, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($instance, $args);
    }
}

class FakePriceOrderBuilder
{
    public int $reorderCalls = 0;

    public ?string $rawSql = null;

    public ?array $rawBindings = null;

    public function reorder(): self
    {
        $this->reorderCalls++;

        return $this;
    }

    public function orderByRaw(string $sql, array $bindings = []): self
    {
        $this->rawSql = $sql;
        $this->rawBindings = $bindings;

        return $this;
    }
}
