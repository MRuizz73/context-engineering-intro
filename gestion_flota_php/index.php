<?php
/** Sirve la interfaz web (aplicación de una sola página). */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/static/index.html');
