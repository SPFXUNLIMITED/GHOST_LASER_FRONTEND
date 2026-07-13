<?php
session_start();
session_unset();
session_destroy();
header('Location: customer-login.php?step=1');
exit;
