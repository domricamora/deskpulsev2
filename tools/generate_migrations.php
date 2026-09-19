<?php
/**
 * Generate Laravel migrations from the LIVE deskpulse schema.
 *
 * Reads information_schema rather than parsing SHOW CREATE TABLE text, so column
 * types, nullability, defaults, indexes and foreign keys come through structured.
 *
 * Deliberate choices:
 *  - Money columns keep their existing SQL types (decision D1). `double` stays
 *    `double`; nothing is "corrected" to decimal here.
 *  - No charset/collation is emitted per table. The live dev database uses
 *    utf8mb4_0900_ai_ci, which only exists on MySQL 8+; the legacy SQL export
 *    downgrades it precisely so dumps restore on MariaDB and older MySQL. Letting
 *    the connection default apply keeps the migrations portable to whatever the
 *    production host runs.
 *  - Tables are emitted in foreign-key dependency order, with sequential
 *    timestamps, so `migrate` never hits a missing referenced table.
 */

$host = '127.0.0.1';
$db   = 'deskpulse';
$user = 'root';
$pass = '';
$outDir = getenv('DP_MIGRATIONS_DIR') ?: (getcwd() . '/database/migrations');
if (!is_dir($outDir)) {
    fwrite(STDERR, "migrations dir not found: $outDir\n");
    exit(1);
}

