<?php
// config.php - local settings from data/config.ini (not in git), e.g.
//   [mayan]
//   ssh_host = user@mayan-host        ; host that runs tools/mayan_host/hv_mayan.py (keeps the Mayan token)
//   root_cabinet = 2                  ; Mayan cabinet id of the property (and its children)
declare(strict_types=1);

function cfg(string $section, string $key, mixed $default = null): mixed {
    static $ini = null;
    if ($ini === null) {
        $f = __DIR__ . '/../data/config.ini';
        $ini = is_file($f) ? (parse_ini_file($f, true, INI_SCANNER_TYPED) ?: []) : [];
    }
    return $ini[$section][$key] ?? $default;
}
