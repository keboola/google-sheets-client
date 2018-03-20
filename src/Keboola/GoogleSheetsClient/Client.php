<?php
/**
 * Author: miro@keboola.com
 * Date: 10/03/2017
 */

namespace Keboola\GoogleSheetsClient;

use GuzzleHttp\Exception\ClientException;
use Keboola\Google\ClientBundle\Google\RestApi as GoogleApi;

class Client
{
    const URI_DRIVE_FILES = 'https://www.googleapis.com/drive/v3/files';

    const URI_DRIVE_UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    const URI_SPREADSHEETS = 'https://sheets.googleapis.com/v4/spreadsheets/';

    const MIME_TYPE_SPREADSHEET = 'application/vnd.google-apps.spreadsheet';

    /** @var GoogleApi */
    protected $api;

    protected $defaultFields = ['kind', 'id', 'name', 'mimeType', 'parents'];

    protected $teamDriveSupport = false;

    public function __construct(GoogleApi $api)
    {
        $this->api = $api;
    }

    /**
     * @return GoogleApi
     */
    public function getApi()
    {
        return $this->api;
    }

    public function setDefaultFields($fields)
    {
        $this->defaultFields = $fields;
    }

    public function setTeamDriveSupport($value)
    {
        $this->teamDriveSupport = $value;
    }

    /**
     * @param $fileId
     * @param array $fields
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function getFile($fileId, $fields = [])
    {
        $uri = $this->addFields(sprintf('%s/%s', self::URI_DRIVE_FILES, $fileId), $fields);
        if ($this->teamDriveSupport) {
            $uri = $this->addTeamDrive($uri);
        }

        $response = $this->api->request($uri);
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param string $query
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function listFiles($query = '')
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

    /**
     * @param $pathname
     * @param $title
     * @param array $params
     * @return array
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function createFile($pathname, $title, $params = [])
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
                'Content-Length' => filesize($pathname)
            ],
            [
                'body' => \GuzzleHttp\Psr7\stream_for(fopen($pathname, 'r'))
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $title
     * @param $params
     * @return mixed
     */
    public function createFileMetadata($title, $params)
    {
        $body = [
            'name' => $title
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
                'json' => array_merge($body, $params)
            ]
        );

        return json_decode($response->getBody(), true);
    }

    /**
     * @param $fileId
     * @param $pathname
     * @param $params
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function updateFile($fileId, $pathname, $params)
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
                'Content-Length' => filesize($pathname)
            ],
            [
                'body' => \GuzzleHttp\Psr7\stream_for(fopen($pathname, 'r'))
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $fileId
     * @param array $body
     * @param array $params
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function updateFileMetadata($fileId, $body = [], $params = [])
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
                'json' => $body
            ]
        );

        return json_decode($response->getBody(), true);
    }

    /**
     * @param $fileId
     * @return \GuzzleHttp\Psr7\Response
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function deleteFile($fileId)
    {
        $uri = sprintf('%s/%s', self::URI_DRIVE_FILES, $fileId);
        if ($this->teamDriveSupport) {
            $uri = $this->addTeamDrive($uri);
        }
        return $this->api->request($uri, 'DELETE');
    }

    /**
     * @param $fileId
     * @param string $mimeType
     * @return \GuzzleHttp\Psr7\Response
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function exportFile($fileId, $mimeType = 'text/csv')
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

    /**
     * Returns list of sheet for given document
     *
     * @param $fileId
     * @return array|bool
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function getSpreadsheet($fileId)
    {
        $response = $this->api->request(
            sprintf('%s%s', self::URI_SPREADSHEETS, $fileId),
            'GET'
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $spreadsheetId
     * @param $range
     * @param array $params
     * @return array
     */
    public function getSpreadsheetValues($spreadsheetId, $range, $params = [])
    {
        $uri = sprintf('%s%s/values/%s', self::URI_SPREADSHEETS, $spreadsheetId, $range);
        if (!empty($params)) {
            $uri .= '?' . \GuzzleHttp\Psr7\build_query($params);
        }

        $response = $this->api->request($uri, 'GET');

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $fileProperties
     * @param $sheets
     * @param null $fileId
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function createSpreadsheet($fileProperties, $sheets, $fileId = null)
    {
        $body = [
            'properties' => $fileProperties,
            'sheets' => $sheets
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

    /**
     * @param $spreadsheetId
     * @param $sheet
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function addSheet($spreadsheetId, $sheet)
    {
        return $this->batchUpdateSpreadsheet($spreadsheetId, [
            'requests' => [
                [
                    'addSheet' => $sheet
                ]
            ]
        ]);
    }

    public function updateSheet($spreadsheetId, $properties)
    {
        return $this->batchUpdateSpreadsheet($spreadsheetId, [
            'requests' => [
                [
                    'updateSheetProperties' => [
                        'properties' => $properties,
                        'fields' => 'title'
                    ]
                ]
            ]
        ]);
    }

    /**
     * @param $spreadsheetId
     * @param $sheetId
     * @return mixed
     */
    public function deleteSheet($spreadsheetId, $sheetId)
    {
        return $this->batchUpdateSpreadsheet($spreadsheetId, [
            'requests' => [
                [
                    'deleteSheet' => [
                        'sheetId' => $sheetId
                    ]
                ]
            ]
        ]);
    }

    /**
     * Batch Update Spreadsheet Metadata
     *
     * @param $spreadsheetId
     * @param $body
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function batchUpdateSpreadsheet($spreadsheetId, $body)
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

    /**
     * @param $spreadsheetId
     * @param $range
     * @param $values
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function updateSpreadsheetValues($spreadsheetId, $range, $values)
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
                    'values' => $values
                ]
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $spreadsheetId
     * @param $range
     * @param $values
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function appendSpreadsheetValues($spreadsheetId, $range, $values)
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
                    'range' => $range,
                    'majorDimension' => 'ROWS',
                    'values' => $values
                ]
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function clearSpreadsheetValues($spreadsheetId, $range)
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

    /**
     * @param int $count
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function generateIds($count = 10)
    {
        $response = $this->api->request(
            sprintf('%s/generateIds?count=%s', self::URI_DRIVE_FILES, $count)
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $fileId
     * @return bool
     */
    public function fileExists($fileId)
    {
        try {
            $this->getFile($fileId);
            return true;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
        }
        return false;
    }

    protected function addFields($uri, $fields = [])
    {
        if (empty($fields)) {
            $fields = $this->defaultFields;
        }
        $delimiter = (strstr($uri, '?') === false) ? '?' : '&';
        return $uri . sprintf('%sfields=%s', $delimiter, implode(',', $fields));
    }

    protected function addTeamDrive($uri)
    {
        $delimiter = (strstr($uri, '?') === false) ? '?' : '&';
        return sprintf('%s%ssupportsTeamDrives=true', $uri, $delimiter);
    }
}
