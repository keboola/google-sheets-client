<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsClient;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\MimeType;
use GuzzleHttp\Psr7\Query;
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

    protected GoogleApi $api;

    /** @var array<int, string> */
    protected array $defaultFields = ['kind', 'id', 'name', 'mimeType', 'parents'];

    protected bool $teamDriveSupport = false;

    public function __construct(GoogleApi $api)
    {
        $this->api = $api;
    }

    public function getApi(): GoogleApi
    {
        return $this->api;
    }

    /**
     * @param array<int, string> $fields
     */
    public function setDefaultFields(array $fields): void
    {
        $this->defaultFields = $fields;
    }

    public function setTeamDriveSupport(bool $value): void
    {
        $this->teamDriveSupport = $value;
    }

    /**
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    public function getFile(string $fileId, array $fields = []): array
    {
        $uri = $this->addFields(sprintf('%s/%s', self::URI_DRIVE_FILES, $fileId), $fields);
        if ($this->teamDriveSupport) {
            $uri = $this->addAllDriveSupport($uri);
        }

        $response = $this->api->request($uri);
        $decoded = json_decode($response->getBody()->getContents(), true);
        if (!is_array($decoded)) {
            throw new SheetsClientException('Invalid JSON response');
        }
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
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
        return $this->decodeJsonResponse($response);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createFile(string $pathname, string $title, array $params = []): array
    {
        $contentType = MimeType::fromFilename($pathname);
        $initResponse = $this->initFileUpload(array_merge(['name' => $title], $params), $contentType);

        $contentUploadUrl = $initResponse->getHeader('Location')[0];
        if ($this->teamDriveSupport) {
            $contentUploadUrl = $this->addAllDriveSupport($contentUploadUrl);
        }

        return $this->uploadFileContent($contentUploadUrl, $pathname, $contentType);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function initFileUpload(
        array $body,
        ?string $contentType = null,
        ?string $fileId = null,
    ): Response {
        $url = $fileId
            ? sprintf('%s/%s?uploadType=resumable', self::URI_DRIVE_UPLOAD, $fileId)
            : sprintf('%s?uploadType=resumable', self::URI_DRIVE_UPLOAD);

        $url = $this->addFields($url);
        if ($this->teamDriveSupport) {
            $url = $this->addAllDriveSupport($url);
        }

        $initResponse = $this->api->request(
            $url,
            $fileId ? 'PATCH' : 'POST',
            [
                'X-Upload-Content-Type' => $contentType,
                'Content-Type' => 'application/json',
            ],
            [
                'json' => $body,
            ],
        );

        if ($initResponse->getStatusCode() !== 200) {
            throw new SheetsClientException(sprintf(
                'Failed to initialize upload. %s',
                $initResponse->getBody()->getContents(),
            ));
        }
        if ($initResponse->hasHeader('Location') === false) {
            throw new SheetsClientException('Missing Location header in response');
        }

        return $initResponse;
    }

    /**
     * @return array<string, mixed>
     */
    protected function uploadFileContent(string $url, string $pathname, ?string $contentType): array
    {
        $uploadResponse = $this->api->request(
            $this->addFields($url),
            'PUT',
            [
                'Content-Type' => $contentType,
                'Content-Length' => filesize($pathname),
            ],
            [
                'body' => Utils::streamFor(fopen($pathname, 'r')),
            ],
        );

        return $this->decodeJsonResponse($uploadResponse);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createFileMetadata(string $title, array $params): array
    {
        $body = [
            'name' => $title,
        ];

        $uri = $this->addFields(self::URI_DRIVE_FILES);
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
            ],
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function updateFile(string $fileId, string $pathname, array $params): array
    {
        $contentType = MimeType::fromFilename($pathname);
        $initResponse = $this->initFileUpload($params, $contentType, $fileId);

        $contentUploadUrl = $initResponse->getHeader('Location')[0];
        if ($this->teamDriveSupport) {
            $contentUploadUrl = $this->addAllDriveSupport($contentUploadUrl);
        }

        return $this->uploadFileContent($contentUploadUrl, $pathname, $contentType);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function updateFileMetadata(string $fileId, array $body = [], array $params = []): array
    {
        $uri = $this->addFields(sprintf('%s/%s', self::URI_DRIVE_FILES, $fileId));
        if (!empty($params)) {
            $uri .= '?' . Query::build($params);
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
            ],
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function deleteFile(string $fileId): Response
    {
        $uri = sprintf('%s/%s', self::URI_DRIVE_FILES, $fileId);
        if ($this->teamDriveSupport) {
            $uri = $this->addAllDriveSupport($uri);
        }
        return $this->api->request($uri, 'DELETE');
    }

    public function exportFile(string $fileId, string $mimeType = 'text/csv'): Response
    {
        return $this->api->request(
            sprintf(
                '%s/%s/export?mimeType=%s',
                self::URI_DRIVE_FILES,
                $fileId,
                $mimeType,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getSpreadsheet(string $fileId): array
    {
        $response = $this->api->request(
            sprintf('%s%s', self::URI_SPREADSHEETS, $fileId),
            'GET',
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getSpreadsheetValues(string $spreadsheetId, string $range, array $params = []): array
    {
        $uri = sprintf('%s%s/values/%s', self::URI_SPREADSHEETS, $spreadsheetId, $range);
        if (!empty($params)) {
            $uri .= '?' . Query::build($params);
        }

        $response = $this->api->request($uri, 'GET');

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param array<string, mixed> $fileProperties
     * @param array<string, mixed> $sheets
     * @return array<string, mixed>
     */
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
            ['json' => $body],
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param array<string, mixed> $sheet
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function batchUpdateSpreadsheet(string $spreadsheetId, array $body): array
    {
        $response = $this->api->request(
            sprintf(
                '%s%s:batchUpdate',
                self::URI_SPREADSHEETS,
                $spreadsheetId,
            ),
            'POST',
            [],
            ['json' => $body],
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param array<int, array<int, mixed>> $values
     * @return array<string, mixed>
     */
    public function updateSpreadsheetValues(string $spreadsheetId, string $range, array $values): array
    {
        $response = $this->api->request(
            sprintf(
                '%s%s/values/%s?valueInputOption=USER_ENTERED',
                self::URI_SPREADSHEETS,
                $spreadsheetId,
                $range,
            ),
            'PUT',
            [],
            [
                'json' => [
                    'values' => $values,
                ],
            ],
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param array<int, array<int, mixed>> $values
     * @return array<string, mixed>
     */
    public function appendSpreadsheetValues(string $spreadsheetId, string $range, array $values): array
    {
        $response = $this->api->request(
            sprintf(
                '%s%s/values/%s:append?valueInputOption=USER_ENTERED',
                self::URI_SPREADSHEETS,
                $spreadsheetId,
                $range,
            ),
            'POST',
            [],
            [
                'json' => [
                    'values' => $values,
                ],
            ],
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function clearSpreadsheetValues(string $spreadsheetId, string $range): array
    {
        $response = $this->api->request(
            sprintf(
                '%s%s/values/%s:clear',
                self::URI_SPREADSHEETS,
                $spreadsheetId,
                $range,
            ),
            'POST',
            [],
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function generateIds(int $count = 10): array
    {
        $response = $this->api->request(
            sprintf('%s/generateIds?count=%s', self::URI_DRIVE_FILES, $count),
        );

        return $this->decodeJsonResponse($response);
    }

    public function fileExists(string $fileId): bool
    {
        try {
            $this->getFile($fileId);
            return true;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            // @phpstan-ignore-next-line ResponseInterface can theoretically be null
            if ($response !== null && $response->getStatusCode() !== 404) {
                throw $e;
            }
        }
        return false;
    }

    /**
     * @param array<int, string> $fields
     */
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

    /**
     * @return array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    protected function decodeJsonResponse(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($response->getBody()->getContents(), true);
        if (!is_array($decoded)) {
            throw new SheetsClientException('Invalid JSON response');
        }
        /** @var array<string, mixed> */
        return $decoded;
    }
}
