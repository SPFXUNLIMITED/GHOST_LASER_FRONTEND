<?php

function prospectStatuses(): array
{
    return [
        'new' => 'New',
        'attempting_contact' => 'Attempting Contact',
        'contacted' => 'Contacted',
        'qualified' => 'Qualified',
        'not_interested' => 'Not Interested',
        'converted' => 'Converted',
        'archived' => 'Archived',
    ];
}

function prospectNormalizePhone(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?: '';
}

function prospectSanitizeField(string $value, int $maxLength = 255): string
{
    return mb_substr(trim($value), 0, $maxLength);
}

function prospectParseRawText(string $rawText): array
{
    $rawText = trim($rawText);
    $lines = preg_split('/\R+/', $rawText) ?: [];
    $cleanLines = array_values(array_filter(array_map(static fn($line) => trim((string) $line), $lines), static fn($line) => $line !== ''));

    $email = '';
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $rawText, $m)) {
        $email = strtolower($m[0]);
    }

    $website = '';
    if (preg_match('~\b((?:https?://)?(?:www\.)?[a-z0-9\-]+\.[a-z]{2,}(?:/[^\s]*)?)~i', $rawText, $m)) {
        $website = $m[1];
        if (!preg_match('~^https?://~i', $website)) {
            $website = 'https://' . ltrim($website, '/');
        }
    }

    $phone = '';
    if (preg_match('/(?:\+?1[\s\-.]?)?(?:\(?\d{3}\)?[\s\-.]?)\d{3}[\s\-.]?\d{4}/', $rawText, $m)) {
        $phone = $m[0];
    }

    $company = '';
    $contact = '';
    foreach ($cleanLines as $line) {
        if ($company === '' && preg_match('/^(?:company|business|organization)\s*:\s*(.+)$/i', $line, $m)) {
            $company = prospectSanitizeField($m[1]);
        }
        if ($contact === '' && preg_match('/^(?:contact|owner|manager|name)\s*:\s*(.+)$/i', $line, $m)) {
            $contact = prospectSanitizeField($m[1]);
        }
    }

    if ($company === '' && isset($cleanLines[0])) {
        $company = prospectSanitizeField($cleanLines[0]);
    }

    if ($contact === '') {
        foreach ($cleanLines as $line) {
            if (preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,2}$/', $line)) {
                $contact = prospectSanitizeField($line);
                break;
            }
        }
    }

    $status = 'new';
    $lower = strtolower($rawText);
    if (str_contains($lower, 'not interested')) {
        $status = 'not_interested';
    } elseif (str_contains($lower, 'qualified') || str_contains($lower, 'booked')) {
        $status = 'qualified';
    } elseif (str_contains($lower, 'called') || str_contains($lower, 'emailed') || str_contains($lower, 'contacted')) {
        $status = 'contacted';
    }

    $confidence = 0.35;
    foreach ([$company, $contact, $phone, $email, $website] as $field) {
        if (trim((string) $field) !== '') {
            $confidence += 0.12;
        }
    }
    $confidence = max(0.0, min(0.95, $confidence));

    $errors = [];
    if ($company === '') {
        $errors[] = 'Company could not be confidently detected.';
    }
    if ($contact === '') {
        $errors[] = 'Contact name could not be confidently detected.';
    }

    return [
        'fields' => [
            'company' => $company,
            'contact_name' => $contact,
            'phone' => prospectSanitizeField($phone, 100),
            'email' => prospectSanitizeField($email),
            'website' => prospectSanitizeField($website),
            'status' => $status,
            'notes' => prospectSanitizeField($rawText, 10000),
        ],
        'confidence' => round($confidence * 100, 2),
        'provider' => 'heuristic-ai',
        'errors' => $errors,
    ];
}
