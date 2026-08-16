<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/prospects_schema.php';
require_once __DIR__ . '/project/prospect_tools.php';

prospectsEnsureSchema($pdo);

if (empty($_SESSION['prospects_csrf'])) {
    $_SESSION['prospects_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['prospects_csrf'];
$adminId = (int) ($_SESSION['admin_id'] ?? 0);

function prospectAllowedStatuses(): array
{
    return array_keys(prospectStatuses());
}

function prospectCategorySlugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function prospectCleanDateTimeInput(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    if ($dt !== false) {
        return $dt->format('Y-m-d H:i:s');
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if ($dt !== false) {
        return $dt->format('Y-m-d H:i:s');
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value);
    if ($dt !== false) {
        return $dt->format('Y-m-d H:i:s');
    }
    return null;
}

function prospectNowLosAngeles(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d H:i:s');
}

function prospectFormatDisplayDateTime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $timezone = new DateTimeZone('America/Los_Angeles');
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value, $timezone);
        if ($dt !== false) {
            return $dt->format($format === 'Y-m-d' ? 'm/d/Y' : 'm/d/Y g:i A');
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone($timezone)
            ->format('m/d/Y g:i A');
    }

    return $value;
}

function prospectNormalizeKeyword(string $value): string
{
    $value = prospectSanitizeField($value, 255);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function prospectNormalizeKeywordList(string $value): array
{
    $lines = preg_split('/\R/', $value) ?: [];
    $keywords = [];
    foreach ($lines as $line) {
        $keyword = prospectNormalizeKeyword((string) $line);
        if ($keyword !== '') {
            $keywords[] = $keyword;
        }
    }

    return array_values(array_unique($keywords));
}

function prospectSplitContactName(string $contactName): array
{
    $contactName = trim($contactName);
    if ($contactName === '') {
        return ['', ''];
    }
    $parts = preg_split('/\s+/', $contactName, 2) ?: [];
    return [
        $parts[0] ?? '',
        $parts[1] ?? '',
    ];
}

function prospectFindDuplicate(PDO $pdo, int $prospectId, string $email, string $phone, string $company): ?array
{
    $conditions = [];
    $params = [':id' => $prospectId];

    if ($email !== '') {
        $conditions[] = 'LOWER(email) = LOWER(:email)';
        $params[':email'] = $email;
    }
    if ($phone !== '') {
        $conditions[] = 'phone = :phone';
        $params[':phone'] = $phone;
    }
    if ($company !== '') {
        $conditions[] = 'LOWER(company) = LOWER(:company)';
        $params[':company'] = $company;
    }

    if ($conditions === []) {
        return null;
    }

    $sql = "
        SELECT id, company, contact_name, email, phone
        FROM prospects
        WHERE is_archived = 0
          AND id != :id
          AND (" . implode(' OR ', $conditions) . ")
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function prospectFindCustomerDuplicate(PDO $pdo, string $email, string $phone, string $company): ?array
{
    $conditions = [];
    $params = [];

    if ($email !== '') {
        $conditions[] = 'LOWER(email) = LOWER(:email)';
        $params[':email'] = $email;
    }
    if ($phone !== '') {
        $conditions[] = 'phone = :phone';
        $params[':phone'] = $phone;
    }
    if ($company !== '') {
        $conditions[] = 'LOWER(company) = LOWER(:company)';
        $params[':company'] = $company;
    }

    if ($conditions === []) {
        return null;
    }

    $sql = "
        SELECT id, company, first_name, last_name, email, phone
        FROM customers
        WHERE " . implode(' OR ', $conditions) . "
        ORDER BY id ASC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function prospectGetCustomerColumns(PDO $pdo): array
{
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        return is_array($columns) ? $columns : [];
    } catch (Throwable $e) {
        return [];
    }
}

$categoryRows = $pdo->query("
    SELECT id, name, slug
    FROM prospect_categories
    ORDER BY name ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$categoriesById = [];
$categoriesBySlug = [];
foreach ($categoryRows as $categoryRow) {
    $categoryId = (int) ($categoryRow['id'] ?? 0);
    $categoryName = trim((string) ($categoryRow['name'] ?? ''));
    if ($categoryId <= 0 || $categoryName === '') {
        continue;
    }
    $categorySlug = prospectCategorySlugify((string) ($categoryRow['slug'] ?? ''));
    if ($categorySlug === '') {
        $categorySlug = prospectCategorySlugify($categoryName);
    }
    $normalizedCategory = [
        'id' => $categoryId,
        'name' => $categoryName,
        'slug' => $categorySlug,
    ];
    $categoriesById[$categoryId] = $normalizedCategory;
    if ($categorySlug !== '') {
        $categoriesBySlug[$categorySlug] = $normalizedCategory;
    }
}

$rawCategoryParam = trim((string) ($_GET['category'] ?? ($_POST['category'] ?? '')));
$activeCategory = null;
if ($rawCategoryParam !== '') {
    if (ctype_digit($rawCategoryParam)) {
        $activeCategory = $categoriesById[(int) $rawCategoryParam] ?? null;
    } else {
        $activeCategory = $categoriesBySlug[prospectCategorySlugify($rawCategoryParam)] ?? null;
    }
}

$flashSuccess = '';
$flashError = '';
if (!empty($_SESSION['prospects_flash_success'])) {
    $flashSuccess = (string) $_SESSION['prospects_flash_success'];
    unset($_SESSION['prospects_flash_success']);
}
if (!empty($_SESSION['prospects_flash_error'])) {
    $flashError = (string) $_SESSION['prospects_flash_error'];
    unset($_SESSION['prospects_flash_error']);
}
if ($rawCategoryParam !== '' && $activeCategory === null && $flashError === '') {
    $flashError = 'Category not found.';
}

$categoryKeywords = [];
if ($activeCategory !== null) {
    $keywordStmt = $pdo->prepare("
        SELECT keyword
        FROM prospect_category_keywords
        WHERE category_id = :category_id
        ORDER BY keyword ASC
    ");
    $keywordStmt->execute([
        ':category_id' => (int) $activeCategory['id'],
    ]);
    $categoryKeywords = $keywordStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = trim((string) ($_POST['csrf'] ?? ''));
    $action = trim((string) ($_POST['action'] ?? ''));
    $queryStatus = trim((string) ($_POST['status_filter'] ?? 'all'));
    $querySearch = trim((string) ($_POST['q'] ?? ''));
    $queryCategory = trim((string) ($_POST['category'] ?? ''));
    $redirectQs = http_build_query(array_filter([
        'category' => $queryCategory,
        'status' => $queryStatus,
        'q' => $querySearch,
    ], static fn($value) => $value !== ''));

    if ($postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
        $_SESSION['prospects_flash_error'] = 'Invalid security token.';
        header('Location: prospects.php' . ($redirectQs !== '' ? '?' . $redirectQs : ''));
        exit;
    }

    try {
        if ($action === 'add_category') {
            $categoryName = prospectSanitizeField((string) ($_POST['category_name'] ?? ''));
            $requestedSlug = prospectCategorySlugify((string) ($_POST['category_slug'] ?? ''));
            $categorySlug = $requestedSlug !== '' ? $requestedSlug : prospectCategorySlugify($categoryName);
            if ($categoryName === '') {
                throw new RuntimeException('Category name is required.');
            }
            if ($categorySlug === '') {
                throw new RuntimeException('Category slug is invalid.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO prospect_categories (name, slug)
                VALUES (:name, :slug)
            ");

            try {
                $stmt->execute([
                    ':name' => $categoryName,
                    ':slug' => $categorySlug,
                ]);
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    throw new RuntimeException('Category already exists. Try a different name.');
                }
                throw $e;
            }

            $_SESSION['prospects_flash_success'] = 'Category created.';
            $redirectQs = http_build_query(array_filter([
                'category' => $categorySlug,
                'status' => $queryStatus,
                'q' => $querySearch,
            ], static fn($value) => $value !== ''));
        } elseif ($action === 'add_category_keyword') {
            if ($activeCategory === null) {
                throw new RuntimeException('Select a category before managing keywords.');
            }

            $keyword = prospectNormalizeKeyword((string) ($_POST['keyword'] ?? ''));
            if ($keyword === '') {
                throw new RuntimeException('Keyword is required.');
            }

            $stmt = $pdo->prepare("
                INSERT IGNORE INTO prospect_category_keywords (category_id, keyword)
                VALUES (:category_id, :keyword)
            ");
            $stmt->execute([
                ':category_id' => (int) $activeCategory['id'],
                ':keyword' => $keyword,
            ]);

            $_SESSION['prospects_flash_success'] = $stmt->rowCount() > 0
                ? 'Keyword added.'
                : 'Keyword already exists for this category.';
        } elseif ($action === 'bulk_add_category_keywords') {
            if ($activeCategory === null) {
                throw new RuntimeException('Select a category before managing keywords.');
            }

            $keywords = prospectNormalizeKeywordList((string) ($_POST['bulk_keywords'] ?? ''));
            if ($keywords === []) {
                throw new RuntimeException('Enter at least one keyword.');
            }

            $stmt = $pdo->prepare("
                INSERT IGNORE INTO prospect_category_keywords (category_id, keyword)
                VALUES (:category_id, :keyword)
            ");

            $addedCount = 0;
            foreach ($keywords as $keyword) {
                $stmt->execute([
                    ':category_id' => (int) $activeCategory['id'],
                    ':keyword' => $keyword,
                ]);
                $addedCount += $stmt->rowCount();
            }

            if ($addedCount > 0) {
                $_SESSION['prospects_flash_success'] = $addedCount === 1
                    ? '1 keyword added.'
                    : $addedCount . ' keywords added.';
            } else {
                $_SESSION['prospects_flash_success'] = 'All pasted keywords already exist for this category.';
            }
        } elseif ($action === 'bulk_add_categories') {
            $rawLines = explode("\n", (string) ($_POST['bulk_category_names'] ?? ''));
            $names = [];
            foreach ($rawLines as $line) {
                $name = prospectSanitizeField(trim($line));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            $names = array_values(array_unique($names));
            if ($names === []) {
                throw new RuntimeException('Enter at least one category name.');
            }

            $insertStmt = $pdo->prepare("
                INSERT IGNORE INTO prospect_categories (name, slug)
                VALUES (:name, :slug)
            ");

            $addedCount = 0;
            foreach ($names as $name) {
                $slug = prospectCategorySlugify($name);
                if ($slug === '') {
                    continue;
                }
                $insertStmt->execute([':name' => $name, ':slug' => $slug]);
                $addedCount += $insertStmt->rowCount();
            }

            if ($addedCount > 0) {
                $_SESSION['prospects_flash_success'] = $addedCount === 1
                    ? '1 category created.'
                    : $addedCount . ' categories created.';
            } else {
                $_SESSION['prospects_flash_success'] = 'All pasted categories already exist.';
            }
        } elseif ($action === 'remove_category_keyword') {
            if ($activeCategory === null) {
                throw new RuntimeException('Select a category before managing keywords.');
            }

            $keyword = prospectNormalizeKeyword((string) ($_POST['keyword'] ?? ''));
            if ($keyword === '') {
                throw new RuntimeException('Keyword is required.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM prospect_category_keywords
                WHERE category_id = :category_id
                  AND keyword = :keyword
                LIMIT 1
            ");
            $stmt->execute([
                ':category_id' => (int) $activeCategory['id'],
                ':keyword' => $keyword,
            ]);

            $_SESSION['prospects_flash_success'] = 'Keyword removed.';
        } elseif ($action === 'delete_category') {
            $deleteCategoryId = (int) ($_POST['category_id'] ?? 0);
            if ($deleteCategoryId <= 0) {
                throw new RuntimeException('Invalid category.');
            }
            $pdo->prepare("DELETE FROM prospect_category_keywords WHERE category_id = :id")->execute([':id' => $deleteCategoryId]);
            $pdo->prepare("DELETE FROM prospect_categories WHERE id = :id LIMIT 1")->execute([':id' => $deleteCategoryId]);
            $_SESSION['prospects_flash_success'] = 'Category deleted.';
            $redirectQs = http_build_query(array_filter([
                'status' => $queryStatus,
                'q' => $querySearch,
            ], static fn($value) => $value !== ''));
        } elseif ($action === 'save_prospect') {
            $prospectId = (int) ($_POST['prospect_id'] ?? 0);
            $company = prospectSanitizeField((string) ($_POST['company'] ?? ''));
            $contactName = prospectSanitizeField((string) ($_POST['contact_name'] ?? ''));
            $phone = prospectSanitizeField((string) ($_POST['phone'] ?? ''), 100);
            $email = strtolower(prospectSanitizeField((string) ($_POST['email'] ?? '')));
            $website = prospectSanitizeField((string) ($_POST['website'] ?? ''));
            $address = prospectSanitizeField((string) ($_POST['address'] ?? ''));
            $city    = prospectSanitizeField((string) ($_POST['city'] ?? ''), 100);
            $state   = prospectSanitizeField((string) ($_POST['state'] ?? ''), 50);
            $zip     = prospectSanitizeField((string) ($_POST['zip'] ?? ''), 20);
            $status = trim((string) ($_POST['status'] ?? 'contacted'));
            $notes = prospectSanitizeField((string) ($_POST['notes'] ?? ''), 10000);
            $rawSource = prospectSanitizeField((string) ($_POST['raw_source'] ?? ($_POST['raw_text_dump'] ?? '')), 65000);
            $parseProvider = prospectSanitizeField((string) ($_POST['parse_provider'] ?? ''), 100);
            $parseConfidence = is_numeric($_POST['parse_confidence'] ?? null) ? (float) $_POST['parse_confidence'] : null;
            $parseErrors = prospectSanitizeField((string) ($_POST['parse_errors'] ?? ''), 3000);
            $formOutcome = prospectSanitizeField((string) ($_POST['form_outcome'] ?? ''));
            $formLastCalledAt = trim((string) ($_POST['form_last_called_at'] ?? ''));
            $normalizedLastCalledAt = prospectCleanDateTimeInput($formLastCalledAt);

            if (!in_array($status, prospectAllowedStatuses(), true)) {
                $status = 'contacted';
            }
            if ($company === '' && $contactName === '') {
                throw new RuntimeException('Company or contact name is required.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email format.');
            }

            $duplicate = prospectFindDuplicate($pdo, $prospectId, $email, $phone, $company);
            $forceCreate = !empty($_POST['force_create']);
            if ($duplicate !== null && !$forceCreate) {
                throw new RuntimeException('Duplicate prospect found (email, phone, or company).');
            }

            $previewPayload = json_encode([
                'company' => $company,
                'contact_name' => $contactName,
                'phone' => $phone,
                'email' => $email,
                'website' => $website,
                'address' => $address,
                'city'    => $city,
                'state'   => $state,
                'zip'     => $zip,
                'status' => $status,
                'notes' => $notes,
            ], JSON_UNESCAPED_UNICODE);

            if ($prospectId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE prospects
                    SET company = :company,
                        contact_name = :contact_name,
                        phone = :phone,
                        email = :email,
                        website = :website,
                        address = :address,
                        city = :city,
                        state = :state,
                        zip = :zip,
                        status = :status,
                        notes = :notes,
                        last_called_at = :last_called_at,
                        raw_source = :raw_source,
                        parse_preview_json = :parse_preview_json,
                        parse_confidence = :parse_confidence,
                        parse_provider = :parse_provider,
                        parse_errors = :parse_errors,
                        updated_by = :updated_by
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':company' => $company,
                    ':contact_name' => $contactName,
                    ':phone' => $phone !== '' ? $phone : null,
                    ':email' => $email !== '' ? $email : null,
                    ':website' => $website !== '' ? $website : null,
                    ':address' => $address !== '' ? $address : null,
                    ':city' => $city !== '' ? $city : null,
                    ':state' => $state !== '' ? $state : null,
                    ':zip' => $zip !== '' ? $zip : null,
                    ':status' => $status,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':last_called_at' => $normalizedLastCalledAt,
                    ':raw_source' => $rawSource !== '' ? $rawSource : null,
                    ':parse_preview_json' => $previewPayload,
                    ':parse_confidence' => $parseConfidence,
                    ':parse_provider' => $parseProvider !== '' ? $parseProvider : null,
                    ':parse_errors' => $parseErrors !== '' ? $parseErrors : null,
                    ':updated_by' => $adminId > 0 ? $adminId : null,
                    ':id' => $prospectId,
                ]);
                $_SESSION['prospects_flash_success'] = 'Prospect updated.';
            } else {
                $insertLastCalledAt = $normalizedLastCalledAt;
                $stmt = $pdo->prepare("
                    INSERT INTO prospects (
                        company, contact_name, phone, email, website, address, city, state, zip, status, notes,
                        raw_source, parse_preview_json, parse_confidence, parse_provider, parse_errors,
                        last_called_at, created_by, updated_by, category_id
                    ) VALUES (
                        :company, :contact_name, :phone, :email, :website, :address, :city, :state, :zip, :status, :notes,
                        :raw_source, :parse_preview_json, :parse_confidence, :parse_provider, :parse_errors,
                        :last_called_at, :created_by, :updated_by, :category_id
                    )
                ");
                $stmt->execute([
                    ':company' => $company,
                    ':contact_name' => $contactName,
                    ':phone' => $phone !== '' ? $phone : null,
                    ':email' => $email !== '' ? $email : null,
                    ':website' => $website !== '' ? $website : null,
                    ':address' => $address !== '' ? $address : null,
                    ':city' => $city !== '' ? $city : null,
                    ':state' => $state !== '' ? $state : null,
                    ':zip' => $zip !== '' ? $zip : null,
                    ':status' => $status,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':raw_source' => $rawSource !== '' ? $rawSource : null,
                    ':parse_preview_json' => $previewPayload,
                    ':parse_confidence' => $parseConfidence,
                    ':parse_provider' => $parseProvider !== '' ? $parseProvider : null,
                    ':parse_errors' => $parseErrors !== '' ? $parseErrors : null,
                    ':last_called_at' => $insertLastCalledAt,
                    ':created_by' => $adminId > 0 ? $adminId : null,
                    ':updated_by' => $adminId > 0 ? $adminId : null,
                    ':category_id' => $activeCategory !== null ? (int) $activeCategory['id'] : null,
                ]);
                $newProspectId = (int) $pdo->lastInsertId();
                if ($formOutcome !== '' && $newProspectId > 0) {
                    $iStmt = $pdo->prepare("
                        INSERT INTO prospect_interactions (prospect_id, interaction_type, outcome, interacted_at, admin_id)
                        VALUES (:prospect_id, 'call', :outcome, :interacted_at, :admin_id)
                    ");
                    $iStmt->execute([
                        ':prospect_id' => $newProspectId,
                        ':outcome' => $formOutcome,
                        ':interacted_at' => $insertLastCalledAt ?? date('Y-m-d H:i:s'),
                        ':admin_id' => $adminId > 0 ? $adminId : null,
                    ]);
                }
                $_SESSION['prospects_flash_success'] = 'Prospect created.';
            }
        } elseif ($action === 'archive_prospect') {
            $prospectId = (int) ($_POST['prospect_id'] ?? 0);
            if ($prospectId <= 0) {
                throw new RuntimeException('Invalid prospect ID.');
            }
            $stmt = $pdo->prepare("
                UPDATE prospects
                SET is_archived = 1,
                    status = 'archived',
                    updated_by = :admin_id
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $prospectId,
                ':admin_id' => $adminId > 0 ? $adminId : null,
            ]);
            $_SESSION['prospects_flash_success'] = 'Prospect archived.';
        } elseif ($action === 'log_interaction') {
            $prospectId = (int) ($_POST['prospect_id'] ?? 0);
            $type = trim((string) ($_POST['interaction_type'] ?? 'note'));
            $outcome = prospectSanitizeField((string) ($_POST['outcome'] ?? ''));
            $interactionNotes = prospectSanitizeField((string) ($_POST['interaction_notes'] ?? ''), 3000);
            $interactedAt = prospectCleanDateTimeInput((string) ($_POST['interacted_at'] ?? '')) ?? prospectNowLosAngeles();
            $newStatus = trim((string) ($_POST['new_status'] ?? ''));

            if ($prospectId <= 0) {
                throw new RuntimeException('Invalid prospect ID.');
            }
            if (!in_array($type, ['call', 'email', 'note', 'status_change'], true)) {
                throw new RuntimeException('Invalid interaction type.');
            }

            $pdo->beginTransaction();
            $insert = $pdo->prepare("
                INSERT INTO prospect_interactions (
                    prospect_id, interaction_type, outcome, interaction_notes, interacted_at, admin_id
                ) VALUES (
                    :prospect_id, :interaction_type, :outcome, :interaction_notes, :interacted_at, :admin_id
                )
            ");
            $insert->execute([
                ':prospect_id' => $prospectId,
                ':interaction_type' => $type,
                ':outcome' => $outcome !== '' ? $outcome : null,
                ':interaction_notes' => $interactionNotes !== '' ? $interactionNotes : null,
                ':interacted_at' => $interactedAt,
                ':admin_id' => $adminId > 0 ? $adminId : null,
            ]);

            if ($type === 'call') {
                $update = $pdo->prepare("UPDATE prospects SET last_called_at = :ts, updated_by = :admin_id WHERE id = :id");
                $update->execute([
                    ':ts' => $interactedAt,
                    ':id' => $prospectId,
                    ':admin_id' => $adminId > 0 ? $adminId : null,
                ]);
            } elseif ($type === 'email') {
                $update = $pdo->prepare("UPDATE prospects SET last_emailed_at = :ts, updated_by = :admin_id WHERE id = :id");
                $update->execute([
                    ':ts' => $interactedAt,
                    ':id' => $prospectId,
                    ':admin_id' => $adminId > 0 ? $adminId : null,
                ]);
            }

            if ($type === 'status_change' && in_array($newStatus, prospectAllowedStatuses(), true)) {
                $update = $pdo->prepare("UPDATE prospects SET status = :status, last_called_at = :ts, updated_by = :admin_id WHERE id = :id");
                $update->execute([
                    ':status' => $newStatus,
                    ':ts' => $interactedAt,
                    ':id' => $prospectId,
                    ':admin_id' => $adminId > 0 ? $adminId : null,
                ]);
            }

            $pdo->commit();
            $_SESSION['prospects_flash_success'] = 'Interaction logged.';
        } elseif ($action === 'convert_to_customer') {
            $prospectId = (int) ($_POST['prospect_id'] ?? 0);
            if ($prospectId <= 0) {
                throw new RuntimeException('Invalid prospect ID.');
            }

            $pdo->beginTransaction();

            $prospectStmt = $pdo->prepare("SELECT * FROM prospects WHERE id = :id LIMIT 1 FOR UPDATE");
            $prospectStmt->execute([':id' => $prospectId]);
            $prospect = $prospectStmt->fetch(PDO::FETCH_ASSOC);
            if (!$prospect) {
                throw new RuntimeException('Prospect not found.');
            }

            $existingMapStmt = $pdo->prepare("SELECT customer_id FROM prospect_conversion_map WHERE prospect_id = :prospect_id LIMIT 1 FOR UPDATE");
            $existingMapStmt->execute([':prospect_id' => $prospectId]);
            $existingCustomerId = (int) ($existingMapStmt->fetchColumn() ?: 0);
            if ($existingCustomerId > 0 || !empty($prospect['converted_at'])) {
                throw new RuntimeException('This prospect has already been converted.');
            }

            $email = trim((string) ($prospect['email'] ?? ''));
            $phone = trim((string) ($prospect['phone'] ?? ''));
            $company = trim((string) ($prospect['company'] ?? ''));
            $duplicateCustomer = prospectFindCustomerDuplicate($pdo, $email, $phone, $company);
            if ($duplicateCustomer !== null) {
                throw new RuntimeException('Conversion blocked: matching customer already exists (#' . (int) $duplicateCustomer['id'] . ').');
            }

            [$firstName, $lastName] = prospectSplitContactName((string) ($prospect['contact_name'] ?? ''));
            if ($firstName === '' && $company !== '') {
                $firstName = $company;
            }

            $availableCustomerCols = prospectGetCustomerColumns($pdo);
            if ($availableCustomerCols === []) {
                throw new RuntimeException('Unable to read customers table schema.');
            }
            $availableSet = array_fill_keys($availableCustomerCols, true);
            $customerValues = [
                'hubspot_contact_id' => 'prospect_' . bin2hex(random_bytes(10)),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'company' => $company,
                'phone' => $phone,
                'email' => $email,
                'address' => (string) ($prospect['address'] ?? ''),
                'city' => (string) ($prospect['city'] ?? ''),
                'state' => (string) ($prospect['state'] ?? ''),
                'zip' => (string) ($prospect['zip'] ?? ''),
                'country' => 'USA',
                'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                'last_updated' => null,
            ];

            $insertCols = [];
            $insertParams = [];
            foreach ($customerValues as $column => $value) {
                if (!isset($availableSet[$column])) {
                    continue;
                }
                $insertCols[] = $column;
                $insertParams[":" . $column] = $value;
            }
            if ($insertCols === []) {
                throw new RuntimeException('No compatible columns found for customer conversion.');
            }

            $placeholders = implode(', ', array_map(static fn($c) => ':' . $c, $insertCols));
            $insertCustomer = $pdo->prepare(
                'INSERT INTO customers (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')'
            );
            $insertCustomer->execute($insertParams);
            $customerId = (int) $pdo->lastInsertId();

            $updateProspect = $pdo->prepare("
                UPDATE prospects
                SET status = 'converted',
                    converted_customer_id = :customer_id,
                    converted_at = NOW(),
                    updated_by = :admin_id
                WHERE id = :id
            ");
            $updateProspect->execute([
                ':customer_id' => $customerId,
                ':admin_id' => $adminId > 0 ? $adminId : null,
                ':id' => $prospectId,
            ]);

            $insertMap = $pdo->prepare("
                INSERT INTO prospect_conversion_map (prospect_id, customer_id, converted_by)
                VALUES (:prospect_id, :customer_id, :converted_by)
            ");
            $insertMap->execute([
                ':prospect_id' => $prospectId,
                ':customer_id' => $customerId,
                ':converted_by' => $adminId > 0 ? $adminId : null,
            ]);

            $insertInteraction = $pdo->prepare("
                INSERT INTO prospect_interactions (
                    prospect_id, interaction_type, outcome, interaction_notes, interacted_at, admin_id
                ) VALUES (
                    :prospect_id, 'conversion', :outcome, :notes, NOW(), :admin_id
                )
            ");
            $insertInteraction->execute([
                ':prospect_id' => $prospectId,
                ':outcome' => 'Converted to customer',
                ':notes' => 'Created customer ID #' . $customerId,
                ':admin_id' => $adminId > 0 ? $adminId : null,
            ]);

            $pdo->commit();
            $_SESSION['prospects_flash_success'] = 'Prospect converted to customer #' . $customerId . '.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['prospects_flash_error'] = $e->getMessage();
    }

    header('Location: prospects.php' . ($redirectQs !== '' ? '?' . $redirectQs : ''));
    exit;
}

$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$search = trim((string) ($_GET['q'] ?? ''));

$where = ['is_archived = 0'];
$params = [];
if ($activeCategory !== null) {
    $where[] = 'category_id = :category_id';
    $params[':category_id'] = (int) $activeCategory['id'];
}
if ($statusFilter !== '' && $statusFilter !== 'all' && in_array($statusFilter, prospectAllowedStatuses(), true)) {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
} else {
    $statusFilter = 'all';
}
if ($search !== '') {
    $where[] = '(company LIKE :q OR contact_name LIKE :q OR email LIKE :q OR phone LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}

$sql = 'SELECT * FROM prospects WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
$prospectStmt = $pdo->prepare($sql);
$prospectStmt->execute($params);
$prospects = $prospectStmt->fetchAll(PDO::FETCH_ASSOC);

$prospectIds = array_map(static fn(array $p): int => (int) ($p['id'] ?? 0), $prospects);
$interactionsByProspect = [];
if ($prospectIds !== []) {
    $placeholders = implode(', ', array_fill(0, count($prospectIds), '?'));
    $interactionStmt = $pdo->prepare("
        SELECT id, prospect_id, interaction_type, outcome, interaction_notes, interacted_at
        FROM prospect_interactions
        WHERE prospect_id IN ({$placeholders})
        ORDER BY interacted_at DESC, id DESC
    ");
    $interactionStmt->execute($prospectIds);
    foreach ($interactionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int) ($row['prospect_id'] ?? 0);
        $row['interacted_at_display'] = prospectFormatDisplayDateTime((string) ($row['interacted_at'] ?? ''));
        if (!isset($interactionsByProspect[$pid])) {
            $interactionsByProspect[$pid] = [];
        }
        $interactionsByProspect[$pid][] = $row;
    }
}

$statusMap = prospectStatuses();
$laNowDateTimeLocal = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d\TH:i');
$prospectsForJs = [];
foreach ($prospects as $prospect) {
    $pid = (int) ($prospect['id'] ?? 0);
    $prospectsForJs[$pid] = [
        'id' => $pid,
        'company' => (string) ($prospect['company'] ?? ''),
        'contact_name' => (string) ($prospect['contact_name'] ?? ''),
        'phone' => (string) ($prospect['phone'] ?? ''),
        'email' => (string) ($prospect['email'] ?? ''),
        'website' => (string) ($prospect['website'] ?? ''),
        'address' => (string) ($prospect['address'] ?? ''),
        'city' => (string) ($prospect['city'] ?? ''),
        'state' => (string) ($prospect['state'] ?? ''),
        'zip' => (string) ($prospect['zip'] ?? ''),
        'status' => (string) ($prospect['status'] ?? 'new'),
        'last_called_at' => (string) ($prospect['last_called_at'] ?? ''),
        'last_called_at_display' => prospectFormatDisplayDateTime((string) ($prospect['last_called_at'] ?? '')),
        'last_emailed_at' => (string) ($prospect['last_emailed_at'] ?? ''),
        'last_emailed_at_display' => prospectFormatDisplayDateTime((string) ($prospect['last_emailed_at'] ?? '')),
        'notes' => (string) ($prospect['notes'] ?? ''),
        'raw_source' => (string) (($prospect['raw_source'] ?? '') !== '' ? $prospect['raw_source'] : ($prospect['raw_text_dump'] ?? '')),
        'parse_provider' => (string) ($prospect['parse_provider'] ?? ''),
        'parse_confidence' => (string) ($prospect['parse_confidence'] ?? ''),
        'parse_errors' => (string) ($prospect['parse_errors'] ?? ''),
    ];
}
$isCategoryFocused = $activeCategory !== null;
$currentCategoryName = $isCategoryFocused ? (string) $activeCategory['name'] : 'All Prospects';
$currentCategorySlug = $isCategoryFocused ? (string) $activeCategory['slug'] : '';
$badgeBaseClasses = 'inline-flex items-center gap-1 rounded-full border border-zinc-700 bg-zinc-800 px-3 py-1 text-sm text-zinc-200';
$badgeLinkClasses = 'transition-colors hover:text-cyan-300';
$activeBadgeLinkClasses = 'text-cyan-200';
$badgeRemoveButtonClasses = 'text-sm leading-none text-zinc-500 transition-colors hover:text-red-400';
$pageTitle = $currentCategoryName . ' Prospects | Ghost Laser';
$pageDescription = $isCategoryFocused
    ? ('Cold calling prospect pipeline focused on ' . $currentCategoryName . '.')
    : 'Cold calling prospect pipeline management.';
$headerRight = '<div class="flex items-center gap-3"><a href="prospect_notifications.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Prospect Templates</a><a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Dashboard</a></div>';
$extraHead = <<<'HTML'
<style>
    .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.35); }
    .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.55); }
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.75); backdrop-filter: blur(2px); display: none; align-items: center; justify-content: center; z-index: 9999; padding: 1rem; overflow-y: auto; }
    .modal-overlay.open { display: flex; }
    body.modal-open { overflow: hidden; }
    .modal-box { width: min(920px, 96vw); max-height: 92vh; overflow: auto; border: 1px solid rgb(63,63,70); background: rgba(24,24,27,.98); border-radius: 1rem; margin: auto; }
    .field { width: 100%; border: 1px solid rgb(63,63,70); background: rgb(9,9,11); color: #fff; border-radius: .5rem; padding: .55rem .75rem; font-size: .875rem; }
    .label { font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: rgb(161,161,170); margin-bottom: .35rem; display: block; font-weight: 600; }
</style>
HTML;
require_once __DIR__ . '/templates/header.php';
?>
<main class="min-h-screen hero-grid pt-24 pb-16 px-4">
    <div class="max-w-7xl mx-auto space-y-7">
        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6">
            <div class="flex flex-col gap-4">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">
                        <?= htmlspecialchars($currentCategoryName, ENT_QUOTES, 'UTF-8') ?>
                    </h1>
                    <p class="mt-2 text-zinc-400">
                        <?= $isCategoryFocused
                            ? ('Dedicated prospect view for ' . htmlspecialchars($currentCategoryName, ENT_QUOTES, 'UTF-8') . '.')
                            : 'All prospects across every category.' ?>
                    </p>
                </div>
            </div>
        </section>

        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-5">
            <div class="space-y-4">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="<?= htmlspecialchars($badgeBaseClasses, ENT_QUOTES, 'UTF-8') ?>">
                        <a href="prospects.php" class="<?= htmlspecialchars((!$isCategoryFocused ? $activeBadgeLinkClasses : $badgeLinkClasses), ENT_QUOTES, 'UTF-8') ?>">All Prospects</a>
                    </span>
                    <?php foreach ($categoryRows as $category): ?>
                        <?php
                            $categoryId = (int) ($category['id'] ?? 0);
                            $categoryName = trim((string) ($category['name'] ?? ''));
                            $categorySlug = prospectCategorySlugify((string) ($category['slug'] ?? ''));
                            if ($categorySlug === '') {
                                $categorySlug = prospectCategorySlugify($categoryName);
                            }
                            if ($categoryId <= 0 || $categoryName === '' || $categorySlug === '') {
                                continue;
                            }
                            $isActiveCategoryLink = $isCategoryFocused && (int) $activeCategory['id'] === $categoryId;
                        ?>
                        <span class="<?= htmlspecialchars($badgeBaseClasses, ENT_QUOTES, 'UTF-8') ?>">
                            <a href="prospects.php?category=<?= urlencode($categorySlug) ?>" class="<?= htmlspecialchars(($isActiveCategoryLink ? $activeBadgeLinkClasses : $badgeLinkClasses), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?></a>
                            <button type="button" onclick="confirmDeleteCategory(<?= $categoryId ?>, <?= htmlspecialchars(json_encode($categoryName), ENT_QUOTES, 'UTF-8') ?>)" class="<?= htmlspecialchars($badgeRemoveButtonClasses, ENT_QUOTES, 'UTF-8') ?>" title="Delete category" aria-label="Delete <?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?>">&times;</button>
                        </span>
                    <?php endforeach; ?>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-800 bg-zinc-950/40 p-4">
                    <div>
                        <p class="text-sm font-semibold text-white">Category Management</p>
                        <p class="mt-1 text-sm text-zinc-400">Use the modal actions below to add one category or import several at once.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" onclick="openCategoryModal()" class="rounded-lg border border-cyan-500/40 bg-cyan-500/10 px-4 py-2 text-sm font-semibold text-cyan-300 hover:bg-cyan-500/20">Create Category</button>
                        <button type="button" onclick="openBulkCategoriesModal()" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-semibold text-zinc-200 hover:border-cyan-500/40 hover:text-cyan-200">Bulk Add Categories</button>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($flashSuccess !== ''): ?>
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-400"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($flashError !== ''): ?>
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($isCategoryFocused): ?>
            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-5 space-y-4">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-white">Search Keywords for this Category</h2>
                        <p class="mt-1 text-sm text-zinc-400">Add reusable Google search terms for <?= htmlspecialchars($currentCategoryName, ENT_QUOTES, 'UTF-8') ?>.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" onclick="openKeywordModal()" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950">Add Keyword</button>
                        <button type="button" onclick="openBulkKeywordsModal()" class="rounded-lg border border-cyan-500/40 bg-cyan-500/10 px-4 py-2 text-sm font-semibold text-cyan-300 hover:bg-cyan-500/20">Bulk Add Keywords</button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <?php if ($categoryKeywords === []): ?>
                        <p class="text-sm text-zinc-500">No keywords added yet.</p>
                    <?php else: ?>
                        <?php foreach ($categoryKeywords as $keyword): ?>
                            <?php $googleSearchUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim((string) $keyword)); ?>
                            <span class="<?= htmlspecialchars($badgeBaseClasses, ENT_QUOTES, 'UTF-8') ?>">
                                <a href="<?= htmlspecialchars($googleSearchUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="<?= htmlspecialchars($badgeLinkClasses, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string) $keyword, ENT_QUOTES, 'UTF-8') ?>
                                </a>
                                <form method="POST" action="prospects.php" class="inline" onsubmit="return confirm('Remove this keyword?');">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="remove_category_keyword">
                                    <input type="hidden" name="category" value="<?= htmlspecialchars($currentCategorySlug, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="keyword" value="<?= htmlspecialchars((string) $keyword, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="<?= htmlspecialchars($badgeRemoveButtonClasses, ENT_QUOTES, 'UTF-8') ?>" aria-label="Remove keyword <?= htmlspecialchars((string) $keyword, ENT_QUOTES, 'UTF-8') ?>">&times;</button>
                                </form>
                            </span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php else: ?>
            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-5">
                <p class="text-sm text-zinc-400">Create or open a category to manage its keywords (including bulk add).</p>
            </section>
        <?php endif; ?>

        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-4">
            <div class="flex flex-wrap items-center gap-2">
                <form method="GET" action="prospects.php" class="flex flex-wrap items-center gap-2">
                    <?php if ($isCategoryFocused): ?>
                        <input type="hidden" name="category" value="<?= htmlspecialchars($currentCategorySlug, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <select name="status" class="field w-40" style="max-width:160px;">
                        <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All statuses</option>
                        <?php foreach ($statusMap as $statusKey => $statusLabel): ?>
                            <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>"<?= $statusFilter === $statusKey ? ' selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" class="field w-48" style="max-width:192px;" placeholder="Company or phone">
                    <button type="submit" class="rounded-lg bg-cyan-500 px-3 py-2 text-sm font-semibold text-zinc-950 whitespace-nowrap">Filter</button>
                </form>
                <div class="flex flex-wrap items-center gap-2 ml-auto">
                    <a href="prospect_notifications.php" class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-zinc-200 hover:border-cyan-500/50 hover:text-cyan-300 whitespace-nowrap">Prospect Templates</a>
                    <button type="button" onclick="openCreateModal()" class="rounded-lg bg-cyan-500 px-3 py-2 text-sm font-semibold text-zinc-950 btn-glow whitespace-nowrap">Add New Prospect</button>
                </div>
            </div>
        </section>

        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-5">
            <?php if ($prospects === []): ?>
                <p class="text-sm text-zinc-500">No prospects found.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-800">
                                <th class="pb-3 text-left text-zinc-500">Company</th>
                                <th class="pb-3 text-left text-zinc-500">Contact</th>
                                <th class="pb-3 text-left text-zinc-500">Status</th>
                                <th class="pb-3 text-left text-zinc-500">Last Called</th>
                                <th class="pb-3 text-left text-zinc-500">Last Emailed</th>
                                <th class="pb-3 text-right text-zinc-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60">
                        <?php foreach ($prospects as $prospect): ?>
                            <?php
                                $pid = (int) ($prospect['id'] ?? 0);
                                $statusKey = (string) ($prospect['status'] ?? 'new');
                                $statusLabel = $statusMap[$statusKey] ?? ucfirst(str_replace('_', ' ', $statusKey));
                            ?>
                            <tr class="align-top">
                                <td class="py-3 pr-3">
                                    <div class="font-semibold text-white"><?= htmlspecialchars((string) ($prospect['company'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-xs text-zinc-500 mt-1"><?= htmlspecialchars((string) ($prospect['website'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="py-3 pr-3 text-zinc-300">
                                    <div><?= htmlspecialchars((string) ($prospect['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-xs text-zinc-500 mt-1"><?= htmlspecialchars((string) ($prospect['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-xs text-zinc-500"><?= htmlspecialchars((string) ($prospect['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="py-3 pr-3 text-zinc-300"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 pr-3 text-zinc-400"><?= htmlspecialchars(prospectFormatDisplayDateTime((string) ($prospect['last_called_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 pr-3 text-zinc-400"><?= htmlspecialchars(prospectFormatDisplayDateTime((string) ($prospect['last_emailed_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 text-right" onclick="event.stopPropagation()">
                                    <div class="inline-flex flex-wrap justify-end gap-2">
                                        <button
                                            type="button"
                                            class="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 hover:text-cyan-300"
                                            onclick="openEditModalById(<?= $pid ?>)"
                                        >Edit</button>
                                        <button type="button" class="rounded-md border border-cyan-700/60 bg-cyan-950/20 px-3 py-1.5 text-xs text-cyan-300 hover:border-cyan-500/60" onclick="openDetailsModal(<?= $pid ?>)">View</button>

                                        <?php if ((string) ($prospect['status'] ?? '') !== 'converted'): ?>
                                            <form method="POST" action="prospects.php" onsubmit="return confirm('Convert this prospect to a customer?');" onclick="event.stopPropagation()">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="convert_to_customer">
                                                <input type="hidden" name="prospect_id" value="<?= $pid ?>">
                                                <input type="hidden" name="category" value="<?= htmlspecialchars($isCategoryFocused ? $currentCategorySlug : '', ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="rounded-md border border-emerald-700/60 bg-emerald-950/30 px-3 py-1.5 text-xs text-emerald-300 hover:border-emerald-500/60">Convert to Customer</button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="POST" action="prospects.php" onsubmit="return confirm('Archive this prospect?');" onclick="event.stopPropagation()">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="archive_prospect">
                                            <input type="hidden" name="prospect_id" value="<?= $pid ?>">
                                            <input type="hidden" name="category" value="<?= htmlspecialchars($isCategoryFocused ? $currentCategorySlug : '', ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-400 hover:text-red-400">Archive</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<div id="prospectDetailsModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="prospectDetailsTitle">
    <div class="modal-box">
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
            <h2 id="prospectDetailsTitle" class="text-lg font-semibold text-white">Prospect Details</h2>
            <div class="flex items-center gap-2">
                <button type="button" id="sendEmailBtn" class="rounded-md border border-cyan-700/60 bg-cyan-950/20 px-3 py-1 text-xs text-cyan-300 hover:border-cyan-500/60" onclick="openEmailModal()">Send Email</button>
                <button type="button" id="sendToPhoneBtn" class="rounded-md border border-green-700/60 bg-green-950/20 px-3 py-1 text-xs text-green-300 hover:border-green-500/60" onclick="sendToPhone()">📞 Send to Phone</button>
                <button type="button" class="rounded-md border border-zinc-700 px-3 py-1 text-xs text-zinc-300" onclick="closeDetailsModal()">Close</button>
            </div>
        </div>
        <div class="p-5 space-y-4">
            <div class="grid gap-3 md:grid-cols-2 text-sm">
                <div><span class="text-zinc-500">Company:</span> <span id="details_company" class="text-zinc-200"></span></div>
                <div><span class="text-zinc-500">Contact:</span> <span id="details_contact_name" class="text-zinc-200"></span></div>
                <div><span class="text-zinc-500">Phone:</span> <span id="details_phone" class="text-zinc-200"></span></div>
                <div><span class="text-zinc-500">Email:</span> <span id="details_email" class="text-zinc-200"></span></div>
                <div><span class="text-zinc-500">Website:</span> <span id="details_website" class="text-zinc-200"></span></div>
                <div><span class="text-zinc-500">Status:</span> <span id="details_status" class="text-zinc-200"></span></div>
                <div><span class="text-zinc-500">Last Called:</span> <span id="details_last_called_at" class="text-zinc-200"></span></div>
                <div><span class="text-zinc-500">Last Emailed:</span> <span id="details_last_emailed_at" class="text-zinc-200"></span></div>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider text-zinc-500 mb-2">Notes</p>
                <div id="details_notes" class="text-sm text-zinc-300 whitespace-pre-line rounded-lg border border-zinc-800 bg-zinc-950/60 p-3"></div>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider text-zinc-500 mb-2">Interaction History</p>
                <div id="details_interactions" class="space-y-2"></div>
            </div>
            <form method="POST" action="prospects.php" class="pt-2 space-y-2">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="log_interaction">
                <input type="hidden" name="prospect_id" id="details_prospect_id" value="0">
                <input type="hidden" name="category" value="<?= htmlspecialchars($isCategoryFocused ? $currentCategorySlug : '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                <div class="flex gap-2 items-center">
                    <select name="interaction_type" class="field" style="width:auto;">
                        <option value="call">Call</option>
                        <option value="email">Email</option>
                        <option value="note">Note</option>
                        <option value="status_change">Status Change</option>
                    </select>
                    <select name="new_status" class="field" style="width:auto;">
                        <option value="">Status (optional)</option>
                        <?php foreach ($statusMap as $statusKeyOption => $statusLabelOption): ?>
                            <option value="<?= htmlspecialchars($statusKeyOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabelOption, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="datetime-local" name="interacted_at" id="details_interacted_at" class="field" style="width:auto;" value="<?= htmlspecialchars($laNowDateTimeLocal, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-xs font-semibold text-zinc-950 whitespace-nowrap">Log</button>
                </div>
                <textarea name="interaction_notes" rows="2" maxlength="3000" class="field" placeholder="Interaction notes"></textarea>
            </form>
        </div>
    </div>
</div>

<div id="prospectEmailModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="emailModalTitle">
    <div class="modal-box">
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
            <h2 id="emailModalTitle" class="text-lg font-semibold text-white">Send Email</h2>
            <button type="button" class="rounded-md border border-zinc-700 px-3 py-1 text-xs text-zinc-300" onclick="closeEmailModal()">Cancel</button>
        </div>
        <div class="p-5 space-y-4">
            <div>
                <label class="block text-xs uppercase tracking-wider text-zinc-500 mb-1">Template</label>
                <select id="email_template_select" class="field w-full" onchange="applyEmailTemplate()">
                    <option value="">— Select a template —</option>
                </select>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider text-zinc-500 mb-1">Subject</label>
                <input type="text" id="email_subject" maxlength="255" class="field w-full" placeholder="Email subject">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider text-zinc-500 mb-1">Body</label>
                <textarea id="email_body" rows="10" class="field w-full" placeholder="Email body"></textarea>
            </div>
            <div id="email_send_error" class="hidden rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs text-red-400"></div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-300" onclick="closeEmailModal()">Cancel</button>
                <button type="button" id="email_send_btn" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400" onclick="submitSendEmail()">Send Email</button>
            </div>
        </div>
    </div>
</div>

<div id="categoryModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="categoryModalTitle">
    <div class="modal-box" style="width:min(560px,96vw);">
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
            <h2 id="categoryModalTitle" class="text-lg font-semibold text-white">Create Category</h2>
            <button type="button" class="rounded-md border border-zinc-700 px-3 py-1 text-xs text-zinc-300" onclick="closeCategoryModal()">Close</button>
        </div>
        <form method="POST" action="prospects.php" class="p-5 space-y-4">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="add_category">
            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="label">Category Name</label>
                    <input type="text" name="category_name" class="field" maxlength="255" placeholder="New category name" required>
                </div>
                <div>
                    <label class="label">Slug</label>
                    <input type="text" name="category_slug" class="field" maxlength="255" placeholder="Optional slug">
                </div>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-300" onclick="closeCategoryModal()">Cancel</button>
                <button type="submit" class="rounded-lg border border-cyan-500/40 bg-cyan-500/10 px-4 py-2 text-sm font-semibold text-cyan-300 hover:bg-cyan-500/20">Create Category</button>
            </div>
        </form>
    </div>
</div>

<div id="bulkCategoriesModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="bulkCategoriesModalTitle">
    <div class="modal-box" style="width:min(640px,96vw);">
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
            <h2 id="bulkCategoriesModalTitle" class="text-lg font-semibold text-white">Bulk Add Categories</h2>
            <button type="button" class="rounded-md border border-zinc-700 px-3 py-1 text-xs text-zinc-300" onclick="closeBulkCategoriesModal()">Close</button>
        </div>
        <form method="POST" action="prospects.php" class="p-5 space-y-4">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="bulk_add_categories">
            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <label class="label">Category Names (one per line)</label>
                <textarea name="bulk_category_names" rows="8" class="field" maxlength="20000" placeholder="Paste one category name per line"></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-300" onclick="closeBulkCategoriesModal()">Cancel</button>
                <button type="submit" class="rounded-lg border border-cyan-500/40 bg-cyan-500/10 px-4 py-2 text-sm font-semibold text-cyan-300 hover:bg-cyan-500/20">Add All Categories</button>
            </div>
        </form>
    </div>
</div>

<?php if ($isCategoryFocused): ?>
<div id="keywordModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="keywordModalTitle">
    <div class="modal-box" style="width:min(560px,96vw);">
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
            <h2 id="keywordModalTitle" class="text-lg font-semibold text-white">Add Keyword</h2>
            <button type="button" class="rounded-md border border-zinc-700 px-3 py-1 text-xs text-zinc-300" onclick="closeKeywordModal()">Close</button>
        </div>
        <form method="POST" action="prospects.php" class="p-5 space-y-4">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="add_category_keyword">
            <input type="hidden" name="category" value="<?= htmlspecialchars($currentCategorySlug, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <label class="label">Keyword</label>
                <input type="text" name="keyword" class="field" maxlength="255" placeholder="e.g. channel letters" required>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-300" onclick="closeKeywordModal()">Cancel</button>
                <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950">Add Keyword</button>
            </div>
        </form>
    </div>
</div>

<div id="bulkKeywordsModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="bulkKeywordsModalTitle">
    <div class="modal-box" style="width:min(640px,96vw);">
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
            <h2 id="bulkKeywordsModalTitle" class="text-lg font-semibold text-white">Bulk Add Keywords</h2>
            <button type="button" class="rounded-md border border-zinc-700 px-3 py-1 text-xs text-zinc-300" onclick="closeBulkKeywordsModal()">Close</button>
        </div>
        <form method="POST" action="prospects.php" class="p-5 space-y-4">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="bulk_add_category_keywords">
            <input type="hidden" name="category" value="<?= htmlspecialchars($currentCategorySlug, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <label class="label">Keywords</label>
                <textarea name="bulk_keywords" rows="8" class="field" maxlength="20000" placeholder="Paste one keyword per line"></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-300" onclick="closeBulkKeywordsModal()">Cancel</button>
                <button type="submit" class="rounded-lg border border-cyan-500/40 bg-cyan-500/10 px-4 py-2 text-sm font-semibold text-cyan-300 hover:bg-cyan-500/20">Add All Keywords</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="prospectModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="prospectModalTitle">
    <div class="modal-box">
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
            <h2 id="prospectModalTitle" class="text-lg font-semibold text-white">Prospect</h2>
            <button type="button" class="rounded-md border border-zinc-700 px-3 py-1 text-xs text-zinc-300" onclick="closeProspectModal()">Close</button>
        </div>
        <form method="POST" action="prospects.php" id="prospectForm" class="p-5 space-y-4">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_prospect">
            <input type="hidden" name="prospect_id" id="form_prospect_id" value="0">
            <input type="hidden" name="category" value="<?= htmlspecialchars($isCategoryFocused ? $currentCategorySlug : '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="parse_provider" id="form_parse_provider" value="">
            <input type="hidden" name="parse_confidence" id="form_parse_confidence" value="">
            <input type="hidden" name="parse_errors" id="form_parse_errors" value="">
            <input type="hidden" name="force_create" id="form_force_create" value="">

            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="label">Company</label><input class="field" type="text" name="company" id="form_company" maxlength="255"></div>
                <div><label class="label">Contact</label><input class="field" type="text" name="contact_name" id="form_contact_name" maxlength="255"></div>
                <div><label class="label">Phone</label><input class="field" type="text" name="phone" id="form_phone" maxlength="100"></div>
                <div><label class="label">Email</label><input class="field" type="email" name="email" id="form_email" maxlength="255"></div>
                <div><label class="label">Website</label><input class="field" type="text" name="website" id="form_website" maxlength="255"></div>
                <div>
                    <label class="label">Status</label>
                    <select class="field" name="status" id="form_status">
                        <option value="no_answer">No Answer</option>
                        <option value="left_voicemail">Left Voicemail</option>
                        <option value="disconnected">Disconnected / Bad Number</option>
                        <option value="not_interested">Not Interested</option>
                        <option value="has_provider">Already Has Service Provider</option>
                        <option value="farms_out">Farms Out Laser Work</option>
                        <option value="interested_service">Interested in Service</option>
                        <option value="interested_machine">Interested in Machine</option>
                        <option value="needs_follow_up">Needs Follow Up</option>
                    </select>
                </div>
                <div>
                    <label class="label">Last Called</label>
                    <input class="field" type="datetime-local" name="form_last_called_at" id="form_last_called_at">
                </div>
                <div class="md:col-span-2"><label class="label">Address</label><input class="field" type="text" name="address" id="form_address" maxlength="255" placeholder="Street address"></div>
                <div><label class="label">City</label><input class="field" type="text" name="city" id="form_city" maxlength="100" placeholder="City"></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="label">State</label><input class="field" type="text" name="state" id="form_state" maxlength="50" placeholder="State"></div>
                    <div><label class="label">ZIP</label><input class="field" type="text" name="zip" id="form_zip" maxlength="20" placeholder="ZIP"></div>
                </div>
            </div>
            <div>
                <label class="label">Notes</label>
                <textarea class="field" rows="4" name="notes" id="form_notes" maxlength="10000"></textarea>
            </div>
            <div>
                <label class="label">Smart Raw Source (AI Parse Preview)</label>
                <textarea class="field" rows="6" name="raw_source" id="form_raw_source" maxlength="65000" placeholder="Paste website/company text dump here."></textarea>
                <div class="mt-2 flex items-center gap-2">
                    <button type="button" id="parseBtn" onclick="parseTextDump()" class="rounded-md border border-cyan-500/40 bg-cyan-500/10 px-3 py-1.5 text-xs text-cyan-300 hover:bg-cyan-500/20">AI Parse &amp; Preview</button>
                    <span id="parseMeta" class="text-xs text-zinc-500"></span>
                </div>
            </div>

            <div class="pt-2 flex justify-end gap-3">
                <button type="button" onclick="closeProspectModal()" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-300">Cancel</button>
                <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950">Save Prospect</button>
            </div>
        </form>
    </div>
</div>

<div id="parsePreviewModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="parsePreviewTitle">
    <div class="modal-box">
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
            <h2 id="parsePreviewTitle" class="text-lg font-semibold text-white">AI Parse Preview</h2>
            <button type="button" class="rounded-md border border-zinc-700 px-3 py-1 text-xs text-zinc-300" onclick="closeParsePreview()">Close</button>
        </div>
        <div class="p-5 space-y-4">
            <p id="parsePreviewMeta" class="text-xs text-zinc-400"></p>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="label">Company</label><input class="field" type="text" id="preview_company"></div>
                <div><label class="label">Contact</label><input class="field" type="text" id="preview_contact_name"></div>
                <div><label class="label">Phone</label><input class="field" type="text" id="preview_phone"></div>
                <div><label class="label">Email</label><input class="field" type="text" id="preview_email"></div>
                <div><label class="label">Website</label><input class="field" type="text" id="preview_website"></div>
                <div>
                    <label class="label">Status</label>
                    <select class="field" id="preview_status">
                        <?php foreach ($statusMap as $statusKey => $statusLabel): ?>
                            <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2"><label class="label">Address</label><input class="field" type="text" id="preview_address" placeholder="Street address"></div>
                <div><label class="label">City</label><input class="field" type="text" id="preview_city" placeholder="City"></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="label">State</label><input class="field" type="text" id="preview_state" placeholder="State"></div>
                    <div><label class="label">ZIP</label><input class="field" type="text" id="preview_zip" placeholder="ZIP"></div>
                </div>
            </div>
            <div>
                <label class="label">Notes (editable before apply)</label>
                <textarea id="preview_notes" class="field" rows="5"></textarea>
            </div>
            <p id="parsePreviewErrors" class="text-xs text-amber-300"></p>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeParsePreview()" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-300">Cancel</button>
                <button type="button" onclick="applyPreviewToForm()" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950">Apply to Form</button>
            </div>
        </div>
    </div>
</div>

<div id="parseDuplicateModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="parseDuplicateTitle">
    <div class="modal-box" style="width:min(560px,96vw);">
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
            <h2 id="parseDuplicateTitle" class="text-lg font-semibold text-amber-400">⚠ Duplicate Detected</h2>
            <button type="button" class="rounded-md border border-zinc-700 px-3 py-1 text-xs text-zinc-300" onclick="closeDuplicateModal()">Close</button>
        </div>
        <div class="p-5 space-y-4">
            <p class="text-sm text-zinc-200">This company already exists in the database.</p>
            <div class="rounded-lg border border-zinc-700 bg-zinc-900 p-4 space-y-1 text-sm">
                <div><span class="text-zinc-400 text-xs uppercase tracking-wide">Company</span><br><span id="dup_company" class="text-white font-medium"></span></div>
                <div class="mt-2"><span class="text-zinc-400 text-xs uppercase tracking-wide">Phone on record</span><br><span id="dup_phone" class="text-zinc-200"></span></div>
                <div class="mt-2"><span class="text-zinc-400 text-xs uppercase tracking-wide">Last Called</span><br><span id="dup_last_called" class="text-zinc-200"></span></div>
            </div>
            <p id="dup_phone_diff_notice" class="hidden text-xs text-amber-300 bg-amber-900/30 border border-amber-700/40 rounded-md px-3 py-2">
                📞 The parsed phone number differs from the one on file. Choosing "Update Existing" will update the phone number.
            </p>
            <div class="flex flex-col gap-2 pt-1">
                <button type="button" id="dupUpdateBtn" onclick="duplicateUpdateExisting()"
                    class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 w-full">
                    Update Existing Record
                </button>
                <button type="button" onclick="duplicateCreateAnyway()"
                    class="rounded-lg border border-zinc-600 bg-zinc-800 px-4 py-2 text-sm text-zinc-300 w-full">
                    Create as New Prospect Anyway
                </button>
                <button type="button" onclick="closeDuplicateModal()"
                    class="text-xs text-zinc-500 hover:text-zinc-300 text-center mt-1">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const prospectsCsrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
const prospectRecords = <?= json_encode($prospectsForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const prospectInteractions = <?= json_encode($interactionsByProspect, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const statusLabels = <?= json_encode($statusMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const laNowDateTimeLocal = <?= json_encode($laNowDateTimeLocal, JSON_UNESCAPED_UNICODE) ?>;
let latestParseResult = null;
let latestDuplicate = null;

function openCategoryModal() {
    document.getElementById('categoryModal').classList.add('open');
}

function confirmDeleteCategory(categoryId, categoryName) {
    if (!confirm('Delete the category "' + categoryName + '"? This will also remove all its keywords. This cannot be undone.')) {
        return;
    }
    document.getElementById('deleteCategoryId').value = categoryId;
    document.getElementById('deleteCategoryForm').submit();
}

function closeCategoryModal() {
    document.getElementById('categoryModal').classList.remove('open');
}

function openBulkCategoriesModal() {
    document.getElementById('bulkCategoriesModal').classList.add('open');
}

function closeBulkCategoriesModal() {
    document.getElementById('bulkCategoriesModal').classList.remove('open');
}

function openKeywordModal() {
    const keywordModal = document.getElementById('keywordModal');
    if (keywordModal) {
        keywordModal.classList.add('open');
    }
}

function closeKeywordModal() {
    const keywordModal = document.getElementById('keywordModal');
    if (keywordModal) {
        keywordModal.classList.remove('open');
    }
}

function openBulkKeywordsModal() {
    const bulkKeywordsModal = document.getElementById('bulkKeywordsModal');
    if (bulkKeywordsModal) {
        bulkKeywordsModal.classList.add('open');
    }
}

function closeBulkKeywordsModal() {
    const bulkKeywordsModal = document.getElementById('bulkKeywordsModal');
    if (bulkKeywordsModal) {
        bulkKeywordsModal.classList.remove('open');
    }
}

function getLaNow() {
    return new Intl.DateTimeFormat('sv-SE', {
        timeZone: 'America/Los_Angeles',
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hour12: false
    }).format(new Date()).replace(' ', 'T');
}

function openCreateModal() {
    document.getElementById('prospectModalTitle').textContent = 'Add Prospect';
    document.getElementById('prospectForm').reset();
    document.getElementById('form_prospect_id').value = '0';
    document.getElementById('form_status').value = 'no_answer';
    document.getElementById('form_last_called_at').value = getLaNow();
    document.getElementById('form_parse_provider').value = '';
    document.getElementById('form_parse_confidence').value = '';
    document.getElementById('form_parse_errors').value = '';
    document.getElementById('parseMeta').textContent = '';
    document.getElementById('form_raw_source').value = '';
    document.getElementById('form_force_create').value = '';
    document.getElementById('prospectModal').classList.add('open');
    document.body.classList.add('modal-open');
}

function openEditModalById(prospectId) {
    openEditModal(prospectRecords[String(prospectId)] || prospectRecords[prospectId] || null);
}

function openEditModal(prospect) {
    if (!prospect) return;
    document.getElementById('prospectModalTitle').textContent = 'Edit Prospect';
    document.getElementById('form_prospect_id').value = String(prospect.id || 0);
    document.getElementById('form_company').value = prospect.company || '';
    document.getElementById('form_contact_name').value = prospect.contact_name || '';
    document.getElementById('form_phone').value = prospect.phone || '';
    document.getElementById('form_email').value = prospect.email || '';
    document.getElementById('form_website').value = prospect.website || '';
    document.getElementById('form_address').value = prospect.address || '';
    document.getElementById('form_city').value = prospect.city || '';
    document.getElementById('form_state').value = prospect.state || '';
    document.getElementById('form_zip').value = prospect.zip || '';
    document.getElementById('form_status').value = prospect.status || 'no_answer';
    document.getElementById('form_last_called_at').value = prospect.last_called_at ? prospect.last_called_at.replace(' ', 'T').substring(0, 16) : getLaNow();
    document.getElementById('form_notes').value = prospect.notes || '';
    document.getElementById('form_raw_source').value = prospect.raw_source || '';
    document.getElementById('form_parse_provider').value = prospect.parse_provider || '';
    document.getElementById('form_parse_confidence').value = prospect.parse_confidence || '';
    document.getElementById('form_parse_errors').value = prospect.parse_errors || '';
    document.getElementById('parseMeta').textContent = prospect.parse_provider ? `Last parse: ${prospect.parse_provider} (${prospect.parse_confidence || ''}%)` : '';
    document.getElementById('prospectModal').classList.add('open');
    document.body.classList.add('modal-open');
}

function closeProspectModal() {
    document.getElementById('prospectModal').classList.remove('open');
    document.body.classList.remove('modal-open');
}

function escapeHtml(value) {
    return String(value || '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function openDetailsModal(prospectId) {
    const prospect = prospectRecords[String(prospectId)] || prospectRecords[prospectId];
    if (!prospect) return;
    document.getElementById('details_prospect_id').value = String(prospect.id || 0);
    document.getElementById('details_company').textContent = prospect.company || '—';
    document.getElementById('details_contact_name').textContent = prospect.contact_name || '—';
    document.getElementById('details_phone').textContent = prospect.phone || '—';
    document.getElementById('details_email').textContent = prospect.email || '—';
    document.getElementById('details_website').textContent = prospect.website || '—';
    document.getElementById('details_status').textContent = statusLabels[prospect.status] || prospect.status || '—';
    document.getElementById('details_last_called_at').textContent = prospect.last_called_at_display || '—';
    document.getElementById('details_last_emailed_at').textContent = prospect.last_emailed_at_display || '—';
    document.getElementById('details_notes').textContent = prospect.notes || 'No notes yet.';
    document.getElementById('details_interacted_at').value = getCurrentLosAngelesDateTimeLocal();

    const interactions = prospectInteractions[String(prospect.id)] || prospectInteractions[prospect.id] || [];
    const history = document.getElementById('details_interactions');
    if (!Array.isArray(interactions) || interactions.length === 0) {
        history.innerHTML = '<p class="text-xs text-zinc-500">No interactions logged.</p>';
    } else {
        history.innerHTML = interactions.map((interaction) => `
            <div class="text-xs text-zinc-300 border border-zinc-800 rounded-lg px-2 py-1.5">
                <div><span class="text-cyan-300 uppercase">${escapeHtml(interaction.interaction_type || '')}</span> · ${escapeHtml(interaction.interacted_at_display || interaction.interacted_at || '')}</div>
                <div class="text-zinc-400">${escapeHtml(interaction.outcome || '')}</div>
                <div class="text-zinc-500">${escapeHtml(interaction.interaction_notes || '')}</div>
            </div>
        `).join('');
    }
    document.getElementById('prospectDetailsModal').classList.add('open');
    document.body.classList.add('modal-open');
}

function closeDetailsModal() {
    document.getElementById('prospectDetailsModal').classList.remove('open');
    document.body.classList.remove('modal-open');
}

function sendToPhone() {
    const raw = document.getElementById('details_phone').textContent.trim();
    if (!raw || raw === '—') return;

    const btn = document.getElementById('sendToPhoneBtn');
    const orig = btn.textContent;
    btn.textContent = '⏳ Sending…';
    btn.disabled = true;

    fetch('/api/send-to-phone.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ number: raw }),
    })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            btn.textContent = data.success ? '✓ Sent!' : '✗ Failed';
        })
        .catch(function () {
            btn.textContent = '✗ Failed';
        })
        .finally(function () {
            setTimeout(function () {
                btn.textContent = orig;
                btn.disabled = false;
            }, 2000);
        });
}

function getCurrentLosAngelesDateTimeLocal() {
    try {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'America/Los_Angeles',
            hour12: false,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        }).formatToParts(new Date());
        const get = (type) => parts.find((part) => part.type === type)?.value || '';
        const year = get('year');
        const month = get('month');
        const day = get('day');
        const hour = get('hour');
        const minute = get('minute');
        if (year && month && day && hour && minute) {
            return `${year}-${month}-${day}T${hour}:${minute}`;
        }
    } catch (e) {
        // Fall back below.
    }
    return laNowDateTimeLocal;
}

async function parseTextDump() {
    const raw = document.getElementById('form_raw_source').value.trim();
    const parseBtn = document.getElementById('parseBtn');
    if (!raw) {
        alert('Paste text to parse first.');
        return;
    }
    parseBtn.disabled = true;
    parseBtn.textContent = 'Parsing...';
    try {
        const res = await fetch('api/prospect-parse.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': prospectsCsrf
            },
            body: JSON.stringify({ raw_text: raw })
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.error || 'Parse failed.');
        }
        latestParseResult = data;
        latestDuplicate = data.duplicate || null;

        // Populate the preview fields regardless so they're ready for either path.
        document.getElementById('preview_company').value = data.parsed_fields.company || '';
        document.getElementById('preview_contact_name').value = data.parsed_fields.contact_name || '';
        document.getElementById('preview_phone').value = data.parsed_fields.phone || '';
        document.getElementById('preview_email').value = data.parsed_fields.email || '';
        document.getElementById('preview_website').value = data.parsed_fields.website || '';
        document.getElementById('preview_address').value = data.parsed_fields.address || '';
        document.getElementById('preview_city').value = data.parsed_fields.city || '';
        document.getElementById('preview_state').value = data.parsed_fields.state || '';
        document.getElementById('preview_zip').value = data.parsed_fields.zip || '';
        document.getElementById('preview_status').value = data.parsed_fields.status || 'new';
        document.getElementById('preview_notes').value = data.parsed_fields.notes || '';
        document.getElementById('parsePreviewMeta').textContent = `Provider: ${data.provider || 'ai'} · Confidence: ${data.confidence || 0}%`;
        document.getElementById('parsePreviewErrors').textContent = Array.isArray(data.errors) && data.errors.length > 0 ? data.errors.join(' ') : '';

        if (latestDuplicate) {
            openDuplicateModal(latestDuplicate, data.parsed_fields);
        } else {
            document.getElementById('parsePreviewModal').classList.add('open');
        }
    } catch (err) {
        alert(err.message || 'Parse failed.');
    } finally {
        parseBtn.disabled = false;
        parseBtn.textContent = 'AI Parse & Preview';
    }
}

function closeParsePreview() {
    document.getElementById('parsePreviewModal').classList.remove('open');
}

// ── Duplicate detection modal ──────────────────────────────────────────────────
function openDuplicateModal(dup, parsedFields) {
    document.getElementById('dup_company').textContent = dup.company || '(unknown)';
    document.getElementById('dup_phone').textContent = dup.phone || '—';

    const lastCalled = dup.last_called_at
        ? new Date(dup.last_called_at).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' })
        : 'Never';
    document.getElementById('dup_last_called').textContent = lastCalled;

    const parsedPhone = (parsedFields.phone || '').replace(/\D/g, '');
    const existingPhone = (dup.phone || '').replace(/\D/g, '');
    const diffNotice = document.getElementById('dup_phone_diff_notice');
    if (parsedPhone !== '' && existingPhone !== '' && parsedPhone !== existingPhone) {
        diffNotice.classList.remove('hidden');
    } else {
        diffNotice.classList.add('hidden');
    }

    document.getElementById('parseDuplicateModal').classList.add('open');
}

function closeDuplicateModal() {
    document.getElementById('parseDuplicateModal').classList.remove('open');
}

function duplicateUpdateExisting() {
    if (!latestDuplicate || !latestParseResult) return;
    closeDuplicateModal();
    // Load the existing record into the edit form.
    const existing = prospectRecords[String(latestDuplicate.id)] || prospectRecords[latestDuplicate.id];
    if (existing) {
        openEditModal(existing);
    } else {
        // Record not in current page's JS data (e.g. filtered out) — open a blank edit form with the ID.
        openCreateModal();
        document.getElementById('form_prospect_id').value = latestDuplicate.id;
        document.getElementById('prospectModalTitle').textContent = 'Update Existing Prospect';
    }
    // Overlay the parsed fields on top of the existing record.
    const f = latestParseResult.parsed_fields;
    if (f.company)       document.getElementById('form_company').value = f.company;
    if (f.contact_name)  document.getElementById('form_contact_name').value = f.contact_name;
    if (f.phone)         document.getElementById('form_phone').value = f.phone;
    if (f.email)         document.getElementById('form_email').value = f.email;
    if (f.website)       document.getElementById('form_website').value = f.website;
    if (f.address)       document.getElementById('form_address').value = f.address;
    if (f.city)          document.getElementById('form_city').value = f.city;
    if (f.state)         document.getElementById('form_state').value = f.state;
    if (f.zip)           document.getElementById('form_zip').value = f.zip;
    if (f.notes)         document.getElementById('form_notes').value = f.notes;
    document.getElementById('form_parse_provider').value = latestParseResult.provider || '';
    document.getElementById('form_parse_confidence').value = latestParseResult.confidence || '';
    document.getElementById('form_parse_errors').value = Array.isArray(latestParseResult.errors) ? latestParseResult.errors.join(' ') : '';
    document.getElementById('parseMeta').textContent = `Last parse: ${latestParseResult.provider || 'ai'} (${latestParseResult.confidence || 0}%)`;
    document.getElementById('form_force_create').value = '';
}

function duplicateCreateAnyway() {
    closeDuplicateModal();
    // Mark the submission as intentionally bypassing the duplicate check.
    document.getElementById('form_force_create').value = '1';
    document.getElementById('parsePreviewModal').classList.add('open');
}

function applyPreviewToForm() {
    document.getElementById('form_company').value = document.getElementById('preview_company').value;
    document.getElementById('form_contact_name').value = document.getElementById('preview_contact_name').value;
    document.getElementById('form_phone').value = document.getElementById('preview_phone').value;
    document.getElementById('form_email').value = document.getElementById('preview_email').value;
    document.getElementById('form_website').value = document.getElementById('preview_website').value;
    document.getElementById('form_address').value = document.getElementById('preview_address').value;
    document.getElementById('form_city').value = document.getElementById('preview_city').value;
    document.getElementById('form_state').value = document.getElementById('preview_state').value;
    document.getElementById('form_zip').value = document.getElementById('preview_zip').value;
    document.getElementById('form_status').value = document.getElementById('preview_status').value;
    document.getElementById('form_notes').value = document.getElementById('preview_notes').value;

    if (latestParseResult) {
        document.getElementById('form_parse_provider').value = latestParseResult.provider || '';
        document.getElementById('form_parse_confidence').value = latestParseResult.confidence || '';
        document.getElementById('form_parse_errors').value = Array.isArray(latestParseResult.errors) ? latestParseResult.errors.join(' ') : '';
        document.getElementById('parseMeta').textContent = `Last parse: ${latestParseResult.provider || 'ai'} (${latestParseResult.confidence || 0}%)`;
    }
    closeParsePreview();
}

// ── Email modal ───────────────────────────────────────────────────────────────
let emailProspectData = null;
let emailTemplates = [];

async function openEmailModal() {
    const prospectId = parseInt(document.getElementById('details_prospect_id').value || '0', 10);
    const prospect = prospectRecords[String(prospectId)] || prospectRecords[prospectId];
    if (!prospect) return;
    emailProspectData = prospect;

    // Load templates if not already loaded
    if (emailTemplates.length === 0) {
        try {
            const res = await fetch('api/prospect-send-email.php');
            const json = await res.json();
            emailTemplates = json.templates || [];
        } catch (err) {
            emailTemplates = [];
        }
    }

    const sel = document.getElementById('email_template_select');
    sel.innerHTML = '<option value="">— Select a template —</option>';
    emailTemplates.forEach((t) => {
        const opt = document.createElement('option');
        opt.value = String(t.id);
        opt.textContent = t.title;
        opt.dataset.subject = t.subject || '';
        opt.dataset.body = t.body || '';
        sel.appendChild(opt);
    });

    document.getElementById('email_subject').value = '';
    document.getElementById('email_body').value = '';
    document.getElementById('email_send_error').classList.add('hidden');
    document.getElementById('email_send_btn').disabled = false;
    document.getElementById('email_send_btn').textContent = 'Send Email';

    document.getElementById('prospectEmailModal').classList.add('open');
    document.body.classList.add('modal-open');
}

function closeEmailModal() {
    document.getElementById('prospectEmailModal').classList.remove('open');
    document.body.classList.remove('modal-open');
}

function prospectReplaceTags(text, prospect) {
    const adminName = <?= json_encode((string) ($_SESSION['admin_username'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
    return text
        .replaceAll('{company}', prospect.company || '')
        .replaceAll('{contact_name}', prospect.contact_name || '')
        .replaceAll('{phone}', prospect.phone || '')
        .replaceAll('{email}', prospect.email || '')
        .replaceAll('{website}', prospect.website || '')
        .replaceAll('{status}', statusLabels[prospect.status] || prospect.status || '')
        .replaceAll('{last_called}', prospect.last_called_at_display || prospect.last_called_at || '')
        .replaceAll('{last_emailed}', prospect.last_emailed_at_display || prospect.last_emailed_at || '')
        .replaceAll('{admin_name}', adminName);
}

function applyEmailTemplate() {
    const sel = document.getElementById('email_template_select');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || opt.value === '' || !emailProspectData) return;
    document.getElementById('email_subject').value = prospectReplaceTags(opt.dataset.subject || '', emailProspectData);
    document.getElementById('email_body').value = prospectReplaceTags(opt.dataset.body || '', emailProspectData);
}

async function submitSendEmail() {
    const btn = document.getElementById('email_send_btn');
    const errBox = document.getElementById('email_send_error');
    const subject = document.getElementById('email_subject').value.trim();
    const body = document.getElementById('email_body').value.trim();

    errBox.classList.add('hidden');

    if (!subject || !body) {
        errBox.textContent = 'Subject and body are required.';
        errBox.classList.remove('hidden');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Sending…';

    try {
        const res = await fetch('api/prospect-send-email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf: <?= json_encode($csrf) ?>,
                prospect_id: parseInt(document.getElementById('details_prospect_id').value || '0', 10),
                subject,
                body,
            }),
        });
        const json = await res.json();
        if (!res.ok || json.error) {
            throw new Error(json.error || 'Send failed.');
        }
        closeEmailModal();
        // Reload the page to reflect updated last_emailed_at and interaction log
        window.location.reload();
    } catch (err) {
        errBox.textContent = err.message || 'Could not send email.';
        errBox.classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Send Email';
    }
}

document.getElementById('prospectModal').addEventListener('click', (e) => {
    if (e.target.id === 'prospectModal') closeProspectModal();
});
document.getElementById('categoryModal').addEventListener('click', (e) => {
    if (e.target.id === 'categoryModal') closeCategoryModal();
});
document.getElementById('bulkCategoriesModal').addEventListener('click', (e) => {
    if (e.target.id === 'bulkCategoriesModal') closeBulkCategoriesModal();
});
const keywordModalEl = document.getElementById('keywordModal');
if (keywordModalEl) {
    keywordModalEl.addEventListener('click', (e) => {
        if (e.target.id === 'keywordModal') closeKeywordModal();
    });
}
const bulkKeywordsModalEl = document.getElementById('bulkKeywordsModal');
if (bulkKeywordsModalEl) {
    bulkKeywordsModalEl.addEventListener('click', (e) => {
        if (e.target.id === 'bulkKeywordsModal') closeBulkKeywordsModal();
    });
}
document.getElementById('parsePreviewModal').addEventListener('click', (e) => {
    if (e.target.id === 'parsePreviewModal') closeParsePreview();
});
document.getElementById('parseDuplicateModal').addEventListener('click', (e) => {
    if (e.target.id === 'parseDuplicateModal') closeDuplicateModal();
});
document.getElementById('prospectDetailsModal').addEventListener('click', (e) => {
    if (e.target.id === 'prospectDetailsModal') closeDetailsModal();
});
document.getElementById('prospectEmailModal').addEventListener('click', (e) => {
    if (e.target.id === 'prospectEmailModal') closeEmailModal();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeCategoryModal();
        closeBulkCategoriesModal();
        closeKeywordModal();
        closeBulkKeywordsModal();
        closeDetailsModal();
        closeProspectModal();
        closeParsePreview();
        closeDuplicateModal();
        closeEmailModal();
    }
});
</script>

<form id="deleteCategoryForm" method="POST" action="prospects.php" hidden>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="delete_category">
    <input type="hidden" name="category_id" id="deleteCategoryId" value="">
</form>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
