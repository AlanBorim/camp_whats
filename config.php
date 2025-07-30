<?php

define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'whats_rep');
define('DB_PASSWORD', '@8.LiRdsQC.7MHYj');
define('DB_NAME', 'campanha_whats');

// Conexão com o banco de dados
$mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Checar conexão
if ($mysqli->connect_error) {
    die("ERRO: Não foi possível conectar. " . $mysqli->connect_error);
}
?>
