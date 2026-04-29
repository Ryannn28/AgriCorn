<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = "localhost";
$dbUser = "root";
$dbPass = "";
$dbName = "agricorn";

$conn = new mysqli($host, $dbUser, $dbPass, $dbName);
$conn->set_charset("utf8mb4");
