<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsClient;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use Keboola\Google\ClientBundle\Google\RestApi as GoogleApi;

class Client
{
    public const URI_DRIVE_FILES = 'https://www.googleapis.com/drive/v3/files';

    public const URI_DRIVE_UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    public const URI_SPREADSHEETS = 'https://sheets.googleapis.com/v4/spreadsheets/';

    public const MIME_TYPE_SPREADSHEET = 'application/vnd.google-apps.spreadsheet';

    /** @var GoogleApi */
    protected $api;

    /** @var array */
    protected $defaultFields = ['kind', 'id', 'name', 'mimeType', 'parents'];

    /** @var bool */
    protected $teamDriveSupport = false;

    public function __construct(GoogleApi $api)
    {
        $this->api = $api;
    }

    public function getApi(): GoogleApi
    {
        return $this->api;
    }

    public function setDefaultFields(array $fields): void
    {
        $this->defaultFields = $fields;
    }

    public function setTeamDriveSupport(bool $value): void
    {
        $this->teamDriveSupport = $value;
    }

    public function getFile(string $fileId, array $fields = []): array
    {
        $uri = $this->addFields(sprintf('%s/%s', self::URI_DRIVE_FILES, $fileId), $fields);
        if ($this->teamDriveSupport) {
            $uri = $this->addTeamDrive($uri);
        }

        $response = $this->api->request($uri);
        return json_decode($response->getBody()->getContents(), true);
    }

    public function listFiles(string $query = ''): array
    {
        $uri = self::URI_DRIVE_FILES;
        if (!empty($query)) {
            $uri .= sprintf('?q=%s', $query);
        }
        if ($this->teamDriveSupport) {
            $uri = $this->addTeamDrive($uri);
        }

        $response = $this->api->request($uri);
        return json_decode($response->getBody()->getContents(), true);
    }