$pdo = new PDO("mysql:host=$host;dbname=information_schema;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

/* ── tables ── */
$tables = $pdo->prepare('SELECT TABLE_NAME FROM TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
$tables->execute([$db]);
$tables = array_column($tables->fetchAll(), 'TABLE_NAME');

/* ── foreign keys ── */
$fkStmt = $pdo->prepare(
    'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME,
            k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
            r.DELETE_RULE, r.UPDATE_RULE
       FROM KEY_COLUMN_USAGE k
       JOIN REFERENTIAL_CONSTRAINTS r
         ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
        AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
      WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
      ORDER BY k.TABLE_NAME, k.ORDINAL_POSITION'
);
$fkStmt->execute([$db]);
$fks = [];
foreach ($fkStmt->fetchAll() as $r) {
    $fks[$r['TABLE_NAME']][] = $r;
}

/* ── topological order so referenced tables are created first ── */
$order = [];
$seen = [];
$visit = function (string $t) use (&$visit, &$order, &$seen, $fks, $tables) {
    if (isset($seen[$t])) {
        return;
    }
    $seen[$t] = 'visiting';
    foreach ($fks[$t] ?? [] as $fk) {
        $ref = $fk['REFERENCED_TABLE_NAME'];
        // Self-references need no ordering, and a cycle must not loop forever.
        if ($ref !== $t && in_array($ref, $tables, true) && ($seen[$ref] ?? '') !== 'visiting') {
            $visit($ref);
        }
    }
    $seen[$t] = 'done';
    $order[] = $t;
};
foreach ($tables as $t) {
    $visit($t);
}

/* ── column mapping ── */
function blueprintFor(array $c): array
{
    $name = $c['COLUMN_NAME'];
    $type = strtolower($c['COLUMN_TYPE']);
    $data = strtolower($c['DATA_TYPE']);
    $auto = str_contains(strtolower($c['EXTRA']), 'auto_increment');
    $unsigned = str_contains($type, 'unsigned');

    $len = null;
    if (preg_match('/\((\d+)(?:,(\d+))?\)/', $type, $m)) {
        $len = [(int) $m[1], isset($m[2]) ? (int) $m[2] : null];
    }

    $q = "'" . addslashes($name) . "'";

    if ($auto) {
        // NOT increments()/bigIncrements(): those force UNSIGNED, while the live
        // schema uses SIGNED int for every primary key. An unsigned PK makes every
        // signed foreign key incompatible (MySQL error 3780). The three-argument
        // form keeps the exact signedness.
        $u = $unsigned ? 'true' : 'false';
        return match ($data) {
            'bigint'   => ["bigInteger($q, true, $u)", true],
            'smallint' => ["smallInteger($q, true, $u)", true],
            'tinyint'  => ["tinyInteger($q, true, $u)", true],
            default    => ["integer($q, true, $u)", true],
        };
    }

    $call = match (true) {
        $data === 'tinyint' && $type === 'tinyint(1)' => "boolean($q)",
        $data === 'tinyint'    => "tinyInteger($q)",
        $data === 'smallint'   => "smallInteger($q)",
        $data === 'mediumint'  => "mediumInteger($q)",
        $data === 'int'        => "integer($q)",
        $data === 'bigint'     => "bigInteger($q)",
        $data === 'decimal'    => "decimal($q, {$len[0]}, {$len[1]})",
        $data === 'double'     => "double($q)",
        $data === 'float'      => "float($q)",
        $data === 'varchar'    => "string($q, {$len[0]})",
        $data === 'char'       => "char($q, {$len[0]})",
        $data === 'text'       => "text($q)",
        $data === 'mediumtext' => "mediumText($q)",
        $data === 'longtext'   => "longText($q)",
        $data === 'tinytext'   => "tinyText($q)",
        $data === 'json'       => "json($q)",
        $data === 'date'       => "date($q)",
        $data === 'datetime'   => "dateTime($q)",
        $data === 'timestamp'  => "timestamp($q)",
        $data === 'time'       => "time($q)",
        $data === 'blob'       => "binary($q)",
        default                => null,
    };

    if ($call === null) {
        // Anything the Blueprint cannot express is emitted raw, so nothing is lost.
        return ["addColumn('string', $q) /* RAW: {$c['COLUMN_TYPE']} */", false, $c['COLUMN_TYPE']];
    }

    $chain = '';
    if ($unsigned) {
        $chain .= '->unsigned()';
    }
    if ($c['IS_NULLABLE'] === 'YES') {
        $chain .= '->nullable()';
    }

    $def = $c['COLUMN_DEFAULT'];
    if ($def !== null) {
        $u = strtoupper($def);
        if ($u === 'CURRENT_TIMESTAMP' || str_starts_with($u, 'CURRENT_TIMESTAMP(')) {
            $chain .= "->useCurrent()";
        } elseif (in_array($data, ['int','bigint','smallint','tinyint','mediumint','decimal','double','float'], true)) {
            $n = trim($def, "'");
            $chain .= is_numeric($n) ? "->default($n)" : "->default('" . addslashes($n) . "')";
        } else {
            $chain .= "->default('" . addslashes(trim($def, "'")) . "')";
        }
    } elseif ($c['IS_NULLABLE'] === 'YES') {
        // nullable() already implies a null default
    }

    if (str_contains(strtolower($c['EXTRA']), 'on update current_timestamp')) {
        $chain .= '->useCurrentOnUpdate()';
    }

    return [$call . $chain, false];
}

/* ── emit ── */
$ts = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
$i = 0;
$written = [];

foreach ($order as $table) {
    $cols = $pdo->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
           FROM COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
    );
    $cols->execute([$db, $table]);
    $cols = $cols->fetchAll();

    $idx = $pdo->prepare(
        'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
           FROM STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
          ORDER BY INDEX_NAME, SEQ_IN_INDEX'
    );
    $idx->execute([$db, $table]);
    $indexes = [];
    foreach ($idx->fetchAll() as $r) {
        $indexes[$r['INDEX_NAME']]['unique'] = ((int) $r['NON_UNIQUE'] === 0);
        $indexes[$r['INDEX_NAME']]['cols'][] = $r['COLUMN_NAME'];
    }

    $lines = [];
    $rawAfter = [];
    foreach ($cols as $c) {
        [$expr, , $raw] = array_pad(blueprintFor($c), 3, null);
        if ($raw !== null) {
            $rawAfter[] = [$c['COLUMN_NAME'], $raw];
        }
        $lines[] = "            \$table->{$expr};";
    }

    // Indexes, excluding PRIMARY (covered by increments) and any index that exactly
    // duplicates a foreign key's own column list — MySQL creates those implicitly.
    $fkCols = [];
    foreach ($fks[$table] ?? [] as $fk) {
        $fkCols[$fk['CONSTRAINT_NAME']][] = $fk['COLUMN_NAME'];
    }

    $idxLines = [];
    foreach ($indexes as $name => $meta) {
        if ($name === 'PRIMARY') {
            continue;
        }
        $colList = "['" . implode("', '", $meta['cols']) . "']";
        $method = $meta['unique'] ? 'unique' : 'index';
        $idxLines[] = "            \$table->{$method}({$colList}, '" . addslashes($name) . "');";
    }

    $fkLines = [];
    foreach ($fkCols as $cname => $cList) {
        $first = null;
        foreach ($fks[$table] as $fk) {
            if ($fk['CONSTRAINT_NAME'] === $cname) {
                $first = $fk;
                break;
            }
        }
        $cols2 = "['" . implode("', '", $cList) . "']";
        $ref = "'" . $first['REFERENCED_COLUMN_NAME'] . "'";
        $on = "'" . $first['REFERENCED_TABLE_NAME'] . "'";
        $fkLine = "            \$table->foreign({$cols2}, '" . addslashes($cname) . "')->references({$ref})->on({$on})";
        if (strtoupper($first['DELETE_RULE']) !== 'NO ACTION') {
            $fkLine .= "->onDelete('" . strtolower($first['DELETE_RULE']) . "')";
        }
        if (strtoupper($first['UPDATE_RULE']) !== 'NO ACTION') {
            $fkLine .= "->onUpdate('" . strtolower($first['UPDATE_RULE']) . "')";
        }
        $fkLines[] = $fkLine . ';';
    }

    $body = implode("\n", $lines);
    if ($idxLines) {
        $body .= "\n\n" . implode("\n", $idxLines);
    }
    if ($fkLines) {
        $body .= "\n\n" . implode("\n", $fkLines);
    }

    $rawBlock = '';
    foreach ($rawAfter as [$col, $type]) {
        $rawBlock .= "\n        DB::statement('ALTER TABLE `{$table}` MODIFY `{$col}` {$type}');";
    }

    $stamp = $ts->modify('+' . (++$i) . ' seconds')->format('Y_m_d_His');
    $file = "{$stamp}_create_{$table}_table.php";

    $useDb = $rawBlock !== '' ? "use Illuminate\\Support\\Facades\\DB;\n" : '';

    $php = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
{$useDb}use Illuminate\Support\Facades\Schema;

/**
 * Generated from the live DeskPulse schema, which is the source of truth —
 * server/schema.sql covers only 30 of the 37 tables.
 *
 * Column types are reproduced as they exist today, including `double` money
 * columns (decision D1: migrate as-is so report parity can be proven, convert
 * later under test).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
{$body}
        });{$rawBlock}
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};

PHP;

    file_put_contents("$outDir/$file", $php);
    $written[] = $file;
}

echo "generated " . count($written) . " migrations\n";
foreach (array_slice($written, 0, 5) as $w) {
    echo "  $w\n";
}
echo "  ...\n";
