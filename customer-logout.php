<?php
session_start();
session_unset();
session_destroy();
header('Location: book_a_repair.php');
exit;