    public function createFile(string $pathname, string $title, array $params = []): array
    {
        $fileMetadata = $this->createFileMetadata($title, $params);

        $mediaUrl = $this->addFields(
            sprintf('%s/%s?uploadType=media', self::URI_DRIVE_UPLOAD, $fileMetadata['id'])
        );
        if ($this->teamDriveSupport) {
            $mediaUrl = $this->addTeamDrive($mediaUrl);
        }

        $response = $this->api->request(
            $mediaUrl,
            'PATCH',
            [
                'Content-Type' => \GuzzleHttp\Psr7\mimetype_from_filename($pathname),
                'Content-Length' => filesize($pathname),
            ],
            [
                'body' => \GuzzleHttp\Psr7\stream_for(fopen($pathname, 'r')),
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function createFileMetadata(string $title, array $params): array
    {
        $body = [
            'name' => $title,
        ];

        $uri = $this->addFields(self::URI_DRIVE_FILES);
        if ($this->teamDriveSupport) {
            $uri = $this->addTeamDrive($uri);
        }

        $response = $this->api->request(
            $uri,
            'POST',
            [
                'Content-Type' => 'application/json',
            ],
            [
                'json' => array_merge($body, $params),
            ]
        );

        return json_decode($response->getBody(), true);
    }

    public function updateFile(string $fileId, string $pathname, array $params): array
    {
        $responseJson = $this->updateFileMetadata($fileId, $params);
        $uri = $this->addFields(sprintf('%s/%s?uploadType=media', self::URI_DRIVE_UPLOAD, $responseJson['id']));
        if ($this->teamDriveSupport) {
            $uri = $this->addTeamDrive($uri);
        }

        $response = $this->api->request(
            $uri,
            'PATCH',
            [
                'Content-Type' => \GuzzleHttp\Psr7\mimetype_from_filename($pathname),
                'Content-Length' => filesize($pathname),
            ],
            [
                'body' => \GuzzleHttp\Psr7\stream_for(fopen($pathname, 'r')),
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function updateFileMetadata(string $fileId, array $body = [], array $params = []): array
    {
        $uri = sprintf('%s/%s', self::URI_DRIVE_FILES, $fileId);
        if (!empty($params)) {
            $uri .= '?' . \GuzzleHttp\Psr7\build_query($params);
        }
        if ($this->teamDriveSupport) {
            $uri = $this->addTeamDrive($uri);
        }

        $response = $this->api->request(
            $this->addFields($uri),
            'PATCH',
            [
                'Content-Type' => 'application/json',
            ],
            [
                'json' => $body,
            ]
        );

        return json_decode($response->getBody(), true);
    }

    public function deleteFile(string $fileId): \GuzzleHttp\Psr7\Response
    {
        $uri = sprintf('%s/%s', self::URI_DRIVE_FILES, $fileId);
        if ($this->teamDriveSupport) {
            $uri = $this->addTeamDrive($uri);
        }
        return $this->api->request($uri, 'DELETE');
    }

    public function exportFile(string $fileId, string $mimeType = 'text/csv'): \GuzzleHttp\Psr7\Response
    {
        return $this->api->request(
            sprintf(
                '%s/%s/export?mimeType=%s',
                self::URI_DRIVE_FILES,
                $fileId,
                $mimeType
            )
        );
    }

    public function getSpreadsheet(string $fileId): array
    {
        $response = $this->api->request(
            sprintf('%s%s', self::URI_SPREADSHEETS, $fileId),
            'GET'
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function getSpreadsheetValues(string $spreadsheetId, string $range, array $params = []): array
    {
        $uri = sprintf('%s%s/values/%s', self::URI_SPREADSHEETS, $spreadsheetId, $range);
        if (!empty($params)) {
            $uri .= '?' . \GuzzleHttp\Psr7\build_query($params);
        }

        $response = $this->api->request($uri, 'GET');

        return json_decode($response->getBody()->getContents(), true);
    }

    public function createSpreadsheet(array $fileProperties, array $sheets, ?string $fileId = null): array
    {
        $body = [
            'properties' => $fileProperties,
            'sheets' => $sheets,
        ];

        if ($fileId !== null) {
            $body['spreadsheetId'] = $fileId;
        }

        $response = $this->api->request(
            self::URI_SPREADSHEETS,
            'POST',
            [],
            ['json' => $body]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function addSheet(string $spreadsheetId, array $sheet): array
    {
        return $this->batchUpdateSpreadsheet($spreadsheetId, [
            'requests' => [
                [
                    'addSheet' => $sheet,
                ],
            ],
        ]);
    }

    public function updateSheet(string $spreadsheetId, array $properties): array
    {
        return $this->batchUpdateSpreadsheet($spreadsheetId, [
            'requests' => [
                [
                    'updateSheetProperties' => [
                        'properties' => $properties,
                        'fields' => 'title',
                    ],
                ],
            ],
        ]);
    }

    public function deleteSheet(string $spreadsheetId, string $sheetId): array
    {
        return $this->batchUpdateSpreadsheet($spreadsheetId, [
            'requests' => [
                [
                    'deleteSheet' => [
                        'sheetId' => $sheetId,
                    ],
                ],
            ],
        ]);
    }

    public function batchUpdateSpreadsheet(string $spreadsheetId, array $body): array
    {
        $response = $this->api->request(
            sprintf(
                '%s%s:batchUpdate',
                self::URI_SPREADSHEETS,
                $spreadsheetId
            ),
            'POST',
            [],
            ['json' => $body]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function updateSpreadsheetValues(string $spreadsheetId, string $range, array $values): array
    {
        $response = $this->api->request(
            sprintf(
                '%s%s/values/%s?valueInputOption=USER_ENTERED',
                self::URI_SPREADSHEETS,
                $spreadsheetId,
                $range
            ),
            'PUT',
            [],
            [
                'json' => [
                    'values' => $values,
                ],
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function appendSpreadsheetValues(string $spreadsheetId, string $range, array $values): array
    {
        $response = $this->api->request(
            sprintf(
                '%s%s/values/%s:append?valueInputOption=USER_ENTERED',
                self::URI_SPREADSHEETS,
                $spreadsheetId,
                $range
            ),
            'POST',
            [],
            [
                'json' => [
                    'values' => $values,
                ],
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function clearSpreadsheetValues(string $spreadsheetId, string $range): array
    {
        $response = $this->api->request(
            sprintf(
                '%s%s/values/%s:clear',
                self::URI_SPREADSHEETS,
                $spreadsheetId,
                $range
            ),
            'POST',
            []
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function generateIds(int $count = 10): array
    {
        $response = $this->api->request(
            sprintf('%s/generateIds?count=%s', self::URI_DRIVE_FILES, $count)
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function fileExists(string $fileId): bool
    {
        try {
            $this->getFile($fileId);
            return true;
        } catch (ClientException $e) {
            if ($e->getResponse() === null || $e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
        }
        return false;
    }

    public function listRevisions(string $fileId): array
    {
        $response = $this->api->request(
            sprintf('%s/%s/revisions', self::URI_DRIVE_FILES, $fileId)
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function getRevision(string $fileId, string $revisionId): array
    {
        $response = $this->api->request(
            $this->addFields(
                sprintf('%s/%s/revisions/%s', self::URI_DRIVE_FILES, $fileId, $revisionId),
                ['kind', 'id', 'mimeType', 'modifiedTime', 'published']
            )
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function updateRevision(string $fileId, string $revisionId, array $body): array
    {
        $response = $this->api->request(
            $this->addFields(
                sprintf('%s/%s/revisions/%s', self::URI_DRIVE_FILES, $fileId, $revisionId),
                ['kind', 'id', 'mimeType', 'modifiedTime', 'published']
            ),
            'PATCH',
            [
                'Content-Type' => 'application/json',
            ],
            [
                'json' => $body,
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function deleteRevision(string $fileId, string $revisionId): Response
    {
        return $this->api->request(
            sprintf('%s/%s/revisions/%s', self::URI_DRIVE_FILES, $fileId, $revisionId),
            'DELETE'
        );
    }

    protected function addFields(string $uri, array $fields = []): string
    {
        if (empty($fields)) {
            $fields = $this->defaultFields;
        }
        $delimiter = (strstr($uri, '?') === false) ? '?' : '&';
        return $uri . sprintf('%sfields=%s', $delimiter, implode(',', $fields));
    }

    protected function addTeamDrive(string $uri): string
    {
        $delimiter = (strstr($uri, '?') === false) ? '?' : '&';
        return sprintf('%s%ssupportsTeamDrives=true', $uri, $delimiter);
    }
}
