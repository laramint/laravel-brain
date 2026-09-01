<?php

use LaraMint\LaravelBrain\Analysis\SchemaIssueBuilder;
use LaraMint\LaravelBrain\Analysis\TableSchema;

/**
 * @param  list<array{name: string, columns: list<string>, unique: bool, primary: bool}>  $indexes
 * @param  list<string>  $keyColumns
 */
function schemaWith(array $indexes, array $keyColumns = ['order_id']): TableSchema
{
    return new TableSchema(
        table: 'line_items',
        columns: [],
        indexes: $indexes,
        foreignKeys: [[
            'name' => 'line_items_order_id_foreign',
            'columns' => $keyColumns,
            'foreignTable' => 'orders',
            'foreignColumns' => ['id'],
            'onDelete' => 'cascade',
            'onUpdate' => null,
        ]],
    );
}

/** @param list<string> $columns */
function index(array $columns, string $name = 'ix'): array
{
    return ['name' => $name, 'columns' => $columns, 'unique' => false, 'primary' => false];
}

it('flags a foreign key with no index at all', function () {
    expect(schemaWith([])->unindexedForeignKeys())->toHaveCount(1);
});

it('accepts an index on exactly the key', function () {
    expect(schemaWith([index(['order_id'])])->unindexedForeignKeys())->toBe([]);
});

it('accepts a composite index that leads with the key', function () {
    // The engine can seek into `(order_id, created_at)` by `order_id` alone, so the key is read
    // efficiently and there is nothing to report.
    expect(schemaWith([index(['order_id', 'created_at'])])->unindexedForeignKeys())->toBe([]);
});

it('still flags a composite index that does not lead with the key', function () {
    // `(created_at, order_id)` cannot be seeked by its second column, so it does nothing for this
    // key. This is the case a set comparison would get wrong, and the reason the check compares
    // sequences.
    expect(schemaWith([index(['created_at', 'order_id'])])->unindexedForeignKeys())->toHaveCount(1);
});

it('accepts a leading prefix match for a composite key', function () {
    $schema = schemaWith([index(['tenant_id', 'order_id', 'created_at'])], ['tenant_id', 'order_id']);

    expect($schema->unindexedForeignKeys())->toBe([]);
});

it('flags a composite key whose columns appear in the wrong order', function () {
    $schema = schemaWith([index(['order_id', 'tenant_id'])], ['tenant_id', 'order_id']);

    expect($schema->unindexedForeignKeys())->toHaveCount(1);
});

it('raises a finding in the shape the graph already uses for routes', function () {
    $issues = (new SchemaIssueBuilder)->forTable(schemaWith([]));

    expect($issues)->toBeArray()
        ->and($issues['riskLevel'])->toBe('medium')
        ->and($issues['issues'])->toHaveCount(1)
        ->and($issues['issues'][0]['type'])->toBe('MISSING_FK_INDEX')
        ->and($issues['issues'][0]['message'])->toContain('order_id')
        ->and($issues['issues'][0]['message'])->toContain('orders');
});

it('raises nothing at all for a table whose keys are covered', function () {
    // A clean table must carry no payload, or every model in the application would light the
    // badge that exists to mean something is wrong.
    expect((new SchemaIssueBuilder)->forTable(schemaWith([index(['order_id'])])))->toBeNull();
});
