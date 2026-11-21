<?php
// import_bank.php FILE.csv [--booked-until=YYYY-MM-DD]  - bank CSV import + automatic matching (CLI)
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/bank.php';
$opt = getopt('', ['booked-until:'], $rest);
$file = $argv[$rest] ?? null;
if (!$file || !is_file($file)) { fwrite(STDERR, "usage: import_bank.php FILE.csv [--booked-until=YYYY-MM-DD]\n"); exit(1); }
$rows = bank_parse_csv(file_get_contents($file));
[$new, $dup] = bank_store($rows, $opt['booked-until'] ?? null);
[$auto, $left] = bank_auto();
printf("%d rows: %d new, %d duplicates; matched %d, open %d\n", count($rows), $new, $dup, $auto, $left);
