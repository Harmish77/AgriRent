<?php 
 
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'agrirent';

$conn = new mysqli($host,$user,$pass,$db);

if(!isset($conn))
{
    die('Connection error');
}

?>

