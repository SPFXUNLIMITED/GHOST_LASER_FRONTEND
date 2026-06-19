<?php
function asset($file) {
    $filepath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($file, '/');
    if (file_exists($filepath)) {
        $version = filemtime($filepath);
        return '/' . ltrim($file, '/') . '?v=' . $version;
    }
    return '/' . ltrim($file, '/');
}
?>
