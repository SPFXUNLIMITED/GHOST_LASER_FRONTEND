<?php

function getSchedulingSettingsDefaults(): array
{
    return [
        'shop_address' => 'Yorba Linda, CA',
        'shop_latitude' => '33.888600',
        'shop_longitude' => '-117.813100',
        'business_start_time' => '08:00',
        'business_end_time' => '17:00',
        'default_buffer_between_jobs_minutes' => 30,
        'average_job_duration_minutes' => 120,
        'maximum_jobs_per_technician_per_day' => 4,
        'default_time_window_size_hours' => 2,
        'work_days' => 'mon-fri',
    ];
}

function getSchedulingWorkDayOptions(): array
{
    return [
        'mon-fri' => [
            'label' => 'Monday - Friday',
            'days' => [1, 2, 3, 4, 5],
        ],
        'mon-sat' => [
            'label' => 'Monday - Saturday',
            'days' => [1, 2, 3, 4, 5, 6],
        ],
        'sun-thu' => [
            'label' => 'Sunday - Thursday',
            'days' => [7, 1, 2, 3, 4],
        ],
        'tue-sat' => [
            'label' => 'Tuesday - Saturday',
            'days' => [2, 3, 4, 5, 6],
        ],
        'mon-sun' => [
            'label' => 'Monday - Sunday',
            'days' => [1, 2, 3, 4, 5, 6, 7],
        ],
    ];
}

function getSchedulingWorkDayNumbers(string $workDays): array
{
    $options = getSchedulingWorkDayOptions();

    return $options[$workDays]['days'] ?? $options['mon-fri']['days'];
}

function getSchedulingWorkDayLabel(string $workDays): string
{
    $options = getSchedulingWorkDayOptions();

    return $options[$workDays]['label'] ?? $options['mon-fri']['label'];
}

function ensureSchedulingSettingsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scheduling_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            shop_address VARCHAR(255) NOT NULL,
            shop_latitude DECIMAL(10,6) NOT NULL,
            shop_longitude DECIMAL(10,6) NOT NULL,
            business_start_time TIME NOT NULL,
            business_end_time TIME NOT NULL,
            default_buffer_between_jobs_minutes SMALLINT UNSIGNED NOT NULL,
            average_job_duration_minutes SMALLINT UNSIGNED NOT NULL,
            maximum_jobs_per_technician_per_day SMALLINT UNSIGNED NOT NULL,
            default_time_window_size_hours SMALLINT UNSIGNED NOT NULL,
            work_days VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
}

function seedSchedulingSettings(PDO $pdo): void
{
    $defaults = getSchedulingSettingsDefaults();

    $stmt = $pdo->prepare("
        INSERT INTO scheduling_settings (
            id,
            shop_address,
            shop_latitude,
            shop_longitude,
            business_start_time,
            business_end_time,
            default_buffer_between_jobs_minutes,
            average_job_duration_minutes,
            maximum_jobs_per_technician_per_day,
            default_time_window_size_hours,
            work_days
        ) VALUES (
            1,
            :shop_address,
            :shop_latitude,
            :shop_longitude,
            :business_start_time,
            :business_end_time,
            :default_buffer_between_jobs_minutes,
            :average_job_duration_minutes,
            :maximum_jobs_per_technician_per_day,
            :default_time_window_size_hours,
            :work_days
        )
    ");
    $stmt->execute([
        ':shop_address' => $defaults['shop_address'],
        ':shop_latitude' => $defaults['shop_latitude'],
        ':shop_longitude' => $defaults['shop_longitude'],
        ':business_start_time' => $defaults['business_start_time'],
        ':business_end_time' => $defaults['business_end_time'],
        ':default_buffer_between_jobs_minutes' => $defaults['default_buffer_between_jobs_minutes'],
        ':average_job_duration_minutes' => $defaults['average_job_duration_minutes'],
        ':maximum_jobs_per_technician_per_day' => $defaults['maximum_jobs_per_technician_per_day'],
        ':default_time_window_size_hours' => $defaults['default_time_window_size_hours'],
        ':work_days' => $defaults['work_days'],
    ]);
}

function normalizeSchedulingSettings(array $settings): array
{
    $defaults = getSchedulingSettingsDefaults();
    $merged = array_merge($defaults, $settings);

    $merged['shop_address'] = trim((string) $merged['shop_address']);
    $merged['shop_latitude'] = number_format((float) $merged['shop_latitude'], 6, '.', '');
    $merged['shop_longitude'] = number_format((float) $merged['shop_longitude'], 6, '.', '');
    $merged['business_start_time'] = substr((string) $merged['business_start_time'], 0, 5);
    $merged['business_end_time'] = substr((string) $merged['business_end_time'], 0, 5);
    $merged['default_buffer_between_jobs_minutes'] = (int) $merged['default_buffer_between_jobs_minutes'];
    $merged['average_job_duration_minutes'] = (int) $merged['average_job_duration_minutes'];
    $merged['maximum_jobs_per_technician_per_day'] = (int) $merged['maximum_jobs_per_technician_per_day'];
    $merged['default_time_window_size_hours'] = (int) $merged['default_time_window_size_hours'];
    $merged['work_days'] = array_key_exists($merged['work_days'], getSchedulingWorkDayOptions())
        ? $merged['work_days']
        : $defaults['work_days'];

    return $merged;
}

function getSchedulingSettings(PDO $pdo): array
{
    ensureSchedulingSettingsTable($pdo);
    $row = $pdo->query("SELECT * FROM scheduling_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        seedSchedulingSettings($pdo);
        $row = $pdo->query("SELECT * FROM scheduling_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }

    return normalizeSchedulingSettings(is_array($row) ? $row : []);
}

function updateSchedulingSettings(PDO $pdo, array $settings): void
{
    ensureSchedulingSettingsTable($pdo);

    $stmt = $pdo->prepare("
        UPDATE scheduling_settings
        SET
            shop_address = :shop_address,
            shop_latitude = :shop_latitude,
            shop_longitude = :shop_longitude,
            business_start_time = :business_start_time,
            business_end_time = :business_end_time,
            default_buffer_between_jobs_minutes = :default_buffer_between_jobs_minutes,
            average_job_duration_minutes = :average_job_duration_minutes,
            maximum_jobs_per_technician_per_day = :maximum_jobs_per_technician_per_day,
            default_time_window_size_hours = :default_time_window_size_hours,
            work_days = :work_days
        WHERE id = 1
    ");
    $stmt->execute([
        ':shop_address' => $settings['shop_address'],
        ':shop_latitude' => $settings['shop_latitude'],
        ':shop_longitude' => $settings['shop_longitude'],
        ':business_start_time' => $settings['business_start_time'],
        ':business_end_time' => $settings['business_end_time'],
        ':default_buffer_between_jobs_minutes' => $settings['default_buffer_between_jobs_minutes'],
        ':average_job_duration_minutes' => $settings['average_job_duration_minutes'],
        ':maximum_jobs_per_technician_per_day' => $settings['maximum_jobs_per_technician_per_day'],
        ':default_time_window_size_hours' => $settings['default_time_window_size_hours'],
        ':work_days' => $settings['work_days'],
    ]);
}

function calculateTechnicianDailyCapacity(array $settings): int
{
    return max(1, (int) $settings['maximum_jobs_per_technician_per_day']);
}

function getDisabledJsWeekdayIndexes(array $settings): array
{
    return array_values(array_map(
        static fn($dayNumber) => $dayNumber % 7,
        array_values(array_diff([1, 2, 3, 4, 5, 6, 7], getSchedulingWorkDayNumbers((string) $settings['work_days'])))
    ));
}
