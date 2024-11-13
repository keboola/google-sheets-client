<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsClient;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\MimeType;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Keboola\Google\ClientBundle\Google\RestApi as GoogleApi;
use Keboola\GoogleSheetsClient\Exception\ClientException as SheetsClientException;

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
            $uri = $this->addAllDriveSupport($uri);
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
            $uri = $this->addAllDriveSupport($uri);
        }

        $response = $this->api->request($uri);
        return json_decode($response->getBody()->getContents(), true);
    }

    public function createFile(string $pathname, string $title, array $params = []): array
    {
        $originalContentType = MimeType::fromFilename($pathname);
        $initResponse = $this->initFileUpload(['name' => $title], $params, $originalContentType);

        $contentUploadUrl = $initResponse->getHeader('Location')[0];
        if ($this->teamDriveSupport) {
            $contentUploadUrl = $this->addAllDriveSupport($contentUploadUrl);
        }

        $uploadResponse = $this->api->request(
            $contentUploadUrl,
            'PUT',
            [
                'Content-Type' => $originalContentType,
                'Content-Length' => filesize($pathname),
            ],
            [
                'body' => Utils::streamFor(fopen($pathname, 'r')),
            ]
        );

        return json_decode($uploadResponse->getBody()->getContents(), true);
    }

    public function initFileUpload($body, $params, $contentType): Response
    {
        $initUploadUrl = sprintf('%s?uploadType=resumable', self::URI_DRIVE_UPLOAD);
        if ($this->teamDriveSupport) {
            $initUploadUrl = $this->addAllDriveSupport($initUploadUrl);
        }

        $initResponse = $this->api->request(
            $initUploadUrl,
            'POST',
            [
                'X-Upload-Content-Type' => $contentType,
                'Content-Type' => 'application/json',
            ],
            [
                'json' => array_merge($body, $params),
            ]
        );

        if ($initResponse->getStatusCode() !== 200) {
            throw new SheetsClientException(sprintf(
                'Failed to initialize upload. %s',
                $initResponse->getBody()->getContents()
            ));
        }
        if ($initResponse->hasHeader('Location') === false) {
            throw new SheetsClientException('Missing Location header in response');
        }

        return $initResponse;
    }

    /** @deprecated */
    public function createFileMetadata(string $title, array $params): array
    {
        $body = [
            'name' => $title,
        ];

        $uri = self::URI_DRIVE_FILES;
        if ($this->teamDriveSupport) {
            $uri = $this->addAllDriveSupport($uri);
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

        return json_decode($response->getBody()->getContents(), true);
    }

    public function updateFile(string $fileId, string $pathname, array $params): array
    {
        $responseJson = $this->updateFileMetadata($fileId, $params);
        $uri = sprintf('%s/%s?uploadType=media', self::URI_DRIVE_UPLOAD, $responseJson['id']);
        if ($this->teamDriveSupport) {
            $uri = $this->addAllDriveSupport($uri);
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
            $uri = $this->addAllDriveSupport($uri);
        }

        $response = $this->api->request(
            $uri,
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

    public function deleteFile(string $fileId): \GuzzleHttp\Psr7\Response
    {
        $uri = sprintf('%s/%s', self::URI_DRIVE_FILES, $fileId);
        if ($this->teamDriveSupport) {
            $uri = $this->addAllDriveSupport($uri);
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

    protected function addFields(string $uri, array $fields = []): string
    {
        if (empty($fields)) {
            $fields = $this->defaultFields;
        }
        $delimiter = (strstr($uri, '?') === false) ? '?' : '&';
        return $uri . sprintf('%sfields=%s', $delimiter, implode(',', $fields));
    }

    protected function addAllDriveSupport(string $uri): string
    {
        $delimiter = (strstr($uri, '?') === false) ? '?' : '&';
        return sprintf('%s%ssupportsAllDrives=true', $uri, $delimiter);
    }
}
