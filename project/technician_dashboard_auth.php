<?php

function technicianDashboardHasAccess(): bool
{
    return !empty($_SESSION['admin_id']);
}

function technicianDashboardAdminId(): int
{
    return (int) ($_SESSION['admin_id'] ?? 0);
}

function technicianDashboardCanAccessServiceRequest(PDO $pdo, int $serviceRequestId): bool
{
    $adminId = technicianDashboardAdminId();
    if ($adminId <= 0 || $serviceRequestId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM scheduled_cluster_jobs scj
         JOIN scheduled_clusters sc ON sc.id = scj.scheduled_cluster_id
         WHERE scj.service_request_id = :service_request_id
         LIMIT 1"
    );
    $stmt->execute([
        ':service_request_id' => $serviceRequestId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function technicianDashboardFetchAccessibleServiceRequest(PDO $pdo, int $serviceRequestId): ?array
{
    $adminId = technicianDashboardAdminId();
    if ($adminId <= 0 || $serviceRequestId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT
            sr.id,
            sr.customer_id,
            sr.problem_summary,
            sr.problem,
            sr.problem_details,
            sr.technician_notes,
            sr.services,
            sr.laser_brand,
            sr.laser_model,
            sr.laser_watts,
            sr.destination_street,
            sr.destination_city,
            sr.destination_state,
            sr.destination_zip,
            COALESCE(c.first_name, '') AS first_name,
            COALESCE(c.last_name, '') AS last_name,
            COALESCE(c.address, sr.destination_street) AS address,
            COALESCE(c.city, sr.destination_city) AS city,
            COALESCE(c.state, sr.destination_state) AS state,
            COALESCE(c.zip, sr.destination_zip) AS zip
         FROM service_requests sr
         JOIN scheduled_cluster_jobs scj ON scj.service_request_id = sr.id
         JOIN scheduled_clusters sc ON sc.id = scj.scheduled_cluster_id
         LEFT JOIN customers c ON c.id = sr.customer_id
         WHERE sr.id = :service_request_id
         LIMIT 1"
    );
    $stmt->execute([
        ':service_request_id' => $serviceRequestId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function technicianDashboardCanAccessAuthorization(PDO $pdo, int $authorizationId): bool
{
    $adminId = technicianDashboardAdminId();
    if ($adminId <= 0 || $authorizationId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT EXISTS(
            SELECT 1
            FROM service_authorizations sa
            WHERE sa.id = :authorization_id
              AND EXISTS (
                SELECT 1
                FROM scheduled_cluster_jobs scj
                JOIN scheduled_clusters sc ON sc.id = scj.scheduled_cluster_id
                WHERE scj.service_request_id = sa.service_request_id
                LIMIT 1
              )
         )"
    );
    $stmt->execute([
        ':authorization_id' => $authorizationId,
    ]);

    return (bool) $stmt->fetchColumn();
}
