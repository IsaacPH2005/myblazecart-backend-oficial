<?php
$inputFile = 'backup.sql'; // Ruta a tu archivo de backup
$outputFile = 'datos_solo.sql'; // Archivo de salida

$input = fopen($inputFile, 'r');
$output = fopen($outputFile, 'w');

$inCreateTable = false;
$inConstraints = false;

while (($line = fgets($input)) !== false) {
    // Saltar sección de restricciones
    if (strpos($line, '-- Restricciones para tablas volcadas') !== false) {
        $inConstraints = true;
    }

    if ($inConstraints) {
        continue;
    }

    // Saltar sentencias CREATE TABLE
    if (strpos($line, 'CREATE TABLE') !== false) {
        $inCreateTable = true;
    }

    if ($inCreateTable) {
        if (strpos($line, ';') !== false) {
            $inCreateTable = false;
        }
        continue;
    }

    // Escribir líneas que no son CREATE TABLE ni restricciones
    fwrite($output, $line);
}

fclose($input);
fclose($output);

echo "Archivo procesado. Resultado en: $outputFile";
