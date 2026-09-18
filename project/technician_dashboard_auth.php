<?php

function technicianDashboardHasAccess(): bool
{
    return !empty($_SESSION['admin_id']);
}
