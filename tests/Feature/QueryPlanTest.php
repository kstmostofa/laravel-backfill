<?php

use Kstmostofa\Backfill\DryRun\QueryPlan;

/**
 * These fixtures are verbatim EXPLAIN output captured from MySQL 8.4,
 * PostgreSQL 18 and SQLite, for a keyset query with the cursor predicate in
 * place. Parsing them here pins the classification down without depending on
 * what a planner decides to do with a five-row test table.
 */
it('reads a MySQL plan that walks the index', function () {
    $plan = QueryPlan::parse('mysql', [[
        'table' => 'bf_users',
        'type' => 'range',
        'possible_keys' => 'PRIMARY',
        'key' => 'PRIMARY',
        'rows' => 4,
        'Extra' => 'Using where',
    ]]);

    expect($plan->usesIndex)->toBeTrue()
        ->and($plan->detail)->toContain('Walks index PRIMARY');
});

it('catches a MySQL plan that sorts every batch', function () {
    $plan = QueryPlan::parse('mysql', [[
        'table' => 'bf_tags',
        'type' => 'ref',
        'key' => 'bf_tags_label_unique',
        'rows' => 5,
        'Extra' => 'Using index condition; Using where; Using filesort',
    ]]);

    // An index is in use — just not for the ordering, which is the part that
    // decides whether this run takes hours or days.
    expect($plan->usesIndex)->toBeFalse()
        ->and($plan->detail)->toContain('filesort');
});

it('catches a MySQL full table scan', function () {
    $plan = QueryPlan::parse('mysql', [[
        'table' => 'bf_users',
        'type' => 'ALL',
        'key' => null,
        'Extra' => 'Using where',
    ]]);

    expect($plan->usesIndex)->toBeFalse()
        ->and($plan->detail)->toContain('Full table scan');
});

it('reads a PostgreSQL index scan', function () {
    $plan = QueryPlan::parse('pgsql', [
        ['QUERY PLAN' => 'Limit  (cost=0.14..8.16 rows=10 width=1040)'],
        ['QUERY PLAN' => '  ->  Index Scan using bf_users_pkey on bf_users  (cost=0.14..8.16 rows=10 width=1040)'],
        ['QUERY PLAN' => '        Index Cond: (id > 1)'],
    ]);

    expect($plan->usesIndex)->toBeTrue()
        ->and($plan->detail)->toContain('Walks an index');
});

it('catches a PostgreSQL sort', function () {
    $plan = QueryPlan::parse('pgsql', [
        ['QUERY PLAN' => 'Limit  (cost=8.17..8.18 rows=1 width=1040)'],
        ['QUERY PLAN' => '  ->  Sort  (cost=8.17..8.18 rows=1 width=1040)'],
        ['QUERY PLAN' => '        Sort Key: name'],
        ['QUERY PLAN' => '        ->  Index Scan using bf_tags_label_unique on bf_tags  (cost=0.14..8.16 rows=1 width=1040)'],
    ]);

    expect($plan->usesIndex)->toBeFalse()
        ->and($plan->detail)->toContain('Sort node')
        // The caveat matters: PostgreSQL sorts small tables whatever you do.
        ->and($plan->detail)->toContain('production-sized data');
});

it('catches a PostgreSQL sequential scan', function () {
    $plan = QueryPlan::parse('pgsql', [
        ['QUERY PLAN' => 'Limit  (cost=0.00..10.88 rows=1 width=1060)'],
        ['QUERY PLAN' => '  ->  Seq Scan on bf_users  (cost=0.00..10.88 rows=1 width=1060)'],
    ]);

    expect($plan->usesIndex)->toBeFalse()
        ->and($plan->detail)->toContain('Sequential scan');
});

it('reads a SQLite primary key walk', function () {
    $plan = QueryPlan::parse('sqlite', [
        ['detail' => 'SEARCH bf_users USING INTEGER PRIMARY KEY (rowid>?)'],
    ]);

    expect($plan->usesIndex)->toBeTrue();
});

it('catches a SQLite temp b-tree sort', function () {
    $plan = QueryPlan::parse('sqlite', [
        ['detail' => 'SEARCH bf_tags USING INDEX bf_tags_label_unique (label=?)'],
        ['detail' => 'USE TEMP B-TREE FOR ORDER BY'],
    ]);

    expect($plan->usesIndex)->toBeFalse()
        ->and($plan->detail)->toContain('temp b-tree');
});

it('catches a SQLite full scan', function () {
    $plan = QueryPlan::parse('sqlite', [['detail' => 'SCAN bf_users']]);

    expect($plan->usesIndex)->toBeFalse();
});

it('says so when it cannot tell', function () {
    expect(QueryPlan::parse('oracle', [['x' => 'y']])->usesIndex)->toBeNull();
    expect(QueryPlan::parse('mysql', [])->usesIndex)->toBeNull();
    expect(QueryPlan::unknown()->label())->toBe('unknown');
});
