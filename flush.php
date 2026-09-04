<?php if (function_exists('opcache_reset')) { opcache_reset(); echo 'done'; } else { echo 'opcache_reset not available'; }
