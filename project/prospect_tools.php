<?php

function prospectStatuses(): array
{
    return [
        'no_answer' => 'No Answer',
        'left_voicemail' => 'Left Voicemail',
        'disconnected' => 'Disconnected / Bad Number',
        'not_interested' => 'Not Interested',
        'has_provider' => 'Already Has Service Provider',
        'farms_out' => 'Farms Out Laser Work',
        'interested_service' => 'Interested in Service',
        'interested_machine' => 'Interested in Machine',
        'needs_follow_up' => 'Needs Follow Up',
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

    $status = 'no_answer';
    $lower = strtolower($rawText);
    if (str_contains($lower, 'not interested')) {
        $status = 'not_interested';
    } elseif (str_contains($lower, 'farms out') || str_contains($lower, 'farms laser')) {
        $status = 'farms_out';
    } elseif (str_contains($lower, 'already has') || str_contains($lower, 'has provider') || str_contains($lower, 'current provider')) {
        $status = 'has_provider';
    } elseif (str_contains($lower, 'interested in machine') || str_contains($lower, 'buy a laser') || str_contains($lower, 'purchase machine')) {
        $status = 'interested_machine';
    } elseif (str_contains($lower, 'interested in service') || str_contains($lower, 'quote for service') || str_contains($lower, 'interested in getting')) {
        $status = 'interested_service';
    } elseif (str_contains($lower, 'follow up') || str_contains($lower, 'follow-up')) {
        $status = 'needs_follow_up';
    } elseif (str_contains($lower, 'left voicemail') || str_contains($lower, 'voicemail')) {
        $status = 'left_voicemail';
    } elseif (str_contains($lower, 'disconnected') || str_contains($lower, 'bad number') || str_contains($lower, 'wrong number')) {
        $status = 'disconnected';
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

    $notes = '';
    foreach ($cleanLines as $line) {
        if (preg_match('/^(?:notes?|summary)\s*:\s*(.+)$/i', $line, $m)) {
            $notes = prospectSanitizeField($m[1], 10000);
            break;
        }
    }

    return [
        'fields' => [
            'company' => $company,
            'contact_name' => $contact,
            'phone' => prospectSanitizeField($phone, 100),
            'email' => prospectSanitizeField($email),
            'website' => prospectSanitizeField($website),
            'status' => $status,
            'notes' => $notes,
        ],
        'confidence' => round($confidence * 100, 2),
        'provider' => 'heuristic-ai',
        'errors' => $errors,
    ];
}
