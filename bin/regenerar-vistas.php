#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenera src/Infraestructura/Persistencia/definiciones_vistas.php desde
 * el motor, con SHOW CREATE VIEW.
 *
 * Correr esto en un entorno que SÍ tenga las vistas creadas (el de
 * desarrollo), cada vez que una migración agregue o cambie una vista.
 * El archivo generado es el que la app usa para inlinearlas en hostings
 * que no permiten CREATE VIEW. Al salir de SHOW CREATE VIEW, la
 * definición es fiel al centavo.
 *
 * Uso:  php bin/regenerar-vistas.php
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Persistencia\BaseDatos;

$pdo = BaseDatos::conexion();

$vistas = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")
    ->fetchAll(\PDO::FETCH_COLUMN);

if ($vistas === []) {
    exit("  No hay vistas en la base. ¿Corrió las migraciones en un host que las permita?\n");
}

$defs = [];

foreach ($vistas as $v) {
    $row    = $pdo->query('SHOW CREATE VIEW `' . $v . '`')->fetch(\PDO::FETCH_ASSOC);
    $create = (string) ($row['Create View'] ?? '');
    $pos    = stripos($create, ' AS ');

    if ($pos === false) {
        fwrite(STDERR, "  No pude leer la definición de {$v}\n");
        continue;
    }

    $defs[$v] = rtrim(trim(substr($create, $pos + 4)), "; \t\r\n");
}

$dest = dirname(__DIR__) . '/src/Infraestructura/Persistencia/definiciones_vistas.php';

$php = "<?php\n\n"
     . "/**\n"
     . " * Definiciones de las vistas, tomadas del motor con SHOW CREATE VIEW.\n"
     . " * Generado por bin/regenerar-vistas.php — NO editar a mano.\n"
     . " * Las usa FuenteVistas para inlinearlas y no depender de CREATE VIEW\n"
     . " * (los hostings gratis lo bloquean). Es la definición exacta.\n"
     . " */\n\n"
     . 'return ' . var_export($defs, true) . ";\n";

file_put_contents($dest, $php);

printf("  %d vista(s) → %s\n", count($defs), $dest);
foreach ($defs as $v => $s) {
    printf("    %-28s %d chars\n", $v, strlen($s));
}
