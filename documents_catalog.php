<?php

function promed_document_catalog(): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    $baseDir = __DIR__ . '/uploads/documents/templates';

    $catalog = [
        'recu-ava' => [
            'slug' => 'recu-ava',
            'title' => 'Recu AVA',
            'project' => 'AVA',
            'description' => 'Modele de recu utilisable par les commerciaux pour les dossiers AVA.',
            'filename' => 'recu-ava-im-1.docx',
            'path' => $baseDir . '/recu-ava-im-1.docx',
            'web_path' => 'uploads/documents/templates/recu-ava-im-1.docx',
            'extension' => 'docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'option-vente-flava' => [
            'slug' => 'option-vente-flava',
            'title' => 'Option de vente FLAVA',
            'project' => 'FLAVA',
            'description' => 'Modele d option de vente a modifier et imprimer pour les clients FLAVA.',
            'filename' => 'option-vente-flava-imp.doc',
            'path' => $baseDir . '/option-vente-flava-imp.doc',
            'web_path' => 'uploads/documents/templates/option-vente-flava-imp.doc',
            'extension' => 'doc',
            'mime' => 'application/msword',
        ],
        'frais-generaux' => [
            'slug' => 'frais-generaux',
            'title' => 'Frais generaux',
            'project' => 'COMMUN',
            'description' => 'Tableau Excel des frais generaux partage entre les equipes.',
            'filename' => 'frais-generaux-imp.xlsx',
            'path' => $baseDir . '/frais-generaux-imp.xlsx',
            'web_path' => 'uploads/documents/templates/frais-generaux-imp.xlsx',
            'extension' => 'xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
        'bordereau-remise-cles' => [
            'slug' => 'bordereau-remise-cles',
            'title' => 'Bordereau remise des cles',
            'project' => 'COMMUN',
            'description' => 'Bordereau de remise des cles a renseigner puis imprimer.',
            'filename' => 'bordereau-remise-cles-im-1.docx',
            'path' => $baseDir . '/bordereau-remise-cles-im-1.docx',
            'web_path' => 'uploads/documents/templates/bordereau-remise-cles-im-1.docx',
            'extension' => 'docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
    ];

    return $catalog;
}

function promed_document_find(string $slug): ?array
{
    $catalog = promed_document_catalog();
    return $catalog[$slug] ?? null;
}

function promed_document_exists(array $document): bool
{
    $path = (string)($document['path'] ?? '');
    return $path !== '' && is_file($path);
}

function promed_document_project_label(string $project): string
{
    $normalized = strtoupper(trim($project));
    return $normalized === '' ? 'Commun' : $normalized;
}

function promed_document_can_use_office_edit(array $document): bool
{
    return in_array(strtolower((string)($document['extension'] ?? '')), ['doc', 'docx', 'xls', 'xlsx'], true);
}

function promed_document_editor_label(array $document): string
{
    $extension = strtolower((string)($document['extension'] ?? ''));
    if (in_array($extension, ['xls', 'xlsx'], true)) {
        return 'Ouvrir dans Excel';
    }
    if (in_array($extension, ['doc', 'docx'], true)) {
        return 'Ouvrir dans Word';
    }
    return 'Ouvrir le document';
}

function promed_document_office_scheme(array $document): string
{
    $extension = strtolower((string)($document['extension'] ?? ''));
    if (in_array($extension, ['doc', 'docx'], true)) {
        return 'ms-word:ofe|u|';
    }
    if (in_array($extension, ['xls', 'xlsx'], true)) {
        return 'ms-excel:ofe|u|';
    }
    return '';
}

function promed_app_base_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim($dir, '/');
    return $scheme . '://' . $host . ($dir !== '' ? $dir : '');
}

function promed_document_stream_url(string $slug, bool $download = false): string
{
    $document = promed_document_find($slug);
    if (!$document) {
        return '#';
    }
    return (string)($document['web_path'] ?? '#');
}

function promed_document_absolute_stream_url(string $slug, bool $download = false): string
{
    return promed_app_base_url() . '/' . promed_document_stream_url($slug, $download);
}

function promed_document_editor_url(array $document): string
{
    $streamUrl = promed_document_absolute_stream_url((string)$document['slug'], false);
    $scheme = promed_document_office_scheme($document);
    if ($scheme === '') {
        return $streamUrl;
    }
    return $scheme . $streamUrl;
}
