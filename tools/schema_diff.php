<?php
/**
 * Compare the migration-built schema against the live one.
 *
 * Ignored deliberately:
 *  - AUTO_INCREMENT counters (row-count artefacts, not schema)
 *  - charset/collation (live dev is MySQL 8's utf8mb4_0900_ai_ci; migrations use
 *    the connection default so they restore on MariaDB, mirroring what the legacy
 *    SQL export already does)
 *  - Laravel's own `migrations` bookkeeping table
 */

$pdo = new PDO('mysql:host=127.0.0.1;dbname=information_schema;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$live = 'deskpulse';
$new  = 'deskpulse_verify';
$skip = ['migrations'];

function tables(PDO $p, string $db, array $skip): array
{
    $s = $p->prepare('SELECT TABLE_NAME FROM TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
    $s->execute([$db]);
    return array_values(array_diff(array_column($s->fetchAll(), 'TABLE_NAME'), $skip));
}

function columns(PDO $p, string $db, string $t): array
{
    $s = $p->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
           FROM COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
    );
    $s->execute([$db, $t]);
    $out = [];
    foreach ($s->fetchAll() as $c) {
        $extra = strtolower(trim(str_replace('DEFAULT_GENERATED', '', $c['EXTRA'])));
        $out[$c['COLUMN_NAME']] = strtolower($c['COLUMN_TYPE'])
            . '|null=' . $c['IS_NULLABLE']
            . '|def=' . ($c['COLUMN_DEFAULT'] === null ? 'NULL' : trim($c['COLUMN_DEFAULT'], "'"))
            . '|extra=' . trim($extra);
    }
    return $out;
}

function indexes(PDO $p, string $db, string $t): array
{
    $s = $p->prepare(
        'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
           FROM STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
          ORDER BY INDEX_NAME, SEQ_IN_INDEX'
    );
    $s->execute([$db, $t]);
    $idx = [];
    foreach ($s->fetchAll() as $r) {
        $idx[$r['INDEX_NAME']]['u'] = (int) $r['NON_UNIQUE'] === 0 ? 'unique' : 'index';
        $idx[$r['INDEX_NAME']]['c'][] = $r['COLUMN_NAME'];
    }
    $out = [];
    foreach ($idx as $n => $m) {
        $out[$n] = $m['u'] . '(' . implode(',', $m['c']) . ')';
    }
    ksort($out);
    return $out;
}

function fks(PDO $p, string $db, string $t): array
{
    $s = $p->prepare(
        'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
           FROM KEY_COLUMN_USAGE k
           JOIN REFERENTIAL_CONSTRAINTS r
             ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
          ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION'
    );
    $s->execute([$db, $t]);
    $out = [];
    foreach ($s->fetchAll() as $r) {
        $out[$r['CONSTRAINT_NAME']] = $r['COLUMN_NAME'] . '->' . $r['REFERENCED_TABLE_NAME']
            . '.' . $r['REFERENCED_COLUMN_NAME'] . '|del=' . $r['DELETE_RULE'] . '|upd=' . $r['UPDATE_RULE'];
    }
    ksort($out);
    return $out;
}

$problems = 0;
$lt = tables($pdo, $live, $skip);
$nt = tables($pdo, $new, $skip);

foreach (array_diff($lt, $nt) as $m) {
    echo "MISSING TABLE:  $m\n";
    $problems++;
}
foreach (array_diff($nt, $lt) as $m) {
    echo "EXTRA TABLE:    $m\n";
    $problems++;
}

foreach (array_intersect($lt, $nt) as $t) {
    foreach ([['columns', 'COLUMN'], ['indexes', 'INDEX'], ['fks', 'FK']] as [$fn, $label]) {
        $a = $fn($pdo, $live, $t);
        $b = $fn($pdo, $new, $t);

        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b)) {
                echo "MISSING $label:  $t.$k  ($v)\n";
                $problems++;
            } elseif ($b[$k] !== $v) {
                echo "DIFFERS $label:  $t.$k\n    live: $v\n    new : {$b[$k]}\n";
                $problems++;
            }
        }
        foreach ($b as $k => $v) {
            if (!array_key_exists($k, $a)) {
                echo "EXTRA $label:    $t.$k  ($v)\n";
                $problems++;
            }
        }
    }
}

echo "\n";
echo "live tables: " . count($lt) . "   rebuilt tables: " . count($nt) . "\n";
echo $problems === 0
    ? "RESULT: schemas are identical\n"
    : "RESULT: $problems difference(s)\n";
exit($problems === 0 ? 0 : 1);
