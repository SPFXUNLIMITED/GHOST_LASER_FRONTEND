<?php
/**
 * Minimal test bootstrap for technician/schedule.php.
 *
 * schedule.php has no dependency injection / include-guard split between
 * its pure functions and its request-handling code, so it cannot be
 * require()'d directly in a test process (it would call session_start(),
 * require the live DB connection, hit headers, etc.). Since the file is
 * never executed under a real HTTP request, everything above the first
 * `session_start();` call is pure function/constant definitions with no
 * side effects, so we extract and load just that portion here.
 */

function ghostLaserLoadScheduleFunctions(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $source = file_get_contents(__DIR__ . '/../technician/schedule.php');
    if ($source === false) {
        throw new RuntimeException('Unable to read technician/schedule.php.');
    }

    $cutPosition = strpos($source, 'session_start();');
    if ($cutPosition === false) {
        throw new RuntimeException('Could not locate the session_start() marker used to isolate pure functions in schedule.php.');
    }

    $functionsOnly = substr($source, 0, $cutPosition);

    $tmpFile = tempnam(sys_get_temp_dir(), 'schedule_functions_');
    file_put_contents($tmpFile, $functionsOnly);
    require $tmpFile;
    unlink($tmpFile);
}

ghostLaserLoadScheduleFunctions();

/**
 * Build an in-memory SQLite PDO connection seeded with a `services` table
 * (matching the columns resolveJobDurationsFromServices()/
 * repairServiceRequestsServiceNames() rely on) and an empty
 * `service_requests` table.
 *
 * @param array<int, array{id:int, name:string, duration:int}> $services
 */
function ghostLaserMakeTestPdo(array $services): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec('CREATE TABLE services (
        id INTEGER PRIMARY KEY,
        service_name TEXT NOT NULL,
        duration_minutes INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE service_requests (
        id INTEGER PRIMARY KEY,
        services TEXT
    )');

    $stmt = $pdo->prepare('INSERT INTO services (id, service_name, duration_minutes) VALUES (:id, :name, :duration)');
    foreach ($services as $row) {
        $stmt->execute([
            ':id'       => $row['id'],
            ':name'     => $row['name'],
            ':duration' => $row['duration'],
        ]);
    }

    return $pdo;
}

/**
 * Insert a service_requests row for a test.
 */
function ghostLaserInsertServiceRequest(PDO $pdo, int $id, ?string $servicesJson): void
{
    $stmt = $pdo->prepare('INSERT INTO service_requests (id, services) VALUES (:id, :services)');
    $stmt->execute([':id' => $id, ':services' => $servicesJson]);
}

function ghostLaserGetServiceRequestServices(PDO $pdo, int $id): ?string
{
    $stmt = $pdo->prepare('SELECT services FROM service_requests WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}
