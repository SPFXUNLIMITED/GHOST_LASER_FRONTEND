<?php

require_once __DIR__ . '/../project/db.php';
require_once __DIR__ . '/../project/service_authorization.php';

ensureServiceAuthorizationSchema($pdo);

echo "service_authorizations schema ready.\n";
